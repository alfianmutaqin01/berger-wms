<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Warehouse;
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
        $filters = [
            'search' => $request->query('search'),
            'action' => $request->query('action'),
            'user_id' => $request->query('user_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'dari' => $request->query('dari'),
            'sampai' => $request->query('sampai'),
        ];

        $logs = ActivityLog::query()
            ->with(['user:id,full_name', 'warehouse:id,code,name'])
            ->when($filters['action'], fn ($q, $a) => $q->where('action', $a))
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
                    ->orWhere('user_name', 'ILIKE', $pola);
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
            'filters' => $filters,
        ]);
    }
}
