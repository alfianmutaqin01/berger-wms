<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\FilterTanggal;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Riwayat Input Produksi (F-INB-01) dan koreksi angka dokumen oleh Tim
 * Produksi setelah Operator menghitung ulang saat put-away.
 */
class InboundHistoryController extends Controller
{
    /**
     * F-INB-01: Riwayat Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.history)
     * -----------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — sudah withCount('details')
     *               dan eager-load warehouse + kolom batch_no tiap detail
     * $warehouses : Collection<Warehouse>
     * $statuses   : array<string, string> — slug status => label
     * $stats      : array{total:int, putaway:int, verifikasi:int, selesai:int}
     * $filters    : array{search:?string, status:?string, warehouse_id:?string,
     *                     from:?string, to:?string}
     *
     * CATATAN: satu dokumen bisa memuat BEBERAPA batch, karena satu berkas
     * produksi berisi banyak baris. Karena itu kolom batch menampilkan daftar
     * unik, bukan satu nilai tunggal seperti pada rancangan mock lama.
     */
    public function historyIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'warehouse_id' => WarehouseScope::resolveFilter($request, $request->user()),
            'from' => FilterTanggal::bersih($request->query('from')),
            'to' => FilterTanggal::bersih($request->query('to')),
        ];

        $base = WarehouseScope::apply(InboundHeader::query(), $request->user())
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount('details')
            // Hanya kolom batch_no yang diambil dari detail; memuat seluruh
            // kolom untuk ratusan palet hanya untuk menampilkan daftar batch
            // adalah pemborosan.
            ->with(['warehouse:id,code,name', 'creator:id,full_name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('production_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('production_date', '<=', $to))
            ->latest('production_date')
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('wms.inbound.history', [
            'documents' => $documents,
            'warehouses' => WarehouseScope::options($request->user()),
            'statuses' => InboundHeader::STATUS_LABELS,
            'stats' => [
                'total' => (clone $base)->count(),
                'putaway' => (clone $base)->where('status', InboundHeader::STATUS_PUTAWAY_PENDING)->count(),
                'verifikasi' => (clone $base)->whereIn('status', [
                    InboundHeader::STATUS_VERIFICATION_PENDING,
                    InboundHeader::STATUS_PARTIAL_VERIFIED,
                ])->count(),
                'selesai' => (clone $base)->where('status', InboundHeader::STATUS_VERIFIED)->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-01: Detail Riwayat Input Produksi.
     *
     * DATA CONTRACT (view: wms.inbound.history-detail)
     * ------------------------------------------------
     * $header          : InboundHeader — eager-load warehouse & creator
     * $details         : Collection<InboundDetail> — eager-load product,
     *                    location, penempat, & penyesuai qty
     * $berselisih      : Collection<InboundDetail> — palet yang qty fisiknya
     *                    berbeda dari yang ditulis Produksi
     * $bolehSesuaikan  : bool — ada selisih yang belum ditanggapi DAN dokumen
     *                    belum disahkan Logistik
     * $totals          : array{palet:int, qty:int, produk:int, batch:int}
     *
     * Dicari berdasarkan `document_number`, bukan id, agar URL-nya terbaca
     * manusia dan cocok dengan nomor yang tercetak di dokumen fisik.
     */
    public function historyDetail(Request $request, string $doc_no): View
    {
        $header = InboundHeader::with(['warehouse', 'creator'])
            ->where('document_number', $doc_no)
            ->firstOrFail();

        // Nomor dokumen terbaca manusia — dan karena itu mudah ditebak.
        // Menyaring daftarnya saja tidak menutup apa-apa.
        WarehouseScope::assert($header->warehouse_id, $request->user());

        $details = $header->details()
            ->with([
                'product:id,sku,name,uom,pack_unit,pack_size,max_qty_per_pallet',
                'location:id,code',
                'qtyAdjustedBy:id,full_name',
                'putawayBy:id,full_name',
            ])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        // Palet berselisih dipisahkan ke panelnya sendiri di atas tabel.
        // Menyerahkannya kepada mata pembaca — "cari sendiri baris mana yang
        // qty-nya beda" — adalah cara paling andal membuat selisih terlewat
        // pada dokumen berisi puluhan palet.
        $berselisih = $details->filter(fn (InboundDetail $d) => $d->qty_variance !== null && $d->qty_variance !== 0);

        return view('wms.inbound.history-detail', [
            'header' => $header,
            'details' => $details,
            'berselisih' => $berselisih->values(),
            // Tombol "Sesuaikan" hanya muncul kalau ada yang bisa disesuaikan
            // DAN dokumennya belum disahkan Logistik. Tombol yang selalu
            // terlihat lalu selalu ditolak melatih orang mengabaikan layar.
            'bolehSesuaikan' => $header->status !== InboundHeader::STATUS_VERIFIED
                && $berselisih->contains(fn (InboundDetail $d) => ! $d->sudah_disesuaikan),
            'totals' => [
                'palet' => $details->count(),
                // Qty dijumlahkan dari pallet_qty, BUKAN total_qty: total_qty
                // berulang pada tiap palet yang berasal dari satu baris
                // produksi, sehingga menjumlahkannya akan berlipat ganda.
                'qty' => $details->sum('pallet_qty'),
                'produk' => $details->pluck('product_id')->unique()->count(),
                'batch' => $details->pluck('batch_no')->unique()->count(),
            ],
        ]);
    }

    /**
     * F-INB-01: Tim Produksi menyesuaikan qty ke hasil hitung fisik Operator.
     *
     * KENAPA LAYAR INI ADA
     * --------------------
     * Operator boleh mengoreksi Qty Aktual saat PDN, dan selisihnya dipakai
     * sebagai angka stok. Tetapi Tim Produksi — yang mengetik angka aslinya —
     * tidak pernah diberi tahu. Dokumen mereka salah, barangnya sudah naik
     * rak, dan mereka baru tahu kalau kebetulan membuka riwayat lalu
     * membandingkan sendiri kolom demi kolom. Sekarang loncengnya berbunyi
     * (lihat kirimKabarSelisih) dan koreksinya bisa dilakukan di tempat.
     *
     * YANG TIDAK DILAKUKAN TOMBOL INI
     * -------------------------------
     * Ia TIDAK menghapus selisihnya dari layar verifikasi Logistik. Angka
     * semula pindah ke pallet_qty_original dan seluruh perhitungan selisih
     * membandingkan ke sana — lihat InboundDetail::scopeBerselisih(). Kalau
     * tidak begitu, Tim Produksi bisa merapikan sendiri bukti kesalahannya
     * sebelum orang yang bertugas memeriksanya sempat melihat.
     *
     * Ia juga TIDAK menyentuh stok. Stok memakai qty_actual sejak awal
     * (InboundDetail::effective_qty), jadi yang dibereskan di sini adalah
     * dokumennya, bukan isinya rak.
     *
     * SETELAH DIVERIFIKASI, PINTUNYA TERTUTUP
     * ---------------------------------------
     * Begitu Logistik mengesahkan, angka itu sudah menjadi stok yang hidup di
     * ledger. Mengubah dokumennya setelah itu membuat dokumen dan buku besar
     * bercerita berbeda tentang kejadian yang sama.
     */
    public function adjustQty(Request $request, string $doc_no): RedirectResponse
    {
        $header = InboundHeader::where('document_number', $doc_no)->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        if ($header->status === InboundHeader::STATUS_VERIFIED) {
            return back()->with('error',
                'Dokumen ini sudah diverifikasi Logistik dan stoknya sudah aktif. '
                .'Angkanya tidak bisa diubah lagi dari sini — koreksi selisih setelah verifikasi dilakukan lewat Koreksi Stok.');
        }

        $validated = $request->validate([
            'pallets' => ['required', 'array', 'min:1'],
            'pallets.*' => ['integer'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [], [
            'pallets' => 'palet yang disesuaikan',
            'reason' => 'alasan penyesuaian',
        ]);

        // Hanya palet MILIK dokumen ini, yang memang berselisih, dan yang
        // belum pernah disesuaikan. Id palet datang dari formulir dan bisa
        // diganti angka apa pun lewat peramban.
        $terpilih = $header->details()
            ->whereIn('id', $validated['pallets'])
            ->selisihBelumDitanggapi()
            ->get();

        if ($terpilih->isEmpty()) {
            return back()->with('error', 'Tidak ada palet berselisih yang bisa disesuaikan dari pilihan itu.');
        }

        DB::transaction(function () use ($terpilih, $validated, $request, $header) {
            foreach ($terpilih as $detail) {
                $detail->update([
                    'pallet_qty_original' => $detail->pallet_qty,
                    'pallet_qty' => $detail->qty_actual,
                    'qty_adjusted_by' => $request->user()?->id,
                    'qty_adjusted_at' => now(),
                    'qty_adjust_reason' => $validated['reason'],
                ]);
            }

            // total_qty menyimpan jumlah SEBELUM dipecah menjadi palet, dan
            // dipakai layar detail untuk menampilkan "satu baris produksi
            // 235 pcs". Membiarkannya di angka lama membuat jumlah paletnya
            // tidak lagi berjumlah sama dengan barisnya — dan yang membaca
            // akan mengira ada palet yang hilang.
            foreach ($terpilih->groupBy(fn ($d) => $d->production_order_no.'|'.$d->batch_no.'|'.$d->product_id) as $grup) {
                $contoh = $grup->first();

                $baru = (int) $header->details()
                    ->where('production_order_no', $contoh->production_order_no)
                    ->where('batch_no', $contoh->batch_no)
                    ->where('product_id', $contoh->product_id)
                    ->sum('pallet_qty');

                $header->details()
                    ->where('production_order_no', $contoh->production_order_no)
                    ->where('batch_no', $contoh->batch_no)
                    ->where('product_id', $contoh->product_id)
                    ->update(['total_qty' => $baru, 'updated_at' => now()]);
            }
        });

        Activity::record(
            ActivityLog::INBOUND_QTY_ADJUST,
            sprintf(
                'Menyesuaikan qty dokumen %s ke hitungan fisik — %d palet. Alasan: %s',
                $header->document_number,
                $terpilih->count(),
                $validated['reason'],
            ),
            $header,
            $header->warehouse_id,
            [
                'dokumen' => $header->document_number,
                'palet' => $terpilih->count(),
                'alasan' => $validated['reason'],
                'perubahan' => $terpilih->map(fn (InboundDetail $d) => [
                    'palet' => $d->pallet_no,
                    'batch' => $d->batch_no,
                    'semula' => $d->pallet_qty,
                    'menjadi' => $d->qty_actual,
                ])->all(),
            ],
        );

        return back()->with('success', sprintf(
            '%d palet disesuaikan ke hitungan fisik. Angka semula tetap tercatat dan selisihnya tetap '
                .'terlihat oleh Logistik saat verifikasi — penyesuaian ini menambah keterangan, bukan menghapus temuan.',
            $terpilih->count(),
        ));
    }
}
