<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Activity;
use App\Support\Export\XlsxWriter;
use App\Support\FilterTanggal;
use App\Support\JenisTransaksi;
use App\Support\Reporting\ReportCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Log aktivitas — siapa melakukan apa, kapan. SUPER ADMIN SAJA.
 *
 * TIDAK MEMAKAI WarehouseScope, dan itu disengaja. Gate-nya sudah membatasi
 * pembacanya ke satu peran yang memang lintas gudang; menambahkan penjepitan
 * per gudang di sini hanya akan menyembunyikan sebagian jejak dari satu-satunya
 * orang yang berhak melihat seluruhnya. Penyaring gudang tetap ada sebagai
 * PILIHAN tampilan.
 *
 * HANYA BACA. Tidak ada store/update/destroy — bukan karena belum dibuat,
 * melainkan karena log yang bisa disunting bukan log. Penegakannya berlapis:
 * tidak ada rutenya di sini, dan App\Models\ActivityLog menolak update/delete
 * sekalipun ada yang memanggilnya dari tempat lain.
 *
 * DATA CONTRACT (view: wms.admin.activity-log)
 * --------------------------------------------
 * $logs    : LengthAwarePaginator<ActivityLog>
 * $users   : Collection<User>   — pelaku yang pernah muncul di log
 * $actions : array<string,string> — nama tindakan => label Indonesia
 * $warehouses, $filters{search,action,user_id,warehouse_id,dari,sampai}
 */
class ActivityLogController extends Controller
{
    /**
     * Batas baris satu berkas unduhan.
     *
     * Angkanya sama dengan laporan (ReportCatalog::MAKS_BARIS) supaya tidak
     * ada dua definisi "sebanyak apa yang wajar" yang suatu hari berbeda.
     * Berkas yang terpotong dikatakan di keterangan berkasnya, tidak dibiarkan
     * diam-diam terlihat lengkap.
     */
    private const MAKS_BARIS = ReportCatalog::MAKS_BARIS;

    public function index(Request $request): View
    {
        $filters = $this->saring($request);

        $logs = $this->pertanyaan($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('wms.admin.activity-log', [
            'logs' => $logs,
            'actions' => ActivityLog::ACTION_LABELS,
            // Hanya pelaku yang benar-benar pernah muncul di log — daftar
            // seluruh user akan penuh nama yang tidak pernah menghasilkan
            // satu baris pun dan membuat penyaringnya tidak berguna.
            'users' => User::whereIn('id', ActivityLog::query()->distinct()->pluck('user_id')->filter())
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name']),
            // Hanya jenis yang benar-benar pernah tercatat, alasannya sama
            // dengan daftar pelaku: pilihan yang tidak pernah menghasilkan satu
            // baris pun membuat penyaringnya terasa rusak.
            'jenisOptions' => array_intersect_key(
                JenisTransaksi::pilihan(),
                array_flip(ActivityLog::query()->distinct()->pluck('subject_type')->filter()->all()),
            ),
            'filters' => $filters,
        ]);
    }

    /**
     * Berkas .xlsx berisi SELURUH baris yang cocok penyaringnya.
     *
     * Bukan hanya 30 baris yang kebetulan tampil di layar: yang mengunduh log
     * sedang menyiapkan bahan pemeriksaan, dan berkas yang diam-diam terpotong
     * di halaman pertama adalah bahan yang menyesatkan.
     *
     * UNDUHANNYA IKUT TERCATAT, sama seperti unduhan laporan. Isi berkas ini
     * seluruh jejak perbuatan orang; sekali keluar ia bisa beredar selamanya,
     * jadi siapa yang mengeluarkannya harus ikut tertulis — termasuk ketika
     * yang mengeluarkannya Super Admin sendiri.
     */
    public function download(Request $request): StreamedResponse
    {
        $filters = $this->saring($request);

        $baris = $this->pertanyaan($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAKS_BARIS)
            ->get();

        Activity::record(
            ActivityLog::REPORT_EXPORT,
            sprintf('Mengunduh log aktivitas — %s baris.', number_format($baris->count())),
            warehouseId: $filters['warehouse_id'] !== null ? (int) $filters['warehouse_id'] : null,
            properties: array_filter([
                'laporan' => 'log-aktivitas',
                'baris' => $baris->count(),
                'terpotong' => $baris->count() >= self::MAKS_BARIS,
            ] + $filters, fn ($nilai) => $nilai !== null),
        );

        return XlsxWriter::unduh(
            'log-aktivitas-'.now()->format('Ymd-His').'.xlsx',
            'Log Aktivitas',
            ['Waktu', 'Pelaku', 'Peran', 'Jenis', 'Nomor Transaksi', 'Tindakan', 'Keterangan', 'Gudang', 'IP'],
            $baris->map(fn (ActivityLog $log) => [
                $log->created_at?->format('Y-m-d H:i:s'),
                $log->user_name ?? '—',
                $log->user_role ?? '—',
                $log->subject_type !== null ? $log->kode_jenis : '—',
                $log->reference_number ?? '—',
                $log->action_label,
                $log->description,
                $log->warehouse?->kode_pendek ?? '—',
                $log->ip_address ?? '—',
            ])->all(),
            keterangan: $this->keterangan($filters, $baris->count()),
        );
    }

    /* ------------------------------------------------------------- Bantuan */

    /**
     * Penyaring yang sudah dibersihkan — satu bentuk untuk layar dan unduhan.
     *
     * Keduanya WAJIB memakai penyaring yang sama persis. Berkas yang isinya
     * berbeda dari yang barusan dilihat di layar adalah cara paling halus
     * untuk membuat orang menarik kesimpulan yang keliru.
     *
     * @return array<string, mixed>
     */
    private function saring(Request $request): array
    {
        /*
         | JENIS TRANSAKSI DISARING LEWAT subject_type, bukan lewat huruf depan
         | nomornya. Nomor Surat Jalan datang dari BC tanpa huruf sama sekali
         | ("206223"), dan PO maupun PL sama-sama berawalan "P" — penyaring yang
         | membaca teks nomornya akan salah pada keduanya. Jenisnya sudah pasti
         | diketahui saat dicatat; yang dipakai di sini kepastian itu.
         */
        $jenis = $request->query('jenis');

        return [
            'search' => $request->query('search'),
            'action' => $request->query('action'),
            'jenis' => is_string($jenis) && isset(JenisTransaksi::DAFTAR[$jenis]) ? $jenis : null,
            'user_id' => $request->query('user_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'dari' => FilterTanggal::bersih($request->query('dari')),
            'sampai' => FilterTanggal::bersih($request->query('sampai')),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ActivityLog>
     */
    private function pertanyaan(array $filters): Builder
    {
        return ActivityLog::query()
            ->with(['user:id,full_name', 'warehouse:id,code,name'])
            ->when($filters['action'], fn ($q, $a) => $q->where('action', $a))
            // Satu kode bisa dipakai dua model (retur dan barisnya), jadi yang
            // disaring seluruh kelas yang berkode sama — kalau tidak, memilih
            // "RJ" akan menyembunyikan separuh jejak retur.
            ->when($filters['jenis'], fn ($q, $k) => $q->whereIn(
                'subject_type',
                array_keys(array_filter(
                    JenisTransaksi::DAFTAR,
                    fn (array $j) => $j['kode'] === JenisTransaksi::kode($k),
                )),
            ))
            ->when($filters['user_id'], fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['dari'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['sampai'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            // Dicari di deskripsi DAN nama pelaku yang tersalin. Nama disalin
            // sebagai teks justru supaya pencarian tetap menemukan jejak orang
            // yang akunnya sudah dihapus.
            ->when($filters['search'], fn ($q, $cari) => $q->where(function ($w) use ($cari) {
                $pola = '%'.$cari.'%';
                $w->where('description', 'ILIKE', $pola)
                    ->orWhere('user_name', 'ILIKE', $pola)
                    // Nomor dokumennya kini kolom tersendiri, jadi "MRF2609001"
                    // menemukan seluruh jejaknya — bukan hanya baris yang
                    // kebetulan menyebutnya di dalam kalimat keterangan.
                    ->orWhere('reference_number', 'ILIKE', $pola);
            }));
    }

    /**
     * Penyaring yang dipakai ikut ditulis DI DALAM berkasnya.
     *
     * Berkas Excel berpindah tangan tanpa membawa alamat halaman yang
     * melahirkannya. Tanpa keterangan ini, yang menerimanya tidak punya cara
     * mengetahui bahwa isinya sudah disaring — dan membaca sebagian jejak
     * sebagai keseluruhan.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function keterangan(array $filters, int $jumlah): array
    {
        $periode = $filters['dari'] || $filters['sampai']
            ? ($filters['dari'] ?? 'awal').' s/d '.($filters['sampai'] ?? 'sekarang')
            : 'Seluruh riwayat tersimpan';

        return array_filter([
            'Periode' => $periode,
            'Jenis transaksi' => $filters['jenis'] !== null ? JenisTransaksi::label($filters['jenis']) : 'Semua',
            'Tindakan' => $filters['action'] !== null
                ? (ActivityLog::ACTION_LABELS[$filters['action']] ?? $filters['action'])
                : 'Semua',
            'Kata kunci' => $filters['search'] ?: null,
            'Jumlah baris' => number_format($jumlah),
            'Diunduh' => now()->format('Y-m-d H:i:s'),
        ], fn ($nilai) => $nilai !== null);
    }
}
