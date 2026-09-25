<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\ConfirmSjHandoverRequest;
use App\Models\DeliveryNoteHandover;
use App\Support\Outbound\SjHandover;
use App\Support\WarehouseScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Terima Surat Jalan fisik — sisi KANTOR PUSAT (Customer Account).
 *
 * HALAMAN AWAL SEBUAH PERAN, bukan sekadar satu layar tambahan. CA tidak
 * punya dasbor: daftar di index() inilah yang ia lihat begitu masuk, karena
 * memang hanya itu pekerjaannya di sistem ini.
 *
 * TANPA BATAS GUDANG — dalam praktiknya. CA dibuat dengan warehouse_id NULL
 * (lihat WarehouseScope), sebab amplop dari ketiga gudang mendarat di meja
 * yang sama. WarehouseScope tetap dipanggil, bukan dilewati: kalau suatu hari
 * ada akun CA yang terlanjur diberi gudang, yang terjadi adalah daftarnya
 * menyempit — bukan pagar yang diam-diam tidak ada.
 *
 * DATA CONTRACT
 * -------------
 * index() : $paket LengthAwarePaginator<DeliveryNoteHandover>, $tab,
 *           $jumlah{masuk,riwayat}, $filters
 * show()  : $paket (items.deliveryNote.customer termuat), $pilihan array
 */
class SjHandoverReceiptController extends Controller
{
    public const TAB_MASUK = 'masuk';

    public const TAB_RIWAYAT = 'riwayat';

    public function __construct(private readonly SjHandover $serahTerima) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $cari = $request->query('search');

        $tab = $request->query('tab') === self::TAB_RIWAYAT ? self::TAB_RIWAYAT : self::TAB_MASUK;

        $dasar = fn () => WarehouseScope::apply(DeliveryNoteHandover::query(), $user)->search($cari);

        return view('wms.outbound.sj-fisik-masuk', [
            'tab' => $tab,
            'paket' => $dasar()
                ->when($tab === self::TAB_MASUK,
                    fn ($q) => $q->dalamPerjalanan(),
                    fn ($q) => $q->where('status', DeliveryNoteHandover::STATUS_RECEIVED),
                )
                ->with(['warehouse:id,code,name', 'sentBy:id,full_name', 'items'])
                ->withCount('items')
                // Yang paling lama di jalan ada di atas saat masih ditunggu;
                // yang paling baru selesai ada di atas saat sudah jadi riwayat.
                ->orderBy('sent_at', $tab === self::TAB_MASUK ? 'asc' : 'desc')
                ->paginate(20)
                ->withQueryString(),
            'jumlah' => [
                self::TAB_MASUK => $dasar()->dalamPerjalanan()->count(),
                self::TAB_RIWAYAT => $dasar()->where('status', DeliveryNoteHandover::STATUS_RECEIVED)->count(),
            ],
            'filters' => ['search' => $cari],
        ]);
    }

    public function show(Request $request, DeliveryNoteHandover $handover): View
    {
        WarehouseScope::assert($handover->warehouse_id, $request->user());

        return view('wms.outbound.sj-fisik-periksa', [
            'paket' => $handover->load([
                'warehouse:id,code,name',
                'sentBy:id,full_name',
                'receivedBy:id,full_name',
                'items.deliveryNote:id,document_no,bc_so_number,customer_id,delivered_at',
                'items.deliveryNote.customer:id,code,name',
                'items.checkedBy:id,full_name',
            ]),
        ]);
    }

    public function konfirmasi(ConfirmSjHandoverRequest $request, DeliveryNoteHandover $handover): RedirectResponse
    {
        WarehouseScope::assert($handover->warehouse_id, $request->user());

        try {
            $this->serahTerima->konfirmasiTerima(
                paket: $handover,
                keputusan: $request->validated('periksa'),
                catatan: $request->validated('received_notes'),
                aktor: $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $hilang = $handover->items->where('check_status', 'missing')->count();

        return redirect()
            ->route('wms.sj-fisik.masuk')
            ->with(
                $hilang > 0 ? 'warning' : 'success',
                $hilang > 0
                    ? 'Paket '.$handover->code.' ditutup. '.$hilang.' lembar dilaporkan tidak ada dan dikembalikan ke gudang untuk dikirim ulang.'
                    : 'Paket '.$handover->code.' diterima lengkap. Terima kasih.',
            );
    }
}
