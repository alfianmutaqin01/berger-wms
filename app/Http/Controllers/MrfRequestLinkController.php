<?php

namespace App\Http\Controllers;

use App\Jobs\SendMrfApprovalRequest;
use App\Models\ActivityLog;
use App\Models\MaterialRequisition;
use App\Models\MrfRequestLink;
use App\Models\Product;
use App\Support\Activity;
use App\Support\PhoneNumber;
use App\Support\Production\MaterialRequisitionRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Formulir permintaan material untuk divisi yang TIDAK punya akun WMS.
 *
 * HALAMAN PUBLIK KETIGA YANG MENGUBAH DATA, sesudah konfirmasi supir dan
 * persetujuan MRF. Alasannya sama: QC dan R&D meminta material beberapa kali
 * setahun, dan membuatkan mereka akun berarti satu peran baru dengan matriks
 * izinnya sendiri, dasbor yang dibuka dua kali setahun, dan kata sandi yang
 * pasti lupa — kata sandi semacam itu berakhir di kertas yang ditempel di meja.
 *
 * Karena itu TOKEN-nya yang jadi kunci, dengan aturan yang sama persis seperti
 * MrfApprovalController dan EpodController:
 *
 *   - Token dicari sebagai KOLOM, bukan disusun dari id.
 *   - Token yang tidak dikenal atau sudah dinonaktifkan dijawab 404 POLOS,
 *     tanpa menyebut apa pun tentang divisi atau gudang yang ada. Halaman ini
 *     terbuka ke internet.
 *   - Yang bisa dilakukan hanya SATU: mengajukan permintaan. Tidak ada daftar,
 *     tidak ada pencarian permintaan lama, tidak ada milik orang lain.
 *   - Yang ditampilkan seperlunya untuk mengisi: daftar produk lewat
 *     pencarian, bukan isi rak, bukan stok, bukan harga.
 *
 * DAN YANG PALING MENENTUKAN: permintaan dari sini tidak menggerakkan apa pun
 * sampai DUA orang lain menyetujuinya — atasan divisi lewat WhatsApp, lalu
 * Logistik yang memastikan barangnya ada. Itulah yang menahan tautan ini
 * kalau ia bocor, bukan kerahasiaan alamatnya.
 */
class MrfRequestLinkController extends Controller
{
    /** Huruf minimal sebelum pencarian produk dijalankan. */
    private const MIN_CARI = 2;

    /** Batas saran yang dikirim ke layar. */
    private const MAKS_SARAN = 10;

    public function __construct(private readonly MaterialRequisitionRun $mrf) {}

    public function show(string $token): View
    {
        $tautan = $this->tautan($token);

        return view('mrf.minta', [
            'tautan' => $tautan,
            'jenisOptions' => MaterialRequisition::TYPES,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $tautan = $this->tautan($token);

        $data = $request->validate([
            'requester_name' => ['required', 'string', 'max:100'],
            'requester_phone' => ['required', 'string', 'max:25'],
            'request_type' => ['required', 'string', 'in:'.implode(',', array_keys(MaterialRequisition::TYPES))],
            'purpose' => ['required', 'string', 'min:5', 'max:1000'],
            // Hanya dipakai bila tautannya TIDAK mengunci atasan. Kalau
            // terkunci, isian ini diabaikan sepenuhnya di layanan.
            'approver_name' => ['nullable', 'string', 'max:100'],
            'approver_phone' => ['nullable', 'string', 'max:25'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999999'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ], [
            'requester_name.required' => 'Nama Anda wajib diisi — inilah yang tertulis di dokumennya nanti.',
            'requester_phone.required' => 'Nomor WhatsApp Anda wajib diisi; ke sinilah kabar "barang siap diambil" dikirim.',
            'purpose.required' => 'Keperluan wajib diisi — inilah satu-satunya keterangan yang menjelaskan kenapa barang keluar dari gudang.',
            'purpose.min' => 'Keperluan terlalu pendek untuk bisa dipahami orang yang membacanya nanti.',
            'items.required' => 'Belum ada satu produk pun yang diminta.',
        ], [
            'requester_name' => 'nama Anda',
            'requester_phone' => 'nomor WhatsApp Anda',
            'purpose' => 'keperluan',
            'approver_name' => 'nama atasan',
            'approver_phone' => 'nomor WhatsApp atasan',
        ]);

        $nomorPemohon = PhoneNumber::forWhatsApp($data['requester_phone']);

        if ($nomorPemohon === null) {
            return back()->withInput()->with('error',
                'Nomor WhatsApp Anda tidak terbaca sebagai satu nomor telepon. Pakai satu nomor ponsel '.
                'Indonesia, mis. 081234567890.');
        }

        try {
            $mrf = $this->mrf->ajukanLewatTautan(
                tautan: $tautan,
                namaPemohon: $data['requester_name'],
                jenis: $data['request_type'],
                keperluan: $data['purpose'],
                baris: array_values($data['items']),
                namaApprover: $data['approver_name'] ?? null,
                nomorApprover: $data['approver_phone'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $mrf->forceFill(['requester_phone' => $nomorPemohon])->save();

        SendMrfApprovalRequest::dispatch($mrf->id);

        // Dicatat TANPA pelaku: tidak ada akun yang menekannya. Yang tercatat
        // sebagai gantinya adalah nama dan divisi yang mengisi formulirnya.
        Activity::record(
            ActivityLog::MRF_CREATE,
            sprintf(
                'Permintaan material %s masuk lewat tautan divisi %s — diisi %s, persetujuan diminta ke %s.',
                $mrf->mrf_number,
                $tautan->department?->name ?? '—',
                $mrf->requester_name,
                $mrf->approver_name,
            ),
            $mrf,
            $mrf->warehouse_id,
            [
                'nomor' => $mrf->mrf_number,
                'lewat_tautan' => true,
                'divisi' => $tautan->department?->name,
                'pemohon' => $mrf->requester_name,
                'approver' => $mrf->approver_name,
            ],
        );

        return redirect()
            ->route('mrf.minta.selesai', ['token' => $token, 'nomor' => $mrf->mrf_number]);
    }

    /** Halaman terima kasih — pemohon tidak punya layar lain untuk kembali. */
    public function done(Request $request, string $token): View
    {
        $tautan = $this->tautan($token);

        $mrf = MaterialRequisition::where('request_link_id', $tautan->id)
            ->where('mrf_number', (string) $request->query('nomor'))
            ->firstOrFail();

        return view('mrf.minta-selesai', ['tautan' => $tautan, 'mrf' => $mrf]);
    }

    /**
     * Pencarian produk sambil mengetik.
     *
     * Dibuka lewat token yang sama, bukan tanpa penjagaan: daftar produk
     * lengkap adalah data perusahaan, dan halaman ini menghadap internet.
     */
    public function lookupProducts(Request $request, string $token): JsonResponse
    {
        $this->tautan($token);

        $cari = trim((string) $request->query('q'));

        if (mb_strlen($cari) < self::MIN_CARI) {
            return response()->json([]);
        }

        return response()->json(
            Product::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('sku', 'ILIKE', '%'.$cari.'%')
                    ->orWhere('name', 'ILIKE', '%'.$cari.'%'))
                ->orderBy('sku')
                ->limit(self::MAKS_SARAN)
                // Stok TIDAK ikut dikirim. Yang membuka halaman ini tidak
                // perlu — dan tidak boleh — melihat isi gudang.
                ->get(['id', 'sku', 'name', 'uom'])
                ->map(fn (Product $p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'uom' => $p->uom]),
        );
    }

    /**
     * Tautan yang masih berlaku, atau 404 polos.
     *
     * Tautan mati dan tautan yang tidak pernah ada dijawab sama: halaman ini
     * menghadap internet, dan membedakan keduanya memberi tahu penebak bahwa
     * ia sedang mendekati token yang benar.
     */
    private function tautan(string $token): MrfRequestLink
    {
        return MrfRequestLink::query()
            ->with(['warehouse:id,code,name', 'department:id,name'])
            ->where('token', $token)
            ->aktif()
            ->firstOrFail();
    }
}
