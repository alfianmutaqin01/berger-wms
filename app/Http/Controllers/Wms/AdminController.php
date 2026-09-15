<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Support\Activity;
use App\Support\DocumentNumber;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengaturan Sistem & Penomoran Dokumen — Fase 10.
 *
 * DUA HALAMAN, DUA SIFAT YANG SENGAJA BERBEDA.
 *
 * settings() BISA DIUBAH. Isinya lima setelan yang lolos tiga ujian sekaligus:
 * keputusan bisnis (bukan teknis), memang berubah dari waktu ke waktu, dan
 * salah isi tidak merusak apa pun — cuma membuat perilakunya berbeda. Lihat
 * App\Support\Settings untuk daftar dan alasan tiap-tiapnya.
 *
 * sequence() BACA-SAJA, dan itu keputusan rancangan, bukan pekerjaan yang
 * belum selesai. Halaman lamanya menyodorkan kolom prefix dan "nomor urut
 * berikutnya" yang bisa diketik — nilainya karangan yang bahkan tidak cocok
 * dengan format sungguhan, dan tombolnya tidak menyimpan apa pun. Dibuat
 * benar-benar bisa diubah justru LEBIH buruk:
 *
 *   - Mengganti prefix di tengah jalan memecah riwayat menjadi dua bentuk yang
 *     tidak bisa dicari sekaligus. DocumentNumber::countToday() mencari dengan
 *     LIKE pada prefiksnya; begitu berubah, hitungannya salah tanpa suara.
 *   - Menggeser nomor urut MUNDUR langsung menghasilkan nomor kembar.
 *     document_sequences punya kunci unik, jadi akibatnya bukan data kotor
 *     melainkan pembuatan pesanan yang berhenti total untuk semua orang.
 *
 * Karena itu yang ditampilkan adalah keadaan APA ADANYA: format sungguhan,
 * nomor terakhir yang benar-benar terpakai, dan contoh nomor berikutnya.
 */
class AdminController extends Controller
{
    // Manajemen User dipindahkan ke UserController (CRUD penuh + validasi).

    /* ------------------------------------------------- Pengaturan Sistem */

    public function settings(): View
    {
        return view('wms.admin.settings', [
            'daftar' => Settings::daftar(),
            'nilai' => Settings::nilai(),
            'terakhir' => SystemSetting::query()
                ->with('updatedBy:id,full_name')
                ->latest('updated_at')
                ->first(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $daftar = Settings::daftar();

        /*
         * Aturan validasinya DISUSUN dari daftar setelan, bukan ditulis ulang
         * di sini. Menuliskannya dua kali berarti suatu hari formulirnya
         * menerima angka yang ditolak penyimpannya — atau sebaliknya, yang
         * jauh lebih buruk.
         */
        $aturan = [];
        $nama = [];

        foreach ($daftar as $key => $meta) {
            $aturan[$key] = ['required', 'integer', 'min:'.$meta['min'], 'max:'.$meta['max']];
            $nama[$key] = strtolower($meta['label']);
        }

        $data = $request->validate($aturan, [], $nama);

        $berubah = Settings::simpan($data, $request->user()?->id);

        if ($berubah === []) {
            return back()->with('success', 'Tidak ada setelan yang berubah.');
        }

        /*
         * DICATAT KE LOG AKTIVITAS, termasuk perubahan umur simpan log itu
         * sendiri. Setelan yang menentukan seberapa lama jejak disimpan adalah
         * justru setelan yang paling perlu meninggalkan jejak saat diubah.
         */
        Activity::record(
            ActivityLog::SETTINGS_UPDATE,
            sprintf(
                'Mengubah pengaturan sistem: %s.',
                implode(', ', array_map(
                    fn ($key, $ubah) => sprintf(
                        '%s %d → %d',
                        $daftar[$key]['label'],
                        $ubah['lama'],
                        $ubah['baru'],
                    ),
                    array_keys($berubah),
                    $berubah,
                )),
            ),
            null,
            null,
            $berubah,
        );

        return back()->with('success', sprintf(
            '%d setelan diperbarui dan langsung berlaku.',
            count($berubah),
        ));
    }

    /* --------------------------------------- Penomoran Dokumen (baca-saja) */

    public function sequence(): View
    {
        /*
         * Nomor terakhir dibaca dari document_sequences APA ADANYA. Baris di
         * sana dikunci per (jenis, gudang, tahun, bulan) — jadi satu jenis
         * dokumen bisa punya beberapa baris, dan yang ditampilkan adalah
         * periode berjalan.
         */
        $urut = DB::table('document_sequences')
            ->where('period_year', now()->year)
            ->where(fn ($q) => $q->whereNull('period_month')->orWhere('period_month', now()->month))
            ->get()
            ->groupBy('document_type')
            ->map(fn ($baris) => (int) $baris->max('last_number'));

        return view('wms.admin.sequence', [
            'jenis' => $this->jenisDokumen(),
            'urut' => $urut,
        ]);
    }

    /**
     * Format sungguhan tiap jenis dokumen, diambil dari App\Support\
     * DocumentNumber — bukan diketik ulang di Blade. Kalau formatnya suatu
     * hari berubah, halaman ini ikut berubah tanpa ada yang perlu ingat.
     *
     * @return array<int, array{tipe:string, nama:string, contoh:string, keterangan:string}>
     */
    private function jenisDokumen(): array
    {
        $tanggal = now()->format('ymd');

        return [
            [
                'tipe' => DocumentNumber::TYPE_SALES_ORDER,
                'nama' => 'Pesanan (Sales Order)',
                'contoh' => 'PO'.$tanggal.'001',
                'keterangan' => 'Nomor internal. Nomor SO dari sistem BC diisi terpisah saat pesanan diterima.',
            ],
            [
                'tipe' => DocumentNumber::TYPE_PICKING_LIST,
                'nama' => 'Daftar Picking',
                'contoh' => 'PL'.$tanggal.'001',
                'keterangan' => 'Dibuat Logistik saat menggabungkan pesanan menjadi satu tugas gudang.',
            ],
            [
                'tipe' => DocumentNumber::TYPE_STOCK_TRANSFER,
                'nama' => 'Transfer Antar Gudang',
                'contoh' => 'TF'.$tanggal.'001',
                'keterangan' => 'Dibuat gudang asal saat barang berangkat.',
            ],
            [
                'tipe' => DocumentNumber::TYPE_SALES_RETURN,
                'nama' => 'Penolakan Customer',
                'contoh' => 'RJ'.$tanggal.'001',
                'keterangan' => 'Dibuat saat Sales melaporkan barang yang ditolak pelanggan.',
            ],
            [
                'tipe' => DocumentNumber::TYPE_DELIVERY_NOTE,
                'nama' => 'Surat Jalan',
                'contoh' => '—',
                'keterangan' => 'TIDAK diterbitkan sistem ini. Dokumen resminya keluar dari sistem BC '
                    .'dan nomornya diimpor apa adanya.',
            ],
        ];
    }
}
