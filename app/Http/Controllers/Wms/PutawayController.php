<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InboundDetail;
use App\Models\InboundHeader;
use App\Models\Location;
use App\Models\Notification;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Inbound\BinAllocator;
use App\Support\Notifier;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Put-away (PDN) — PRD §6.3 F-INB-02: Operator menempatkan palet hasil
 * produksi ke rak dan mencatat Qty Aktual.
 */
class PutawayController extends Controller
{
    /**
     * F-INB-02: Daftar dokumen yang menunggu put-away.
     *
     * DATA CONTRACT (view: wms.inbound.putaway-list)
     * ----------------------------------------------
     * $documents  : LengthAwarePaginator<InboundHeader> — withCount details
     *               (total) & details_placed (sudah punya lokasi)
     * $warehouses : Collection<Warehouse>
     * $stats      : array{dokumen:int, palet:int, belum:int}
     * $filters    : array{search:?string, warehouse_id:?string}
     *
     * Hanya dokumen berstatus `putaway_pending` yang muncul. Dokumen yang
     * put-away-nya baru sebagian tetap di daftar ini — lihat kolom kemajuan —
     * karena pekerjaan fisik lazim terputus dan harus bisa dilanjutkan.
     */
    public function putawayIndex(Request $request): View
    {
        $filters = [
            'search' => $request->query('search'),
            'warehouse_id' => WarehouseScope::resolveFilter($request, $request->user()),
        ];

        $base = WarehouseScope::apply(InboundHeader::query(), $request->user())
            ->awaitingPutaway()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $documents = (clone $base)
            ->withCount(['details', 'details as details_placed_count' => fn ($q) => $q->placed()])
            ->with(['warehouse:id,code,name', 'details:id,inbound_header_id,batch_no'])
            ->search($filters['search'])
            // Dokumen terlama didahulukan: barang yang sudah lama menganggur di
            // area terima adalah yang paling mendesak dimasukkan ke rak.
            ->oldest('production_date')
            ->oldest('id')
            ->paginate(15)
            ->withQueryString();

        $paletBase = InboundDetail::whereIn('inbound_header_id', (clone $base)->select('id'));

        return view('wms.inbound.putaway-list', [
            'documents' => $documents,
            'warehouses' => WarehouseScope::options($request->user()),
            'stats' => [
                'dokumen' => (clone $base)->count(),
                'palet' => (clone $paletBase)->count(),
                'belum' => (clone $paletBase)->whereNull('location_id')->count(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * F-INB-02: Layar penempatan palet ke rak.
     *
     * DATA CONTRACT (view: wms.inbound.putaway-process)
     * -------------------------------------------------
     * $header    : InboundHeader
     * $details   : Collection<InboundDetail> — eager-load product & location
     * $locations : Collection<Location> — SELURUH bin aktif di gudang dokumen
     *              ini; ketersediaan per baris dihitung di sisi klien karena
     *              tergantung SKU baris itu (lihat $occupancy)
     * $occupancy : array<string, array{product_id:int, qty:int, capacity:?int,
     *              uom:?string}> — kode bin => isi bin saat ini
     * $totals    : array{palet:int, ditempatkan:int}
     *
     * Daftar bin dibatasi ke gudang dokumen. Tanpa itu, Operator bisa memilih
     * bin milik gudang lain — kode rak seperti "B-01-01" berulang antar gudang,
     * jadi kesalahannya tidak akan terlihat sampai barangnya dicari.
     */
    public function putawayProcess(Request $request, string $doc_no): View
    {
        $header = InboundHeader::with('warehouse')
            ->awaitingPutaway()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $details = $header->details()
            ->with(['product:id,sku,name,uom,pack_unit,pack_size,max_qty_per_pallet', 'location:id,code'])
            ->orderBy('production_order_no')
            ->orderBy('pallet_no')
            ->get();

        $locations = Location::where('warehouse_id', $header->warehouse_id)
            ->active()
            ->penyimpanan()
            ->inStorageOrder()
            ->get(['id', 'code', 'zone']);

        return view('wms.inbound.putaway-process', [
            'header' => $header,
            'details' => $details,
            'locations' => $locations,
            'occupancy' => BinAllocator::occupancyByCode($locations),
            'totals' => [
                'palet' => $details->count(),
                'ditempatkan' => $details->whereNotNull('location_id')->count(),
            ],
        ]);
    }

    /**
     * F-INB-02: menyimpan penempatan palet.
     *
     * Aturan yang membentuk method ini:
     *
     * 1. PUT-AWAY BOLEH SEBAGIAN. Palet yang lokasinya dikosongkan hanya
     *    dilewati, tidak menggagalkan penyimpanan. Memaksa semua palet terisi
     *    sekaligus akan membuat Operator kehilangan pekerjaan setengah jalan
     *    setiap kali giliran kerjanya habis.
     * 2. STATUS NAIK HANYA BILA LENGKAP. Dokumen baru berpindah ke
     *    `verification_pending` setelah seluruh paletnya punya lokasi.
     * 3. SATU BIN = SATU SLOT PALET. Boleh memuat beberapa palet dari SKU
     *    yang SAMA sampai kapasitas palet SKU itu (Product::max_qty_per_
     *    pallet) — pallet split (PRD §7.1) boleh digabung kembali di bin
     *    yang sama. SKU yang berbeda TIDAK boleh berbagi bin.
     *
     * Qty Aktual boleh dikoreksi Operator (PRD §6.3 F-INB-02) — SKU dan batch
     * tidak, karena keduanya berasal dari dokumen produksi dan bukan wewenang
     * gudang untuk mengubahnya.
     */
    public function putawayStore(Request $request, string $doc_no): RedirectResponse
    {
        $header = InboundHeader::awaitingPutaway()
            ->where('document_number', $doc_no)
            ->firstOrFail();

        WarehouseScope::assert($header->warehouse_id, $request->user());

        $validated = $request->validate([
            'pallets' => ['required', 'array'],
            'pallets.*.location_code' => ['nullable', 'string', 'max:20'],
            // Batas atas 100.000 mencegah salah ketik yang mustahil secara
            // fisik; palet terbesar di sistem ini memuat 720 pcs.
            'pallets.*.qty_actual' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $details = $header->details()->with('product:id,uom,pack_unit,pack_size,max_qty_per_pallet')->get()->keyBy('id');
        $allocator = BinAllocator::forWarehouse($header->warehouse_id, $header->warehouse?->code);

        $errors = [];

        // TAHAP 1 — kumpulkan kandidat & periksa isian dasarnya. Belum
        // menyentuh aturan kapasitas: seluruh kandidat harus diketahui lebih
        // dulu supaya bisa dilepas bersama-sama pada tahap 2.
        $kandidat = [];

        foreach ($validated['pallets'] as $detailId => $input) {
            $detail = $details->get((int) $detailId);

            // Palet dari dokumen lain diabaikan diam-diam: id-nya bisa saja
            // dikarang lewat peramban, dan tidak ada alasan sah untuk itu.
            if (! $detail) {
                continue;
            }

            $code = $allocator->normalize((string) ($input['location_code'] ?? ''));

            if ($code === '') {
                continue;
            }

            if (! $allocator->has($code)) {
                $errors["pallets.{$detailId}.location_code"] = $allocator->unknownCodeMessage($code);

                continue;
            }

            $qty = $input['qty_actual'] ?? null;

            if ($qty === null || $qty === '') {
                $errors["pallets.{$detailId}.qty_actual"] = 'Qty Aktual wajib diisi untuk palet yang ditempatkan.';

                continue;
            }

            $kandidat[$detail->id] = ['detail' => $detail, 'code' => $code, 'qty' => (int) $qty];
        }

        // TAHAP 2 — lepas seluruh kandidat dari isi bin lama, lalu tempatkan.
        // Tanpa pelepasan ini, palet yang disimpan ulang terhitung dua kali.
        $allocator->release(array_keys($kandidat));

        $penempatan = [];

        foreach ($kandidat as $detailId => $calon) {
            $hasil = $allocator->place($calon['detail'], $calon['code'], $calon['qty']);

            if (isset($hasil['error'])) {
                $errors["pallets.{$detailId}.location_code"] = $hasil['error'];

                continue;
            }

            $penempatan[$detailId] = [
                'location_id' => $hasil['location_id'],
                'qty_actual' => $calon['qty'],
            ];
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        if ($penempatan === []) {
            return back()->with('error', 'Belum ada palet yang diberi lokasi rak.');
        }

        DB::transaction(function () use ($header, $penempatan, $request) {
            foreach ($penempatan as $detailId => $nilai) {
                $header->details()->whereKey($detailId)->update($nilai + [
                    'putaway_by' => $request->user()?->id,
                    'putaway_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($header->isFullyPlaced()) {
                $header->update(['status' => InboundHeader::STATUS_VERIFICATION_PENDING]);
            }
        });

        $header->refresh();
        $tersisa = $header->details()->whereNull('location_id')->count();

        if ($tersisa > 0) {
            return redirect()->route('wms.inbound.putaway.process', $header->document_number)->with(
                'success',
                sprintf(
                    '%d palet tersimpan. Masih ada %d palet yang belum ditempatkan — dokumen tetap di daftar PDN.',
                    count($penempatan),
                    $tersisa
                )
            );
        }

        Activity::record(
            ActivityLog::INBOUND_PUTAWAY,
            sprintf(
                'Menaikkan dokumen %s ke rak — %d palet ditempatkan.',
                $header->document_number,
                $header->details()->count(),
            ),
            $header,
            $header->warehouse_id,
            ['dokumen' => $header->document_number, 'palet' => $header->details()->count()],
        );

        Notifier::toPermission(
            Permission::INBOUND_VERIFY,
            $header->warehouse_id,
            Notification::INBOUND_VERIFY_READY,
            'Barang masuk menunggu verifikasi',
            sprintf(
                'Dokumen %s sudah naik rak — %d palet menunggu diperiksa. Stok belum aktif sebelum diverifikasi.',
                $header->document_number,
                $header->details()->count(),
            ),
            route('wms.inbound.verify'),
            $header,
        );

        // Tim Produksi diberi tahu kalau hitungan fisik Operator berbeda dari
        // angka yang mereka tulis. Sebelum ini tidak ada satu pun jalur yang
        // memberitahu mereka — selisihnya hanya beredar antara Operator dan
        // Logistik, padahal yang bisa memperbaiki sumbernya adalah Produksi.
        $this->kirimKabarSelisih($header);

        return redirect()->route('wms.inbound.putaway')->with('success', sprintf(
            'PDN dokumen %s selesai: %d palet ditempatkan, kini menunggu verifikasi Logistik.',
            $header->document_number,
            $header->details()->count()
        ));
    }

    /**
     * Memberi tahu Tim Produksi bahwa hitungan fisiknya berbeda.
     *
     * Dikirim ke pemegang izin INBOUND_CREATE di gudang itu — Tim Produksi
     * dan Super Admin. Pembuat dokumennya diberi tahu terpisah kalau ternyata
     * ia tidak tercakup: dialah yang mengetik angkanya, dan dia yang paling
     * perlu tahu berkas buatannya meleset.
     *
     * Notifier tidak pernah mengirim ke diri sendiri, jadi Operator yang baru
     * saja menekan simpan tidak menerima loncengnya sendiri.
     */
    private function kirimKabarSelisih(InboundHeader $header): void
    {
        $berselisih = $header->details()->berselisih()->get();

        if ($berselisih->isEmpty()) {
            return;
        }

        $judul = 'Qty fisik berbeda dari dokumen produksi';
        $isi = sprintf(
            'Dokumen %s — %d palet dihitung ulang Operator dan hasilnya berbeda (%s). '
                .'Buka detailnya untuk menyesuaikan angka dokumen.',
            $header->document_number,
            $berselisih->count(),
            $berselisih
                ->take(3)
                ->map(fn (InboundDetail $d) => sprintf(
                    'batch %s: %d → %d',
                    $d->batch_no,
                    $d->qty_sistem_asli,
                    $d->qty_actual,
                ))
                ->implode('; ').($berselisih->count() > 3 ? '; …' : ''),
        );

        $url = route('wms.inbound.history.detail', $header->document_number);

        Notifier::toPermission(
            Permission::INBOUND_CREATE,
            $header->warehouse_id,
            Notification::INBOUND_QTY_VARIANCE,
            $judul,
            $isi,
            $url,
            $header,
        );

        // Pembuat dokumen bisa saja TIDAK tercakup kiriman di atas: perannya
        // berubah, akunnya dipindah gudang, atau ia dinonaktifkan lalu aktif
        // lagi. Dia yang mengetik angkanya, jadi dia yang paling perlu tahu.
        //
        // Diperiksa dulu apakah ia sudah termasuk — Notifier menulis apa yang
        // diberikan tanpa memeriksa penerima ganda, jadi memanggil keduanya
        // begitu saja akan membunyikan dua lonceng identik untuk satu orang.
        $pembuat = $header->creator()->first();

        $sudahDapat = $pembuat !== null
            && $pembuat->is_active
            && Permission::allows($pembuat, Permission::INBOUND_CREATE)
            && ($pembuat->warehouse_id === null || $pembuat->warehouse_id === $header->warehouse_id);

        if (! $sudahDapat) {
            Notifier::toUser(
                $header->created_by,
                Notification::INBOUND_QTY_VARIANCE,
                $judul,
                $isi,
                $url,
                $header->warehouse_id,
                $header,
            );
        }
    }
}
