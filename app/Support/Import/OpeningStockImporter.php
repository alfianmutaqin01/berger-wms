<?php

namespace App\Support\Import;

use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Outbound\PendingAllocationFiller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Impor Stok Awal — memasukkan isi gudang yang sudah berjalan ke sistem baru.
 *
 * Kolom yang dikenali:
 *   SKU | Batch | Tanggal Produksi | Qty | Lokasi
 *
 * MENGAPA IMPOR, BUKAN DIKETIK SATU PER SATU
 * ------------------------------------------
 * Gudang Berger sudah berisi ribuan palet saat sistem ini dipasang.
 * Mengetiknya lewat layar Penyesuaian Stok tidak realistis dan pasti banyak
 * salah ketik. Ini pekerjaan sekali jalan per gudang.
 *
 * BATCH DAN TANGGAL PRODUKSI WAJIB — tidak ada kelonggaran "stok lama".
 * Keputusan pemilik produk: semuanya diisi sejak pengisian database awal
 * supaya kedaluwarsa terdeteksi sejak hari pertama dan FIFO langsung benar.
 * Baris yang kosong DITOLAK, bukan diisi taksiran.
 *
 * IDEMPOTEN: qty DISAMAKAN dengan isi berkas, bukan ditambahkan. Berkas
 * dianggap kebenaran, sehingga mengimpor berkas yang sama dua kali
 * menghasilkan angka yang sama. Kalau ditambahkan, satu impor ulang yang
 * tidak disengaja melipatgandakan stok seluruh gudang tanpa tanda apa pun,
 * dan baru ketahuan saat stock opname berikutnya.
 */
class OpeningStockImporter extends Importer
{
    /**
     * @param  ?int  $warehouseId  Gudang yang menjadi batas kewenangan
     *                             pengimpor; NULL berarti lintas gudang
     *                             (Super Admin).
     */
    public function __construct(
        ?int $actorId = null,
        private ?PendingAllocationFiller $pengisi = null,
        private readonly ?int $warehouseId = null,
    ) {
        parent::__construct($actorId);

        $this->pengisi = $pengisi ?? app(PendingAllocationFiller::class);
    }

    /** @var array<string, Product> */
    private array $produkCache = [];

    /** @var array<string, Location> */
    private array $lokasiCache = [];

    /** Total unit yang tersedot ke pesanan yang menunggu selama impor. */
    private int $terisiKePesanan = 0;

    protected function requiredHeaders(): array
    {
        return ['sku'];
    }

    protected function keyColumn(): string
    {
        return 'sku';
    }

    protected function table(): string
    {
        return 'inventory_stocks';
    }

    protected function columnLabels(): array
    {
        return [
            'batch_no' => 'Batch',
        ];
    }

    /**
     * Kunci baris adalah GABUNGAN empat kolom, bukan satu.
     *
     * Satu SKU sah muncul berkali-kali di berkas: batch berbeda, rak berbeda,
     * tanggal produksi berbeda. Yang tidak boleh kembar adalah kombinasi
     * keempatnya — itulah definisi "baris stok yang sama" di seluruh sistem
     * (lihat StockActivator).
     */
    protected function existingKeys(): array
    {
        // Ekspresinya WAJIB diberi alias lalu dipetik lewat nama alias itu.
        // Tanpa alias, Postgres menamai kolomnya "?column?" dan pemetikan
        // gagal — yang muncul di layar bukan pesan yang menjelaskan, melainkan
        // seluruh pratinjau impor berbalik jadi redirect galat.
        return InventoryStock::query()
            ->join('products', 'products.id', '=', 'inventory_stocks.product_id')
            ->join('locations', 'locations.id', '=', 'inventory_stocks.location_id')
            ->selectRaw("UPPER(products.sku) || '|' || UPPER(inventory_stocks.batch_no) || '|' ".
                "|| UPPER(locations.code) || '|' || inventory_stocks.production_date AS kunci")
            ->pluck('kunci')
            ->all();
    }

    protected function mapRow(array $row): ?array
    {
        $sku = $this->upper($this->value($row, ['sku', 'no', 'kode_produk', 'product']));
        $batch = $this->upper($this->value($row, ['batch', 'batch_no', 'no_batch', 'nomor_batch']));
        $lokasi = $this->upper($this->value($row, ['lokasi', 'location', 'location_code', 'kode_lokasi', 'rak']));
        $qtyMentah = $this->value($row, ['qty', 'jumlah', 'quantity', 'stok']);
        $tanggalMentah = $this->value($row, ['tanggal_produksi', 'production_date', 'tgl_produksi', 'produksi']);

        if (blank($sku)) {
            $this->fail('Kolom SKU kosong.');

            return null;
        }

        if (blank($batch)) {
            $this->fail('Kolom Batch kosong. Nomor batch wajib — tanpa itu FIFO dan kedaluwarsa tidak bisa dihitung.');

            return null;
        }

        if (blank($tanggalMentah)) {
            $this->fail('Kolom Tanggal Produksi kosong. Wajib diisi — tanggal kedaluwarsa dihitung dari sini.');

            return null;
        }

        if (blank($lokasi)) {
            $this->fail('Kolom Lokasi kosong.');

            return null;
        }

        $tanggal = $this->tanggal($tanggalMentah);

        if ($tanggal === null) {
            $this->fail("Tanggal Produksi \"{$tanggalMentah}\" tidak terbaca. Pakai format YYYY-MM-DD atau DD/MM/YYYY.");

            return null;
        }

        if ($tanggal->isFuture()) {
            // Batch bertanggal masa depan selalu tampak paling muda, sehingga
            // FIFO tidak akan pernah mengeluarkannya — dan tanggal
            // kedaluwarsanya ikut meleset ke depan.
            $this->fail('Tanggal Produksi ada di masa depan.');

            return null;
        }

        $qty = filter_var($qtyMentah, FILTER_VALIDATE_INT);

        if ($qty === false || $qty < 0) {
            $this->fail("Qty \"{$qtyMentah}\" bukan bilangan bulat yang sah.");

            return null;
        }

        $produk = $this->produk($sku);

        if ($produk === null) {
            $this->fail("SKU {$sku} tidak ada di Master Produk, atau produknya tidak aktif.");

            return null;
        }

        $rak = $this->lokasi($lokasi);

        if ($rak === null) {
            // Lokasi TIDAK dibuat otomatis. Master Lokasi sudah lengkap
            // (2.264 baris), jadi kode yang tidak dikenal hampir pasti salah
            // ketik — membuatnya otomatis berarti melahirkan rak hantu yang
            // tidak ada wujudnya di gudang.
            $this->fail("Lokasi {$lokasi} tidak ada di Master Lokasi, atau lokasinya tidak aktif.");

            return null;
        }

        // Gudangnya ditentukan RAK, bukan kolom tersendiri di berkas. Satu
        // berkas yang tanpa sengaja memuat kode rak gudang lain akan menulis
        // stok ke gudang itu tanpa ada yang menyadarinya — pesannya dibuat
        // menyebut nama gudang supaya salahnya langsung kelihatan.
        if ($this->warehouseId !== null && $rak->warehouse_id !== $this->warehouseId) {
            $this->fail("Lokasi {$lokasi} milik gudang {$rak->warehouse?->name}, di luar kewenangan akun Anda.");

            return null;
        }

        // Diperiksa SEBELUM menyimpan, supaya ketahuan di pratinjau — itulah
        // gunanya pratinjau. Menurunkan qty di bawah yang sudah dijanjikan ke
        // pesanan membuat pesanan yang sudah diterima kehilangan barangnya,
        // dan CHECK (qty_available >= 0) tidak menangkapnya karena angkanya
        // masih positif. persist() memeriksanya sekali lagi di dalam kunci.
        $terikat = $this->qtyTeralokasi($produk->id, $rak->id, $batch, $tanggal->toDateString());

        if ($qty < $terikat) {
            $this->fail("Qty {$qty} di bawah {$terikat} yang sudah dialokasikan untuk pesanan.");

            return null;
        }

        return [
            'key' => $sku.'|'.$batch.'|'.$lokasi.'|'.$tanggal->toDateString(),
            'label' => $produk->name,
            'data' => [
                'product_id' => $produk->id,
                'location_id' => $rak->id,
                'warehouse_id' => $rak->warehouse_id,
                'batch_no' => $batch,
                'qty_available' => $qty,
                'production_date' => $tanggal->toDateString(),
                // Aturan kedaluwarsa yang SAMA dengan jalur inbound (§7.2.1).
                'expiry_date' => InventoryStock::calculateExpiry($tanggal, $produk->shelf_life_months)->toDateString(),
                'status' => InventoryStock::STATUS_ACTIVE,
            ],
        ];
    }

    protected function persist(string $key, array $data): bool
    {
        return DB::transaction(function () use ($data) {
            $stock = InventoryStock::query()
                ->where('product_id', $data['product_id'])
                ->where('location_id', $data['location_id'])
                ->where('batch_no', $data['batch_no'])
                ->whereDate('production_date', $data['production_date'])
                ->lockForUpdate()
                ->first();

            $baru = $stock === null;
            $sebelum = $stock?->qty_available ?? 0;
            $qty = (int) $data['qty_available'];

            if ($baru) {
                $stock = new InventoryStock($data);
            } else {
                // Qty DISAMAKAN dengan berkas, bukan ditambahkan — lihat
                // catatan idempoten di kepala kelas ini.
                if ($qty < $stock->qty_allocated) {
                    // Sudah diperiksa di mapRow(), diperiksa lagi DI DALAM
                    // kunci: alokasi bisa bertambah antara pratinjau dan
                    // impor. RowRejected, bukan RuntimeException biasa —
                    // yang gagal cuma baris ini, sisa berkas tetap jalan.
                    throw new RowRejected(sprintf(
                        'qty %d di bawah %d yang sudah dialokasikan untuk pesanan',
                        $qty,
                        $stock->qty_allocated
                    ));
                }

                $stock->qty_available = $qty;
            }

            $stock->verified_by = $this->actorId;
            $stock->verified_at = now();
            $stock->save();

            $selisih = $qty - $sebelum;

            if ($selisih !== 0) {
                StockMovement::create([
                    'product_id' => $stock->product_id,
                    'location_id' => $stock->location_id,
                    'warehouse_id' => $stock->warehouse_id,
                    'movement_type' => StockMovement::TYPE_ADJUSTMENT,
                    'qty_change' => $selisih,
                    'qty_before' => $sebelum,
                    'qty_after' => $qty,
                    'reference_type' => StockMovement::REF_ADJUSTMENT,
                    'reference_id' => $stock->id,
                    'batch_no' => $stock->batch_no,
                    'notes' => 'Impor Stok Awal.',
                    'user_id' => $this->actorId,
                ]);
            }

            if ($selisih > 0) {
                $hasil = $this->pengisi->fill($stock->product_id, $stock->warehouse_id, $this->actorId);
                $this->terisiKePesanan += $hasil['terisi'];
            }

            return $baru;
        });
    }

    /** Jumlah unit yang langsung tersedot ke pesanan yang menunggu stok. */
    public function terisiKePesanan(): int
    {
        return $this->terisiKePesanan;
    }

    /** Qty baris stok ini yang sudah dijanjikan ke pesanan. */
    private function qtyTeralokasi(int $productId, int $locationId, string $batch, string $tanggal): int
    {
        return (int) InventoryStock::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->where('batch_no', $batch)
            ->whereDate('production_date', $tanggal)
            ->value('qty_allocated');
    }

    private function produk(string $sku): ?Product
    {
        return $this->produkCache[$sku] ??= Product::where('is_active', true)
            ->whereRaw('UPPER(sku) = ?', [$sku])
            ->first();
    }

    private function lokasi(string $code): ?Location
    {
        return $this->lokasiCache[$code] ??= Location::active()
            ->with('warehouse:id,name')
            ->whereRaw('UPPER(code) = ?', [$code])
            ->first();
    }

    private function upper(?string $value): ?string
    {
        return blank($value) ? null : strtoupper(trim($value));
    }

    /**
     * Membaca tanggal dari sel Excel.
     *
     * Excel menyimpan tanggal sebagai ANGKA SERIAL (hari sejak 1899-12-30),
     * dan pembaca spreadsheet mengembalikannya apa adanya bila selnya tidak
     * berformat tanggal. "45000" harus dibaca sebagai tanggal, bukan ditolak
     * — kalau ditolak, pengguna melihat "tanggal tidak terbaca" pada sel yang
     * di layar Excel tampak seperti tanggal biasa.
     */
    private function tanggal(string $mentah): ?Carbon
    {
        $mentah = trim($mentah);

        if (preg_match('/^\d{5}(\.\d+)?$/', $mentah)) {
            return Carbon::create(1899, 12, 30)->addDays((int) (float) $mentah);
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            $tanggal = Carbon::createFromFormat($format, $mentah);

            if ($tanggal !== false && $tanggal->format($format) === $mentah) {
                return $tanggal->startOfDay();
            }
        }

        try {
            return Carbon::parse($mentah)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
