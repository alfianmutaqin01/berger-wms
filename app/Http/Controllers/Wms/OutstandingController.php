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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 * SATUAN HALAMANNYA NOMOR SO, BUKAN BARIS SKU
 * -------------------------------------------
 * Dulu tiap SKU yang kurang berdiri sebagai barisnya sendiri di daftar utama.
 * Akibatnya satu pesanan berisi dua belas SKU memenuhi seluruh halaman
 * sendirian, dan pesanan lain yang juga terutang terdorong ke halaman
 * berikutnya — padahal pertanyaan yang dibawa orang ke layar ini adalah "PO
 * mana yang masih terutang", bukan "baris riwayat ke berapa". Rinciannya tetap
 * ada, tinggal satu klik di bawah nomor SO-nya.
 *
 * DUA ANGKA, DUA SUMBER, SENGAJA
 * ------------------------------
 * Rincian yang muncul saat sebuah pesanan dibuka berasal dari
 * `sales_order_outstandings` — catatan MOMEN, tidak pernah berubah. Ringkasan
 * di baris pesanannya dibaca dari `sales_order_details` — KEADAAN, yang memang
 * berubah terus. Keduanya tidak pernah bisa berselisih karena masing-masing
 * hanya punya satu sumber; yang satu bercerita apa yang pernah terjadi, yang
 * satu apa yang masih terutang hari ini.
 *
 * DATA CONTRACT
 * -------------
 * index() : $halaman LengthAwarePaginator (satu entri per sales_order_id,
 *           dipakai hanya untuk tautan halaman), $kelompok Collection<array{
 *           order, sku, dipesan, terkirim, sisa, sku_kurang, peristiwa,
 *           terakhir}>, $warehouses, $filters{search,sebab,keadaan,warehouse},
 *           $stats{baris_berjalan,qty_berjalan,pesanan_berjalan},
 *           $bolehKirimUlang Collection<int,int> berkunci sales_order_id
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

        /*
         * Dasar yang sama dipakai dua kali: sekali untuk memilih PESANAN mana
         * yang masuk halaman ini, sekali lagi untuk mengambil baris riwayat
         * milik pesanan-pesanan itu. Ditulis sebagai closure, bukan variabel,
         * supaya pemakaian kedua tidak mewarisi klausa yang ditambahkan
         * pemakaian pertama.
         */
        $dasar = fn () => SalesOrderOutstanding::query()
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
            ));

        /*
         * MAX(created_at) jadi kunci urut supaya pesanan yang kekurangannya
         * paling baru berdiri paling atas — sama seperti perilaku lama saat
         * barisnya masih berdiri sendiri-sendiri.
         */
        $halaman = $dasar()
            ->selectRaw('sales_order_id, MAX(created_at) AS terakhir, MAX(id) AS id_terakhir')
            ->groupBy('sales_order_id')
            ->orderByDesc('terakhir')
            ->orderByDesc('id_terakhir')
            ->paginate(20)
            ->withQueryString();

        $idPesanan = collect($halaman->items())->pluck('sales_order_id');

        /*
         * Pesanan mana yang tombol "Kirim Outstanding"-nya boleh muncul.
         *
         * Dihitung SEKALI untuk seluruh halaman: bertanya ke database di dalam
         * perulangan Blade berarti dua puluh pesanan menjadi dua puluh query.
         *
         * Ini hanya menentukan TAMPIL atau tidaknya tombol. Penegakan yang
         * sebenarnya ada di Reshipment::open(), di dalam kunci — daftar ini
         * sudah basi begitu halamannya terkirim ke layar.
         */
        $bolehKirimUlang = $idPesanan->isEmpty() ? collect() : SalesOrder::query()
            ->whereIn('id', $idPesanan)
            ->whereIn('status', Reshipment::STATUS_BOLEH)
            ->whereHas('details', fn ($d) => $d->where('outstanding_qty', '>', 0))
            ->pluck('id')
            ->flip();

        return view('wms.outbound.outstanding', [
            'halaman' => $halaman,
            'kelompok' => $this->kelompokkan($idPesanan, $dasar),
            'warehouses' => WarehouseScope::options($user),
            'filters' => $filters,
            'stats' => $this->angkaBerjalan($request),
            'bolehKirimUlang' => $bolehKirimUlang,
        ]);
    }

    /**
     * Merakit satu kelompok per nomor SO, lengkap dengan rincian SKU-nya.
     *
     * Urutan kelompok mengikuti urutan $idPesanan apa adanya — itu hasil
     * pengurutan di database, dan mengurutkan ulang di sini akan diam-diam
     * berbeda dari halaman berikutnya.
     *
     * @param  Collection<int, int>  $idPesanan
     * @param  callable():Builder  $dasar
     * @return Collection<int, array<string, mixed>>
     */
    private function kelompokkan(Collection $idPesanan, callable $dasar): Collection
    {
        if ($idPesanan->isEmpty()) {
            return collect();
        }

        $riwayat = $dasar()
            ->whereIn('sales_order_id', $idPesanan)
            ->with([
                'product:id,sku,name,uom',
                'recordedBy:id,full_name',
                'detail:id,outstanding_qty,qty_ordered,qty_approved,qty_shipped',
            ])
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->groupBy('sales_order_id');

        $pesanan = SalesOrder::query()
            ->whereIn('id', $idPesanan)
            ->with([
                'customer:id,code,name',
                'warehouse:id,code,name',
                'details:id,sales_order_id,product_id,qty_ordered,qty_approved,qty_shipped,outstanding_qty',
                'details.product:id,sku,name,uom',
            ])
            ->get()
            ->keyBy('id');

        return $idPesanan
            ->map(function ($id) use ($pesanan, $riwayat): ?array {
                $order = $pesanan->get($id);

                if ($order === null) {
                    return null;
                }

                $isi = $riwayat->get($id) ?? collect();
                $detail = $order->details;

                return [
                    'order' => $order,
                    'sku' => $this->rincianSku($isi, $detail),
                    'dipesan' => (int) $detail->sum('qty_ordered'),
                    'terkirim' => (int) $detail->sum('qty_shipped'),
                    'sisa' => (int) $detail->sum('outstanding_qty'),
                    'sku_kurang' => $detail->where('outstanding_qty', '>', 0)->count(),
                    'peristiwa' => $isi->count(),
                    'terakhir' => $isi->first()?->created_at,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Rincian per SKU di dalam satu pesanan.
     *
     * Satu baris pesanan bisa punya BEBERAPA peristiwa kekurangan — disetujui
     * sebagian saat penerimaan, lalu kurang lagi saat Surat Jalan berangkat.
     * Keduanya ditampilkan di bawah SKU yang sama alih-alih sebagai dua baris
     * sejajar: yang dibaca orang adalah "SKU ini kurang berapa", dan riwayat
     * peristiwanya menjawab pertanyaan lanjutan — bukan barisnya sendiri.
     *
     * @param  Collection<int, SalesOrderOutstanding>  $riwayat
     * @param  Collection<int, SalesOrderDetail>  $detail
     * @return Collection<int, array<string, mixed>>
     */
    private function rincianSku(Collection $riwayat, Collection $detail): Collection
    {
        $perDetail = $detail->keyBy('id');

        return $riwayat
            ->groupBy('sales_order_detail_id')
            ->map(function (Collection $peristiwa, $detailId) use ($perDetail): array {
                $baris = $perDetail->get($detailId);
                $terbaru = $peristiwa->first();

                return [
                    'produk' => $baris?->product ?? $terbaru->product,
                    'dipesan' => (int) ($baris?->qty_ordered ?? $terbaru->qty_ordered),
                    'disetujui' => (int) ($baris?->qty_approved ?? 0),
                    'terkirim' => (int) ($baris?->qty_shipped ?? 0),
                    // NULL berarti barisnya sudah DICABUT dari pesanan —
                    // berbeda dari nol yang berarti kewajibannya terpenuhi.
                    'sisa' => $baris?->outstanding_qty === null ? null : (int) $baris->outstanding_qty,
                    'peristiwa' => $peristiwa,
                ];
            })
            // Yang masih kurang naik ke atas; sisanya menyusul menurut
            // peristiwa terakhirnya.
            ->sortByDesc(fn (array $s) => [$s['sisa'] ?? -1, $s['peristiwa']->first()?->id])
            ->values();
    }

    /**
     * KIRIM OUTSTANDING: membuka putaran pengiriman berikutnya atas kekurangan.
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
