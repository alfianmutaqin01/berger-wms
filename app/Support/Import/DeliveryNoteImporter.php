<?php

namespace App\Support\Import;

use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Product;
use App\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Impor Surat Jalan dari sistem BC — PRD §6.5 F-OUT-04, Fase 6 tahap 4.
 *
 * Kolom yang dikenali (judul apa adanya dari ekspor BC):
 *   Document No. | SO Number | Sell-to Customer No. | No. | Description
 *   Location Code | Quantity | Unit of Measure Code | Shipment Date
 *   Quantity Invoiced
 *
 * MENGAPA IMPOR, BUKAN DIKETIK — DAN MENGAPA BUKAN KAMI YANG MENERBITKAN
 * ---------------------------------------------------------------------
 * Surat Jalan resmi diterbitkan sistem BC (keputusan pemilik produk). Sistem
 * ini tidak mencetak dokumen apa pun; ia menyalin dokumen yang sudah terbit
 * supaya bisa dicocokkan dengan apa yang benar-benar diambil dari rak.
 *
 * Logistik mengunggah ekspor per hari atau per container yang akan berangkat
 * — bukan satu berkas per transaksi. Berkasnya dibuang setelah dibaca; yang
 * tersimpan hanya datanya.
 *
 * SATU BARIS BERKAS = SATU BARIS BARANG, BUKAN SATU DOKUMEN. Satu Document
 * No. lazim memuat beberapa SKU, jadi header dokumennya dibuat saat baris
 * pertamanya ditemui lalu dipakai bersama baris berikutnya.
 *
 * BERKAS ADALAH KEBENARAN, SAMPAI SEBATAS DOKUMEN YANG DISEBUTNYA. Saat satu
 * dokumen pertama kali disentuh dalam satu impor, seluruh barisnya yang lama
 * DIHAPUS lebih dulu. Kalau tidak, baris yang dicabut di BC akan tetap hidup
 * di sini selamanya — dan yang paling berbahaya bukan barisnya, melainkan
 * qty-nya yang ikut dihitung saat pencocokan. Dokumen yang TIDAK disebut
 * berkas tidak disentuh sama sekali.
 */
class DeliveryNoteImporter extends Importer
{
    /**
     * @param  ?int  $warehouseId  Batas kewenangan pengimpor; NULL berarti
     *                             lintas gudang (Super Admin).
     */
    public function __construct(?int $actorId = null, private readonly ?int $warehouseId = null)
    {
        parent::__construct($actorId);
    }

    /** @var array<string, ?Product> */
    private array $produkCache = [];

    /** @var array<string, bool> Dokumen yang barisnya sudah dibersihkan di impor ini. */
    private array $sudahDibersihkan = [];

    /** Dokumen yang tidak menemukan pesanannya, untuk dilaporkan di akhir. */
    private array $tanpaPasangan = [];

    /**
     * SKU yang ada di SJ tetapi tidak ada dalam pesanannya, per dokumen.
     *
     * @var array<string, array<string, true>>
     */
    private array $skuAsing = [];

    /** @var array<int, array<int, true>> product_id milik tiap pesanan */
    private array $produkPesananCache = [];

    protected function requiredHeaders(): array
    {
        return ['document_no'];
    }

    protected function keyColumn(): string
    {
        return 'document_no';
    }

    protected function table(): string
    {
        return 'delivery_note_lines';
    }

    protected function columnLabels(): array
    {
        return [
            'sku' => 'No. (SKU)',
            'description' => 'Description',
        ];
    }

    /**
     * Kunci baris adalah GABUNGAN dokumen dan SKU.
     *
     * Bukan Document No. saja: satu dokumen sah memuat beberapa SKU, dan
     * memakai nomor dokumen sebagai kunci akan membuat seluruh baris kedua
     * dan seterusnya terbaca sebagai "kembar" di pratinjau.
     */
    protected function existingKeys(): array
    {
        return DeliveryNoteLine::query()
            ->join('delivery_notes', 'delivery_notes.id', '=', 'delivery_note_lines.delivery_note_id')
            ->selectRaw("UPPER(delivery_notes.document_no) || '|' || UPPER(delivery_note_lines.sku) AS kunci")
            ->pluck('kunci')
            ->all();
    }

    protected function mapRow(array $row): ?array
    {
        $dokumen = $this->upper($this->value($row, ['document_no', 'dokumen', 'no_dokumen']));
        $nomorSo = $this->upper($this->value($row, ['so_number', 'no_so', 'nomor_so']));
        $sku = $this->upper($this->value($row, ['no', 'sku', 'item_no', 'kode_produk']));

        if (blank($dokumen)) {
            $this->fail('Kolom Document No. kosong — tanpa nomor dokumen, baris ini bukan bagian Surat Jalan mana pun.');

            return null;
        }

        if (blank($nomorSo)) {
            $this->fail("Kolom SO Number kosong pada dokumen {$dokumen}. Nomor SO adalah satu-satunya jembatan ke pesanan di sistem ini.");

            return null;
        }

        if (blank($sku)) {
            $this->fail("Kolom No. (SKU) kosong pada dokumen {$dokumen}.");

            return null;
        }

        $qty = $this->qtyBulat($this->value($row, ['quantity', 'qty', 'jumlah']));

        if ($qty === null) {
            $mentah = $this->value($row, ['quantity', 'qty', 'jumlah']) ?? '';
            $this->fail("Qty \"{$mentah}\" pada dokumen {$dokumen} bukan bilangan bulat positif.");

            return null;
        }

        // Pesanannya dicari SEKARANG, bukan nanti saat mau kirim: kalau nomor
        // SO-nya tidak berpasangan, yang perlu tahu adalah orang yang sedang
        // memegang berkasnya — bukan orang lain, tiga hari kemudian, saat
        // container sudah menunggu di halaman.
        $pesanan = $this->pesanan($nomorSo);

        if ($pesanan !== null && $this->warehouseId !== null && $pesanan->warehouse_id !== $this->warehouseId) {
            // Ekspor harian BC memuat SJ seluruh perusahaan. Baris milik
            // gudang lain ditolak dengan menyebut gudangnya, bukan disimpan
            // diam-diam ke data yang tidak bisa dilihat pengimpornya.
            $this->fail(sprintf(
                'Dokumen %s (SO %s) milik gudang %s, di luar kewenangan akun Anda.',
                $dokumen,
                $nomorSo,
                $pesanan->warehouse?->name ?? '—'
            ));

            return null;
        }

        if ($pesanan === null) {
            $this->tanpaPasangan[$dokumen] = $nomorSo;
        } elseif (! $this->adaDalamPesanan($pesanan, $sku)) {
            $this->skuAsing[$dokumen][$sku] = true;
        }

        $kodeCustomer = $this->upper($this->value($row, ['sell_to_customer_no', 'customer_no', 'kode_customer']));
        $tanggalMentah = $this->value($row, ['shipment_date', 'tanggal_kirim', 'tgl_kirim']);

        return [
            'key' => $dokumen.'|'.$sku,
            'label' => $this->value($row, ['description']) ?: $sku,
            'data' => [
                'document_no' => $dokumen,
                'bc_so_number' => $nomorSo,
                'sales_order_id' => $pesanan?->id,
                'warehouse_id' => $pesanan?->warehouse_id,
                'customer_code' => $kodeCustomer,
                'customer_id' => $this->customerId($kodeCustomer, $pesanan),
                'bc_location_code' => $this->upper($this->value($row, ['location_code', 'kode_lokasi'])),
                'shipment_date' => blank($tanggalMentah) ? null : $this->tanggal($tanggalMentah)?->toDateString(),

                'sku' => $sku,
                'product_id' => $this->produk($sku)?->id,
                // Deskripsi versi BC DISIMPAN tapi tidak menimpa nama produk
                // kami — aturan yang sama dengan penerimaan pesanan di tahap 1.
                'description' => $this->value($row, ['description']),
                'qty' => $qty,
                'qty_invoiced' => $this->qtyBulat($this->value($row, ['quantity_invoiced', 'qty_invoiced'])),
                'uom_code' => $this->upper($this->value($row, ['unit_of_measure_code', 'uom', 'satuan'])),
            ],
        ];
    }

    protected function persist(string $key, array $data): bool
    {
        return DB::transaction(function () use ($data) {
            $dokumen = DeliveryNote::query()
                ->lockForUpdate()
                ->firstOrNew(['document_no' => $data['document_no']]);

            $baruDokumen = ! $dokumen->exists;

            $dokumen->fill([
                'bc_so_number' => $data['bc_so_number'],
                'sales_order_id' => $data['sales_order_id'],
                'customer_code' => $data['customer_code'],
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $data['warehouse_id'],
                'bc_location_code' => $data['bc_location_code'],
                'shipment_date' => $data['shipment_date'],
                'imported_at' => now(),
                'imported_by' => $this->actorId,
            ]);

            // Status TIDAK ditimpa pada dokumen yang sudah ada: SJ yang
            // barangnya sudah berangkat tidak boleh mundur jadi "menunggu
            // berangkat" hanya karena berkasnya diunggah ulang.
            if ($baruDokumen) {
                $dokumen->status = DeliveryNote::STATUS_IMPORTED;
            }

            $dokumen->save();

            $this->bersihkanBarisLama($dokumen, $data['sku'], $baruDokumen);

            $baris = DeliveryNoteLine::updateOrCreate(
                ['delivery_note_id' => $dokumen->id, 'sku' => $data['sku']],
                [
                    'product_id' => $data['product_id'],
                    'description' => $data['description'],
                    'qty' => $data['qty'],
                    'qty_invoiced' => $data['qty_invoiced'],
                    'uom_code' => $data['uom_code'],
                ],
            );

            // "Baru" dihitung per BARIS BARANG, bukan per dokumen. Ringkasan
            // impor menghitung satu angka per baris berkas; melaporkan status
            // dokumen di situ membuat dokumen tiga SKU terbaca "1 baru,
            // 2 diperbarui" padahal ketiganya sama-sama baru.
            return $baris->wasRecentlyCreated;
        });
    }

    /**
     * Ringkasan tambahan untuk ditampilkan setelah impor selesai.
     *
     * SJ tanpa pasangan bukan kegagalan — ekspor BC memuat seluruh dokumen
     * perusahaan. Tetapi ia WAJIB dilaporkan: kalau sebuah SJ seharusnya
     * berpasangan dan ternyata tidak, artinya nomor SO di BC berbeda dari
     * yang diketik Logistik saat menerima pesanan — dan itu ketahuan di sini
     * atau tidak sama sekali.
     */
    public function catatanTambahan(): ?string
    {
        $catatan = array_filter([$this->catatanTanpaPasangan(), $this->catatanSkuAsing()]);

        return $catatan === [] ? null : implode(' ', $catatan);
    }

    private function catatanTanpaPasangan(): ?string
    {
        if ($this->tanpaPasangan === []) {
            return null;
        }

        $daftar = collect($this->tanpaPasangan)
            ->map(fn (string $so, string $dok) => "{$dok} (SO {$so})")
            ->take(10)
            ->implode(', ');

        $sisa = count($this->tanpaPasangan) - 10;

        return sprintf(
            '%d Surat Jalan belum menemukan pesanannya di sistem ini: %s%s. '.
            'Periksa nomor SO-nya — kalau seharusnya ada, berarti nomor yang diketik saat menerima pesanan berbeda dari BC.',
            count($this->tanpaPasangan),
            $daftar,
            $sisa > 0 ? ", dan {$sisa} lainnya" : '',
        );
    }

    /**
     * SKU yang ada di Surat Jalan tetapi tidak ada dalam pesanannya.
     *
     * PENCEGAHAN DI HULU. Keadaan ini menghentikan pengiriman di layar Surat
     * Jalan, tetapi kalau baru ketahuan di sana, orangnya sudah berdiri di
     * dermaga dengan kendaraan menunggu. Diberitahukan di sini, ia ketahuan
     * berjam-jam lebih awal — saat masih murah dibetulkan, entah di BC atau
     * di gudang.
     */
    private function catatanSkuAsing(): ?string
    {
        if ($this->skuAsing === []) {
            return null;
        }

        $daftar = collect($this->skuAsing)
            ->map(fn (array $sku, string $dok) => $dok.' → '.implode(', ', array_keys($sku)))
            ->take(10)
            ->implode('; ');

        return sprintf(
            'PERHATIAN: %d Surat Jalan memuat SKU yang TIDAK ADA dalam pesanannya (%s). '.
            'Ini bukan selisih jumlah melainkan barang yang berbeda, dan pengirimannya akan DITAHAN '.
            'sampai diputuskan: SKU di BC yang keliru, atau barang yang diambil dari rak yang keliru.',
            count($this->skuAsing),
            $daftar,
        );
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Menghapus baris lama satu dokumen, sekali saja per impor.
     *
     * Dipanggil sebelum baris pertama dokumen itu ditulis. Baris yang sedang
     * ditulis dikecualikan supaya tidak dihapus lalu dibuat lagi — bukan soal
     * kecepatan, melainkan supaya id barisnya tidak berganti tanpa sebab.
     */
    private function bersihkanBarisLama(DeliveryNote $dokumen, string $skuSekarang, bool $baruDibuat): void
    {
        if (isset($this->sudahDibersihkan[$dokumen->document_no])) {
            return;
        }

        $this->sudahDibersihkan[$dokumen->document_no] = true;

        if ($baruDibuat) {
            return;
        }

        $dokumen->lines()->where('sku', '<>', $skuSekarang)->delete();
    }

    /**
     * Pesanan pemegang nomor SO ini.
     *
     * whereNull(so_merged_into_id): pada penggabungan invoice, nomor SO
     * dipegang pesanan INDUK — pesanan tambahan menumpang nomor yang sama.
     * Tanpa penyaring ini, satu nomor SO bisa mengembalikan beberapa pesanan
     * dan yang terpilih tergantung urutan baris di database.
     */
    private function pesanan(string $nomorSo): ?SalesOrder
    {
        return SalesOrder::query()
            ->with('warehouse:id,name')
            ->whereRaw('UPPER(bc_so_number) = ?', [$nomorSo])
            ->whereNull('so_merged_into_id')
            ->first();
    }

    private function customerId(?string $kode, ?SalesOrder $pesanan): ?int
    {
        if ($pesanan !== null) {
            return $pesanan->customer_id;
        }

        if (blank($kode)) {
            return null;
        }

        return Customer::query()->whereRaw('UPPER(code) = ?', [$kode])->value('id');
    }

    /**
     * Apakah SKU ini termasuk yang dipesan?
     *
     * Produk yang tidak dikenal Master Produk sengaja TIDAK dihitung asing di
     * sini: masalahnya berbeda (data master belum lengkap) dan sudah punya
     * pesan sendiri saat pengiriman. Menggabungkan keduanya membuat pesan
     * impor menyalahkan hal yang salah.
     */
    private function adaDalamPesanan(SalesOrder $pesanan, string $sku): bool
    {
        $produkId = $this->produk($sku)?->id;

        if ($produkId === null) {
            return true;
        }

        $this->produkPesananCache[$pesanan->id] ??= $pesanan->details()
            ->pluck('product_id')
            ->flip()
            ->map(fn () => true)
            ->all();

        $dipesan = $this->produkPesananCache[$pesanan->id];

        // Pesanan tanpa rincian sama sekali tidak bisa dibandingkan. Menandai
        // SELURUH SKU-nya asing akan menenggelamkan peringatan ini di antara
        // baris yang tidak berarti apa-apa, dan peringatan yang selalu muncul
        // adalah peringatan yang berhenti dibaca.
        if ($dipesan === []) {
            return true;
        }

        return isset($dipesan[$produkId]);
    }

    private function produk(string $sku): ?Product
    {
        return $this->produkCache[$sku] ??= Product::query()
            ->whereRaw('UPPER(sku) = ?', [$sku])
            ->first();
    }

    /**
     * Qty dari ekspor BC: "1," dan "10," berarti 1 dan 10.
     *
     * Ekspor mereka memakai koma sebagai pemisah desimal dan MENYERTAKANNYA
     * meski tanpa angka di belakang. Pecahan ditolak, bukan dibulatkan: cat
     * dijual per kaleng, dan "2,5 pail" pada dokumen resmi adalah tanda
     * berkasnya salah, bukan angka yang perlu ditebak maksudnya.
     */
    private function qtyBulat(?string $mentah): ?int
    {
        $angka = $this->decimal($mentah);

        if ($angka === null) {
            return null;
        }

        $nilai = (float) $angka;

        if ($nilai <= 0 || floor($nilai) !== $nilai) {
            return null;
        }

        return (int) $nilai;
    }

    private function upper(?string $value): ?string
    {
        return blank($value) ? null : strtoupper(trim($value));
    }

    /**
     * Membaca tanggal dari sel Excel.
     *
     * Excel menyimpan tanggal sebagai ANGKA SERIAL (hari sejak 1899-12-30)
     * bila selnya tidak berformat tanggal. "45000" harus terbaca sebagai
     * tanggal, bukan ditolak — kalau ditolak, pengguna melihat "tanggal tidak
     * terbaca" pada sel yang di layar Excel tampak seperti tanggal biasa.
     */
    private function tanggal(string $mentah): ?Carbon
    {
        $mentah = trim($mentah);

        if (preg_match('/^\d{5}(\.\d+)?$/', $mentah)) {
            return Carbon::create(1899, 12, 30)->addDays((int) (float) $mentah);
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            // try/catch DI DALAM perulangan, bukan pemeriksaan `!== false`.
            // Carbon 3 MELEMPAR InvalidFormatException ketika untainya tidak
            // cocok — ia tidak lagi mengembalikan false seperti Carbon 2.
            // Tanpa ini, "31/08/2026" meledak pada percobaan format PERTAMA
            // (Y-m-d) dan tidak pernah sampai ke format yang benar.
            try {
                $tanggal = Carbon::createFromFormat($format, $mentah);

                if ($tanggal->format($format) === $mentah) {
                    return $tanggal->startOfDay();
                }
            } catch (\Throwable) {
                // Format berikutnya.
            }
        }

        try {
            return Carbon::parse($mentah)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
