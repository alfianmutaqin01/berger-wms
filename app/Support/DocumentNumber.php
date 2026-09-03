<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pembangkit nomor dokumen.
 *
 * Kelas ini memuat DUA MEKANISME yang hidup berdampingan. Bedanya penting:
 *
 * 1. BERBASIS HITUNGAN (peek/reserve/format) — dipakai dokumen inbound sejak
 *    Fase 3. Format {AWALAN}-{YYMMDD}-{NNN}, mis. IN-260828-001; urut kembali
 *    ke 001 tiap ganti hari, dihitung dari isi tabelnya.
 *
 * 2. BERBASIS TABEL document_sequences (forSalesOrder/next) — ditambahkan
 *    Fase 5. Penghitungnya disimpan eksplisit dan barisnya dikunci, sehingga
 *    dua orang yang menyimpan bersamaan tidak berebut nomor yang sama.
 *
 * Mekanisme kedua lebih kuat dan pada akhirnya harus menggantikan yang
 * pertama. Inbound SENGAJA belum dipindahkan di Fase 5: memindahkannya
 * mengubah penomoran dokumen yang sudah dipakai di lapangan, dan itu
 * perubahan tersendiri yang butuh persetujuan pemilik produk. Jangan
 * memakai mekanisme pertama untuk dokumen BARU.
 */
class DocumentNumber
{
    /** Dokumen produksi masuk. */
    public const PREFIX_INBOUND = 'IN';

    /* ------------------------------------------------------------------
     | Berbasis tabel document_sequences (Fase 5 ke atas)
     |------------------------------------------------------------------ */

    public const TYPE_SALES_ORDER = 'sales_order';

    public const TYPE_DELIVERY_NOTE = 'delivery_note';

    public const TYPE_STOCK_TRANSFER = 'stock_transfer';

    /**
     * Nomor transfer antar gudang: TF{YYMMDD}{urut 3 digit}.
     *
     * Penomorannya LINTAS GUDANG, bukan per gudang. Satu dokumen transfer
     * menyangkut dua gudang sekaligus dan dibaca keduanya; nomor yang diulang
     * di tiap gudang akan membuat "TF260913001" berarti dua kiriman berbeda
     * tergantung siapa yang menyebutnya.
     *
     * Bentuknya sengaja menyamai nomor PO (reset tiap bulan, tanggal di
     * tengah) supaya keduanya terbaca dengan kebiasaan yang sama.
     *
     * WAJIB dipanggil di dalam DB::transaction — lihat next().
     */
    public static function forStockTransfer(?Carbon $waktu = null): string
    {
        $waktu = $waktu ?? now();

        $urut = self::next(
            type: self::TYPE_STOCK_TRANSFER,
            year: (int) $waktu->format('Y'),
            month: (int) $waktu->format('n'),
        );

        return 'TF'.$waktu->format('ymd').str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Nomor PO: PO{YYMMDD}{urut 3 digit}.
     *
     * Urutannya BERJALAN TERUS SEPANJANG BULAN dan baru kembali ke 001 saat
     * ganti bulan — keputusan pemilik produk. Jadi pesanan pertama tanggal 2
     * September MELANJUTKAN angka dari tanggal 1, bukan mengulang dari awal:
     *
     *   1 Sep pesanan ke-1  -> PO260901001
     *   1 Sep pesanan ke-2  -> PO260901002
     *   2 Sep pesanan ke-1  -> PO260902003
     *   1 Okt pesanan ke-1  -> PO261001001
     *
     * Bagian tanggal mengikuti tanggal pembuatan, jadi nomornya tetap
     * memberi tahu kapan pesanan itu dibuat tanpa membuka datanya.
     *
     * WAJIB dipanggil di dalam DB::transaction — lihat next().
     */
    public static function forSalesOrder(?Carbon $waktu = null): string
    {
        $waktu = $waktu ?? now();

        $urut = self::next(
            type: self::TYPE_SALES_ORDER,
            year: (int) $waktu->format('Y'),
            month: (int) $waktu->format('n'),
        );

        return 'PO'.$waktu->format('ymd').str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Menaikkan penghitung lalu mengembalikan nomor barunya.
     *
     * MENGAPA PAKAI TABEL, BUKAN MAX(nomor) + 1: dua orang yang menekan
     * simpan pada detik yang sama akan sama-sama membaca nomor terakhir yang
     * sama, lalu sama-sama menulis nomor berikutnya yang sama — dan yang
     * kalah ditolak unique constraint tepat setelah formulirnya selesai
     * diisi. Baris di document_sequences dikunci lebih dulu (lockForUpdate)
     * sehingga yang kedua MENUNGGU GILIRAN alih-alih gagal.
     *
     * WAJIB dipanggil di dalam DB::transaction: kunci baris hanya berlaku
     * selama transaksi, di luar itu ia dilepas seketika dan penjagaannya
     * tidak ada artinya.
     *
     * @param  int|null  $month  NULL bila urutannya hanya reset tiap tahun.
     * @param  int|null  $warehouseId  NULL bila penomorannya lintas gudang.
     */
    public static function next(string $type, int $year, ?int $month = null, ?int $warehouseId = null): int
    {
        $kunci = [
            'document_type' => $type,
            'period_year' => $year,
            'period_month' => $month,
            'warehouse_id' => $warehouseId,
        ];

        // Baris periode ini mungkin belum ada (dokumen pertama di bulan itu).
        // insertOrIgnore, bukan firstOrCreate: bila dua proses berlomba
        // membuatnya, yang kalah cukup diabaikan — bukan dilempar sebagai
        // pelanggaran unique constraint.
        DB::table('document_sequences')->insertOrIgnore($kunci + [
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dicari satu per satu, BUKAN ->where($kunci): nilai null pada where()
        // menghasilkan "kolom = NULL" yang tidak pernah cocok dengan apa pun
        // di SQL, sehingga barisnya seolah hilang setiap kali.
        $query = DB::table('document_sequences');
        foreach ($kunci as $kolom => $nilai) {
            $nilai === null ? $query->whereNull($kolom) : $query->where($kolom, $nilai);
        }

        $baris = $query->lockForUpdate()->first();
        $berikutnya = ((int) $baris->last_number) + 1;

        DB::table('document_sequences')
            ->where('id', $baris->id)
            ->update(['last_number' => $berikutnya, 'updated_at' => now()]);

        return $berikutnya;
    }

    /* ------------------------------------------------------------------
     | Berbasis hitungan isi tabel (inbound, Fase 3)
     |------------------------------------------------------------------ */

    /**
     * Nomor berikutnya yang BELUM dipakai.
     *
     * Dipakai untuk pratinjau di layar. Nomor final tetap dibangkitkan ulang
     * saat menyimpan lewat `reserve()`, karena antara layar terbuka dan tombol
     * simpan ditekan bisa saja ada dokumen lain yang tersimpan lebih dulu.
     */
    public static function peek(string $prefix, string $table, string $column = 'document_number'): string
    {
        return self::format($prefix, self::countToday($prefix, $table, $column) + 1);
    }

    /**
     * Nomor final saat menyimpan, dijamin belum terpakai.
     *
     * Mengulang pencarian bila nomor kandidat ternyata sudah dipakai — itu
     * terjadi saat dua orang menyimpan hampir bersamaan. Kolom
     * `document_number` tetap berconstraint UNIQUE sebagai pengaman terakhir
     * di sisi basis data.
     */
    public static function reserve(string $prefix, string $table, string $column = 'document_number'): string
    {
        $sequence = self::countToday($prefix, $table, $column) + 1;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = self::format($prefix, $sequence + $attempt);

            if (! DB::table($table)->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "Gagal membangkitkan nomor dokumen {$prefix}: 50 nomor berurutan sudah terpakai."
        );
    }

    public static function format(string $prefix, int $sequence, ?Carbon $date = null): string
    {
        return sprintf('%s-%s-%03d', $prefix, ($date ?? now())->format('ymd'), $sequence);
    }

    /** Jumlah dokumen berawalan sama yang sudah dibuat hari ini. */
    private static function countToday(string $prefix, string $table, string $column): int
    {
        return DB::table($table)
            ->where($column, 'LIKE', $prefix.'-'.now()->format('ymd').'-%')
            ->count();
    }
}
