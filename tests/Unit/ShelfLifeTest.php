<?php

namespace Tests\Unit;

use App\Support\ShelfLife;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Sisa umur simpan dalam BULAN + MINGGU.
 *
 * Format ini diminta pemilik produk supaya orang gudang bisa memutuskan mana
 * yang dijual duluan tanpa menghitung mundur dari tanggal kedaluwarsa.
 */
class ShelfLifeTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-09-01');
    }

    private function label(string $expiry): string
    {
        return ShelfLife::remainingLabel(Carbon::parse($expiry), $this->now);
    }

    private function urgency(string $expiry): string
    {
        return ShelfLife::urgency(Carbon::parse($expiry), $this->now);
    }

    public function test_bulan_dan_minggu_ditampilkan_bersama(): void
    {
        $this->assertSame('5 bln 1 minggu', $this->label('2027-02-12'));
        $this->assertSame('10 bln 1 minggu', $this->label('2027-07-08'));
        $this->assertSame('5 bln 3 minggu', $this->label('2027-02-25'));
    }

    /**
     * Bulan bulat tetap menyebut minggunya: "6 bln 0 minggu", bukan "6 bulan pas".
     *
     * Diminta pemilik produk supaya bentuk labelnya seragam — angka bulan dan
     * angka minggu selalu ada di posisi yang sama saat kolomnya dibaca sekilas.
     */
    public function test_bulan_bulat_tetap_menulis_nol_minggu(): void
    {
        $this->assertSame('6 bln 0 minggu', $this->label('2027-03-01'));
        $this->assertSame('30 bln 0 minggu', $this->label('2029-03-01'));
        $this->assertSame('1 bln 0 minggu', $this->label('2026-10-01'));
    }

    /** Di bawah satu bulan, minggu dan hari yang ditampilkan. */
    public function test_kurang_dari_sebulan(): void
    {
        $this->assertSame('3 minggu', $this->label('2026-09-22'));
        $this->assertSame('5 hari', $this->label('2026-09-06'));
        $this->assertSame('1 hari', $this->label('2026-09-02'));
    }

    /**
     * Bulan dihitung sebagai bulan KALENDER, bukan 30 hari.
     *
     * Kalau dibagi rata 30 hari, masa simpan panjang seperti 30 bulan akan
     * meleset makin jauh dari tanggal kedaluwarsa yang tercetak.
     */
    public function test_bulan_mengikuti_kalender_bukan_30_hari(): void
    {
        // Februari pendek: 1 Feb -> 1 Mar tetap "1 bln 0 minggu".
        $this->assertSame('1 bln 0 minggu', ShelfLife::remainingLabel(
            Carbon::parse('2027-03-01'),
            Carbon::parse('2027-02-01')
        ));
    }

    /**
     * Batch yang kedaluwarsa HARI INI sudah tidak boleh dijual: aturan FIFO
     * menyaring expiry_date > CURRENT_DATE, bukan >=.
     */
    public function test_kedaluwarsa_hari_ini_dinyatakan_tegas(): void
    {
        $this->assertSame('Kedaluwarsa hari ini', $this->label('2026-09-01'));
        $this->assertSame('expired', $this->urgency('2026-09-01'));
    }

    public function test_sudah_lewat_ditandai_kedaluwarsa(): void
    {
        $this->assertSame('Kedaluwarsa 1 minggu lalu', $this->label('2026-08-20'));
        $this->assertSame('Kedaluwarsa 2 bln 0 minggu lalu', $this->label('2026-07-01'));
        $this->assertSame('expired', $this->urgency('2026-08-20'));
    }

    public function test_tanpa_tanggal_kedaluwarsa_aman(): void
    {
        $this->assertSame('—', ShelfLife::remainingLabel(null, $this->now));
        $this->assertSame('safe', ShelfLife::urgency(null, $this->now));
    }

    /** Ambang kegentingan mengikuti peringatan dini 90 hari (PRD §7.2.1). */
    public function test_tingkat_kegentingan(): void
    {
        $this->assertSame('critical', $this->urgency('2026-11-30'));  // 90 hari
        $this->assertSame('warning', $this->urgency('2026-12-01'));   // 91 hari
        $this->assertSame('warning', $this->urgency('2027-02-28'));   // 180 hari
        $this->assertSame('safe', $this->urgency('2027-03-05'));      // > 180 hari
    }

    /** Label tidak pernah mengandung pecahan (Carbon 3 mengembalikan float). */
    public function test_label_tidak_mengandung_pecahan(): void
    {
        foreach (['2027-02-12', '2027-07-08', '2026-09-22', '2029-03-01'] as $tanggal) {
            $this->assertStringNotContainsString('.', $this->label($tanggal));
        }
    }
}
