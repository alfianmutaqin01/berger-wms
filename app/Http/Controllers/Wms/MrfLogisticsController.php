<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\MaterialRequisition;
use App\Models\Notification;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Production\MaterialRequisitionDecision;
use App\Support\Production\MaterialRequisitionHandover;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/**
 * MRF — keputusan Logistik (izin MRF_APPROVE): memilih batch sungguhannya
 * dari rak saat menyetujui, menolak, dan menutup permintaan lewat tautan
 * divisi saat pemohonnya datang mengambil.
 *
 * DATA CONTRACT
 * -------------
 * approveForm() : $mrf, $batch Collection<InventoryStock> dikelompokkan produk
 */
class MrfLogisticsController extends Controller
{
    public function __construct(
        private readonly MaterialRequisitionDecision $keputusan,
        private readonly MaterialRequisitionHandover $serahTerima,
    ) {}

    /* ------------------------------------------------ Logistik: pilih batch */

    public function approveForm(Request $request, MaterialRequisition $mrf): View|RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        if (! $mrf->menungguLogistik()) {
            return redirect()->route('wms.mrf.show', $mrf)->with('error', sprintf(
                'MRF %s berstatus "%s", bukan menunggu keputusan Logistik.',
                $mrf->mrf_number,
                $mrf->status_label,
            ));
        }

        $mrf->load(['items.product:id,sku,name,uom', 'warehouse:id,code,name', 'requestedBy:id,full_name']);

        return view('wms.produksi.mrf-approve', [
            'mrf' => $mrf,
            // Batch per produk yang diminta saja. Menyodorkan seluruh isi
            // gudang membuat layar ini tidak terbaca, dan yang dicari Logistik
            // memang cuma produk yang tertulis di permintaannya.
            'batchPerProduk' => $this->batchUntukPermintaan($mrf),
        ]);
    }

    public function approve(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        $data = $request->validate([
            'baris' => ['required', 'array', 'min:1'],
            'baris.*.item_id' => ['required', 'integer'],
            'baris.*.stock_id' => ['required', 'integer'],
            'baris.*.qty' => ['required', 'integer', 'min:1'],
        ], [
            'baris.required' => 'Belum ada satu batch pun yang dipilih. Isi qty pada batch yang akan diambilkan.',
        ]);

        try {
            $mrf = $this->keputusan->setujuiLogistik($mrf, array_values($data['baris']), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $mrf->load('pickingList:id,list_number');

        Activity::record(
            ActivityLog::MRF_LOGISTICS_APPROVE,
            sprintf(
                'Menyetujui permintaan material %s: %d batch dicadangkan, daftar picking %s dibuat.',
                $mrf->mrf_number,
                $mrf->allocations()->count(),
                $mrf->pickingList?->list_number ?? '—',
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'daftar_picking' => $mrf->pickingList?->list_number,
                'batch' => $mrf->allocations()->count(),
            ],
        );

        Notifier::toUser(
            $mrf->requested_by,
            Notification::MRF_DECIDED,
            'Permintaan material disetujui Logistik',
            sprintf(
                'MRF %s disetujui. Barangnya sedang diambilkan operator; Anda akan dikabari lagi begitu siap diambil.',
                $mrf->mrf_number,
            ),
            route('wms.mrf.show', $mrf),
            $mrf->warehouse_id,
            $mrf,
        );

        return redirect()->route('wms.mrf.show', $mrf)->with('success', sprintf(
            'MRF %s disetujui. Daftar picking %s sudah masuk antrean operator.',
            $mrf->mrf_number,
            $mrf->pickingList?->list_number ?? '—',
        ));
    }

    public function reject(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi — Produksi perlu tahu apakah harus menunggu atau mencari jalan lain.',
        ], ['reason' => 'alasan penolakan']);

        try {
            $mrf = $this->keputusan->tolakLogistik($mrf, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::MRF_LOGISTICS_REJECT,
            sprintf('Menolak permintaan material %s. Alasan: %s', $mrf->mrf_number, $data['reason']),
            $mrf,
            $mrf->warehouse_id,
            ['nomor' => $mrf->mrf_number, 'alasan' => $data['reason']],
        );

        Notifier::toUser(
            $mrf->requested_by,
            Notification::MRF_DECIDED,
            'Permintaan material ditolak Logistik',
            sprintf('MRF %s ditolak. Alasan: %s', $mrf->mrf_number, $data['reason']),
            route('wms.mrf.show', $mrf),
            $mrf->warehouse_id,
            $mrf,
        );

        return redirect()->route('wms.mrf.show', $mrf)->with('warning', sprintf(
            'MRF %s ditolak dan Produksi sudah dikabari.',
            $mrf->mrf_number,
        ));
    }

    /**
     * Barang permintaan lewat tautan divisi DIAMBIL pemohonnya. Selesai.
     *
     * Ditekan orang gudang saat orangnya datang, bukan oleh pemohonnya —
     * pemohon dari QC atau R&D tidak punya akun dan tidak akan membuka WMS.
     * Yang dicatat adalah nama orang yang benar-benar membawa barangnya
     * keluar, dan itulah satu-satunya jejak siapa yang memegangnya.
     */
    public function collect(Request $request, MaterialRequisition $mrf): RedirectResponse
    {
        WarehouseScope::assert($mrf->warehouse_id, $request->user());

        abort_unless($mrf->lewatTautan(), 404);

        $data = $request->validate([
            'collected_by_name' => ['required', 'string', 'max:100'],
        ], [
            'collected_by_name.required' => 'Isi nama orang yang mengambil — inilah satu-satunya catatan '.
                'siapa yang membawa barang ini keluar gudang.',
        ], ['collected_by_name' => 'nama pengambil']);

        try {
            $hasil = $this->serahTerima->tandaiDiambil($mrf, $data['collected_by_name'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::MRF_RECEIVE,
            sprintf(
                '%s diambil %s dari divisi %s: %d unit dalam %d batch, dan permintaannya ditutup.',
                $mrf->mrf_number,
                trim($data['collected_by_name']),
                $mrf->department_name ?? '—',
                $hasil['unit'],
                $hasil['baris'],
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'lewat_tautan' => true,
                'divisi' => $mrf->department_name,
                'diambil_oleh' => trim($data['collected_by_name']),
                'unit' => $hasil['unit'],
            ],
        );

        return back()->with('success', sprintf(
            '%s ditutup: %d unit diambil %s. Pemakaiannya tercatat di Riwayat Pemakaian MRF.',
            $mrf->mrf_number,
            $hasil['unit'],
            trim($data['collected_by_name']),
        ));
    }

    /* --------------------------------------------------------------- Dalam */

    /**
     * Batch yang bisa dipilih Logistik, dikelompokkan per baris permintaan.
     *
     * DDP IKUT, dan itu justru intinya. Jenis permintaan yang paling sering
     * dipakai adalah reproses barang DDP; menyaringnya keluar seperti pada
     * penjualan membuat layar ini kosong persis pada perkara yang paling
     * sering terjadi. Barang karantina TIDAK ikut — ia sedang ditahan QC dan
     * belum boleh ke mana-mana.
     *
     * @return array<int, Collection> dikunci id baris permintaan
     */
    private function batchUntukPermintaan(MaterialRequisition $mrf): array
    {
        $produkId = $mrf->items->pluck('product_id')->unique()->all();

        $batch = InventoryStock::query()
            ->where('warehouse_id', $mrf->warehouse_id)
            ->whereIn('product_id', $produkId)
            ->where('qty_available', '>', 0)
            ->whereIn('status', [InventoryStock::STATUS_ACTIVE, InventoryStock::STATUS_DDP])
            ->with(['location:id,code', 'product:id,sku,name,uom'])
            // FIFO: yang paling dekat kedaluwarsa di atas. Untuk reproses,
            // itu pula yang paling pantas didahulukan.
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $hasil = [];

        foreach ($mrf->items as $item) {
            $hasil[$item->id] = $batch->get($item->product_id, collect());
        }

        return $hasil;
    }
}
