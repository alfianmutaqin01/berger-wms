<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\FilterTanggal;
use App\Support\JenisTransaksi;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
    public function index(Request $request): View
    {
        /*
         | JENIS TRANSAKSI DISARING LEWAT subject_type, bukan lewat huruf depan
         | nomornya. Nomor Surat Jalan datang dari BC tanpa huruf sama sekali
         | ("206223"), dan PO maupun PL sama-sama berawalan "P" — penyaring yang
         | membaca teks nomornya akan salah pada keduanya. Jenisnya sudah pasti
         | diketahui saat dicatat; yang dipakai di sini kepastian itu.
         */
        $jenis = $request->query('jenis');
        $jenis = is_string($jenis) && isset(JenisTransaksi::DAFTAR[$jenis]) ? $jenis : null;

        $filters = [
            'search' => $request->query('search'),
            'action' => $request->query('action'),
            'jenis' => $jenis,
            'user_id' => $request->query('user_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'dari' => FilterTanggal::bersih($request->query('dari')),
            'sampai' => FilterTanggal::bersih($request->query('sampai')),
        ];

        $logs = ActivityLog::query()
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
            }))
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
}
