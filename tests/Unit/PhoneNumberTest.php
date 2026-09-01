<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * Sel nyata dari ekspor ERP yang menghentikan impor 1.863 pelanggan.
     * Dua nomor asli, keduanya harus selamat.
     */
    public function test_dua_nomor_dipisah_garis_miring_tetap_utuh(): void
    {
        $this->assertSame(
            '6285775005758 / 6282233024171',
            PhoneNumber::normalize('6285775005758/6282233024171')
        );
    }

    public function test_koma_titik_koma_pipa_dan_ganti_baris_juga_memisah(): void
    {
        foreach ([',', ';', '|', "\n"] as $pemisah) {
            $this->assertSame(
                '6281234567890 / 6289876543210',
                PhoneNumber::normalize('6281234567890'.$pemisah.'6289876543210'),
                "Pemisah '{$pemisah}' seharusnya memisah dua nomor."
            );
        }
    }

    /**
     * Spasi dan strip adalah hiasan DI DALAM satu nomor, bukan pemisah.
     * Kalau keliru dianggap pemisah, "62 895 3143 5435" akan pecah menjadi
     * empat potongan yang bukan nomor siapa pun.
     */
    public function test_spasi_strip_kurung_dan_plus_hanya_dibuang(): void
    {
        $this->assertSame('6289531435435', PhoneNumber::normalize('62 895 3143 5435'));
        $this->assertSame('6289531435435', PhoneNumber::normalize('+62-895-3143-5435'));
        $this->assertSame('62215551234', PhoneNumber::normalize('(6221) 555-1234'));
    }

    public function test_kosong_menjadi_null_bukan_string_kosong(): void
    {
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('-'));
        $this->assertNull(PhoneNumber::normalize(' / '));
    }

    public function test_nomor_kembar_di_satu_sel_hanya_disimpan_sekali(): void
    {
        $this->assertSame('6281234567890', PhoneNumber::normalize('6281234567890 / 6281234567890'));
    }

    /** Bentuk simpan bisa dibaca ulang tanpa berubah. */
    public function test_normalisasi_ulang_tidak_mengubah_hasil(): void
    {
        $sekali = PhoneNumber::normalize('6285775005758/6282233024171');

        $this->assertSame($sekali, PhoneNumber::normalize($sekali));
    }

    public function test_label_memberi_awalan_plus_pada_tiap_nomor(): void
    {
        $this->assertSame('+6289531435435', PhoneNumber::label('6289531435435'));
        $this->assertSame(
            '+6285775005758 / +6282233024171',
            PhoneNumber::label('6285775005758 / 6282233024171')
        );
    }

    /** Nomor lokal tanpa kode negara tidak dipaksa memakai "+". */
    public function test_label_nomor_lokal_dibiarkan_apa_adanya(): void
    {
        $this->assertSame('0215551234', PhoneNumber::label('021-555-1234'));
    }

    public function test_label_kosong_menjadi_tanda_pisah(): void
    {
        $this->assertSame('—', PhoneNumber::label(null));
        $this->assertSame('—', PhoneNumber::label(''));
    }
}
