<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Jobs\SendMrfPickupReady;
use App\Models\ActivityLog;
use App\Models\MaterialRequisition;
use App\Models\Notification;
use App\Models\PickingList;
use App\Models\StockTransfer;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Outbound\PickingRun;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Picking — menyelesaikan satu daftar. Daftar dari pesanan, MRF, dan transfer
 * antar gudang ditutup lewat pintu yang sama; kabar penutupnya berbeda per
 * jenis (selesaiMrf, selesaiTransfer).
 */
class PickingCompletionController extends Controller
{
    public function __construct(
        private readonly PickingRun $picking,
    ) {}

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
        // biasa dengan kodenya — yang membaca pesan ini divisi pemohon, bukan
        // operator yang hafal denah.
        $rak = $mrf->handoverLocation?->nama_serah_terima ?? '—';

        // Divisinya disebut dengan namanya: permintaan material tidak lagi
        // selalu datang dari Produksi.
        $divisi = $mrf->nama_divisi;

        Activity::record(
            ActivityLog::PICKING_RELEASE,
            sprintf(
                $hasil['lewat_tautan']
                    ? 'Daftar %s untuk permintaan material %s selesai: %d unit turun dari rak dan '.
                      'menunggu di %s sampai pemohonnya datang mengambil.'
                    : 'Daftar %s untuk permintaan material %s diserahterimakan: %d unit turun dari rak, '.
                      'ditaruh di %s, dan langsung tercatat di buku %s.',
                $list->list_number,
                $mrf->mrf_number,
                $hasil['diambil'],
                $rak,
                $divisi,
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

        if ($hasil['lewat_tautan']) {
            // Pemohonnya tidak punya akun WMS, jadi loncengnya tidak akan
            // pernah ia lihat — kabarnya dikirim lewat WhatsApp, jalur yang
            // sama dengan tautan permintaannya.
            SendMrfPickupReady::dispatch($mrf->id);
        } else {
            Notifier::toUser(
                $mrf->requested_by,
                Notification::MRF_READY_FOR_PICKUP,
                'Material Anda sudah diserahkan',
                sprintf(
                    '%s: %d unit sudah turun dari rak, ditaruh di %s, dan tercatat atas nama Anda. '.
                    'Pemakaiannya dicatat di MRF Picked — lokasinya boleh Anda pindahkan di sana.',
                    $mrf->mrf_number,
                    $hasil['diambil'],
                    $rak,
                ),
                route('wms.material-produksi.index'),
                $mrf->warehouse_id,
                $mrf,
            );
        }

        $pesan = $hasil['lewat_tautan']
            ? sprintf(
                'Daftar %s selesai. %d unit untuk permintaan material %s menunggu di %s, dan %s sudah '.
                'dikabari lewat WhatsApp. Tekan "Sudah Diambil" di dokumen MRF-nya saat orangnya datang.',
                $list->list_number,
                $hasil['diambil'],
                $mrf->mrf_number,
                $rak,
                $divisi,
            )
            : sprintf(
                'Serah terima %s selesai. %d unit untuk permintaan material %s ditaruh di %s dan langsung '.
                'tercatat di buku %s.',
                $list->list_number,
                $hasil['diambil'],
                $mrf->mrf_number,
                $rak,
                $divisi,
            );

        if ($hasil['kurang'] > 0) {
            return redirect()->route('wms.picking.queue')->with('warning', $pesan.sprintf(
                ' %d unit TIDAK ditemukan di rak, jadi %s menerima kurang dari yang disetujui — '.
                'selisihnya sudah dicatat sebagai koreksi stok dan terbaca di dokumen MRF.',
                $hasil['kurang'],
                $divisi,
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
}
