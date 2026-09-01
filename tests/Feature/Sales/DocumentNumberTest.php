<?php

namespace Tests\Feature\Sales;

use App\Support\DocumentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Penomoran PO — format dan aturan reset yang ditetapkan pemilik produk.
 *
 * Format: PO{YYYYMMDD}{urut 3 digit}, urut BERJALAN TERUS SEPANJANG BULAN
 * dan baru kembali ke 001 saat ganti bulan.
 */
class DocumentNumberTest extends TestCase
{
    use RefreshDatabase;

    private function nomor(string $waktu): string
    {
        Carbon::setTestNow(Carbon::parse($waktu));

        return DB::transaction(fn () => DocumentNumber::forSalesOrder());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_format_nomor_po(): void
    {
        $this->assertSame('PO260901001', $this->nomor('2026-09-01 08:00:00'));
    }

    /**
     * Urutan tidak kembali ke 001 saat ganti HARI.
     *
     * Ini bagian yang paling mudah salah dibaca dari aturannya: bagian
     * tanggal ikut hari pembuatan, tapi angka urutnya menghitung pesanan
     * dalam satu BULAN.
     */
    public function test_urutan_berjalan_terus_sepanjang_bulan(): void
    {
        $this->assertSame('PO260901001', $this->nomor('2026-09-01 08:00:00'));
        $this->assertSame('PO260901002', $this->nomor('2026-09-01 09:00:00'));
        $this->assertSame('PO260902003', $this->nomor('2026-09-02 08:00:00'));
        $this->assertSame('PO260930004', $this->nomor('2026-09-30 08:00:00'));
    }

    public function test_urutan_reset_saat_ganti_bulan(): void
    {
        $this->nomor('2026-09-30 08:00:00');

        $this->assertSame('PO261001001', $this->nomor('2026-10-01 08:00:00'));
    }

    public function test_urutan_reset_saat_ganti_tahun(): void
    {
        $this->nomor('2026-12-31 08:00:00');

        $this->assertSame('PO270101001', $this->nomor('2027-01-01 08:00:00'));
    }

    /** Nomor ke-100 tetap 3 digit, bukan meluber jadi format lain. */
    public function test_nomor_di_atas_seratus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00'));

        DB::table('document_sequences')->insert([
            'document_type' => DocumentNumber::TYPE_SALES_ORDER,
            'period_year' => 2026, 'period_month' => 9, 'warehouse_id' => null,
            'last_number' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('PO260901100', $this->nomor('2026-09-01 10:00:00'));
    }

    /**
     * Baris periode dengan warehouse_id NULL tidak boleh terbuat dua kali.
     *
     * Postgres menganggap dua NULL BERBEDA, jadi unique index biasa TIDAK
     * akan mencegahnya — dan dua baris untuk periode yang sama berarti dua
     * pesanan bisa memperoleh nomor identik. Indeksnya memakai
     * NULLS NOT DISTINCT justru untuk ini.
     */
    public function test_periode_lintas_gudang_hanya_punya_satu_baris(): void
    {
        $this->nomor('2026-09-01 08:00:00');
        $this->nomor('2026-09-05 08:00:00');
        $this->nomor('2026-09-20 08:00:00');

        $baris = DB::table('document_sequences')
            ->where('document_type', DocumentNumber::TYPE_SALES_ORDER)
            ->where('period_year', 2026)
            ->where('period_month', 9)
            ->whereNull('warehouse_id')
            ->get();

        $this->assertCount(1, $baris);
        $this->assertSame(3, (int) $baris->first()->last_number);
    }
}
