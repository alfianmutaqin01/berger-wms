<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ReceiveStockTransferRequest;
use App\Http\Requests\Wms\StoreStockTransferRequest;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\Inventory\WarehouseTransfer;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Transfer stok antar gudang — PRD F-INV-05.
 *
 * SATU DOKUMEN, DUA GUDANG. Ini bentuk data pertama di sistem ini yang tidak
 * dimiliki satu gudang saja: Karawang berhak melihatnya sebagai kiriman
 * keluar, Pekanbaru sebagai kiriman masuk. Karena itu penyaringannya TIDAK
 * memakai WarehouseScope::apply() yang menyaring satu kolom, melainkan
 * StockTransfer::touchingWarehouse() yang mencakup keduanya.
 *
 * Yang tetap dijaga ketat adalah WEWENANGNYA, dan keduanya berbeda:
 *   - hanya gudang ASAL yang boleh mengirim dan membatalkan
 *   - hanya gudang TUJUAN yang boleh menerima
 *
 * DATA CONTRACT
 * -------------
 * index()   : $transfers LengthAwarePaginator<StockTransfer>, $filters,
 *             $statuses, $stats{masuk,keluar,menunggu}, $gudangSaya
 * create()  : $tujuan Collection<Warehouse>, $batch Collection<InventoryStock>,
 *             $gudangAsal Warehouse
 * show()    : $transfer StockTransfer
 * receive() : $transfer StockTransfer, $rak Collection<Location>
 */
class StockTransferController extends Controller
{
    public function __construct(private readonly WarehouseTransfer $transfer) {}

    /** Daftar transfer yang menyangkut gudang user — keluar maupun masuk. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $gudangSaya = WarehouseScope::boundary($user);

        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            // "arah" hanya berarti bagi user yang punya gudang: kiriman yang
            // sama adalah "keluar" bagi Karawang dan "masuk" bagi Pekanbaru.
            'arah' => $gudangSaya !== null ? $request->query('arah') : null,
        ];

        $terlihat = fn () => StockTransfer::query()->touchingWarehouse($gudangSaya);

        $transfers = $terlihat()
            ->with(['fromWarehouse:id,code,name', 'toWarehouse:id,code,name',
                'shippedBy:id,full_name', 'receivedBy:id,full_name'])
            ->withCount('details')
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['arah'] === 'keluar', fn ($q) => $q->where('from_warehouse_id', $gudangSaya))
            ->when($filters['arah'] === 'masuk', fn ($q) => $q->where('to_warehouse_id', $gudangSaya))
            // Yang masih di jalan selalu di atas: itu satu-satunya yang
            // menunggu tindakan seseorang.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [StockTransfer::STATUS_IN_TRANSIT])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('wms.inventory.transfers', [
            'transfers' => $transfers,
            'filters' => $filters,
            'statuses' => StockTransfer::STATUS_LABELS,
            'gudangSaya' => $user?->warehouse,
            'stats' => [
                'menunggu' => $terlihat()->where('status', StockTransfer::STATUS_IN_TRANSIT)->count(),
                'masuk' => $gudangSaya === null ? null : StockTransfer::query()
                    ->where('to_warehouse_id', $gudangSaya)
                    ->where('status', StockTransfer::STATUS_IN_TRANSIT)
                    ->count(),
                'keluar' => $gudangSaya === null ? null : StockTransfer::query()
                    ->where('from_warehouse_id', $gudangSaya)
                    ->where('status', StockTransfer::STATUS_IN_TRANSIT)
                    ->count(),
            ],
        ]);
    }

    /** Layar pembuatan kiriman: pilih tujuan, lalu pilih batch dari rak sendiri. */
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $asalId = WarehouseScope::boundary($user);

        // Super Admin tidak terikat satu gudang, jadi ia harus menyatakan
        // gudang asalnya. Tanpa ini, "kirim dari gudang mana" tidak terjawab.
        if ($asalId === null) {
            $asalId = (int) $request->query('from');

            if ($asalId < 1) {
                return view('wms.inventory.transfer-create', [
                    'gudangAsal' => null,
                    'semuaGudang' => Warehouse::active()->orderBy('code')->get(),
                    'tujuan' => collect(),
                    'batch' => collect(),
                ]);
            }
        }

        $asal = Warehouse::find($asalId);
        abort_if($asal === null, 404, 'Gudang asal tidak ditemukan.');
        WarehouseScope::assert($asal->id, $user);

        return view('wms.inventory.transfer-create', [
            'gudangAsal' => $asal,
            'semuaGudang' => Warehouse::active()->orderBy('code')->get(),
            'tujuan' => Warehouse::active()->whereKeyNot($asal->id)->orderBy('code')->get(),
            'batch' => $this->batchSiapKirim($asal->id),
        ]);
    }

    public function store(StoreStockTransferRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Gudang asal DARI AKUN, bukan dari formulir. Super Admin yang tidak
        // terikat gudang menyatakannya lewat `from_warehouse_id`, dan itu
        // satu-satunya jalur yang menerimanya dari isian.
        $asalId = WarehouseScope::boundary($user) ?? (int) $request->input('from_warehouse_id');

        if ($asalId < 1) {
            return back()->withInput()->with('error', 'Gudang asal belum ditentukan.');
        }

        WarehouseScope::assert($asalId, $user);

        try {
            $transfer = $this->transfer->ship(
                $asalId,
                (int) $request->validated('to_warehouse_id'),
                $request->itemData(),
                $request->validated('notes'),
                $user?->id,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('wms.transfers.show', $transfer)->with('success', sprintf(
            'Transfer %s dikirim ke gudang %s. Stoknya sudah keluar dari gudang Anda dan tercatat DALAM PERJALANAN sampai diterima di sana.',
            $transfer->transfer_number,
            $transfer->toWarehouse?->name ?? 'tujuan',
        ));
    }

    public function show(Request $request, StockTransfer $transfer): View
    {
        $this->pastikanBerhakMelihat($request, $transfer);

        $transfer->load([
            'fromWarehouse', 'toWarehouse',
            'shippedBy:id,full_name', 'receivedBy:id,full_name', 'cancelledBy:id,full_name',
            'details.product:id,sku,name,uom', 'details.toLocation:id,code',
        ]);

        return view('wms.inventory.transfer-detail', ['transfer' => $transfer]);
    }

    /** Layar penerimaan — hanya untuk gudang tujuan. */
    public function receiveForm(Request $request, StockTransfer $transfer): View|RedirectResponse
    {
        $this->pastikanGudangTujuan($request, $transfer);

        if (! $transfer->isInTransit()) {
            return redirect()->route('wms.transfers.show', $transfer)->with('error', sprintf(
                'Transfer %s sudah %s dan tidak bisa diterima lagi.',
                $transfer->transfer_number,
                strtolower($transfer->status_label),
            ));
        }

        $transfer->load([
            'fromWarehouse', 'toWarehouse',
            'details.product:id,sku,name,uom',
        ]);

        return view('wms.inventory.transfer-receive', [
            'transfer' => $transfer,
            // Kode rak GUDANG TUJUAN, bukan gudang asal. Penomoran rak tiap
            // gudang berbeda, dan inilah kesalahan yang paling mudah terjadi
            // di layar ini.
            'rak' => Location::where('warehouse_id', $transfer->to_warehouse_id)
                ->active()->inStorageOrder()->get(['id', 'code', 'zone']),
        ]);
    }

    public function receive(ReceiveStockTransferRequest $request, StockTransfer $transfer): RedirectResponse
    {
        try {
            $hasil = $this->transfer->receive($transfer, $request->barisData(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $pesan = sprintf(
            'Transfer %s diterima. %d unit masuk ke stok gudang %s.',
            $transfer->transfer_number,
            $hasil['diterima'],
            $transfer->toWarehouse?->name ?? 'ini',
        );

        if ($hasil['hilang'] > 0) {
            $pesan .= sprintf(' %d unit TIDAK SAMPAI dan sudah dicatat beserta alasannya.', $hasil['hilang']);
        }

        if ($hasil['susulan'] !== []) {
            $pesan .= ' '.implode(' ', $hasil['susulan']);
        }

        return redirect()->route('wms.transfers.show', $transfer)->with(
            $hasil['hilang'] > 0 ? 'warning' : 'success',
            $pesan,
        );
    }

    /** Membatalkan kiriman yang belum sampai; stok kembali ke gudang asal. */
    public function cancel(Request $request, StockTransfer $transfer): RedirectResponse
    {
        // Hanya gudang ASAL. Gudang tujuan yang tidak menghendaki kiriman
        // menerimanya dengan qty 0 beserta alasan — itu meninggalkan jejak
        // bahwa barangnya pernah berangkat, sedangkan pembatalan tidak.
        WarehouseScope::assert($transfer->from_warehouse_id, $request->user());

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [], ['cancellation_reason' => 'alasan pembatalan']);

        try {
            $this->transfer->cancel($transfer, $data['cancellation_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('wms.transfers.show', $transfer)->with(
            'success',
            "Transfer {$transfer->transfer_number} dibatalkan. Stoknya sudah dikembalikan ke rak asal."
        );
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Batch yang bisa dikirim dari gudang $warehouseId.
     *
     * Stok kedaluwarsa TIDAK ikut: memindahkannya ke gudang lain hanya
     * memindahkan masalahnya, dan barang yang sudah lewat masa simpan tidak
     * punya tujuan yang sah selain pemusnahan. Stok DDP tetap ikut — menarik
     * barang rusak kembali ke Karawang justru salah satu alasan fitur ini ada.
     */
    private function batchSiapKirim(int $warehouseId)
    {
        return InventoryStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('qty_available', '>', 0)
            ->whereIn('status', [InventoryStock::STATUS_ACTIVE, InventoryStock::STATUS_DDP])
            ->with(['product:id,sku,name,uom', 'location:id,code'])
            // Urutan FIFO: yang paling dekat kedaluwarsa di atas, karena
            // itulah yang paling sering perlu dipindahkan.
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Dokumen ini boleh dibaca KEDUA gudang yang terlibat.
     *
     * Karena itu tidak bisa memakai WarehouseScope::assert() yang hanya
     * membandingkan satu gudang.
     */
    private function pastikanBerhakMelihat(Request $request, StockTransfer $transfer): void
    {
        $gudang = WarehouseScope::boundary($request->user());

        abort_if(
            $gudang !== null
                && $gudang !== $transfer->from_warehouse_id
                && $gudang !== $transfer->to_warehouse_id,
            403,
            'Transfer ini tidak menyangkut gudang Anda.'
        );
    }

    private function pastikanGudangTujuan(Request $request, StockTransfer $transfer): void
    {
        $gudang = WarehouseScope::boundary($request->user());

        abort_if(
            $gudang !== null && $gudang !== $transfer->to_warehouse_id,
            403,
            'Hanya gudang tujuan yang boleh menerima kiriman ini.'
        );
    }
}
