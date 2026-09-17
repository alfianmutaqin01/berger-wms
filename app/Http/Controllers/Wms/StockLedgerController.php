<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Models\InboundHeader;
use App\Models\MaterialRequisition;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockBooking;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Support\FilterTanggal;
use App\Support\JenisTransaksi;
use App\Support\WarehouseScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Kartu stok — buku besar mutasi barang, HANYA BACA.
 *
 * KENAPA LAYAR TERSENDIRI, BUKAN BAGIAN LOG AKTIVITAS. Keduanya sama-sama
 * jejak audit, tetapi menjawab pertanyaan yang berbeda dan tidak bisa saling
 * menggantikan:
 *
 *   Log aktivitas  siapa melakukan apa — "Manager mengoreksi stok pukul 10:14"
 *   Kartu stok     bagaimana angkanya bergerak — "APKO-001 turun 300, sisa 700"
 *
 * Satu tindakan bisa menggerakkan puluhan baris stok, dan satu baris stok bisa
 * bergerak tanpa ada orang yang menekan apa pun (alokasi otomatis saat pesanan
 * masuk). Menggabungkannya membuat yang satu selalu kehilangan sebagian
 * jawabannya. Yang menyambungkan keduanya nomor dokumen: nomor yang sama
 * terbaca di kedua layar.
 *
 * SUMBER KEBENARAN ANGKA. qty_before dan qty_after direkam apa adanya saat
 * kejadian, jadi selisih yang tidak wajar bisa ditemukan tanpa memutar ulang
 * seluruh riwayat — dan kalau ada baris yang hilang, lompatan angkanya
 * langsung terlihat di kolom itu juga.
 *
 * DATA CONTRACT (view: wms.inventory.kartu-stok)
 * ---------------------------------------------
 * $halaman  : LengthAwarePaginator<StockMovement>
 * $dokumen  : array<string, array{kode:string, nomor:?string, pihak:?string}>
 *             kunci "<reference_type>:<reference_id>"
 * $stats    : array{baris:int, masuk:int, keluar:int}
 * $filters, $gudangOptions, $tipeOptions
 */
class StockLedgerController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $gudang = WarehouseScope::resolveFilter($request, $user);

        $tipe = $request->string('tipe')->toString();
        $tipe = isset(StockMovement::TYPE_LABELS[$tipe]) ? $tipe : null;

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'tipe' => $tipe,
            'dari' => FilterTanggal::bersih($request->query('dari')),
            'sampai' => FilterTanggal::bersih($request->query('sampai')),
            'warehouse_id' => $gudang,
        ];

        $dasar = fn () => WarehouseScope::apply(StockMovement::query(), $user)
            ->when($gudang, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($filters['tipe'], fn ($q, $t) => $q->where('movement_type', $t))
            ->when($filters['dari'], fn ($q, $t) => $q->whereDate('created_at', '>=', $t))
            ->when($filters['sampai'], fn ($q, $t) => $q->whereDate('created_at', '<=', $t))
            /*
             | Dicari di produk, batch, catatan — DAN di nomor dokumennya.
             | Yang membuka layar ini biasanya berangkat dari satu nomor
             | ("ke mana barang PO260901003 pergi"), jadi nomor itu harus
             | menjadi jalan masuk, bukan sesuatu yang baru terlihat setelah
             | membuka barisnya satu per satu.
             */
            ->when($filters['search'], function ($q, $cari) {
                $pola = '%'.str_replace('%', '\%', $cari).'%';

                return $q->where(fn ($w) => $w
                    ->where('batch_no', 'ILIKE', $pola)
                    ->orWhere('notes', 'ILIKE', $pola)
                    ->orWhereHas('product', fn ($p) => $p
                        ->where('sku', 'ILIKE', $pola)->orWhere('name', 'ILIKE', $pola))
                    ->orWhere(fn ($d) => $this->cocokkanNomorDokumen($d, $pola)));
            });

        $halaman = $dasar()
            ->with([
                'product:id,sku,name,uom',
                'warehouse:id,code,name',
                'location:id,code,zone',
                'user:id,full_name',
            ])
            // Terbaru di atas: rekonsiliasi hampir selalu berangkat dari yang
            // terakhir dan berjalan mundur.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('wms.inventory.kartu-stok', [
            'halaman' => $halaman,
            'dokumen' => $this->dokumen(collect($halaman->items())),
            'filters' => $filters,
            'gudangOptions' => WarehouseScope::options($user),
            'tipeOptions' => StockMovement::TYPE_LABELS,
            'stats' => [
                'baris' => (clone $dasar())->count(),
                'masuk' => (int) (clone $dasar())->where('qty_change', '>', 0)->sum('qty_change'),
                'keluar' => (int) (clone $dasar())->where('qty_change', '<', 0)->sum('qty_change'),
            ],
        ]);
    }

    /**
     * Nomor dokumen dan lawan transaksinya untuk baris yang SEDANG tampil.
     *
     * DIKUMPULKAN PER JENIS, bukan per baris. Ledger menyimpan acuannya sebagai
     * (reference_type, reference_id) — bukan relasi Eloquent — jadi tanpa ini
     * setiap baris berarti satu query sendiri, tiga puluh kali per halaman.
     * Yang dibaca hanya halaman ini saja, karena hanya itu yang terlihat.
     *
     * "Pihak" sengaja ikut diambil: nomor dokumen saja belum menjawab untuk
     * SIAPA barangnya bergerak, dan pertanyaan itulah yang paling sering
     * menyusul begitu satu baris mencurigakan ditemukan.
     *
     * @param  Collection<int, StockMovement>  $baris
     * @return array<string, array{kode:string, nomor:?string, pihak:?string}>
     */
    private function dokumen(Collection $baris): array
    {
        $idPerJenis = $baris
            ->groupBy('reference_type')
            ->map(fn (Collection $g) => $g->pluck('reference_id')->unique()->values()->all());

        $hasil = [];

        foreach ($idPerJenis as $jenis => $idList) {
            $kelas = JenisTransaksi::ACUAN_LEDGER[$jenis] ?? null;

            if ($kelas === null) {
                continue;
            }

            foreach ($this->bacaDokumen($kelas, $idList) as $id => $isi) {
                $hasil[$jenis.':'.$id] = [
                    'kode' => JenisTransaksi::kode($kelas),
                    'nomor' => $isi['nomor'],
                    'pihak' => $isi['pihak'],
                ];
            }
        }

        return $hasil;
    }

    /**
     * Satu query per jenis dokumen, berikut lawan transaksinya.
     *
     * @param  class-string  $kelas
     * @param  array<int, int>  $idList
     * @return array<int, array{nomor:?string, pihak:?string}>
     */
    private function bacaDokumen(string $kelas, array $idList): array
    {
        $kolom = JenisTransaksi::untuk($kelas)['kolom'];

        // Lawan transaksinya berbeda-beda bentuknya, dan memang harus begitu:
        // pesanan pergi ke pelanggan, transfer ke gudang lain, MRF ke divisi
        // peminta. Satu kolom "tujuan" yang dipaksakan akan salah di tiganya.
        $relasi = match ($kelas) {
            SalesOrder::class, SalesReturn::class => ['customer:id,name'],
            StockTransfer::class => ['toWarehouse:id,code,name'],
            StockBooking::class => ['customer:id,name'],
            default => [],
        };

        $dokumen = $kelas::query()->whereKey($idList)->with($relasi)->get();

        $hasil = [];

        foreach ($dokumen as $satu) {
            $hasil[$satu->getKey()] = [
                'nomor' => $kolom !== null ? $satu->getAttribute($kolom) : null,
                'pihak' => match ($kelas) {
                    SalesOrder::class, SalesReturn::class, StockBooking::class => $satu->customer?->name,
                    StockTransfer::class => $satu->toWarehouse?->code,
                    MaterialRequisition::class => $satu->department_name,
                    InboundHeader::class => null,
                    default => null,
                },
            ];
        }

        return $hasil;
    }

    /**
     * Mencocokkan kata kunci ke NOMOR dokumen penyebab mutasinya.
     *
     * Ledger tidak menyimpan nomor dokumen, hanya (reference_type,
     * reference_id) — jadi nomornya dicari dulu di tabel dokumennya, lalu
     * id-nya yang dipakai menyaring. Tanpa ini, mengetik "PO260901003" di
     * kotak pencarian tidak menemukan apa pun, padahal justru dari nomor
     * itulah penelusuran hampir selalu berangkat.
     *
     * Dibatasi 500 dokumen per jenis: kata kunci sependek "26" bisa cocok
     * dengan hampir seluruh tabel, dan daftar id sepanjang itu memberatkan
     * query tanpa menolong siapa pun — yang mencari begitu memang belum tahu
     * apa yang dicarinya.
     */
    private function cocokkanNomorDokumen(Builder $query, string $pola): void
    {
        foreach (JenisTransaksi::ACUAN_LEDGER as $jenis => $kelas) {
            if ($kelas === null) {
                continue;
            }

            $kolom = JenisTransaksi::untuk($kelas)['kolom'];

            if ($kolom === null) {
                continue;
            }

            $id = $kelas::query()->where($kolom, 'ILIKE', $pola)->limit(500)->pluck('id');

            if ($id->isEmpty()) {
                continue;
            }

            $query->orWhere(fn (Builder $w) => $w
                ->where('reference_type', $jenis)
                ->whereIn('reference_id', $id));
        }
    }
}
