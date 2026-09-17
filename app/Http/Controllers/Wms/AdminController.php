<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\MrfRequestLink;
use App\Models\SystemSetting;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\DocumentNumber;
use App\Support\PhoneNumber;
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
            /*
             | Tautan permintaan material per divisi, MENUMPANG halaman ini
             | alih-alih berdiri sebagai menu sendiri.
             |
             | Ia diatur sekali lalu nyaris tidak disentuh lagi — sama sifatnya
             | dengan setelan di atas. Menu tersendiri untuk daftar yang berisi
             | dua baris dan diubah setahun sekali hanya memperpanjang menu
             | samping yang harus dibaca orang setiap hari.
             */
            'tautanMrf' => MrfRequestLink::query()
                ->with(['warehouse:id,code,name', 'department:id,name', 'createdBy:id,full_name'])
                ->withCount('requisitions')
                ->orderBy('department_id')
                ->get(),
            'divisiOptions' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'gudangOptions' => Warehouse::query()->active()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Menerbitkan tautan permintaan material untuk satu divisi.
     *
     * SATU TAUTAN HIDUP PER DIVISI PER GUDANG (dijaga kunci unik di basis
     * data). Dua tautan untuk divisi yang sama berarti dua alamat yang
     * beredar, dan yang dicabut belum tentu yang sedang dipakai orang.
     */
    public function storeMrfLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            // Boleh dikosongkan: divisi yang atasannya berganti-ganti
            // menyebutkannya sendiri di formulir, seperti Produksi.
            'approver_name' => ['nullable', 'string', 'max:100'],
            'approver_phone' => ['nullable', 'string', 'max:25'],
        ], [], [
            'warehouse_id' => 'gudang',
            'department_id' => 'divisi',
            'approver_name' => 'nama atasan',
            'approver_phone' => 'nomor WhatsApp atasan',
        ]);

        $nomor = null;

        if (filled($data['approver_phone'] ?? null)) {
            $nomor = PhoneNumber::forWhatsApp($data['approver_phone']);

            if ($nomor === null) {
                return back()->withInput()->with('error',
                    'Nomor WhatsApp atasan tidak terbaca sebagai satu nomor telepon. '.
                    'Pakai satu nomor ponsel Indonesia, mis. 081234567890.');
            }
        }

        if (MrfRequestLink::where('warehouse_id', $data['warehouse_id'])
            ->where('department_id', $data['department_id'])->exists()) {
            return back()->with('error',
                'Divisi itu sudah punya tautan di gudang ini. Nonaktifkan atau terbitkan ulang yang sudah ada, '.
                'supaya tidak ada dua alamat yang beredar sekaligus.');
        }

        $tautan = MrfRequestLink::create([
            'warehouse_id' => (int) $data['warehouse_id'],
            'department_id' => (int) $data['department_id'],
            'token' => MrfRequestLink::tokenBaru(),
            'approver_name' => filled($data['approver_name'] ?? null) ? trim($data['approver_name']) : null,
            'approver_phone' => $nomor,
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]);

        Activity::record(
            ActivityLog::SETTINGS_UPDATE,
            sprintf(
                'Menerbitkan tautan permintaan material untuk divisi %s di gudang %s.',
                $tautan->department?->name ?? '—',
                $tautan->warehouse?->kode_pendek ?? '—',
            ),
            $tautan,
            $tautan->warehouse_id,
            ['divisi' => $tautan->department?->name, 'atasan_terkunci' => $tautan->atasanTerkunci()],
        );

        return back()->with('success', sprintf(
            'Tautan untuk %s dibuat. Berikan sekali kepada kepala divisinya — perlakukan seperti kunci, '.
            'jangan disebar di grup.',
            $tautan->department?->name ?? 'divisi itu',
        ));
    }

    /**
     * Menonaktifkan, menghidupkan, atau menerbitkan ulang tautan.
     *
     * TERBITKAN ULANG mengganti tokennya, sehingga alamat lama mati seketika.
     * Itulah jalan keluar kalau tautannya terlanjur tersebar.
     */
    public function updateMrfLink(Request $request, MrfRequestLink $link): RedirectResponse
    {
        $data = $request->validate([
            'aksi' => ['required', 'in:aktifkan,nonaktifkan,terbitkan_ulang'],
        ]);

        $pesan = match ($data['aksi']) {
            'aktifkan' => 'dihidupkan kembali',
            'nonaktifkan' => 'dinonaktifkan — alamat lamanya berhenti bekerja',
            'terbitkan_ulang' => 'diterbitkan ulang dengan alamat baru; alamat lamanya mati seketika',
        };

        $link->fill(match ($data['aksi']) {
            'aktifkan' => ['is_active' => true],
            'nonaktifkan' => ['is_active' => false],
            'terbitkan_ulang' => ['token' => MrfRequestLink::tokenBaru(), 'is_active' => true],
        })->save();

        Activity::record(
            ActivityLog::SETTINGS_UPDATE,
            sprintf('Tautan permintaan material divisi %s %s.', $link->department?->name ?? '—', $pesan),
            $link,
            $link->warehouse_id,
            ['divisi' => $link->department?->name, 'aksi' => $data['aksi']],
        );

        return back()->with('success', sprintf(
            'Tautan %s %s.',
            $link->department?->name ?? 'divisi',
            $pesan,
        ));
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
