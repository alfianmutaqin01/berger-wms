<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ReportPickingShortageRequest;
use App\Http\Requests\Wms\StorePickingListRequest;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\MaterialRequisition;
use App\Models\Notification;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\SalesOrder;
use App\Models\StockTransfer;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Outbound\PickingListBuilder;
use App\Support\Outbound\PickingRun;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Picking — PRD §6.5 F-OUT-03, Fase 6 tahap 3.
 *
 * DUA LAYAR UNTUK DUA ORANG, dan itu sebabnya keduanya ada di satu controller
 * tetapi di balik hak akses yang berbeda:
 *
 *   batching()  Logistik  menyusun daftar: pesanan mana berangkat bersama
 *   queue()     Operator  mengerjakannya: ambil tugas, jalan, tandai
 *
 * Wewenangnya sengaja tidak sama. Yang menentukan isi container bukan yang
 * berjalan ke rak — menyatukannya berarti operator bisa memilih sendiri
 * pesanan mana yang ia kerjakan hari ini.
 *
 * PEMBATASAN GUDANG. Daftar picking adalah pekerjaan fisik di satu bangunan,
 * jadi ia tidak pernah lintas gudang seperti transfer. Penyaringannya cukup
 * WarehouseScope::apply() biasa, dan tiap titik masuk yang menerima satu
 * objek memanggil assert() — menyaring daftar saja tidak menutup URL detail.
 *
 * DATA CONTRACT
 * -------------
 * batching() : $lists LengthAwarePaginator<PickingList>, $antrean
 *              Collection<SalesOrder>, $gudangSaya, $filters
 * queue()    : $tugas Collection<PickingList>, $milikSaya ?PickingList
 * show()     : $list PickingList, $baris Collection<PickingListItem>,
 *              $ringkas array, $bolehDikerjakan bool
 */
class PickingController extends Controller
{
    public function __construct(
        private readonly PickingListBuilder $penyusun,
        private readonly PickingRun $picking,
    ) {}

    /* ------------------------------------------------------------ Logistik */

    /** Layar penyusunan daftar: antrean pesanan siap picking + daftar berjalan. */
    public function batching(Request $request): View
    {
        $user = $request->user();
        $gudang = WarehouseScope::resolveFilter($request, $user);

        $antrean = SalesOrder::query()
            ->where('status', SalesOrder::STATUS_APPROVED)
            // Yang sudah masuk daftar lain tidak boleh muncul lagi: satu
            // pesanan di dua daftar berarti barangnya diambil dua kali.
            ->whereNull('picking_list_id')
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->with(['customer:id,code,name', 'warehouse:id,code,name'])
            ->withCount('details')
            // Terlama dulu: pesanan yang paling lama menunggu adalah yang
            // paling dekat melanggar SLA (§7.6).
            ->orderBy('approved_at')
            ->get();

        $lists = WarehouseScope::apply(PickingList::query(), $user)
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->with(['warehouse:id,code,name', 'createdBy:id,full_name', 'claimedBy:id,full_name',
                'transfer:id,picking_list_id,transfer_number,to_warehouse_id',
                'transfer.toWarehouse:id,code,name',
                'requisition:id,picking_list_id,mrf_number,request_type'])
            ->withCount(['orders', 'items',
                // Dibaca PickingList::bolehDibatalkan() untuk tombol Batal.
                'items as items_tersentuh_count' => fn ($q) => $q->where('status', '<>', PickingListItem::STATUS_PENDING)])
            // Yang masih perlu dikerjakan selalu di atas.
            ->orderByRaw("CASE WHEN status IN ('open', 'picking') THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return view('wms.outbound.picking-batching', [
            'antrean' => $antrean,
            'lists' => $lists,
            'gudangSaya' => $user?->warehouse,
            'gudangOptions' => WarehouseScope::options($user),
            'filters' => ['warehouse_id' => $gudang],
        ]);
    }

    public function store(StorePickingListRequest $request): RedirectResponse
    {
        $user = $request->user();

        try {
            $daftar = $this->penyusun->build(
                warehouseId: (int) $request->validated('warehouse_id'),
                orderIds: array_map('intval', $request->validated('order_ids')),
                catatan: $request->validated('notes'),
                userId: $user?->id,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.picking.show', $daftar)
            ->with('success', sprintf(
                'Daftar picking %s dibuat: %d pesanan, %d baris pengambilan. Operator sudah bisa mengambilnya.',
                $daftar->list_number,
                $daftar->orders()->count(),
                $daftar->items()->count(),
            ));
    }

    /** Membatalkan daftar yang belum tersentuh; pesanannya kembali ke antrean. */
    public function cancel(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [], ['cancellation_reason' => 'alasan pembatalan']);

        try {
            $this->penyusun->cancel($list, $data['cancellation_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.picking.batching')
            ->with('success', sprintf(
                'Daftar %s dibatalkan. Pesanannya kembali ke antrean dan bisa disusun ulang.',
                $list->list_number
            ));
    }

    /* ------------------------------------------------------------ Operator */

    /** Antrean tugas operator: daftar yang bebas + yang sedang ia pegang. */
    public function queue(Request $request): View
    {
        $user = $request->user();

        $tugas = WarehouseScope::apply(PickingList::query(), $user)
            ->aktif()
            // transfer ikut dimuat supaya antrean operator bisa menyebut
            // dengan jelas mana tugas pesanan dan mana tugas kiriman antar
            // gudang. Keduanya pekerjaan yang sama di rak, tetapi barangnya
            // berakhir di kendaraan yang berbeda.
            ->with(['warehouse:id,code,name', 'claimedBy:id,full_name',
                'transfer:id,picking_list_id,transfer_number,to_warehouse_id',
                'transfer.toWarehouse:id,code,name',
                'requisition:id,picking_list_id,mrf_number,request_type'])
            ->withCount(['orders', 'items'])
            ->orderBy('created_at')
            ->get();

        return view('wms.outbound.picking', [
            'tugas' => $tugas,
            'milikSaya' => $tugas->firstWhere('claimed_by', $user?->id),
        ]);
    }

    /** Rincian satu daftar — dipakai Logistik maupun Operator. */
    public function show(Request $request, PickingList $list): View
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $baris = $list->items()
            // stock ikut dimuat demi penanda "Dahulukan Keluar": operator yang
            // melihat batch baru diambil sementara yang lama masih di rak akan
            // mengira daftarnya salah. Alasannya harus terbaca di kertas yang
            // ia bawa, bukan cuma tersimpan di layar Logistik.
            ->with(['product:id,sku,name,uom', 'location:id,code', 'salesOrder.customer:id,code,name',
                'transferDetail:id,stock_transfer_id,qty_requested',
                'allocation:id,material_requisition_id,qty_allocated',
                'stock:id,prioritize_out,prioritize_reason'])
            // Urutan berjalan operator: menurut kode rak, dari A ke belakang
            // (F-OUT-03 #3). Diurutkan lewat join supaya yang menentukan
            // adalah KODE raknya, bukan id barisnya.
            ->join('locations', 'locations.id', '=', 'picking_list_items.location_id')
            ->orderBy('locations.code')
            ->orderBy('picking_list_items.id')
            ->select('picking_list_items.*')
            ->get();

        return view('wms.outbound.picking-detail', [
            'list' => $list->load(['warehouse:id,code,name', 'createdBy:id,full_name',
                'claimedBy:id,full_name', 'completedBy:id,full_name',
                'orders.customer:id,code,name',
                'transfer.toWarehouse:id,code,name', 'transfer.fromWarehouse:id,code,name',
                'requisition.requestedBy:id,full_name']),
            'baris' => $baris,
            'ringkas' => [
                'total' => $baris->count(),
                'selesai' => $baris->where('status', '<>', PickingListItem::STATUS_PENDING)->count(),
                'kurang' => $baris->where('status', PickingListItem::STATUS_SHORT)->count(),
                'qty' => $baris->sum('qty_to_pick'),
            ],
            'bolehDikerjakan' => $list->status === PickingList::STATUS_PICKING
                && $list->claimed_by === $request->user()?->id,
            // Logistik/Manager melepas tugas milik ORANG LAIN. Sengaja tidak
            // ditampilkan untuk daftar yang ia pegang sendiri — untuk itu
            // tombolnya sudah ada di kelompok tombol operator.
            'bolehMelepasTugas' => $list->status === PickingList::STATUS_PICKING
                && $list->claimed_by !== $request->user()?->id
                && Gate::allows(Permission::OUTBOUND_PICKING_LIST),
            /*
             | Tempat yang boleh dipilih sebagai titik serah terima MRF.
             |
             | RAK TRANSIT DIDAHULUKAN, bukan disendirikan. Barang MRF hampir
             | selalu berhenti di salah satu dari dua titik transit, jadi
             | keduanya berdiri paling atas dan tidak perlu dicari. Tetapi
             | kenyataan di lantai gudang tidak selalu begitu — kadang barangnya
             | memang dititipkan di rak biasa — dan daftar yang menolak
             | menyebutkan tempat sebenarnya hanya melahirkan catatan yang
             | tidak cocok dengan keadaan.
             |
             | Hanya dimuat untuk daftar MRF: pada daftar pesanan dan transfer
             | barangnya naik kendaraan, dan daftar rak yang tidak pernah
             | dipakai cuma memperberat halaman yang dibuka dari HP gudang.
             */
            'rakSerah' => $list->requisition()->exists()
                ? Location::where('warehouse_id', $list->warehouse_id)
                    ->active()
                    ->orderByRaw('CASE WHEN zone IN (?, ?) THEN 0 ELSE 1 END', Location::ZONES_TRANSIT)
                    ->orderBy('code')
                    ->get(['id', 'code', 'zone'])
                : collect(),
        ]);
    }

    public function claim(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        try {
            $this->picking->claim($list, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.picking.show', $list)
            ->with('success', sprintf('Daftar %s sekarang tugas Anda. Selamat berjalan.', $list->list_number));
    }

    /**
     * Melepas tugas yang sudah diambil — daftar kembali bebas diambil orang lain.
     *
     * DUA PINTU MASUK, SATU JALAN KELUAR. Operator melepas tugasnya sendiri;
     * Logistik/Manager boleh melepas milik siapa pun, dan itu satu-satunya
     * jalan saat operatornya sudah pulang dan daftarnya tertinggal terkunci.
     * Keduanya lewat PickingRun::release() supaya tidak ada dua versi aturan.
     *
     * ALASAN WAJIB. Yang melepas biasanya tahu sebabnya — pengiriman digeser
     * ke besok, atau tugasnya dioper ke orang lain — dan itulah yang Logistik
     * butuhkan untuk memutuskan daftar ini disusun ulang atau tidak.
     */
    public function release(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $data = $request->validate([
            'release_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'release_reason.required' => 'Alasan melepas tugas wajib diisi — Logistik perlu tahu kenapa daftar ini kembali.',
        ], ['release_reason' => 'alasan']);

        $user = $request->user();
        // Pengawas = yang boleh menyusun daftar picking. Ia pula yang akan
        // mengatur ulang daftarnya sesudah dilepas.
        $pengawas = Gate::allows(Permission::OUTBOUND_PICKING_LIST);

        try {
            $dikosongkan = $this->picking->release($list, $user, $pengawas && $list->claimed_by !== $user?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::PICKING_RELEASE,
            sprintf(
                'Melepas tugas picking %s — daftar kembali bebas diambil. Alasan: %s',
                $list->list_number,
                $data['release_reason'],
            ),
            $list,
            $list->warehouse_id,
            [
                'daftar' => $list->list_number,
                'baris_dikosongkan' => $dikosongkan,
                'alasan' => $data['release_reason'],
            ],
        );

        $pesan = sprintf(
            'Tugas %s dilepas. Daftarnya kembali ke antrean dan bisa diambil operator lain.',
            $list->list_number,
        );

        // Baris yang tandanya ikut hilang WAJIB disebut. Operator yang sudah
        // menandai lima rak berhak tahu bahwa lima tanda itu kini kosong lagi.
        if ($dikosongkan > 0) {
            $pesan .= sprintf(' %d baris yang sudah ditandai dikembalikan ke keadaan belum diambil.', $dikosongkan);
        }

        return redirect()
            ->route($pengawas ? 'wms.picking.batching' : 'wms.picking.queue')
            ->with('warning', $pesan);
    }

    /** Jalur cepat: satu ketuk, barangnya lengkap sesuai daftar. */
    public function pick(Request $request, PickingList $list, PickingListItem $item)
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->pick($item, $request->user());
        } catch (RuntimeException $e) {
            return $this->gagal($request, $item, $e->getMessage());
        }

        return $this->berhasil($request, $list, $item, 'success', sprintf(
            '%s di rak %s ditandai terambil.',
            $item->product?->sku ?? 'Baris',
            $item->location?->code ?? '—'
        ));
    }

    /** Pintu terpisah untuk keadaan khusus: barang di rak kurang. */
    public function short(ReportPickingShortageRequest $request, PickingList $list, PickingListItem $item)
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->reportShort(
                $item,
                $request->user(),
                (int) $request->validated('qty_picked'),
                $request->validated('discrepancy_reason'),
            );
        } catch (RuntimeException $e) {
            return $this->gagal($request, $item, $e->getMessage());
        }

        return $this->berhasil($request, $list, $item, 'warning', sprintf(
            'Selisih dicatat: %s di rak %s tertulis %d, ditemukan %d. Selisihnya akan menjadi koreksi stok saat Siap Loading.',
            $item->product?->sku ?? 'Baris',
            $item->location?->code ?? '—',
            $item->qty_to_pick,
            (int) $request->validated('qty_picked'),
        ));
    }

    /** Membatalkan penandaan satu baris — operator salah ketuk. */
    public function reset(Request $request, PickingList $list, PickingListItem $item)
    {
        $this->pastikanBarisMilikDaftar($request, $list, $item);

        try {
            $this->picking->resetItem($item, $request->user());
        } catch (RuntimeException $e) {
            return $this->gagal($request, $item, $e->getMessage());
        }

        return $this->berhasil($request, $list, $item, 'success', 'Tanda pada baris itu dibatalkan.');
    }

    /** "Siap Loading" — stok berkurang, pesanan berpindah ke Siap Kirim. */
    public function complete(Request $request, PickingList $list): RedirectResponse
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        $mrf = $list->requisition()->first();

        /*
         | DAFTAR MRF MENUNTUT SATU ISIAN LAGI: rak tempat barangnya ditaruh.
         |
         | Barang permintaan Produksi tidak naik kendaraan mana pun — ia
         | berdiri di sebuah rak sampai Produksi datang mengambilnya. Tanpa rak
         | yang disebut, Produksi harus menelepon Logistik untuk bertanya, dan
         | kebiasaan itulah yang membuat material sering hilang dari ingatan
         | berbulan-bulan. Divalidasi di sini supaya pesan galatnya muncul di
         | layar operator, bukan sebagai kesalahan basis data.
         */
        $rakSerah = null;
        $catatanSerah = null;

        if ($mrf !== null) {
            $data = $request->validate([
                'handover_location_id' => [
                    'required', 'integer',
                    // Rak mana pun di gudang ini, asal aktif. Yang dijaga di
                    // sini cuma satu: tempatnya benar-benar ada dan milik
                    // gudang yang sama.
                    Rule::exists('locations', 'id')
                        ->where('warehouse_id', $list->warehouse_id)
                        ->where('is_active', true),
                ],
                'handover_note' => ['nullable', 'string', 'max:500'],
            ], [
                'handover_location_id.required' => 'Pilih dulu tempat barang ini ditaruh — Produksi perlu tahu harus mengambilnya ke mana.',
                'handover_location_id.exists' => 'Tempat yang dipilih tidak ada atau tidak aktif di gudang ini.',
            ], ['handover_location_id' => 'tempat serah terima']);

            $rakSerah = (int) $data['handover_location_id'];
            $catatanSerah = $data['handover_note'] ?? null;
        }

        try {
            $hasil = $this->picking->complete($list, $request->user(), $rakSerah, $catatanSerah);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($mrf !== null) {
            return $this->selesaiMrf($mrf->refresh(), $list, $hasil);
        }

        // DAFTAR TRANSFER PUNYA AKHIR YANG BERBEDA. Barangnya tidak menunggu
        // Surat Jalan di dermaga — ia langsung berangkat ke gudang lain, dan
        // orang di ujung sana perlu tahu sekarang, bukan saat truknya muncul.
        $transfer = $list->transfer()->first();

        if ($transfer !== null) {
            return $this->selesaiTransfer($transfer->refresh(), $list, $hasil);
        }

        $pesan = sprintf(
            'Daftar %s selesai. %d unit turun dari rak dan siap dimuat.',
            $list->list_number,
            $hasil['diambil'],
        );

        if ($hasil['kurang'] > 0) {
            // Selisih TIDAK boleh lewat sebagai pesan sukses biasa. Angka
            // stok baru saja dikoreksi turun, dan yang mengoreksinya adalah
            // temuan di rak — itu perlu dibaca seseorang, bukan disembunyikan
            // di balik kalimat "selesai".
            return redirect()->route('wms.picking.queue')->with('warning', $pesan.sprintf(
                ' %d unit TIDAK ditemukan di rak dan sudah dicatat sebagai koreksi stok — periksa di Riwayat Mutasi.',
                $hasil['kurang']
            ));
        }

        return redirect()->route('wms.picking.queue')->with('success', $pesan);
    }

    /**
     * Akhir daftar picking MRF: barangnya LANGSUNG menjadi milik Produksi.
     *
     * Tidak ada lagi konfirmasi terpisah. Serah terima di layar operator
     * terjadi bersamaan dengan serah terima sungguhan di lantai gudang, jadi
     * di situlah kepemilikannya berpindah — bukan menunggu seseorang di
     * Produksi membuka WMS dan menekan tombol untuk barang yang sudah lama
     * dibawanya.
     *
     * Loncengnya tetap dikirim, tetapi isinya berubah: ia mengabari bahwa
     * barangnya SUDAH tercatat atas nama Produksi, bukan menyuruh menekan apa
     * pun.
     *
     * @param  array{diambil:int, kurang:int, baris:int, unit:int}  $hasil
     */
    private function selesaiMrf(MaterialRequisition $mrf, PickingList $list, array $hasil): RedirectResponse
    {
        $mrf->load('handoverLocation:id,code,zone');
        // Titik transit disebut dengan namanya ("In-Transit Produksi"), rak
        // biasa dengan kodenya — yang membaca pesan ini Produksi, bukan
        // operator yang hafal denah.
        $rak = $mrf->handoverLocation?->nama_serah_terima ?? '—';

        Activity::record(
            ActivityLog::PICKING_RELEASE,
            sprintf(
                'Daftar %s untuk permintaan material %s diserahterimakan: %d unit turun dari rak, '.
                'ditaruh di %s, dan langsung tercatat di buku Produksi.',
                $list->list_number,
                $mrf->mrf_number,
                $hasil['diambil'],
                $rak,
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'mrf' => $mrf->mrf_number,
                'daftar_picking' => $list->list_number,
                'diambil' => $hasil['diambil'],
                'kurang' => $hasil['kurang'],
                'rak_serah' => $rak,
            ],
        );

        Notifier::toUser(
            $mrf->requested_by,
            Notification::MRF_READY_FOR_PICKUP,
            'Material Anda sudah diserahkan',
            sprintf(
                '%s: %d unit sudah turun dari rak, ditaruh di %s, dan tercatat atas nama Produksi. '.
                'Pemakaiannya dicatat di MRF Picked — lokasinya boleh Anda pindahkan di sana.',
                $mrf->mrf_number,
                $hasil['diambil'],
                $rak,
            ),
            route('wms.material-produksi.index'),
            $mrf->warehouse_id,
            $mrf,
        );

        $pesan = sprintf(
            'Serah terima %s selesai. %d unit untuk permintaan material %s ditaruh di %s dan langsung '.
            'tercatat di buku Produksi.',
            $list->list_number,
            $hasil['diambil'],
            $mrf->mrf_number,
            $rak,
        );

        if ($hasil['kurang'] > 0) {
            return redirect()->route('wms.picking.queue')->with('warning', $pesan.sprintf(
                ' %d unit TIDAK ditemukan di rak, jadi Produksi menerima kurang dari yang disetujui — '.
                'selisihnya sudah dicatat sebagai koreksi stok dan terbaca di dokumen MRF.',
                $hasil['kurang'],
            ));
        }

        return redirect()->route('wms.picking.queue')->with('success', $pesan);
    }

    /**
     * Akhir daftar picking TRANSFER: barangnya berangkat ke gudang lain.
     *
     * @param  array{diambil:int, kurang:int}  $hasil
     */
    private function selesaiTransfer(StockTransfer $transfer, PickingList $list, array $hasil): RedirectResponse
    {
        Activity::record(
            ActivityLog::TRANSFER_CREATE,
            sprintf(
                'Transfer %s berangkat ke gudang %s: %d unit turun dari rak lewat daftar %s.',
                $transfer->transfer_number,
                $transfer->toWarehouse?->name ?? 'tujuan',
                $hasil['diambil'],
                $list->list_number,
            ),
            $transfer,
            $transfer->from_warehouse_id,
            [
                'nomor' => $transfer->transfer_number,
                'daftar_picking' => $list->list_number,
                'berangkat' => $hasil['diambil'],
                'kurang' => $hasil['kurang'],
            ],
        );

        // Dikirim ke gudang TUJUAN. Yang perlu bersiap menerima ada di ujung
        // sana, dan tanpa lonceng ini satu-satunya cara mereka tahu adalah
        // ditelepon.
        Notifier::toPermission(
            Permission::TRANSFER_RECEIVE,
            $transfer->to_warehouse_id,
            Notification::TRANSFER_INCOMING,
            'Kiriman antar gudang dalam perjalanan',
            sprintf(
                'Transfer %s dari gudang %s — %d unit menunggu diterima dan dimasukkan ke rak.',
                $transfer->transfer_number,
                $transfer->fromWarehouse?->name ?? 'asal',
                $hasil['diambil'],
            ),
            route('wms.transfers.show', $transfer),
            $transfer,
        );

        $pesan = sprintf(
            'Daftar %s selesai. Transfer %s berangkat ke gudang %s dengan %d unit dan sekarang DALAM PERJALANAN.',
            $list->list_number,
            $transfer->transfer_number,
            $transfer->toWarehouse?->name ?? 'tujuan',
            $hasil['diambil'],
        );

        // Kiriman yang berangkat kurang dari yang diminta TIDAK boleh lewat
        // sebagai pesan sukses biasa: gudang tujuan akan menghitung barangnya
        // dan menemukan kekurangan yang tidak pernah dikabarkan siapa pun.
        if ($hasil['kurang'] > 0) {
            return redirect()->route('wms.picking.queue')->with('warning', $pesan.sprintf(
                ' %d unit TIDAK ditemukan di rak, jadi kirimannya kurang dari yang disusun — '.
                'selisihnya sudah dicatat sebagai koreksi stok dan terbaca di dokumen transfer.',
                $hasil['kurang'],
            ));
        }

        return redirect()->route('wms.picking.queue')->with('success', $pesan);
    }

    /* --------------------------------------------------------------- Dalam */

    /*
     | MENANDAI BARIS TIDAK BOLEH MEMUAT ULANG HALAMAN.
     |
     | Temuan lapangan pemilik produk: satu daftar bisa berisi 100 baris.
     | Kalau tiap ketukan memuat ulang halaman, operator yang sedang di baris
     | ke-80 dilempar kembali ke atas dan harus menggulir turun lagi — seratus
     | kali dalam satu tugas. Itu bukan gangguan kecil; itu membuat orang
     | berhenti menandai baris satu per satu dan mulai menandainya sekaligus
     | di akhir dari ingatan, yang persis menghapus gunanya penandaan ini.
     |
     | Karena itu kedua jawaban disediakan:
     |   - JSON  untuk layar, yang memperbarui satu baris saja tanpa berpindah
     |   - redirect BER-ANCHOR sebagai jalan mundur bila JavaScript mati,
     |     supaya halaman setidaknya kembali ke baris yang barusan ditekan
     |
     | Jalan mundurnya bukan basa-basi: HP gudang sering tua, dan layar yang
     | hanya bekerja dengan JavaScript berarti tugas yang tidak bisa
     | diselesaikan sama sekali.
     */

    /** @return JsonResponse|RedirectResponse */
    private function berhasil(Request $request, PickingList $list, PickingListItem $item, string $jenis, string $pesan)
    {
        $item->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'jenis' => $jenis,
                'pesan' => $pesan,
                'item' => [
                    'id' => $item->id,
                    'status' => $item->status,
                    'qty_picked' => $item->qty_picked,
                    'qty_kurang' => $item->qty_kurang,
                    'alasan' => $item->discrepancy_reason,
                ],
                'ringkas' => $this->ringkasan($list),
            ]);
        }

        return back()->withFragment('baris-'.$item->id)->with($jenis, $pesan);
    }

    /** @return JsonResponse|RedirectResponse */
    private function gagal(Request $request, PickingListItem $item, string $pesan)
    {
        if ($request->expectsJson()) {
            // 422, bukan 200 berisi ok:false — layar memakai response.ok
            // untuk memutuskan, dan galat yang dikirim sebagai sukses akan
            // menandai baris di layar padahal servernya menolak.
            return response()->json(['ok' => false, 'pesan' => $pesan], 422);
        }

        return back()->withFragment('baris-'.$item->id)->with('error', $pesan);
    }

    /** @return array{total:int, selesai:int, kurang:int} */
    private function ringkasan(PickingList $list): array
    {
        $perStatus = $list->items()
            ->selectRaw('status, COUNT(*) AS jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return [
            'total' => (int) $perStatus->sum(),
            'selesai' => (int) $perStatus->sum() - (int) $perStatus->get(PickingListItem::STATUS_PENDING, 0),
            'kurang' => (int) $perStatus->get(PickingListItem::STATUS_SHORT, 0),
        ];
    }

    /**
     * Baris HARUS milik daftar di URL-nya.
     *
     * Tanpa ini, /picking/{daftar A}/item/{baris milik daftar B} lolos:
     * pemeriksaan gudang membaca daftar A yang memang boleh, lalu yang
     * ditandai adalah baris daftar B milik operator lain.
     */
    private function pastikanBarisMilikDaftar(Request $request, PickingList $list, PickingListItem $item): void
    {
        WarehouseScope::assert($list->warehouse_id, $request->user());

        abort_unless($item->picking_list_id === $list->id, 404);
    }
}
