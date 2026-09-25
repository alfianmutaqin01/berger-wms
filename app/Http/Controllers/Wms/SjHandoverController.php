<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\CancelSjHandoverRequest;
use App\Http\Requests\Wms\StoreSjHandoverRequest;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteHandover;
use App\Models\DeliveryNoteHandoverItem;
use App\Support\Outbound\SjHandover;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Kirim Surat Jalan fisik ke Kantor Pusat — sisi GUDANG.
 *
 * Sisi seberangnya ada di SjHandoverReceiptController, dan keduanya sengaja
 * tidak digabung meski membaca tabel yang sama: yang memisahkan bukan
 * datanya melainkan wewenangnya. Satu controller berarti satu izin, dan satu
 * izin berarti orang yang mengirim amplop juga bisa menyatakannya sampai.
 *
 * DATA CONTRACT
 * -------------
 * index()    : $tab, $belum LengthAwarePaginator<DeliveryNote>|null,
 *              $paket LengthAwarePaginator<DeliveryNoteHandover>|null,
 *              $jumlah{belum-dikirim,dalam-perjalanan,riwayat}, $filters
 * pratinjau(): $terpilih Collection<DeliveryNote>, $cara array
 * show()     : $paket (items.deliveryNote.customer termuat)
 * cetak()    : $paket — tanpa layout WMS, langsung siap cetak
 */
class SjHandoverController extends Controller
{
    /** Lembar yang sudah boleh berangkat tetapi belum masuk amplop mana pun. */
    public const TAB_BELUM = 'belum-dikirim';

    public const TAB_JALAN = 'dalam-perjalanan';

    public const TAB_RIWAYAT = 'riwayat';

    public function __construct(private readonly SjHandover $serahTerima) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $tab = in_array($request->query('tab'), [
            self::TAB_BELUM, self::TAB_JALAN, self::TAB_RIWAYAT,
        ], true) ? $request->query('tab') : self::TAB_BELUM;

        $cari = $request->query('search');

        $belumQuery = fn () => WarehouseScope::apply(DeliveryNote::query(), $user)
            ->siapKeHo()
            ->search($cari);

        $paketQuery = fn () => WarehouseScope::apply(DeliveryNoteHandover::query(), $user)
            ->search($cari);

        return view('wms.outbound.sj-fisik', [
            'tab' => $tab,
            'belum' => $tab === self::TAB_BELUM
                ? $belumQuery()
                    ->with(['customer:id,code,name', 'salesOrder:id,order_number,bc_so_number'])
                    // Lembar yang PERNAH berangkat lalu dilaporkan tidak ada di
                    // amplop. Ia kembali ke daftar ini terlihat sama persis
                    // dengan yang belum pernah dikirim — padahal yang satu
                    // tinggal dimasukkan amplop, yang satu lagi harus dicari
                    // dulu kertasnya.
                    ->withCount(['handoverItems as pernah_hilang_count' => fn ($q) => $q
                        ->where('released_reason', DeliveryNoteHandoverItem::RELEASED_MISSING)])
                    ->orderBy('delivered_at')
                    ->paginate(25)
                    ->withQueryString()
                : null,
            'paket' => $tab === self::TAB_BELUM
                ? null
                : $paketQuery()
                    ->when($tab === self::TAB_JALAN,
                        fn ($q) => $q->dalamPerjalanan(),
                        fn ($q) => $q->whereNot('status', DeliveryNoteHandover::STATUS_SENT),
                    )
                    ->with(['sentBy:id,full_name', 'receivedBy:id,full_name', 'items'])
                    ->withCount('items')
                    ->latest('sent_at')
                    ->paginate(20)
                    ->withQueryString(),
            'jumlah' => [
                self::TAB_BELUM => $belumQuery()->count(),
                self::TAB_JALAN => $paketQuery()->dalamPerjalanan()->count(),
                self::TAB_RIWAYAT => $paketQuery()->whereNot('status', DeliveryNoteHandover::STATUS_SENT)->count(),
            ],
            'filters' => ['search' => $cari],
        ]);
    }

    /**
     * Pratinjau sebelum amplop dibuat.
     *
     * SATU HALAMAN TERSENDIRI, bukan modal di atas daftar. Yang diperiksa di
     * sini adalah tumpukan kertas di tangan: orangnya membaca daftar di layar
     * sambil menghitung lembar di meja, dan itu tidak bisa dilakukan sambil
     * daftar aslinya tertutup separuh oleh kotak putih.
     *
     * POST, bukan GET dengan id di URL: jumlah lembar sekali kirim bisa
     * puluhan, dan alamat sepanjang itu terpotong diam-diam oleh peramban.
     */
    public function pratinjau(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $ids = array_filter((array) $request->input('delivery_note_id', []));

        $terpilih = WarehouseScope::apply(DeliveryNote::query(), $user)
            ->siapKeHo()
            ->whereIn('id', $ids)
            ->with(['customer:id,code,name', 'salesOrder:id,order_number,bc_so_number'])
            ->orderBy('delivered_at')
            ->get();

        if ($terpilih->isEmpty()) {
            return redirect()
                ->route('wms.sj-fisik.index')
                ->with('warning', 'Pilih dulu Surat Jalan yang mau dikirim.');
        }

        return view('wms.outbound.sj-fisik-pratinjau', [
            'terpilih' => $terpilih,
            'cara' => DeliveryNoteHandover::CARRIER_LABELS,
        ]);
    }

    public function store(StoreSjHandoverRequest $request): RedirectResponse
    {
        try {
            $paket = $this->serahTerima->buat(
                deliveryNoteIds: $request->validated('delivery_note_id'),
                data: $request->validated(),
                aktor: $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.sj-fisik.show', $paket)
            ->with('success', 'Paket '.$paket->code.' tercatat berangkat. Cetak lembar serah terimanya lalu masukkan ke amplop.');
    }

    public function show(Request $request, DeliveryNoteHandover $handover): View
    {
        WarehouseScope::assert($handover->warehouse_id, $request->user());

        return view('wms.outbound.sj-fisik-detail', [
            'paket' => $this->muat($handover),
        ]);
    }

    /**
     * Lembar serah terima — yang ikut masuk ke dalam amplop.
     *
     * KERTAS INILAH yang menyambungkan amplop fisik dengan catatan di sistem.
     * Tanpa dia, CA menerima setumpuk Surat Jalan tanpa tahu seharusnya
     * berapa lembar dan dari paket mana; nomor PSJ di sudut atasnya yang
     * membuat seluruh ceklis di layar berikutnya ada gunanya.
     */
    public function cetak(Request $request, DeliveryNoteHandover $handover): View
    {
        WarehouseScope::assert($handover->warehouse_id, $request->user());

        return view('wms.outbound.sj-fisik-cetak', [
            'paket' => $this->muat($handover),
        ]);
    }

    public function batal(CancelSjHandoverRequest $request, DeliveryNoteHandover $handover): RedirectResponse
    {
        WarehouseScope::assert($handover->warehouse_id, $request->user());

        try {
            $this->serahTerima->batalkan($handover, $request->validated('reason'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('wms.sj-fisik.index')
            ->with('success', 'Paket '.$handover->code.' dibatalkan. Surat Jalan di dalamnya kembali ke daftar belum dikirim.');
    }

    private function muat(DeliveryNoteHandover $handover): DeliveryNoteHandover
    {
        return $handover->load([
            'warehouse:id,code,name',
            'sentBy:id,full_name',
            'receivedBy:id,full_name',
            'cancelledBy:id,full_name',
            'items.deliveryNote:id,document_no,bc_so_number,customer_id,delivered_at',
            'items.deliveryNote.customer:id,code,name',
            'items.checkedBy:id,full_name',
        ]);
    }
}
