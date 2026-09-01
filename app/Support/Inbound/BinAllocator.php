<?php

namespace App\Support\Inbound;

use App\Models\InboundDetail;
use App\Models\Location;
use Illuminate\Support\Collection;

/**
 * Aturan penempatan palet ke bin rak — SATU-SATUNYA sumber kebenarannya.
 *
 * Dipakai bersama oleh dua layar yang harus selalu sepakat:
 *   1. Put-away (F-INB-02) — Operator menempatkan palet pertama kali.
 *   2. Verifikasi (F-INB-03) — Logistik boleh MEMINDAHKAN palet saat
 *      pengecekan fisik menemukan lokasinya tidak sesuai.
 *
 * Kalau aturannya disalin ke dua tempat, keduanya akan diam-diam berbeda
 * begitu salah satunya disesuaikan — dan bedanya baru ketahuan sebagai stok
 * yang tidak cocok dengan rak fisiknya.
 *
 * ATURANNYA: satu bin adalah satu SLOT PALET. Boleh memuat beberapa palet
 * dari SKU yang SAMA sampai kapasitas palet SKU itu (Product::
 * max_qty_per_pallet) — pallet split (PRD §7.1) boleh digabung kembali di
 * bin yang sama. SKU yang BERBEDA tidak boleh berbagi bin.
 */
class BinAllocator
{
    /**
     * Akumulasi DALAM SATU pengiriman formulir: location_id =>
     * ['product_id' => int, 'qty' => int].
     *
     * Tanpa ini, dua palet SKU sama yang menunjuk bin sama pada formulir yang
     * sama akan dicek sendiri-sendiri terhadap kapasitas dan lolos, padahal
     * gabungannya melebihi.
     *
     * `qty` HANYA menjumlahkan kiriman formulir — isi bin dari database
     * TIDAK ikut ditumpuk di sini. Kalau ikut, isi lama akan terhitung
     * berulang setiap kali ada palet berikutnya menunjuk bin yang sama.
     *
     * @var array<int, array{product_id: int, qty: int}>
     */
    private array $dalamPengiriman = [];

    /**
     * @param  Collection<string, Location>  $bins  kode HURUF BESAR => Location
     * @param  Collection<int, array{product_id: int, per_detail: Collection<int, int>}>  $penghuni
     */
    public function __construct(
        private readonly Collection $bins,
        private readonly Collection $penghuni,
        private readonly ?string $warehouseCode = null,
    ) {}

    public static function forWarehouse(int $warehouseId, ?string $warehouseCode = null): self
    {
        $bins = Location::where('warehouse_id', $warehouseId)
            ->active()
            ->get(['id', 'code'])
            ->keyBy(fn (Location $l) => strtoupper($l->code));

        // Isi bin SAAT INI di database — dari dokumen MANAPUN, termasuk palet
        // lain milik dokumen yang sedang diproses. Kontribusi tiap palet
        // disimpan terpisah supaya palet yang sedang disunting bisa
        // dikeluarkan dari hitungan tanpa ikut menyembunyikan palet lain di
        // bin yang sama.
        $penghuni = InboundDetail::whereNotNull('location_id')
            ->whereIn('location_id', $bins->pluck('id'))
            ->get(['id', 'location_id', 'product_id', 'qty_actual', 'pallet_qty'])
            ->groupBy('location_id')
            ->map(fn ($group) => [
                'product_id' => $group->first()->product_id,
                'per_detail' => $group->keyBy('id')->map(fn (InboundDetail $d) => $d->effective_qty),
            ]);

        return new self($bins, $penghuni, $warehouseCode);
    }

    /**
     * Melepas palet-palet ini dari isi bin yang tercatat di database.
     *
     * WAJIB dipanggil di muka untuk SELURUH palet yang akan ditulis pada
     * pengiriman ini, sebelum place() yang pertama.
     *
     * Alasannya: palet yang sedang disimpan ulang sudah menghuni sebuah bin
     * di database. Kalau tidak dilepas, jumlahnya terhitung dua kali —
     * sekali dari database, sekali dari nilai yang sedang dikirim — sehingga
     * konsolidasi yang sah (mis. palet 100 dan 50 ke satu bin berkapasitas
     * 180) ditolak dengan pesan "sudah terisi 200".
     *
     * Melepas SELURUH kandidat di muka juga membuat hasilnya tidak lagi
     * bergantung urutan baris pada formulir.
     *
     * @param  list<int>  $detailIds
     */
    public function release(array $detailIds): void
    {
        if ($detailIds === []) {
            return;
        }

        foreach ($this->penghuni as $locationId => $isi) {
            $sisa = $isi['per_detail']->except($detailIds);

            if ($sisa->count() === $isi['per_detail']->count()) {
                continue;
            }

            // Bin yang penghuninya habis terlepas menjadi benar-benar kosong,
            // termasuk kehilangan penanda produknya — supaya SKU lain boleh
            // masuk ke bin yang baru saja dikosongkan.
            if ($sisa->isEmpty()) {
                unset($this->penghuni[$locationId]);

                continue;
            }

            $this->penghuni[$locationId] = ['product_id' => $isi['product_id'], 'per_detail' => $sisa];
        }
    }

    public function has(string $code): bool
    {
        return $this->bins->has($this->normalize($code));
    }

    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function unknownCodeMessage(string $code): string
    {
        return sprintf(
            'Kode lokasi "%s" tidak ada atau tidak aktif di gudang %s.',
            $this->normalize($code),
            $this->warehouseCode ?? '—'
        );
    }

    /**
     * Isi tiap bin siap tampil: kode => {product_id, qty, capacity, uom}.
     *
     * Dipakai layar put-away & verifikasi untuk menandai bin yang sudah
     * terisi. Dikunci dengan KODE, bukan id, karena Operator/Logistik
     * mengetik kode rak — pencocokan di layar jadi langsung.
     *
     * @param  Collection<int, Location>  $locations  bin yang ingin dilaporkan
     * @return array<string, array{product_id: int, qty: int, capacity: ?int, uom: ?string}>
     */
    public static function occupancyByCode(Collection $locations): array
    {
        $idKeKode = $locations->pluck('code', 'id');

        return InboundDetail::query()
            ->whereIn('location_id', $locations->pluck('id'))
            ->with('product:id,uom,max_qty_per_pallet')
            ->get(['id', 'location_id', 'product_id', 'qty_actual', 'pallet_qty'])
            ->groupBy('location_id')
            ->mapWithKeys(function ($group) use ($idKeKode) {
                $produk = $group->first()->product;

                return [$idKeKode[$group->first()->location_id] => [
                    'product_id' => $group->first()->product_id,
                    'qty' => $group->sum(fn (InboundDetail $d) => $d->effective_qty),
                    'capacity' => $produk?->max_qty_per_pallet,
                    'uom' => $produk?->uom,
                ]];
            })
            ->all();
    }

    /**
     * Mencoba menempatkan satu palet ke bin `$code` dengan jumlah `$qty`.
     *
     * Berhasil: mencatat pemakaiannya (sehingga palet berikutnya pada
     * formulir yang sama ikut memperhitungkannya) lalu mengembalikan
     * ['location_id' => int]. Gagal: ['error' => string] tanpa mencatat
     * apa pun.
     *
     * @return array{location_id: int}|array{error: string}
     */
    public function place(InboundDetail $detail, string $code, int $qty): array
    {
        $code = $this->normalize($code);

        if (! $this->bins->has($code)) {
            return ['error' => $this->unknownCodeMessage($code)];
        }

        $locationId = $this->bins->get($code)->id;
        $kapasitas = $detail->product?->max_qty_per_pallet;

        $terpakai = 0;
        $produkLain = null;

        if ($sudahAda = $this->penghuni->get($locationId)) {
            // Kontribusi palet ini SENDIRI dikeluarkan dulu — kalau dialah
            // satu-satunya penghuni bin itu (menyimpan ulang ke bin miliknya
            // sendiri), bin dianggap kosong untuk pengecekan ini, bukan
            // "sudah terisi dirinya sendiri".
            $lainnya = $sudahAda['per_detail']->except([$detail->id]);

            if ($lainnya->isNotEmpty()) {
                $produkLain = $sudahAda['product_id'];
                $terpakai = $lainnya->sum();
            }
        }

        if ($dalamPengiriman = $this->dalamPengiriman[$locationId] ?? null) {
            $produkLain ??= $dalamPengiriman['product_id'];
            $terpakai += $dalamPengiriman['qty'];
        }

        if ($produkLain !== null && $produkLain !== $detail->product_id) {
            return ['error' => "Rak \"{$code}\" sudah terisi produk lain."];
        }

        if ($kapasitas === null) {
            // Kapasitas palet produk ini belum diisi di Master Produk — tanpa
            // angka itu sisa ruangnya tidak bisa dipastikan, jadi bin yang
            // sudah terisi APAPUN ditolak sebagai jaring pengaman.
            if ($terpakai > 0) {
                return ['error' => "Rak \"{$code}\" sudah terisi, dan kapasitas palet produk ini belum diisi di Master Produk."];
            }
        } elseif (($terpakai + $qty) > $kapasitas) {
            return ['error' => sprintf(
                'Rak "%s" kelebihan kapasitas: sudah terisi %d, ditambah %d akan melebihi kapasitas %d.',
                $code,
                $terpakai,
                $qty,
                $kapasitas
            )];
        }

        // HANYA kiriman formulir yang ditumpuk di sini; isi dari database
        // dibaca ulang tiap kali dari $penghuni. Menumpuk keduanya membuat
        // isi lama terhitung berulang untuk tiap palet berikutnya.
        $this->dalamPengiriman[$locationId] = [
            'product_id' => $detail->product_id,
            'qty' => ($this->dalamPengiriman[$locationId]['qty'] ?? 0) + $qty,
        ];

        return ['location_id' => $locationId];
    }
}
