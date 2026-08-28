<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * Pelanggan contoh — disalin apa adanya dari ekspor ERP Berger.
 *
 * Kolom sumber: No./id | Ship-to Code | Name | Phone No. | Contact | Email |
 *               Address | Address 2 | Territory Code
 *
 * Kolom "Contact" kosong pada seluruh data contoh, jadi contact_name NULL.
 * Ship-to Code hanya dimiliki sebagian pelanggan (4 dari 9) — yang belum
 * terdaftar di ERP dibiarkan kosong, bukan diisi nilai palsu.
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // [code, ship_to_code, name, phone, email, address, address_2]
        $rows = [
            ['IDI10101', '1061600017', 'PT PANDU BIO POLIMER', '6289531435435', 'MARKETING@PANDUBIOPOLIMER.COM',
                'JL RAYA PONDOK GEDE NO. 17 A, RT 002 RW 002, DUKUH KRAMAT JATI', 'JAKARTA TIMUR, DKI JAKARTA'],
            ['IDI10102', null, 'PT VICTORINDO INTI CEMERLANG', '6282110878778', 'PURCHASING.PTVIC@YAHOO.COM',
                'JL KAMAL MUARA VII SENTRA INDUSTRI TERPADU TAHAP 3', 'BLOK A1 NO 5 KAMAR MUARA'],
            ['IDI10103', '1061600002', 'PT KEILANO ANUGRAH MANDIRI', '6289630197199', 'KEILANOANUGRAHMANDIRI@GMAIL.COM',
                'PERUMAHAN PERMATA CIKARANG TIMUR BLOK I NO 11', 'RT 004 RW 12, JATIREJA'],
            ['IDI10104', null, 'CV NIREMADO JAYA ABADI', '6281226352363', 'cvnja2018@gmail.com',
                'PERUM BHUMI NIRWANA CITY, JL. ATHENA III O NO. 11', 'RT 47 RW 000, GRAHA INDAH, BALIKPAPAN UTARA'],
            ['IDI10105', null, 'PT BIRU NUSA LESTARI', '6281290044447', 'PT.BIRUNUSALESTARI@GMAIL.COM',
                'JL. BOULEVARD HIJAU RAYA BLOK B6, NO. 21', 'PEJUANGAN, KEC. MEDAN SATRIA'],
            ['IDI10106', null, 'PT ORBI PERDANGAN INDONESIA', '6281111157845', 'INDONESIA.ORBI@GMAIL.COM',
                'JL. ASRTERI KELAPA GADING NO. E.1/10, 15, RT 005/RW 002', 'PEGANGSAAN DUA, KELAPA GADING'],
            ['IDI10107', null, 'CV PUTRI JAYA MANDIRI', '6283148452610', 'PUTRIJAYAMANDIRI97@GMAIL.COM',
                'JL. DESA SRENGSENG BLOK LURAH RT 015 RW 004', 'SRENGSENG, KEC. KRANGKENG'],
            ['IDI10108', '1061600027', 'PT REKAN ACARA GEMILANG', '6281224637325', 'REKANACARAGEMILANG@YAHOO.COM',
                'JL. PULAU PELANGI RAYA NO. 14, KOMPLEK TAMAN PERMATA BUANA', 'KEMBANGAN UTARA, KEMBANGAN, JAKARTA BARAT'],
            ['IDI10109', '1061600069', 'PT SURYA VIRGO PERKASA', '62895344476667', 'suryaperkasaindonesia60@gmail.com',
                'JL. PAMOYANAN NO. 15 RT 01 RW 01', 'MEKARMANIK, CIMENYAN'],
        ];

        foreach ($rows as [$code, $shipTo, $name, $phone, $email, $address, $address2]) {
            Customer::firstOrCreate(
                ['code' => $code],
                [
                    'ship_to_code' => $shipTo,
                    'name' => $name,
                    'phone' => $phone,
                    'contact_name' => null,
                    'email' => $email,
                    'address' => $address,
                    'address_2' => $address2,
                    'territory_code' => 'PROJECT',
                    'is_active' => true,
                ]
            );
        }
    }
}
