<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Notification;
use App\Support\Activity;
use App\Support\Inventory\PemindahanRak;
use App\Support\Notifier;
use App\Support\Permission;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Memindahkan stok DDP dari rak barang bagus ke rak DDP.
 *
 * KENAPA LAYAR INI ADA. Sistem sudah menandai batch kedaluwarsa menjadi DDP
 * setiap malam, tetapi barangnya tetap berdiri di rak FG: tidak ada satu pun
 * layar yang bisa menyebut "hari ini ada 3 batch yang harus turun ke rak DDP".
 * Akibatnya barang tidak layak jual bersanding dengan barang siap kirim di rak
 * yang sama — justru keadaan yang ingin dihindari dengan menyediakan rak DDP.
 *
 * DAFTARNYA TIDAK DISIMPAN, MELAINKAN DISIMPULKAN. "Stok DDP yang lokasinya
 * bukan rak DDP" sudah merupakan daftar pekerjaan itu sendiri, dan ia selalu
 * benar: barisnya hilang pada detik barangnya benar-benar berada di rak DDP.
 * Tidak ada tugas yang bisa lupa dibuat, dan tidak ada tugas yang menyatakan
 * selesai padahal barangnya belum pindah. Lihat InventoryStock::menungguRakDdp.
 *
 * PEMBAGIAN PERAN.
 * - Logistik MEMERIKSA lalu MENYERAHKAN baris ke daftar kerja operator. Ia
 *   tidak memilih rak tujuannya satu per satu: rak DDP ditandai sekali saja
 *   per deret di Master Lokasi.
 * - Operator MENGANGKAT barangnya, dan saat memindahkan hanya ditawari rak
 *   dari deret bertanda DDP.
 *
 * DATA CONTRACT
 * -------------
 * index() : $belumDiserahkan, $daftarKerja : Collection<InventoryStock>
 *           $rakDdp    : Collection<Location> — pilihan rak tujuan
 *           $deretDdp  : Collection<string>   — deret bertanda DDP
 *           $stats     : array{baris:int, unit:int, siap:int}
 *           $bolehSerah, $bolehPindah : bool
 */
class DdpRelocationController extends Controller
{
    public function __construct(private readonly PemindahanRak $pemindahan) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // Pintunya sudah dijaga gate INVENTORY_DDP_VIEW di berkas rute; kedua
        // nilai ini memutuskan BAGIAN MANA yang tampil bagi pembacanya.
        $bolehSerah = Permission::allows($user, Permission::INVENTORY_DDP_ASSIGN);
        $bolehPindah = Permission::allows($user, Permission::INVENTORY_DDP_MOVE);

        $menunggu = WarehouseScope::apply(
            InventoryStock::menungguRakDdp()->with(['product:id,sku,name,uom', 'location:id,code', 'ddpAssignedBy:id,full_name']),
            $user,
        )
            // Yang paling lama menganggur di rak FG naik ke atas: makin lama
            // barang tak layak jual berdiri di sana, makin besar peluangnya
            // ikut terambil saat picking.
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();

        $rakDdp = WarehouseScope::apply(Location::query()->active()->ddp(), $user)
            ->inStorageOrder()
            ->get(['id', 'code', 'rack', 'warehouse_id']);

        return view('wms.inventory.ddp', [
            'belumDiserahkan' => $menunggu->whereNull('ddp_assigned_at')->values(),
            'daftarKerja' => $menunggu->whereNotNull('ddp_assigned_at')->values(),
            'rakDdp' => $rakDdp,
            'deretDdp' => $rakDdp->pluck('rack')->unique()->values(),
            'stats' => [
                'baris' => $menunggu->count(),
                'unit' => (int) $menunggu->sum('qty_available'),
                'siap' => $menunggu->whereNotNull('ddp_assigned_at')->count(),
            ],
            'bolehSerah' => $bolehSerah,
            'bolehPindah' => $bolehPindah,
        ]);
    }

    /**
     * Logistik menyerahkan baris terpilih ke daftar kerja operator.
     *
     * Yang ditambahkan di sini hanyalah SATU keterangan yang memang tidak bisa
     * disimpulkan dari data lain: bahwa Logistik sudah melihat baris ini dan
     * menyatakannya siap dikerjakan. Selebihnya — SKU, batch, qty, rak asal —
     * sudah ada di barisnya sendiri dan tidak disalin ke mana-mana.
     */
    public function serahkan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_ids' => ['required', 'array', 'min:1'],
            'stock_ids.*' => ['integer', 'exists:inventory_stocks,id'],
        ], [], ['stock_ids' => 'baris stok']);

        $baris = WarehouseScope::apply(
            InventoryStock::menungguRakDdp()
                ->whereIn('id', $validated['stock_ids'])
                ->whereNull('ddp_assigned_at')
                ->with('product:id,sku'),
            $request->user(),
        )->get();

        if ($baris->isEmpty()) {
            return back()->with('error', 'Tidak ada baris yang bisa diserahkan — mungkin barangnya sudah dipindah atau sudah diserahkan lebih dulu.');
        }

        InventoryStock::whereIn('id', $baris->pluck('id'))->update([
            'ddp_assigned_at' => now(),
            'ddp_assigned_by' => $request->user()?->id,
        ]);

        $unit = (int) $baris->sum('qty_available');

        Notifier::toPermission(
            Permission::INVENTORY_DDP_MOVE,
            $baris->first()->warehouse_id,
            Notification::STOCK_DDP_ASSIGNED,
            'Pemindahan ke rak DDP menunggu',
            sprintf(
                '%d baris stok DDP (%s unit) siap diturunkan dari rak FG ke rak DDP.',
                $baris->count(),
                number_format($unit),
            ),
            route('wms.ddp.index'),
        );

        Activity::record(
            ActivityLog::STOCK_TRANSFER,
            sprintf('Menyerahkan %d baris stok DDP ke daftar kerja operator.', $baris->count()),
            null,
            $baris->first()->warehouse_id,
            ['baris' => $baris->count(), 'unit' => $unit, 'sku' => $baris->pluck('product.sku')->filter()->values()->all()],
        );

        return back()->with('success', sprintf(
            '%d baris (%s unit) masuk daftar kerja operator.',
            $baris->count(),
            number_format($unit),
        ));
    }

    /**
     * Operator memindahkan satu baris ke rak DDP.
     *
     * RAK TUJUAN WAJIB RAK DDP, dan itu diperiksa di sini — bukan hanya
     * dibatasi isi dropdown-nya. Dropdown yang sudah benar tetap bisa dilewati
     * dengan mengirim kode rak lain, dan memindahkan barang kedaluwarsa ke rak
     * FG adalah persis kesalahan yang sedang dicegah seluruh layar ini.
     */
    public function pindahkan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stock_id' => ['required', 'integer', 'exists:inventory_stocks,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'qty' => ['required', 'integer', 'min:1'],
        ], [], ['location_id' => 'rak DDP tujuan']);

        $stock = InventoryStock::with(['product:id,sku', 'location:id,code'])->findOrFail($validated['stock_id']);

        WarehouseScope::assert($stock->warehouse_id, $request->user());

        if (! in_array($stock->status, [InventoryStock::STATUS_DDP, InventoryStock::STATUS_EXPIRED], true)) {
            return back()->with('error', 'Baris ini bukan stok DDP, jadi tidak lewat jalur pemindahan ini.');
        }

        $tujuan = Location::where('warehouse_id', $stock->warehouse_id)
            ->active()
            ->ddp()
            ->find($validated['location_id']);

        if (! $tujuan) {
            return back()->with('error', 'Rak tujuan bukan rak DDP aktif di gudang ini. Tandai dulu deretnya sebagai rak DDP di Denah Gudang.');
        }

        if ($tujuan->id === $stock->location_id) {
            return back()->with('error', 'Barang ini sudah berada di rak tersebut.');
        }

        $asal = $stock->location?->code ?? '—';
        $qty = (int) $validated['qty'];

        try {
            $this->pemindahan->pindahkan(
                $stock,
                $tujuan,
                $qty,
                sprintf('Pemindahan stok DDP (%s) dari rak %s ke rak DDP %s.', $stock->ddp_reason_label ?? 'DDP', $asal, $tujuan->code),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Activity::record(
            ActivityLog::STOCK_TRANSFER,
            sprintf(
                'Menurunkan %d %s batch %s dari rak %s ke rak DDP %s.',
                $qty,
                $stock->product?->sku ?? '—',
                $stock->batch_no ?? '—',
                $asal,
                $tujuan->code,
            ),
            $stock,
            $stock->warehouse_id,
            [
                'sku' => $stock->product?->sku,
                'batch' => $stock->batch_no,
                'qty' => $qty,
                'dari_rak' => $asal,
                'ke_rak' => $tujuan->code,
            ],
        );

        return back()->with('success', sprintf(
            '%d %s batch %s sudah di rak DDP %s.',
            $qty,
            $stock->product?->sku ?? '—',
            $stock->batch_no ?? '—',
            $tujuan->code,
        ));
    }
}
