<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreLocationRequest;
use App\Http\Requests\Wms\UpdateLocationRequest;
use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Lokasi Rak — PRD §6.2, §5.2 "Master Lokasi Rak (CRUD)".
 *
 * DATA CONTRACT (view: wms.master.locations)
 * ------------------------------------------
 * $locations  : LengthAwarePaginator<Location> — eager-load `warehouse`
 * $warehouses : Collection<Warehouse>
 * $racks      : Collection<string> — daftar rak pada gudang terpilih
 * $zones      : list<string>       — Fast / Slow / Middle Moving Area
 * $stats      : array{total:int, active:int, inactive:int, per_zone:array}
 * $filters    : array{warehouse_id:?string, search:?string, rack:?string,
 *                     level:?string, zone:?string, status:?string}
 *
 * CATATAN: jumlahnya besar (2.264 bin untuk satu gudang), sehingga halaman ini
 * mengandalkan filter + paginasi. Pengurutan memakai kolom rack/level/cell,
 * BUKAN string kode — mengurutkan lewat kode akan menaruh "B-01-10" sebelum
 * "B-01-02".
 */
class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'warehouse_id' => $request->query('warehouse_id'),
            'search' => $request->query('search'),
            'rack' => $request->query('rack'),
            'level' => $request->query('level'),
            'zone' => $request->query('zone'),
            'status' => $request->query('status'),
        ];

        $base = Location::query()
            ->when($filters['warehouse_id'], fn ($q, $id) => $q->where('warehouse_id', $id));

        $locations = (clone $base)
            ->with('warehouse')
            ->search($filters['search'])
            ->when($filters['rack'], fn ($q, $rack) => $q->where('rack', $rack))
            ->when($filters['level'], fn ($q, $level) => $q->where('level', $level))
            ->when($filters['zone'], fn ($q, $zone) => $q->where('zone', $zone))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->inStorageOrder()
            ->paginate(50)
            ->withQueryString();

        return view('wms.master.locations', [
            'locations' => $locations,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'racks' => (clone $base)->distinct()->orderBy('rack')->pluck('rack'),
            'zones' => Location::ZONES,
            'stats' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
                'per_zone' => (clone $base)->selectRaw('zone, count(*) as jumlah')
                    ->groupBy('zone')->pluck('jumlah', 'zone'),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Denah gudang — peta visual seluruh bin, disusun seperti letak fisiknya.
     *
     * DATA CONTRACT (view: wms.master.locations-map)
     * ----------------------------------------------
     * $racks      : Collection<string, Collection<int, Collection<Location>>>
     *               rak => level => daftar bin (terurut nomor sel)
     * $rackMeta   : array<string, array{zone:?string, total:int, inactive:int}>
     * $warehouses : Collection<Warehouse>
     * $warehouse  : ?Warehouse — gudang yang sedang ditampilkan
     * $zones      : list<string>
     * $stats      : array{total:int, active:int, inactive:int, per_zone:array}
     * $filters    : array{warehouse_id:?string, zone:?string, highlight:?string}
     *
     * CATATAN FASE 4: halaman ini belum menampilkan ISI bin karena tabel
     * `inventory_stocks` belum ada. Struktur tampilannya sudah disiapkan untuk
     * itu — tiap kotak bin punya slot indikator keterisian yang tinggal diisi
     * begitu data stok tersedia, tanpa perlu menyusun ulang denahnya.
     */
    public function map(Request $request): View
    {
        $filters = [
            'warehouse_id' => $request->query('warehouse_id'),
            'zone' => $request->query('zone'),
            'highlight' => trim((string) $request->query('highlight')),
        ];

        $warehouse = $filters['warehouse_id']
            ? Warehouse::find($filters['warehouse_id'])
            : Warehouse::orderBy('code')->first();

        $base = Location::query()->where('warehouse_id', $warehouse?->id);

        $locations = (clone $base)
            ->when($filters['zone'], fn ($q, $zone) => $q->where('zone', $zone))
            ->inStorageOrder()
            ->get();

        // Disusun rak -> level -> bin. Pengelompokan dilakukan di PHP karena
        // seluruh bin satu gudang (~2.264 baris) sudah diambil sekali jalan;
        // memecahnya jadi query per rak justru menghasilkan puluhan query.
        $racks = $locations->groupBy('rack')->map(
            fn ($binsPerRack) => $binsPerRack->groupBy('level')
        );

        $rackMeta = $racks->map(fn ($levels, $rack) => [
            'zone' => $levels->flatten()->first()?->zone,
            'total' => $levels->flatten()->count(),
            'inactive' => $levels->flatten()->where('is_active', false)->count(),
        ])->all();

        return view('wms.master.locations-map', [
            'racks' => $racks,
            'rackMeta' => $rackMeta,
            'warehouses' => Warehouse::orderBy('code')->get(),
            'warehouse' => $warehouse,
            'zones' => Location::ZONES,
            'stats' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
                'per_zone' => (clone $base)->selectRaw('zone, count(*) as jumlah')
                    ->groupBy('zone')->pluck('jumlah', 'zone'),
            ],
            'filters' => $filters,
        ]);
    }

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        $location = Location::create($request->locationData());

        return redirect()->route('wms.locations.index')
            ->with('success', "Lokasi {$location->code} berhasil ditambahkan.");
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->locationData());

        return redirect()->route('wms.locations.index')
            ->with('success', "Lokasi {$location->code} berhasil diperbarui.");
    }

    /**
     * Menonaktifkan/mengaktifkan lokasi.
     *
     * Memakai flag `is_active`, BUKAN penghapusan: bin yang pernah dipakai
     * masih direferensikan riwayat stok dan pergerakan barang. Bin non-aktif
     * tidak akan dipilih lagi oleh proses put-away.
     */
    public function toggleStatus(Location $location): RedirectResponse
    {
        $location->update(['is_active' => ! $location->is_active]);

        return back()->with('success', sprintf(
            'Lokasi %s berhasil %s.',
            $location->code,
            $location->is_active ? 'diaktifkan' : 'dinonaktifkan'
        ));
    }
}
