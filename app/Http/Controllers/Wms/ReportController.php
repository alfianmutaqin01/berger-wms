<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Export\XlsxWriter;
use App\Support\Permission;
use App\Support\Reporting\ReportCatalog;
use App\Support\Reporting\ReportRunner;
use App\Support\WarehouseScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Laporan & Ekspor.
 *
 * APA YANG DIGANTI DI SINI
 * ------------------------
 * Halaman lamanya memajang empat kartu dengan delapan tombol unduh, dan
 * SETIAP tombol hanya memanggil alert('Mempersiapkan File Excel...'). Rentang
 * tanggalnya pun diketik tangan di dalam Blade (2026-08-01 s/d 2026-08-31)
 * dan tidak tersambung ke apa pun. Kebohongan aktif yang keempat setelah
 * simulateUploadBukti(), lonceng palsu, dan penomoran dokumen yang bisa
 * diketik.
 *
 * PRATINJAU DULU, BARU UNDUH
 * --------------------------
 * Tiap laporan punya halamannya sendiri yang menampilkan 25 baris pertama
 * beserta JUMLAH BARIS SEBENARNYA sebelum tombol unduh ditekan. Alurnya
 * disengaja: mengunduh berkas dengan mata tertutup, membukanya di Excel, dan
 * mendapati isinya kosong atau salah rentang adalah putaran yang mahal —
 * apalagi kalau berkasnya sudah terlanjur diteruskan ke orang lain.
 *
 * UNDUHAN TERCATAT DI LOG AKTIVITAS
 * ---------------------------------
 * Satu berkas Excel yang keluar sekali bisa beredar selamanya, dan isinya
 * data pelanggan beserta volume pembeliannya. Yang mengunduh, kapan, laporan
 * apa, rentang berapa, dan berapa baris — semuanya masuk activity_logs.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportRunner $runner) {}

    /** Daftar laporan yang boleh dibuka pengguna ini. */
    public function index(Request $request)
    {
        return view('wms.reports.index', [
            'laporan' => $this->tersedia($request),
        ]);
    }

    /** Satu laporan: penyaring, pratinjau, dan tombol unduh. */
    public function show(Request $request, string $key)
    {
        $meta = $this->pastikanBoleh($request, $key);
        $filter = $this->filter($request, $meta);

        $tabel = $this->runner->jalankan($key, $request->user(), $filter, ReportCatalog::PRATINJAU);

        return view('wms.reports.show', [
            'key' => $key,
            'meta' => $meta,
            'filter' => $filter,
            'tabel' => $tabel,
            'ringkas' => $this->runner->ringkasan($key, $tabel),
            'gudangPilihan' => WarehouseScope::options($request->user()),
            'laporan' => $this->tersedia($request),
            'maksBaris' => ReportCatalog::MAKS_BARIS,
        ]);
    }

    /** Berkas .xlsx berisi seluruh baris, bukan hanya yang tampil di layar. */
    public function download(Request $request, string $key)
    {
        $meta = $this->pastikanBoleh($request, $key);
        $filter = $this->filter($request, $meta);

        $tabel = $this->runner->jalankan($key, $request->user(), $filter, ReportCatalog::MAKS_BARIS);

        $keterangan = $this->keterangan($meta, $filter, $tabel);

        Activity::record(
            ActivityLog::REPORT_EXPORT,
            'Mengunduh laporan '.$meta['nama'].' ('.$keterangan['Periode'].') — '
                .number_format(count($tabel['baris'])).' baris.',
            warehouseId: $filter['warehouse_id'],
            properties: [
                'laporan' => $key,
                'dari' => $filter['dari'],
                'sampai' => $filter['sampai'],
                'baris' => count($tabel['baris']),
                'terpotong' => $tabel['total'] > count($tabel['baris']),
            ],
        );

        return XlsxWriter::unduh(
            $this->namaBerkas($key, $filter),
            $meta['nama'],
            $tabel['kolom'],
            $tabel['baris'],
            $tabel['angka'],
            $keterangan,
        );
    }

    /* ------------------------------------------------------------- Bantuan */

    /**
     * Laporan yang boleh dilihat, sudah disaring izinnya.
     *
     * @return array<string, array<string, mixed>>
     */
    private function tersedia(Request $request): array
    {
        return array_filter(
            ReportCatalog::daftar(),
            fn (array $m) => Permission::allows($request->user(), $m['izin']),
        );
    }

    /**
     * Memastikan laporannya ada DAN boleh dibuka orang ini.
     *
     * Dua pemeriksaan berbeda dengan dua jawaban berbeda. Kunci yang tidak
     * dikenal adalah 404 — ia memang tidak ada. Kunci yang dikenal tetapi
     * izinnya tidak lolos adalah 403 — menjawabnya 404 memang menyembunyikan
     * keberadaannya, tetapi juga membuat orang yang seharusnya berhak
     * mengira laporannya rusak dan melapor ke tempat yang salah.
     *
     * @return array<string, mixed>
     */
    private function pastikanBoleh(Request $request, string $key): array
    {
        if (! ReportCatalog::ada($key)) {
            throw new NotFoundHttpException('Laporan tidak dikenal.');
        }

        $meta = ReportCatalog::ambil($key);

        if (! Permission::allows($request->user(), $meta['izin'])) {
            throw new AccessDeniedHttpException('Laporan ini bukan wewenang Anda.');
        }

        return $meta;
    }

    /**
     * Penyaring yang sudah dibersihkan.
     *
     * Tanggal laporan POTRET dibuang sejak di sini, bukan diabaikan diam-diam
     * di lapisan query. Nilai yang masih tersangkut di URL akan muncul lagi
     * di kolom isian dan di judul berkas Excel — dan berkas bertuliskan
     * "Periode 1–30 September" yang isinya keadaan hari ini adalah bentuk
     * salah paham yang paling sulit dibantah, karena keterangannya tertulis
     * di berkasnya sendiri.
     *
     * @return array{dari: ?string, sampai: ?string, warehouse_id: ?int}
     */
    private function filter(Request $request, array $meta): array
    {
        $bersih = function (?string $nilai): ?string {
            if (blank($nilai)) {
                return null;
            }

            try {
                return Carbon::parse($nilai)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };

        $dari = $meta['berkala'] ? $bersih($request->query('dari')) : null;
        $sampai = $meta['berkala'] ? $bersih($request->query('sampai')) : null;

        // Rentang terbalik diluruskan, bukan ditolak. Yang salah pilih tanggal
        // menginginkan datanya, bukan pesan galat — dan rentang terbalik
        // selalu menghasilkan nol baris, yang terbaca seperti "tidak ada
        // penjualan bulan itu".
        if ($dari && $sampai && $dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        if ($meta['berkala'] && ! $dari && ! $sampai) {
            [$dari, $sampai] = $this->periodeBawaan();
        }

        return [
            'dari' => $dari,
            'sampai' => $sampai,
            'warehouse_id' => WarehouseScope::resolveFilter($request, $request->user()),
        ];
    }

    /**
     * Rentang bawaan: bulan berjalan.
     *
     * @return array{0: string, 1: string}
     */
    private function periodeBawaan(): array
    {
        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }

    /** @return array<string, string> */
    private function keterangan(array $meta, array $filter, array $tabel): array
    {
        $periode = $meta['berkala'] && $filter['dari']
            ? Carbon::parse($filter['dari'])->format('d/m/Y').' s/d '.Carbon::parse($filter['sampai'])->format('d/m/Y')
            : 'Keadaan per '.now()->format('d/m/Y H:i');

        $ket = [
            'Periode' => $periode,
            'Dasar tanggal' => $meta['dasar'],
            'Gudang' => $filter['warehouse_id']
                ? (Warehouse::find($filter['warehouse_id'])?->code ?? '—')
                : 'Semua gudang',
            'Diunduh' => now()->format('d/m/Y H:i').' WIB',
            'Jumlah baris' => number_format(count($tabel['baris'])),
        ];

        // Berkas yang terpotong HARUS mengaku di dalam berkasnya sendiri.
        // Peringatan yang cuma muncul di layar akan hilang begitu berkasnya
        // diteruskan, dan penerimanya menjumlahkan 20.000 baris seolah itu
        // seluruhnya.
        if ($tabel['total'] > count($tabel['baris'])) {
            $ket['PERHATIAN'] = 'Terpotong pada '.number_format(ReportCatalog::MAKS_BARIS)
                .' baris dari total '.number_format($tabel['total'])
                .'. Persempit rentang tanggalnya agar lengkap.';
        }

        return $ket;
    }

    private function namaBerkas(string $key, array $filter): string
    {
        $periode = $filter['dari']
            ? Carbon::parse($filter['dari'])->format('Ymd').'-'.Carbon::parse($filter['sampai'])->format('Ymd')
            : now()->format('Ymd-Hi');

        return 'laporan-'.$key.'-'.$periode.'.xlsx';
    }
}
