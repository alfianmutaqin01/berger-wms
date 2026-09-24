<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\StoreLocationRequest;
use App\Http\Requests\Wms\UpdateLocationRequest;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Support\Activity;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $user = $request->user();

        $filters = [
            'warehouse_id' => WarehouseScope::resolveFilter($request, $user),
            'search' => $request->query('search'),
            'rack' => $request->query('rack'),
            // Kolom level bertipe tinyint: teks atau angka raksasa dari URL
            // ditolak PostgreSQL dan menjatuhkan halaman (temuan SQA).
            'level' => filter_var($request->query('level'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]) ?: null,
            'zone' => $request->query('zone'),
            'status' => $request->query('status'),
        ];

        // RAK TRANSIT TIDAK IKUT. Ia bukan bagian dari denah gudang melainkan
        // perlengkapan tetap sistem: dua titik serah terima material produksi
        // yang dibuat sendiri untuk tiap gudang. Menampilkannya di sini hanya
        // menambah dua baris yang selamanya kosong ke hitungan "rak terisi" —
        // dan membuka jalan untuk menonaktifkannya, yang akan mematikan serah
        // terima MRF tanpa ada yang menghubungkan sebabnya.
        $base = WarehouseScope::apply(Location::query(), $user)
            ->penyimpanan()
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
            'warehouses' => WarehouseScope::options($user),
            'racks' => (clone $base)->distinct()->orderBy('rack')->pluck('rack'),
            // Formulir tambah/sunting hanya menawarkan tiga zona pergerakan;
            // rak DDP ditandai per deret, bukan dipilih per sel.
            'zones' => Location::ZONES,
            'zonesRingkas' => Location::ZONES_SEMUA,
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
     *               rak => level => daftar titik rak (terurut nomor sel)
     * $rackMeta   : array<string, array{zone:?string, total:int, inactive:int,
     *               terisi:int, qty:int}>
     * $isi        : array<int, array{qty:int, sku:int}> — location_id => ringkasan isi
     * $warehouses : Collection<Warehouse>
     * $warehouse  : ?Warehouse — gudang yang sedang ditampilkan
     * $zones      : list<string>
     * $stats      : array{total:int, active:int, inactive:int, terisi:int, qty:int,
     *               per_zone:array}
     * $filters    : array{warehouse_id:?string, zone:?string, highlight:?string}
     *
     * ISINYA DIRINGKAS DI SINI, RINCIANNYA MENYUSUL LEWAT contents().
     * Denah satu gudang memuat ~2.264 kotak; menyertakan rincian batch di
     * setiap kotak membuat halamannya membengkak berkali lipat demi data yang
     * 99% tidak pernah dibuka. Yang ikut ke halaman hanya dua angka per rak —
     * cukup untuk mewarnai dan menuliskan jumlahnya — sementara daftar
     * produk, batch, dan kedaluwarsanya diambil saat kotaknya diklik.
     */
    public function map(Request $request): View
    {
        $user = $request->user();

        $filters = [
            'warehouse_id' => WarehouseScope::resolveFilter($request, $user),
            'zone' => $request->query('zone'),
            'highlight' => trim((string) $request->query('highlight')),
        ];

        $pilihan = WarehouseScope::options($user);

        // Gudang default adalah yang PERTAMA DALAM KEWENANGANNYA, bukan
        // WH-01. Kalau tidak, Operator Surabaya membuka denah dan melihat rak
        // Karawang — lalu menaruh barang di kode bin yang tidak ada di sana.
        $warehouse = $filters['warehouse_id']
            ? $pilihan->firstWhere('id', (int) $filters['warehouse_id'])
            : $pilihan->first();

        // Sama seperti daftar rak: titik serah terima bukan bagian denah.
        $base = Location::query()->penyimpanan()->where('warehouse_id', $warehouse?->id);

        $locations = (clone $base)
            ->when($filters['zone'], fn ($q, $zone) => $q->where('zone', $zone))
            ->inStorageOrder()
            ->get();

        // Disusun rak -> level -> titik rak. Pengelompokan dilakukan di PHP
        // karena seluruh titik satu gudang (~2.264 baris) sudah diambil sekali
        // jalan; memecahnya jadi query per rak justru menghasilkan puluhan query.
        $racks = $locations->groupBy('rack')->map(
            fn ($titikPerRak) => $titikPerRak->groupBy('level')
        );

        $isi = $this->ringkasanIsi($locations->pluck('id')->all());

        $rackMeta = $racks->map(function ($levels, $rack) use ($isi) {
            $titik = $levels->flatten();

            return [
                'zone' => $titik->first()?->zone,
                'total' => $titik->count(),
                'inactive' => $titik->where('is_active', false)->count(),
                'terisi' => $titik->filter(fn ($t) => isset($isi[$t->id]))->count(),
                'qty' => $titik->sum(fn ($t) => $isi[$t->id]['qty'] ?? 0),
            ];
        })->all();

        return view('wms.master.locations-map', [
            'racks' => $racks,
            'isi' => $isi,
            'rackMeta' => $rackMeta,
            'warehouses' => $pilihan,
            'warehouse' => $warehouse,
            'zones' => Location::ZONES_SEMUA,
            'stats' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
                'terisi' => count($isi),
                'qty' => array_sum(array_column($isi, 'qty')),
                'per_zone' => (clone $base)->selectRaw('zone, count(*) as jumlah')
                    ->groupBy('zone')->pluck('jumlah', 'zone'),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Rincian isi satu titik rak — dipanggil saat kotaknya diklik di denah.
     *
     * TERPISAH dari map() dengan sengaja: lihat catatan di sana. Yang
     * dikembalikan adalah SELURUH baris stok di titik itu, termasuk yang
     * berstatus karantina dan DDP — rak fisiknya memang memuat semua itu, dan
     * denah yang hanya menyebut barang layak jual membuat orang mencari-cari
     * barang yang sebenarnya ada di depan matanya.
     */
    public function contents(Request $request, Location $location): JsonResponse
    {
        WarehouseScope::assert($location->warehouse_id, $request->user());

        $baris = InventoryStock::query()
            ->where('location_id', $location->id)
            ->with('product:id,sku,name,uom')
            ->orderBy('product_id')
            ->orderBy('production_date')
            ->get()
            ->map(fn (InventoryStock $s) => [
                'sku' => $s->product?->sku ?? '—',
                'nama' => $s->product?->name ?? '—',
                'uom' => $s->product?->uom,
                'batch' => $s->batch_no,
                'produksi' => $s->production_date?->format('d M Y'),
                'kedaluwarsa' => $s->expiry_date?->format('d M Y'),
                'tersedia' => (int) $s->qty_available,
                'teralokasi' => (int) $s->qty_allocated,
                'status' => $s->status,
                'status_label' => $s->status_label,
                'masalah_kualitas' => (bool) $s->has_quality_issue,
                'dahulukan' => (bool) $s->prioritize_out,
                'alasan_dahulukan' => $s->prioritize_reason,
            ])
            ->values()
            ->all();

        return response()->json([
            'kode' => $location->code,
            'baris' => $baris,
            'total' => array_sum(array_map(
                fn (array $b) => $b['tersedia'] + $b['teralokasi'],
                $baris
            )),
        ]);
    }

    /**
     * Ringkasan isi tiap titik rak: berapa unit, berapa SKU.
     *
     * Satu query untuk seluruh gudang. Menghitungnya per kotak berarti ribuan
     * query pada satu kali muat halaman.
     *
     * @param  list<int>  $locationIds
     * @return array<int, array{qty:int, sku:int}>
     */
    private function ringkasanIsi(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        return InventoryStock::query()
            ->whereIn('location_id', $locationIds)
            ->groupBy('location_id')
            // qty_available DITAMBAH qty_allocated: yang dilihat orang saat
            // berdiri di depan rak adalah barang fisiknya, dan barang yang
            // sudah dicadangkan untuk pesanan tetap berdiri di sana sampai
            // benar-benar diambil operator.
            ->selectRaw('location_id, SUM(qty_available + qty_allocated) AS qty, COUNT(DISTINCT product_id) AS sku')
            ->havingRaw('SUM(qty_available + qty_allocated) > 0')
            ->get()
            ->mapWithKeys(fn ($b) => [
                (int) $b->location_id => ['qty' => (int) $b->qty, 'sku' => (int) $b->sku],
            ])
            ->all();
    }

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        // Manager gudang lain tidak boleh membuat rak di gudang yang bukan
        // kewenangannya; `warehouse_id` di sini datang dari isian formulir.
        WarehouseScope::assert((int) $request->input('warehouse_id'), $request->user());

        $location = Location::create($request->locationData());

        Activity::record(
            ActivityLog::MASTER_CREATE,
            sprintf('Menambah lokasi rak %s.', $location->code),
            $location,
            $location->warehouse_id,
            ['kode' => $location->code],
        );

        return redirect()->route('wms.locations.index')
            ->with('success', "Lokasi {$location->code} berhasil ditambahkan.");
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        // Dua-duanya diperiksa: rak asal (jangan menyentuh milik gudang lain)
        // DAN gudang tujuan (jangan memindahkannya ke luar kewenangan).
        WarehouseScope::assert($location->warehouse_id, $request->user());
        WarehouseScope::assert((int) $request->input('warehouse_id'), $request->user());

        $location->update($request->locationData());

        Activity::record(
            ActivityLog::MASTER_UPDATE,
            sprintf('Mengubah lokasi rak %s.', $location->code),
            $location,
            $location->warehouse_id,
            ['kode' => $location->code, 'kolom_berubah' => array_keys($location->getChanges())],
        );

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
    public function toggleStatus(Request $request, Location $location): RedirectResponse
    {
        WarehouseScope::assert($location->warehouse_id, $request->user());

        $location->update(['is_active' => ! $location->is_active]);

        Activity::record(
            ActivityLog::MASTER_DEACTIVATE,
            sprintf(
                'Lokasi rak %s %s.',
                $location->code,
                $location->is_active ? 'diaktifkan' : 'dinonaktifkan',
            ),
            $location,
            $location->warehouse_id,
            ['kode' => $location->code, 'aktif' => $location->is_active],
        );

        return back()->with('success', sprintf(
            'Lokasi %s berhasil %s.',
            $location->code,
            $location->is_active ? 'diaktifkan' : 'dinonaktifkan'
        ));
    }

    /**
     * Menandai SATU DERET rak sebagai rak DDP, atau melepas tandanya.
     *
     * PER DERET, BUKAN PER SEL. Di gudang, yang dipisahkan adalah deretnya:
     * "barang DDP taruh di deret W". Deret W di sini berisi 90 sel — meminta
     * Logistik menandainya satu per satu berarti 90 kali keputusan yang sama,
     * dan satu sel terlewat sudah cukup membuat barang bagus tetap disarankan
     * masuk ke sana.
     *
     * AKIBATNYA DUA, DAN KEDUANYA OTOMATIS: deret ini hilang dari saran
     * put-away barang bagus, dan hanya deret seperti inilah yang ditawarkan
     * saat operator memindahkan stok DDP.
     *
     * ZONA LAMANYA DISIMPAN DI CATATAN AKTIVITAS, bukan di kolom tersembunyi.
     * Melepas tanda DDP mengembalikan deret ke zona yang dipilih Logistik saat
     * itu; menebak-nebak zona asalnya justru membuat strategi put-away berubah
     * diam-diam tanpa ada yang memutuskan.
     */
    public function tandaiDeretDdp(Request $request, string $rack): RedirectResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'jadikan_ddp' => ['required', 'boolean'],
            // Hanya dipakai saat tanda DDP DILEPAS: deret ini mau dikembalikan
            // menjadi zona apa.
            'zona_kembali' => ['nullable', 'string', Rule::in(Location::ZONES)],
        ]);

        WarehouseScope::assert((int) $validated['warehouse_id'], $request->user());

        $deret = strtoupper(trim($rack));
        $jadikanDdp = (bool) $validated['jadikan_ddp'];

        $baris = Location::where('warehouse_id', $validated['warehouse_id'])
            ->whereRaw('UPPER(rack) = ?', [$deret]);

        if ((clone $baris)->doesntExist()) {
            return back()->with('error', sprintf('Deret rak %s tidak ada di gudang ini.', $deret));
        }

        if ($jadikanDdp) {
            /*
             * BARANG BAGUS DI DERET INI MENGHALANGI. Menandai deret yang masih
             * berisi stok siap jual akan menyembunyikan barang itu dari saran
             * put-away sekaligus menaruhnya di rak yang seharusnya hanya berisi
             * barang tak layak jual — persis percampuran yang ingin dicegah,
             * hanya terbalik arahnya.
             */
            $barangBagus = InventoryStock::whereIn('location_id', (clone $baris)->pluck('id'))
                ->whereIn('status', [InventoryStock::STATUS_ACTIVE, InventoryStock::STATUS_QUARANTINE])
                ->where('qty_available', '>', 0)
                ->count();

            if ($barangBagus > 0) {
                return back()->with('error', sprintf(
                    'Deret %s masih menyimpan %d baris stok siap jual atau karantina. Pindahkan dulu isinya, baru deret ini bisa dijadikan rak DDP.',
                    $deret,
                    $barangBagus,
                ));
            }
        }

        $zonaBaru = $jadikanDdp
            ? Location::ZONE_DDP
            : ($validated['zona_kembali'] ?? Location::ZONE_MIDDLE);

        $jumlah = (clone $baris)->update(['zone' => $zonaBaru]);

        Activity::record(
            ActivityLog::MASTER_UPDATE,
            sprintf(
                $jadikanDdp
                    ? 'Menandai deret rak %s (%d sel) sebagai rak DDP.'
                    : 'Melepas tanda DDP dari deret rak %s (%d sel).',
                $deret,
                $jumlah,
            ),
            null,
            (int) $validated['warehouse_id'],
            ['deret' => $deret, 'sel' => $jumlah, 'zona' => $zonaBaru],
        );

        return back()->with('success', sprintf(
            $jadikanDdp
                ? 'Deret %s (%d sel) kini rak DDP: tidak lagi disarankan untuk barang bagus, dan menjadi pilihan saat stok DDP dipindahkan.'
                : 'Tanda DDP pada deret %s (%d sel) dilepas; zonanya kembali ke %s.',
            $deret,
            $jumlah,
            $zonaBaru,
        ));
    }
}
