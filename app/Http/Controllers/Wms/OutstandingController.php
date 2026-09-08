<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\SalesOrderOutstanding;
use App\Support\Activity;
use App\Support\Outbound\Reshipment;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Riwayat Outstanding — kekurangan pesanan yang pernah terjadi.
 *
 * MENGAPA HALAMAN SENDIRI
 * -----------------------
 * Sebelum ini, kekurangan hanya terlihat satu pesanan pada satu waktu: Sales
 * membukanya di detail pesanannya sendiri, dan Logistik hanya melihatnya
 * sekilas sebagai peringatan di layar penerimaan. Pertanyaan yang sebenarnya
 * dipakai di lapangan justru yang menyeberang pesanan — "SKU apa yang paling
 * sering kurang", "PO siapa saja yang masih terutang" — dan itu tidak bisa
 * dijawab dengan membuka pesanan satu per satu.
 *
 * DUA ANGKA, DUA SUMBER, SENGAJA
 * ------------------------------
 * Barisnya berasal dari `sales_order_outstandings` — catatan MOMEN, tidak
 * pernah berubah. Kolom "sisa sekarang" dibaca dari
 * `sales_order_details.outstanding_qty` — KEADAAN, yang memang berubah terus.
 * Keduanya tidak pernah bisa berselisih karena masing-masing hanya punya satu
 * sumber; yang satu bercerita apa yang pernah terjadi, yang satu apa yang
 * masih terutang hari ini.
 *
 * DATA CONTRACT
 * -------------
 * index() : $baris LengthAwarePaginator<SalesOrderOutstanding>, $warehouses,
 *           $filters{search,sebab,keadaan,warehouse},
 *           $stats{baris_berjalan,qty_berjalan,pesanan_berjalan}
 */
class OutstandingController extends Controller
{
    public function __construct(private readonly Reshipment $reshipment) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'search' => $request->query('search'),
            'sebab' => $request->query('sebab'),
            // 'berjalan' | 'selesai' | null(semua)
            'keadaan' => $request->query('keadaan'),
            'warehouse' => WarehouseScope::resolveFilter($request, $user, 'warehouse'),
        ];

        $baris = SalesOrderOutstanding::query()
            // Pesanan yang sudah dihapus Sales tidak ikut muncul. Barisnya
            // tetap tersimpan (riwayat tidak dihapus), hanya tidak lagi
            // ditagihkan ke siapa pun.
            ->whereHas('salesOrder')
            ->search($filters['search'])
            ->when($filters['sebab'], fn ($q, $sebab) => $q->where('cause', $sebab))
            ->when($filters['warehouse'], fn ($q, $w) => $q->where('warehouse_id', $w))
            // Menyaring keadaan lewat baris pesanannya, bukan lewat kolom di
            // riwayat: kolom di sini adalah cuplikan masa lalu dan tidak ikut
            // berubah saat kekurangannya akhirnya tertutup.
            ->when($filters['keadaan'] === 'berjalan', fn ($q) => $q->whereHas(
                'detail', fn ($d) => $d->where('outstanding_qty', '>', 0)
            ))
            ->when($filters['keadaan'] === 'selesai', fn ($q) => $q->whereDoesntHave(
                'detail', fn ($d) => $d->where('outstanding_qty', '>', 0)
            ))
            ->with([
                'salesOrder:id,order_number,customer_po_number,bc_so_number,customer_id,status',
                'salesOrder.customer:id,code,name',
                'product:id,sku,name,uom',
                'warehouse:id,code,name',
                'recordedBy:id,full_name',
                'detail:id,outstanding_qty,qty_ordered,qty_approved,qty_shipped',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        /*
         * Pesanan mana yang tombol "Kirim Ulang"-nya boleh muncul.
         *
         * Dihitung SEKALI untuk seluruh halaman, bukan per baris: satu SKU
         * yang kurang bisa muncul beberapa kali dalam riwayat pesanan yang
         * sama, dan bertanya ke database di dalam perulangan Blade berarti
         * dua puluh baris menjadi dua puluh query.
         *
         * Ini hanya menentukan TAMPIL atau tidaknya tombol. Penegakan yang
         * sebenarnya ada di Reshipment::open(), di dalam kunci — daftar ini
         * sudah basi begitu halamannya terkirim ke layar.
         */
        $idPesanan = $baris->pluck('sales_order_id')->unique()->values();

        $bolehKirimUlang = $idPesanan->isEmpty() ? collect() : SalesOrder::query()
            ->whereIn('id', $idPesanan)
            ->whereIn('status', Reshipment::STATUS_BOLEH)
            ->whereHas('details', fn ($d) => $d->where('outstanding_qty', '>', 0))
            ->pluck('id')
            ->flip();

        return view('wms.outbound.outstanding', [
            'baris' => $baris,
            'warehouses' => WarehouseScope::options($user),
            'filters' => $filters,
            'stats' => $this->angkaBerjalan($request),
            'bolehKirimUlang' => $bolehKirimUlang,
        ]);
    }

    /**
     * KIRIM ULANG: membuka putaran pengiriman berikutnya atas kekurangan.
     *
     * NOMOR SO SAMA, SURAT JALAN BARU. Tidak ada pesanan baru yang dibuat —
     * pesanan yang sama dibuka kembali, lalu masuk lagi ke Daftar Picking dan
     * mendapat Surat Jalan sendiri dari sistem BC. Lihat
     * App\Support\Outbound\Reshipment untuk alasan lengkapnya.
     */
    public function reship(Request $request, SalesOrder $order): RedirectResponse
    {
        WarehouseScope::assert($order->warehouse_id, $request->user());

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['note' => 'catatan']);

        try {
            $hasil = $this->reshipment->open($order, $data['note'] ?? null, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::ORDER_RESHIP,
            sprintf(
                'Membuka pengiriman ulang ke-%d untuk %s: %d unit kurang, %d berhasil dicadangkan dari stok.',
                $hasil['putaran'],
                $order->order_number,
                $hasil['diminta'],
                $hasil['didapat'],
            ),
            $order,
            $order->warehouse_id,
            [
                'pesanan' => $order->order_number,
                'putaran' => $hasil['putaran'],
                'diminta' => $hasil['diminta'],
                'didapat' => $hasil['didapat'],
                'catatan' => $data['note'] ?? null,
            ],
        );

        $pesan = sprintf(
            'Pengiriman ulang ke-%d dibuka untuk %s. Pesanan kembali ke Daftar Picking dengan nomor SO yang sama, '.
            'dan akan mendapat Surat Jalan baru.',
            $hasil['putaran'],
            $order->order_number,
        );

        // Yang tidak kebagian stok WAJIB dikatakan. Orang yang menekan tombol
        // untuk 50 unit akan mengira 50 itu sudah aman; kalau yang terpegang
        // cuma 30, sisanya tetap terutang dan tidak ada yang tahu.
        if ($hasil['didapat'] < $hasil['diminta']) {
            return redirect()->route('wms.outstanding.index')->with('warning', $pesan.sprintf(
                ' Dari %d unit yang kurang, %d belum kebagian stok dan TETAP tercatat outstanding — '.
                'bisa dikirim ulang lagi begitu stoknya ada.',
                $hasil['diminta'],
                $hasil['diminta'] - $hasil['didapat'],
            ));
        }

        return redirect()->route('wms.outstanding.index')->with('success', $pesan.sprintf(
            ' Seluruh %d unit sudah dicadangkan dari stok.',
            $hasil['didapat'],
        ));
    }

    /**
     * Ringkasan yang MASIH terutang hari ini.
     *
     * Dihitung dari baris pesanan, bukan dari riwayat: yang ditanyakan orang
     * saat melihat kartu ringkas adalah "berapa yang masih kurang sekarang",
     * dan menjumlahkan riwayat akan menghitung kekurangan yang sudah lama
     * tertutup ikut ke dalamnya.
     *
     * @return array{baris_berjalan:int, qty_berjalan:int, pesanan_berjalan:int}
     */
    private function angkaBerjalan(Request $request): array
    {
        $q = fn () => SalesOrderDetail::query()
            ->where('outstanding_qty', '>', 0)
            ->whereHas('salesOrder', fn ($o) => WarehouseScope::apply($o, $request->user()));

        return [
            'baris_berjalan' => $q()->count(),
            'qty_berjalan' => (int) $q()->sum('outstanding_qty'),
            'pesanan_berjalan' => $q()->distinct('sales_order_id')->count('sales_order_id'),
        ];
    }
}
