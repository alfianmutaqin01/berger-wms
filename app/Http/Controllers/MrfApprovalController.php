<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MaterialRequisition;
use App\Models\Notification;
use App\Support\Activity;
use App\Support\Notifier;
use App\Support\Permission;
use App\Support\Production\MaterialRequisitionRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Persetujuan MRF oleh atasan, lewat tautan WhatsApp.
 *
 * HALAMAN PUBLIK KEDUA YANG MENGUBAH DATA, sesudah konfirmasi supir. Atasan
 * yang menyetujui permintaan material tidak punya akun WMS dan tidak akan
 * dibuatkan: ia bekerja di lantai produksi dan satu-satunya layar yang ia
 * buka adalah WhatsApp di HP-nya. Membuatkannya akun berarti satu kata sandi
 * lagi yang tidak pernah dipakai — dan kata sandi semacam itu berakhir di
 * kertas yang ditempel di meja.
 *
 * Karena itu TOKEN-nya yang jadi kunci, dan seluruh rancangan halaman ini
 * mengikuti dari sana — aturan yang sama persis dengan EpodController:
 *
 *   - Token dicari sebagai KOLOM, bukan disusun dari id. Tautan yang bisa
 *     ditebak dari nomor urut membuat siapa pun menyetujui permintaan
 *     orang lain.
 *   - Token yang tidak dikenal dijawab 404 POLOS, tanpa menyebut apa pun
 *     tentang dokumen yang ada. Halaman ini terbuka ke internet.
 *   - Yang bisa dilakukan hanya DUA: setuju atau tolak. Tidak ada daftar,
 *     tidak ada pencarian, tidak ada permintaan orang lain.
 *   - Yang ditampilkan seperlunya untuk memutuskan: siapa meminta, apa,
 *     berapa, untuk keperluan apa. Bukan isi gudang, bukan harga.
 */
class MrfApprovalController extends Controller
{
    public function __construct(private readonly MaterialRequisitionRun $mrf) {}

    public function show(string $token): View
    {
        return view('mrf.approval', [
            'mrf' => $this->cari($token),
        ]);
    }

    public function approve(Request $request, string $token): RedirectResponse
    {
        $mrf = $this->cari($token);

        $data = $request->validate([
            // Tidak wajib. Atasan yang setuju tanpa syarat tidak punya apa-apa
            // untuk ditulis, dan menahan persetujuan karena kolom kosong
            // berarti permintaan yang sebenarnya sudah disetujui tidak pernah
            // sampai ke Logistik.
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['note' => 'catatan']);

        try {
            $mrf = $this->mrf->setujuiApprover($mrf, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->catat($request, $mrf, true, $data['note'] ?? null);

        // Logistik di gudang itu yang ditunggu sekarang. Tanpa lonceng ini,
        // satu-satunya cara mereka tahu adalah dihampiri orang Produksi.
        Notifier::toPermission(
            Permission::MRF_APPROVE,
            $mrf->warehouse_id,
            Notification::MRF_NEEDS_LOGISTICS,
            'Permintaan material menunggu Logistik',
            sprintf(
                '%s dari %s sudah disetujui %s. Tinggal dipilih batch mana yang diambilkan.',
                $mrf->mrf_number,
                // nama_pemohon: permintaan lewat tautan divisi tidak punya
                // akun, dan "Produksi" adalah tebakan yang salah sejak QC,
                // R&D dan Sales ikut meminta.
                $mrf->nama_pemohon,
                $mrf->approver_name,
            ),
            route('wms.mrf.show', $mrf),
            $mrf,
        );

        Notifier::toUser(
            $mrf->requested_by,
            Notification::MRF_DECIDED,
            'Permintaan material disetujui atasan',
            sprintf('%s disetujui %s. Sekarang menunggu Logistik.', $mrf->mrf_number, $mrf->approver_name),
            route('wms.mrf.show', $mrf),
            $mrf->warehouse_id,
            $mrf,
        );

        return redirect()->route('mrf.approval.show', $token)->with('success', sprintf(
            'Terima kasih. Permintaan %s Anda SETUJUI dan sudah diteruskan ke Logistik.',
            $mrf->mrf_number,
        ));
    }

    public function reject(Request $request, string $token): RedirectResponse
    {
        $mrf = $this->cari($token);

        $data = $request->validate([
            // WAJIB, berbeda dari catatan persetujuan. Produksi yang
            // permintaannya ditolak perlu tahu apakah ia harus memperbaiki
            // sesuatu atau memang tidak boleh sama sekali — penolakan tanpa
            // alasan hanya melahirkan permintaan kedua yang sama persis.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi supaya Produksi tahu apa yang harus diperbaiki.',
            'reason.min' => 'Alasannya terlalu pendek untuk bisa dipahami orang yang membacanya.',
        ], ['reason' => 'alasan penolakan']);

        try {
            $mrf = $this->mrf->tolakApprover($mrf, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->catat($request, $mrf, false, $data['reason']);

        Notifier::toUser(
            $mrf->requested_by,
            Notification::MRF_DECIDED,
            'Permintaan material ditolak atasan',
            sprintf('%s ditolak %s. Alasan: %s', $mrf->mrf_number, $mrf->approver_name, $data['reason']),
            route('wms.mrf.show', $mrf),
            $mrf->warehouse_id,
            $mrf,
        );

        return redirect()->route('mrf.approval.show', $token)->with('warning', sprintf(
            'Permintaan %s Anda TOLAK. Produksi sudah dikabari beserta alasannya.',
            $mrf->mrf_number,
        ));
    }

    /* --------------------------------------------------------------- Dalam */

    /**
     * Log dengan pelaku yang BUKAN pengguna sistem.
     *
     * Sama halnya dengan konfirmasi supir: user_id-nya kosong dan yang tersisa
     * hanyalah nama approver, nomornya, serta IP. Justru karena pelakunya
     * tidak bernama di sistem, jejaknya wajib ditinggalkan — inilah titik di
     * mana sebuah permintaan berubah dari usulan menjadi keputusan.
     */
    private function catat(Request $request, MaterialRequisition $mrf, bool $setuju, ?string $keterangan): void
    {
        Activity::record(
            ActivityLog::MRF_APPROVER_DECIDE,
            sprintf(
                '%s %s permintaan material %s lewat tautan WhatsApp%s',
                $mrf->approver_name,
                $setuju ? 'MENYETUJUI' : 'MENOLAK',
                $mrf->mrf_number,
                filled($keterangan) ? '. Keterangan: '.$keterangan : '.',
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'keputusan' => $setuju ? 'setuju' : 'tolak',
                'approver' => $mrf->approver_name,
                'nomor_wa' => $mrf->approver_phone,
                'ip' => $request->ip(),
                'keterangan' => $keterangan,
            ],
        );
    }

    private function cari(string $token): MaterialRequisition
    {
        $mrf = MaterialRequisition::query()
            ->where('approval_token', $token)
            ->with([
                'items.product:id,sku,name,uom',
                'warehouse:id,name',
                'requestedBy:id,full_name',
            ])
            ->first();

        // 404 POLOS. Menjawab "token kedaluwarsa" atau "sudah dipakai" sudah
        // membocorkan bahwa token itu pernah ada — dan halaman ini terbuka ke
        // internet untuk siapa saja yang mencoba menebak.
        abort_if($mrf === null, 404);

        return $mrf;
    }
}
