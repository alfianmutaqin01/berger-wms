<?php

namespace App\Support\Production;

use App\Models\MaterialRequisition;
use App\Models\MrfApproverContact;
use App\Models\MrfRequestLink;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * MRF — permintaan material Produksi ke Logistik: pengajuannya.
 *
 * Aturan MRF terbagi tiga kelas menurut tahap, dan hanya ketiganya yang
 * menulis MRF. Stok adalah angka yang dipercaya keuangan; kalau jalur
 * tulisnya tersebar di controller, cepat atau lambat salah satunya lupa
 * menulis mutasi.
 *
 * PERJALANANNYA, DAN SIAPA YANG MENGERJAKAN TIAP LANGKAH
 * ------------------------------------------------------
 *   ajukan()           Produksi  menyusun permintaan; tautan WA dibuat
 *                                (kelas ini)
 *   setujuiApprover()  atasan    menekan Setuju di tautan WA (tanpa akun)
 *   setujuiLogistik()  Logistik  memilih batch; stok DICADANGKAN, daftar
 *                                picking masuk antrean operator
 *                                (MaterialRequisitionDecision, juga batal())
 *   siapDiambil()      Operator  selesai picking; dipanggil PickingRun
 *   terima()           Produksi  mengambil barangnya; stok pindah ke buku
 *                                Produksi dan HILANG dari inventory Logistik
 *   pakai()            Produksi  memakai sebagian atau seluruhnya, berkali-kali
 *                                (MaterialRequisitionHandover)
 */
class MaterialRequisitionSubmission
{
    /**
     * Produksi menyimpan permintaan.
     *
     * @param  list<array{product_id:int, qty:int, note:?string}>  $baris
     *
     * @throws RuntimeException
     */
    public function ajukan(
        User $pemohon,
        int $warehouseId,
        string $jenis,
        string $keperluan,
        array $baris,
        string $namaApprover,
        string $nomorApprover,
        bool $simpanKontak,
    ): MaterialRequisition {
        if ($baris === []) {
            throw new RuntimeException('Belum ada satu produk pun yang diminta. Tambahkan minimal satu baris.');
        }

        $nomor = PhoneNumber::forWhatsApp($nomorApprover);

        if ($nomor === null) {
            throw new RuntimeException(
                'Nomor WhatsApp atasan tidak terbaca sebagai satu nomor telepon. '.
                'Pakai satu nomor ponsel Indonesia, mis. 081234567890.'
            );
        }

        return DB::transaction(function () use (
            $pemohon, $warehouseId, $jenis, $keperluan, $baris, $namaApprover, $nomor, $simpanKontak
        ) {
            $mrf = MaterialRequisition::create([
                'mrf_number' => DocumentNumber::forMaterialRequisition(),
                'warehouse_id' => $warehouseId,
                'requested_by' => $pemohon->id,
                'department_id' => $pemohon->department_id,
                // Disalin sebagai teks: departemen boleh berganti nama, dan
                // dokumen lama harus tetap menyebut bagian yang benar saat itu.
                'department_name' => $pemohon->department?->name,
                'request_type' => $jenis,
                'purpose' => trim($keperluan),
                'status' => MaterialRequisition::STATUS_PENDING_APPROVAL,
                'approver_name' => trim($namaApprover),
                'approver_phone' => $nomor,
                // Token acak 64 karakter, BUKAN disusun dari id: tautan yang
                // bisa ditebak dari nomor urut membuat siapa pun menyetujui
                // permintaan orang lain. Aturan yang sama dengan tautan supir.
                'approval_token' => Str::random(64),
                'notify_status' => MaterialRequisition::NOTIFY_PENDING,
            ]);

            foreach ($baris as $item) {
                $qty = (int) ($item['qty'] ?? 0);

                if ($qty < 1) {
                    throw new RuntimeException('Qty tiap baris permintaan harus minimal 1.');
                }

                $mrf->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'qty_requested' => $qty,
                    'note' => blank($item['note'] ?? null) ? null : trim((string) $item['note']),
                ]);
            }

            if ($simpanKontak) {
                $this->simpanKontak($pemohon, $warehouseId, trim($namaApprover), $nomor);
            }

            return $mrf;
        });
    }

    /**
     * Produksi memperbaiki permintaan yang ditolak lalu mengajukannya lagi.
     *
     * NOMORNYA TETAP. Kolom penolakan di MRF-nya dikosongkan supaya keadaan
     * sekarangnya jujur — layar persetujuan tidak boleh menampilkan "sudah
     * ditolak" di atas berkas yang justru sedang menunggu jawaban. Jejaknya
     * tidak hilang: tiap penolakan sudah dicatat sebagai baris tersendiri di
     * material_requisition_rejections, dan yang menyetujui berikutnya berhak
     * tahu berkas di tangannya pernah ditolak dan karena apa.
     *
     * TOKEN PERSETUJUANNYA DIGANTI. Tautan lama sudah dipakai untuk menolak;
     * membiarkannya hidup berarti keputusan lama masih bisa ditekan ulang atas
     * berkas yang isinya sudah berbeda.
     *
     * @param  list<array{product_id:int, qty:int, note:?string}>  $baris
     *
     * @throws RuntimeException
     */
    public function ajukanUlang(
        MaterialRequisition $mrf,
        string $jenis,
        string $keperluan,
        array $baris,
        string $namaApprover,
        string $nomorApprover,
        bool $simpanKontak,
        User $pemohon,
    ): MaterialRequisition {
        if ($baris === []) {
            throw new RuntimeException('Belum ada satu produk pun yang diminta. Tambahkan minimal satu baris.');
        }

        $nomor = PhoneNumber::forWhatsApp($nomorApprover);

        if ($nomor === null) {
            throw new RuntimeException(
                'Nomor WhatsApp atasan tidak terbaca sebagai satu nomor telepon. '.
                'Pakai satu nomor ponsel Indonesia, mis. 081234567890.'
            );
        }

        return DB::transaction(function () use (
            $mrf, $jenis, $keperluan, $baris, $namaApprover, $nomor, $simpanKontak, $pemohon
        ) {
            $terkunci = MaterialRequisition::query()->lockForUpdate()->findOrFail($mrf->id);

            if (! $terkunci->bolehDiperbaiki()) {
                throw new RuntimeException(sprintf(
                    'Permintaan %s berstatus "%s", jadi isinya tidak bisa diubah lagi. Hanya permintaan '.
                    'yang sedang ditolak yang boleh diperbaiki dan diajukan ulang.',
                    $terkunci->mrf_number,
                    $terkunci->status_label,
                ));
            }

            $terkunci->fill([
                'request_type' => $jenis,
                'purpose' => trim($keperluan),
                'status' => MaterialRequisition::STATUS_PENDING_APPROVAL,
                'approver_name' => trim($namaApprover),
                'approver_phone' => $nomor,
                'approval_token' => Str::random(64),
                // Keputusan lama dibersihkan justru karena berkasnya berangkat
                // lagi: kalau tidak, layar persetujuan akan menampilkan
                // "sudah ditolak" di atas berkas yang sedang menunggu jawaban.
                'approver_rejected_at' => null,
                'approver_rejection_reason' => null,
                'logistics_rejected_at' => null,
                'logistics_rejected_by' => null,
                'logistics_rejection_reason' => null,
                'approved_at' => null,
                'approval_note' => null,
                'notify_status' => MaterialRequisition::NOTIFY_PENDING,
                'notify_error' => null,
                'notify_attempts' => 0,
                'notified_at' => null,
            ])->save();

            // Baris ditulis ulang seluruhnya, bukan disamakan satu per satu:
            // formulir mengirim keadaan akhir yang dikehendaki Produksi, dan
            // menyamakan selisihnya hanya menambah jalan untuk keliru.
            $terkunci->items()->delete();

            foreach ($baris as $item) {
                $qty = (int) ($item['qty'] ?? 0);

                if ($qty < 1) {
                    throw new RuntimeException('Qty tiap baris permintaan harus minimal 1.');
                }

                $terkunci->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'qty_requested' => $qty,
                    'note' => blank($item['note'] ?? null) ? null : trim((string) $item['note']),
                ]);
            }

            if ($simpanKontak) {
                $this->simpanKontak($pemohon, $terkunci->warehouse_id, trim($namaApprover), $nomor);
            }

            return $terkunci;
        });
    }

    /**
     * Permintaan dari divisi yang tidak punya akun WMS, lewat tautan divisi.
     *
     * Bentuk dokumennya SAMA PERSIS dengan MRF dari akun — nomor, jenis,
     * keperluan, baris barang, dan dua pintu persetujuan yang sama. Yang
     * berbeda hanya dari mana ia datang dan bagaimana ia berakhir: pemohonnya
     * tidak punya akun, dan barangnya selesai saat diambil alih-alih masuk
     * buku pemakaian bertahap.
     *
     * ATASANNYA DIAMBIL DARI TAUTAN bila Manager sudah menetapkannya. Tanpa
     * itu, siapa pun yang memegang tautannya bisa mengetik nomornya sendiri,
     * menerima tautan persetujuannya, lalu menyetujui permintaannya sendiri —
     * dan seluruh pemeriksaan ini jadi hiasan.
     *
     * @param  list<array{product_id:int, qty:int, note:?string}>  $baris
     *
     * @throws RuntimeException
     */
    public function ajukanLewatTautan(
        MrfRequestLink $tautan,
        string $namaPemohon,
        string $jenis,
        string $keperluan,
        array $baris,
        ?string $namaApprover,
        ?string $nomorApprover,
    ): MaterialRequisition {
        if (! $tautan->is_active) {
            throw new RuntimeException(
                'Tautan permintaan ini sudah tidak berlaku. Hubungi Logistik untuk mendapatkan tautan baru.'
            );
        }

        if ($baris === []) {
            throw new RuntimeException('Belum ada satu produk pun yang diminta. Tambahkan minimal satu baris.');
        }

        $namaAtasan = $tautan->atasanTerkunci() ? $tautan->approver_name : trim((string) $namaApprover);
        $nomor = PhoneNumber::forWhatsApp(
            $tautan->atasanTerkunci() ? $tautan->approver_phone : $nomorApprover,
        );

        if ($namaAtasan === '' || $nomor === null) {
            throw new RuntimeException(
                'Nama dan nomor WhatsApp atasan yang menyetujui wajib diisi, dan nomornya harus satu nomor '.
                'ponsel Indonesia — mis. 081234567890.'
            );
        }

        return DB::transaction(function () use ($tautan, $namaPemohon, $jenis, $keperluan, $baris, $namaAtasan, $nomor) {
            $mrf = MaterialRequisition::create([
                'mrf_number' => DocumentNumber::forMaterialRequisition(),
                'warehouse_id' => $tautan->warehouse_id,
                // TANPA akun pemohon. Memalsukannya sebagai akun siapa pun
                // membuat dokumen ini berbohong soal siapa yang meminta.
                'requested_by' => null,
                'request_link_id' => $tautan->id,
                'requester_name' => trim($namaPemohon),
                'department_id' => $tautan->department_id,
                'department_name' => $tautan->department?->name,
                'request_type' => $jenis,
                'purpose' => trim($keperluan),
                'status' => MaterialRequisition::STATUS_PENDING_APPROVAL,
                'approver_name' => $namaAtasan,
                'approver_phone' => $nomor,
                'approval_token' => Str::random(64),
                'notify_status' => MaterialRequisition::NOTIFY_PENDING,
            ]);

            foreach ($baris as $item) {
                $qty = (int) ($item['qty'] ?? 0);

                if ($qty < 1) {
                    throw new RuntimeException('Qty tiap baris permintaan harus minimal 1.');
                }

                $mrf->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'qty_requested' => $qty,
                    'note' => blank($item['note'] ?? null) ? null : trim((string) $item['note']),
                ]);
            }

            return $mrf;
        });
    }

    /**
     * Menyimpan nomor approver supaya MRF berikutnya tinggal diklik.
     *
     * updateOrCreate, bukan create: nomor yang sama disimpan dua kali hanya
     * menghasilkan dua pilihan kembar di formulir, dan yang memilih di antara
     * keduanya tidak punya cara tahu mana yang benar. Kalau namanya berbeda,
     * yang terbaru menang — orang berganti jabatan, nomornya tidak.
     */
    public function simpanKontak(User $pemilik, ?int $warehouseId, string $nama, string $nomor): MrfApproverContact
    {
        return MrfApproverContact::updateOrCreate(
            ['warehouse_id' => $warehouseId, 'phone' => $nomor],
            ['name' => $nama, 'created_by' => $pemilik->id],
        );
    }
}
