<?php

namespace App\Support\Reporting;

use App\Models\DeliveryNote;
use App\Models\DeliveryProof;
use App\Models\InventoryStock;
use App\Models\SalesOrder;
use App\Models\SalesOrderDetail;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\ShelfLife;
use App\Support\WarehouseScope;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan satu laporan dan mengembalikan tabel siap pakai.
 *
 * SATU JALUR UNTUK LAYAR DAN UNTUK BERKAS
 * ---------------------------------------
 * Pratinjau di layar dan isi berkas Excel dihitung metode yang SAMA PERSIS;
 * yang berbeda hanya batas barisnya. Kalau keduanya punya query sendiri,
 * cepat atau lambat yang di layar dan yang di berkas akan berbeda — dan yang
 * ketahuan belakangan justru yang sudah terlanjur dikirim ke atasan.
 *
 * BENTUK KEMBALIANNYA SELALU SAMA
 * -------------------------------
 *   kolom  : list<string>                      judul kolom, urut
 *   baris  : list<list<scalar|null>>           nilai, urut sama dengan kolom
 *   total  : int                               jumlah baris SEBENARNYA
 *   angka  : list<int>                         indeks kolom yang numerik
 *
 * `total` dihitung terpisah dari `baris` supaya layar bisa berkata "1.240
 * baris, 25 ditampilkan" — pratinjau yang tidak menyebut jumlah sebenarnya
 * membuat orang mengira datanya memang cuma segitu.
 *
 * BATAS GUDANG BERLAKU DI SEMUA LAPORAN. Laporan adalah bentuk kebocoran
 * data yang paling mudah terjadi tanpa disadari: satu berkas Excel yang
 * terunduh sekali bisa beredar selamanya. Tidak ada satu pun query di sini
 * yang tidak lewat WarehouseScope.
 */
class ReportRunner
{
    /**
     * @param  array{dari?: ?string, sampai?: ?string, warehouse_id?: ?int}  $filter
     * @return array{kolom: list<string>, baris: list<array>, total: int, angka: list<int>}
     */
    public function jalankan(string $key, ?User $user, array $filter, int $batas): array
    {
        return match ($key) {
            'penjualan-selesai' => $this->penjualanSelesai($user, $filter, $batas),
            'pesanan-outstanding' => $this->pesananOutstanding($user, $filter, $batas),
            'produk-terlaris' => $this->produkTerlaris($user, $filter, $batas),
            'pelanggan-teratas' => $this->pelangganTeratas($user, $filter, $batas),
            'kinerja-sales' => $this->kinerjaSales($user, $filter, $batas),
            'pengiriman' => $this->pengiriman($user, $filter, $batas),
            'posisi-stok' => $this->posisiStok($user, $filter, $batas),
            'pergerakan-stok' => $this->pergerakanStok($user, $filter, $batas),
            default => ReportCatalog::ambil($key), // melempar InvalidArgumentException
        };
    }

    /* ==================================================== Alat bantu bersama */

    /**
     * Menyempitkan query ke gudang yang boleh DAN yang dipilih.
     *
     * Dua hal sekaligus dan urutannya penting: batas kewenangan dulu, baru
     * pilihan pengguna. Filter tidak pernah bisa melebarkan apa pun — nilai
     * `warehouse_id` di sini sudah dijepit WarehouseScope::resolveFilter di
     * controller, jadi memilih gudang lain hanya menghasilkan daftar kosong.
     */
    private function gudang(BuilderContract $q, ?User $user, array $filter, string $kolom = 'warehouse_id'): BuilderContract
    {
        $q = WarehouseScope::apply($q, $user, $kolom);

        return $q->when(
            filled($filter['warehouse_id'] ?? null),
            fn ($qq) => $qq->where($kolom, $filter['warehouse_id']),
        );
    }

    /**
     * Menyaring rentang tanggal pada satu kolom waktu.
     *
     * `sampai` dinaikkan ke AKHIR hari. Tanpa itu, memilih 1–30 September
     * akan membuang seluruh isi tanggal 30 — karena '2026-09-30' sebagai
     * timestamp berarti pukul 00:00, dan tidak ada yang terjadi pada detik
     * itu. Kesalahan sehari yang paling mudah tidak disadari, dan paling
     * sering baru ketahuan setelah laporannya dipakai rapat.
     */
    private function rentang(BuilderContract $q, array $filter, string $kolom): BuilderContract
    {
        return $q
            ->when(filled($filter['dari'] ?? null),
                fn ($qq) => $qq->where($kolom, '>=', Carbon::parse($filter['dari'])->startOfDay()))
            ->when(filled($filter['sampai'] ?? null),
                fn ($qq) => $qq->where($kolom, '<=', Carbon::parse($filter['sampai'])->endOfDay()));
    }

    private static function tanggal(mixed $nilai): ?string
    {
        return $nilai ? Carbon::parse($nilai)->format('d/m/Y') : null;
    }

    private static function waktu(mixed $nilai): ?string
    {
        return $nilai ? Carbon::parse($nilai)->format('d/m/Y H:i') : null;
    }

    /** Selisih jam antara dua waktu, dibulatkan satu desimal. */
    private static function jam(mixed $dari, mixed $sampai): ?float
    {
        if (! $dari || ! $sampai) {
            return null;
        }

        return round(Carbon::parse($dari)->diffInMinutes(Carbon::parse($sampai)) / 60, 1);
    }

    /**
     * Membungkus hasil menjadi bentuk baku.
     *
     * @param  list<string>  $kolom
     * @param  list<int>  $angka
     */
    private function tabel(array $kolom, array $baris, int $total, array $angka = []): array
    {
        return ['kolom' => $kolom, 'baris' => $baris, 'total' => $total, 'angka' => $angka];
    }

    /* ======================================================= 1. Finish Order */

    private function penjualanSelesai(?User $user, array $filter, int $batas): array
    {
        $q = $this->dasarPenjualanSelesai($user, $filter);

        $total = (clone $q)->count();

        $baris = (clone $q)
            ->with(['salesOrder.customer', 'salesOrder.user', 'salesOrder.warehouse', 'product'])
            ->orderByDesc(
                SalesOrder::query()->select('completed_at')->whereColumn('id', 'sales_order_details.sales_order_id')
            )
            ->limit($batas)
            ->get()
            ->map(function (SalesOrderDetail $d) {
                $o = $d->salesOrder;

                return [
                    $o?->order_number,
                    $o?->customer_po_number,
                    self::tanggal($o?->submitted_at),
                    self::tanggal($o?->completed_at),
                    self::jam($o?->submitted_at, $o?->completed_at),
                    $o?->customer?->code,
                    $o?->customer?->name,
                    $o?->user?->full_name,
                    $o?->warehouse?->code,
                    $d->product?->sku,
                    $d->product?->name,
                    $d->product?->uom,
                    (int) $d->qty_ordered,
                    (int) $d->qty_shipped,
                    (int) $d->outstanding_qty,
                ];
            })
            ->all();

        return $this->tabel([
            'No Pesanan', 'No PO Customer', 'Tgl Masuk', 'Tgl Selesai', 'Lama (jam)',
            'Kode Pelanggan', 'Pelanggan', 'Sales', 'Gudang',
            'SKU', 'Produk', 'Satuan', 'Qty Dipesan', 'Qty Terkirim', 'Outstanding',
        ], $baris, $total, [4, 12, 13, 14]);
    }

    private function dasarPenjualanSelesai(?User $user, array $filter): BuilderContract
    {
        return SalesOrderDetail::query()->whereHas('salesOrder', function ($o) use ($user, $filter) {
            // Dua status, bukan satu. `completed_billing` adalah pesanan yang
            // barangnya SUDAH sampai dan tinggal menunggu urusan penagihan —
            // membuangnya dari laporan penjualan berarti mengaku barangnya
            // belum keluar padahal pelanggan sudah menerimanya.
            $o->whereIn('status', [SalesOrder::STATUS_COMPLETED, SalesOrder::STATUS_COMPLETED_BILLING])
                ->whereNotNull('completed_at');

            $this->rentang($this->gudang($o, $user, $filter), $filter, 'completed_at');
        });
    }

    /* =============================================== 2. Pesanan Outstanding */

    private function pesananOutstanding(?User $user, array $filter, int $batas): array
    {
        // Dibaca dari outstanding_qty yang HIDUP, bukan dari riwayat
        // sales_order_outstandings. Riwayat mencatat setiap kejadian dan tidak
        // pernah menyusut; yang ditanyakan orang di sini adalah "apa yang
        // masih kita utang HARI INI", dan itu hanya terjawab oleh kolom yang
        // ikut berkurang saat barangnya menyusul dikirim.
        $q = SalesOrderDetail::query()
            ->where('outstanding_qty', '>', 0)
            ->whereHas('salesOrder', function ($o) use ($user, $filter) {
                $o->whereNotIn('status', [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_REJECTED])
                    ->whereNull('cancelled_at');

                $this->gudang($o, $user, $filter);
            });

        $total = (clone $q)->count();

        $baris = (clone $q)
            ->with(['salesOrder.customer', 'salesOrder.user', 'salesOrder.warehouse', 'product'])
            ->orderBy(
                SalesOrder::query()->select('submitted_at')->whereColumn('id', 'sales_order_details.sales_order_id')
            )
            ->limit($batas)
            ->get()
            ->map(function (SalesOrderDetail $d) {
                $o = $d->salesOrder;

                return [
                    $o?->order_number,
                    self::tanggal($o?->submitted_at),
                    $o?->submitted_at ? (int) Carbon::parse($o->submitted_at)->diffInDays(now()) : null,
                    $o?->customer?->code,
                    $o?->customer?->name,
                    $o?->user?->full_name,
                    $o?->warehouse?->code,
                    $d->product?->sku,
                    $d->product?->name,
                    $d->product?->uom,
                    (int) $d->qty_ordered,
                    (int) $d->qty_shipped,
                    (int) $d->outstanding_qty,
                    SalesOrder::STATUS_LABELS[$o?->status] ?? $o?->status,
                ];
            })
            ->all();

        return $this->tabel([
            'No Pesanan', 'Tgl Masuk', 'Umur (hari)', 'Kode Pelanggan', 'Pelanggan',
            'Sales', 'Gudang', 'SKU', 'Produk', 'Satuan',
            'Qty Dipesan', 'Qty Terkirim', 'Outstanding', 'Status Pesanan',
        ], $baris, $total, [2, 10, 11, 12]);
    }

    /* ==================================================== 3. Produk Terlaris */

    private function produkTerlaris(?User $user, array $filter, int $batas): array
    {
        $q = DB::table('sales_order_details as d')
            ->join('sales_orders as o', 'o.id', '=', 'd.sales_order_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('product_categories as k', 'k.id', '=', 'p.category_id')
            ->whereNull('o.deleted_at')
            ->whereNotNull('o.shipped_at')
            // Baris dengan qty terkirim nol bukan penjualan. Ikut dihitung, ia
            // membuat produk yang justru paling sering kosong naik peringkat.
            ->where('d.qty_shipped', '>', 0)
            ->groupBy('p.id', 'p.sku', 'p.name', 'p.uom', 'k.name');

        $this->rentang($this->gudang($q, $user, $filter, 'o.warehouse_id'), $filter, 'o.shipped_at');

        $agregat = (clone $q)->selectRaw(
            'p.sku, p.name as produk, p.uom, k.name as kategori,'
            .' sum(d.qty_shipped) as terkirim,'
            .' count(distinct o.id) as pesanan,'
            .' count(distinct o.customer_id) as pelanggan,'
            .' sum(d.outstanding_qty) as outstanding'
        );

        $total = DB::query()->fromSub((clone $q)->selectRaw('p.id'), 'x')->count();

        $baris = [];
        $peringkat = 0;

        foreach ($agregat->orderByRaw('sum(d.qty_shipped) desc')->limit($batas)->get() as $r) {
            $baris[] = [
                ++$peringkat,
                $r->sku,
                $r->produk,
                $r->kategori,
                $r->uom,
                (int) $r->terkirim,
                (int) $r->pesanan,
                (int) $r->pelanggan,
                (int) $r->outstanding,
            ];
        }

        return $this->tabel([
            'Peringkat', 'SKU', 'Produk', 'Kategori', 'Satuan',
            'Qty Terkirim', 'Jml Pesanan', 'Jml Pelanggan', 'Qty Outstanding',
        ], $baris, $total, [0, 5, 6, 7, 8]);
    }

    /* =================================================== 4. Pelanggan Teratas */

    private function pelangganTeratas(?User $user, array $filter, int $batas): array
    {
        $q = DB::table('sales_order_details as d')
            ->join('sales_orders as o', 'o.id', '=', 'd.sales_order_id')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereNull('o.deleted_at')
            ->whereNotNull('o.shipped_at')
            ->groupBy('c.id', 'c.code', 'c.name');

        $this->rentang($this->gudang($q, $user, $filter, 'o.warehouse_id'), $filter, 'o.shipped_at');

        $total = DB::query()->fromSub((clone $q)->selectRaw('c.id'), 'x')->count();

        $baris = [];
        $peringkat = 0;

        $agregat = (clone $q)->selectRaw(
            'c.code, c.name as pelanggan,'
            .' count(distinct o.id) as pesanan,'
            .' count(distinct d.product_id) as produk,'
            .' sum(d.qty_shipped) as terkirim,'
            .' sum(d.outstanding_qty) as outstanding,'
            .' max(o.shipped_at) as terakhir'
        );

        foreach ($agregat->orderByRaw('sum(d.qty_shipped) desc')->limit($batas)->get() as $r) {
            $baris[] = [
                ++$peringkat,
                $r->code,
                $r->pelanggan,
                (int) $r->pesanan,
                (int) $r->produk,
                (int) $r->terkirim,
                (int) $r->outstanding,
                self::tanggal($r->terakhir),
            ];
        }

        return $this->tabel([
            'Peringkat', 'Kode', 'Pelanggan', 'Jml Pesanan', 'Ragam Produk',
            'Qty Terkirim', 'Qty Outstanding', 'Kiriman Terakhir',
        ], $baris, $total, [0, 3, 4, 5, 6]);
    }

    /* ====================================================== 5. Kinerja Sales */

    /**
     * Dikelompokkan menurut PEMBUAT pesanan, apa pun perannya — bukan hanya
     * yang berperan Sales. Pesanan lewat dokumen kadang diinput Logistik, dan
     * menyaringnya ke peran Sales saja berarti membuang penjualan sungguhan
     * dari laporan sambil terlihat rapi.
     */
    private function kinerjaSales(?User $user, array $filter, int $batas): array
    {
        $q = DB::table('sales_orders as o')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->whereNull('o.deleted_at')
            ->whereNotNull('o.submitted_at')
            ->groupBy('u.id', 'u.full_name');

        $this->rentang($this->gudang($q, $user, $filter, 'o.warehouse_id'), $filter, 'o.submitted_at');

        $total = DB::query()->fromSub((clone $q)->selectRaw('u.id'), 'x')->count();

        // Qty dijumlahkan lewat subquery per pesanan, BUKAN dengan join ke
        // sales_order_details. Join akan menggandakan tiap pesanan sebanyak
        // barisnya, sehingga count(pesanan) ikut membengkak dan "% terpenuhi"
        // dihitung dari angka yang sudah salah sejak awal.
        $qtyPesan = 'coalesce((select sum(x.qty_ordered) from sales_order_details x where x.sales_order_id = o.id), 0)';
        $qtyKirim = 'coalesce((select sum(x.qty_shipped) from sales_order_details x where x.sales_order_id = o.id), 0)';

        $agregat = (clone $q)->selectRaw(
            'u.full_name as sales,'
            .' count(*) as pesanan,'
            .' count(*) filter (where o.approved_at is not null) as disetujui,'
            .' count(*) filter (where o.rejected_at is not null) as ditolak,'
            .' count(*) filter (where o.cancelled_at is not null) as dibatalkan,'
            .' count(*) filter (where o.completed_at is not null) as selesai,'
            ." sum({$qtyPesan}) as qty_pesan,"
            ." sum({$qtyKirim}) as qty_kirim,"
            .' avg(o.sla_hours) as sla'
        );

        $baris = [];

        foreach ($agregat->orderByRaw('count(*) desc')->limit($batas)->get() as $r) {
            $pesan = (int) $r->qty_pesan;
            $kirim = (int) $r->qty_kirim;

            $baris[] = [
                $r->sales,
                (int) $r->pesanan,
                (int) $r->disetujui,
                (int) $r->ditolak,
                (int) $r->dibatalkan,
                (int) $r->selesai,
                $pesan,
                $kirim,
                $pesan > 0 ? round($kirim / $pesan * 100, 1) : null,
                $r->sla !== null ? round((float) $r->sla, 1) : null,
            ];
        }

        return $this->tabel([
            'Sales', 'Jml Pesanan', 'Disetujui', 'Ditolak', 'Dibatalkan', 'Selesai',
            'Qty Dipesan', 'Qty Terkirim', '% Terpenuhi', 'Rata-rata SLA (jam)',
        ], $baris, $total, [1, 2, 3, 4, 5, 6, 7, 8, 9]);
    }

    /* ========================================================= 6. Pengiriman */

    private function pengiriman(?User $user, array $filter, int $batas): array
    {
        $q = DeliveryNote::query()->whereNotNull('shipped_at');

        $this->rentang($this->gudang($q, $user, $filter), $filter, 'shipped_at');

        $total = (clone $q)->count();

        $baris = (clone $q)
            ->select('delivery_notes.*')
            ->with(['customer', 'warehouse', 'salesOrder'])
            ->withCount('lines')
            // Buktinya dihitung dari PESANAN, bukan dari kolom delivery_note_id
            // di delivery_proofs. Kolom itu boleh kosong: kalau nomor SO di BC
            // berbeda dan tidak ada SJ yang terpasang saat unggah, buktinya
            // tetap sah tetapi tidak menunjuk surat jalan mana pun. Menghitung
            // lewat kolom itu akan melaporkan "0 foto" untuk pengiriman yang
            // fotonya sebenarnya ada — persis kebalikan dari yang dicari orang
            // ketika sebuah pengiriman dipersoalkan.
            ->addSelect(['jml_bukti' => DeliveryProof::query()
                ->selectRaw('count(*)')
                ->whereColumn('delivery_proofs.sales_order_id', 'delivery_notes.sales_order_id')
                ->masihBerlaku(),
            ])
            ->orderByDesc('shipped_at')
            ->limit($batas)
            ->get()
            ->map(fn (DeliveryNote $n) => [
                $n->document_no,
                $n->salesOrder?->order_number,
                self::waktu($n->shipped_at),
                self::waktu($n->delivered_at),
                self::jam($n->shipped_at, $n->delivered_at),
                $n->customer?->code ?? $n->customer_code,
                $n->customer?->name,
                $n->warehouse?->code,
                $n->driver_name,
                $n->vehicle_plate,
                (int) $n->lines_count,
                $n->received_by_name,
                (int) $n->jml_bukti,
                $n->status,
            ])
            ->all();

        return $this->tabel([
            'No Surat Jalan', 'No Pesanan', 'Waktu Kirim', 'Waktu Sampai', 'Lama (jam)',
            'Kode Pelanggan', 'Pelanggan', 'Gudang', 'Supir', 'Plat Nomor',
            'Jml Baris', 'Diterima Oleh', 'Foto Bukti', 'Status',
        ], $baris, $total, [4, 10, 12]);
    }

    /* ======================================================= 7. Posisi Stok */

    private function posisiStok(?User $user, array $filter, int $batas): array
    {
        // Baris nol TIDAK dibuang. Batch yang habis tetapi masih terdaftar
        // adalah keterangan yang berguna saat menelusuri ke mana barangnya
        // pergi — dan menyembunyikannya membuat kartu stok tampak terputus.
        $q = $this->gudang(InventoryStock::query(), $user, $filter);

        $total = (clone $q)->count();

        $baris = (clone $q)
            ->with(['product', 'location', 'warehouse'])
            ->orderBy('warehouse_id')
            ->orderBy('expiry_date')
            ->limit($batas)
            ->get()
            ->map(fn (InventoryStock $s) => [
                $s->warehouse?->code,
                $s->location?->code,
                $s->product?->sku,
                $s->product?->name,
                $s->product?->uom,
                $s->batch_no,
                self::tanggal($s->production_date),
                self::tanggal($s->expiry_date),
                ShelfLife::remainingLabel($s->expiry_date),
                InventoryStock::STATUS_LABELS[$s->status] ?? $s->status,
                (int) $s->qty_available,
                (int) $s->qty_allocated,
                (int) $s->qty_available + (int) $s->qty_allocated,
                $s->has_quality_issue ? 'Ya' : '',
                $s->prioritize_out ? 'Ya' : '',
                self::tanggal($s->quarantine_until),
            ])
            ->all();

        return $this->tabel([
            'Gudang', 'Lokasi', 'SKU', 'Produk', 'Satuan', 'Batch',
            'Tgl Produksi', 'Kedaluwarsa', 'Sisa Umur', 'Status',
            'Qty Tersedia', 'Qty Dialokasi', 'Total', 'Masalah Kualitas',
            'Dahulukan Keluar', 'Karantina s/d',
        ], $baris, $total, [10, 11, 12]);
    }

    /* ==================================================== 8. Pergerakan Stok */

    private function pergerakanStok(?User $user, array $filter, int $batas): array
    {
        $q = $this->rentang(
            $this->gudang(StockMovement::query(), $user, $filter),
            $filter,
            'created_at',
        );

        $total = (clone $q)->count();

        $baris = (clone $q)
            ->with(['product', 'location', 'warehouse', 'user'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($batas)
            ->get()
            ->map(fn (StockMovement $m) => [
                self::waktu($m->created_at),
                $m->warehouse?->code,
                $m->location?->code,
                $m->product?->sku,
                $m->product?->name,
                $m->batch_no,
                StockMovement::TYPE_LABELS[$m->movement_type] ?? $m->movement_type,
                (int) $m->qty_change,
                (int) $m->qty_before,
                (int) $m->qty_after,
                $m->reference_type ? class_basename($m->reference_type).' #'.$m->reference_id : null,
                $m->user?->full_name,
                $m->notes,
            ])
            ->all();

        return $this->tabel([
            'Waktu', 'Gudang', 'Lokasi', 'SKU', 'Produk', 'Batch', 'Jenis',
            'Perubahan', 'Sebelum', 'Sesudah', 'Referensi', 'Pelaku', 'Catatan',
        ], $baris, $total, [7, 8, 9]);
    }

    /* ================================================= Ringkasan untuk layar */

    /**
     * Beberapa angka pembuka yang menjawab "sehat atau tidak" sekilas.
     *
     * Sengaja hanya untuk laporan yang punya jawaban singkat. Yang tidak
     * punya mengembalikan array kosong, dan layarnya tidak menggambar apa pun
     * — kartu ringkasan berisi "—" lebih buruk daripada tidak ada kartu.
     *
     * @return array<string, string>
     */
    public function ringkasan(string $key, array $tabel): array
    {
        $jumlahKolom = function (int $i) use ($tabel): int {
            return (int) array_sum(array_map(fn ($b) => (int) ($b[$i] ?? 0), $tabel['baris']));
        };

        return match ($key) {
            'penjualan-selesai' => [
                'Baris produk' => number_format($tabel['total']),
                'Qty terkirim (halaman ini)' => number_format($jumlahKolom(13)),
            ],
            'pesanan-outstanding' => [
                'Baris menggantung' => number_format($tabel['total']),
                'Qty outstanding (halaman ini)' => number_format($jumlahKolom(12)),
            ],
            'produk-terlaris', 'pelanggan-teratas' => [
                'Total baris' => number_format($tabel['total']),
                'Qty terkirim (halaman ini)' => number_format($jumlahKolom(5)),
            ],
            default => ['Total baris' => number_format($tabel['total'])],
        };
    }
}
