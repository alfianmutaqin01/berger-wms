<?php

namespace Tests\Feature\Messaging;

use App\Models\MaterialRequisition;
use App\Support\Messaging\CloudApiWhatsAppSender;
use App\Support\Messaging\DispatchResult;
use App\Support\Messaging\FonnteWhatsAppSender;
use App\Support\Messaging\ManualWhatsAppSender;
use App\Support\Messaging\PesanWhatsApp;
use App\Support\Messaging\WhatsAppSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Penyedia WhatsApp — yang benar-benar dikirim ke luar.
 *
 * Tidak ada pesan sungguhan yang keluar: seluruh permintaan HTTP dipalsukan.
 * Yang diperiksa adalah BENTUK permintaannya, karena di situlah dua kesalahan
 * yang paling mahal bersembunyi — dan keduanya tidak menghasilkan galat apa
 * pun di sisi kita:
 *
 *   1. Template yang salah. Meta menerima template apa pun yang sudah
 *      disetujui; ia tidak tahu bahwa atasan MRF seharusnya tidak menerima
 *      template "konfirmasi pengiriman".
 *   2. Gagal yang dicatat berhasil. Fonnte menjawab HTTP 200 walau pesannya
 *      ditolak; memercayai kode HTTP saja mencatat "terkirim" untuk pesan yang
 *      tidak pernah sampai.
 */
class WhatsAppSenderTest extends TestCase
{
    private function pesan(string $template, array $variabel = ['https://contoh/tautan']): PesanWhatsApp
    {
        return new PesanWhatsApp('Isi pesan lengkap untuk teks bebas.', $template, $variabel);
    }

    /* ------------------------------------------------------------ Meta */

    public function test_meta_memakai_template_milik_jenis_pesannya(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        $penyedia = new CloudApiWhatsAppSender('123', 'rahasia', [
            PesanWhatsApp::TEMPLATE_KONFIRMASI_SUPIR => 'konfirmasi_pengiriman',
            PesanWhatsApp::TEMPLATE_BARANG_SAMPAI => 'barang_sampai_sales_v2',
        ]);

        $hasil = $penyedia->send('6281298765432', $this->pesan(
            PesanWhatsApp::TEMPLATE_BARANG_SAMPAI,
            ['Budi', 'SO260903', 'Toko Maju', '206215', 'https://contoh/sales/orders/1'],
        ));

        $this->assertTrue($hasil->berhasil());

        Http::assertSent(function (Request $r) {
            $template = $r['template'];

            // Nama yang DISETUJUI Meta dari peta, bukan nama rencananya.
            return $template['name'] === 'barang_sampai_sales_v2'
                && $r['to'] === '6281298765432'
                // Kelima variabel terkirim, dengan urutan yang sama.
                && count($template['components'][0]['parameters']) === 5
                && $template['components'][0]['parameters'][1]['text'] === 'SO260903';
        });
    }

    public function test_pesan_mrf_tidak_lagi_memakai_template_supir(): void
    {
        $mrf = new MaterialRequisition([
            'mrf_number' => 'MR261011001',
            'request_type' => MaterialRequisition::TYPE_REPROSES,
            'purpose' => 'Reproses DDP batch Juli.',
            'approver_name' => 'Pak Gandhi',
            'approval_token' => str_repeat('a', 64),
        ]);

        $pesan = $mrf->pesanWhatsAppApprover();

        // Regresi yang ditemukan saat fitur kabar-ke-Sales dibangun: dulu
        // jalur Meta hanya mengenal satu template untuk seluruh sistem, dan
        // atasan MRF akan menerima pesan berbunyi "konfirmasi pengiriman".
        $this->assertSame(PesanWhatsApp::TEMPLATE_PERSETUJUAN_MRF, $pesan->template);
        $this->assertNotSame(PesanWhatsApp::TEMPLATE_KONFIRMASI_SUPIR, $pesan->template);

        $this->assertSame('Pak Gandhi', $pesan->variabel[0]);
        $this->assertSame('MR261011001', $pesan->variabel[1]);
        $this->assertStringContainsString('/mrf/'.str_repeat('a', 64), $pesan->variabel[3]);
    }

    public function test_galat_meta_disimpan_apa_adanya(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Template name does not exist in the translation'],
        ], 404)]);

        $hasil = (new CloudApiWhatsAppSender('123', 'rahasia'))
            ->send('6281298765432', $this->pesan(PesanWhatsApp::TEMPLATE_BARANG_SAMPAI));

        $this->assertFalse($hasil->berhasil());
        $this->assertStringContainsString('does not exist', $hasil->error);
    }

    /* ----------------------------------------------------------- Fonnte */

    public function test_fonnte_mengirim_teks_lengkap_dengan_token_perangkat(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => true, 'detail' => 'success! message in queue'])]);

        $hasil = (new FonnteWhatsAppSender('token-perangkat'))
            ->send('6281298765432', $this->pesan(PesanWhatsApp::TEMPLATE_BARANG_SAMPAI));

        $this->assertTrue($hasil->berhasil());

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'token-perangkat')
            && $r['target'] === '6281298765432'
            // Teks bebas, bukan nama template.
            && $r['message'] === 'Isi pesan lengkap untuk teks bebas.');
    }

    public function test_fonnte_yang_menjawab_200_tetapi_menolak_dicatat_gagal(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'device disconnected'], 200)]);

        $hasil = (new FonnteWhatsAppSender('token-perangkat'))
            ->send('6281298765432', $this->pesan(PesanWhatsApp::TEMPLATE_BARANG_SAMPAI));

        $this->assertFalse($hasil->berhasil());
        $this->assertSame('device disconnected', $hasil->error);
    }

    /* ------------------------------------------------------ Pemilihan */

    public function test_fonnte_tanpa_token_turun_ke_manual_bukan_meledak(): void
    {
        config([
            'services.whatsapp.driver' => 'fonnte',
            'services.whatsapp.fonnte_token' => null,
        ]);

        $this->app->forgetInstance(WhatsAppSender::class);

        $penyedia = app(WhatsAppSender::class);

        $this->assertInstanceOf(ManualWhatsAppSender::class, $penyedia);
        $this->assertSame(
            DispatchResult::manual()->status,
            $penyedia->send('6281298765432', $this->pesan(PesanWhatsApp::TEMPLATE_BARANG_SAMPAI))->status,
        );
    }

    public function test_fonnte_dengan_token_dipakai(): void
    {
        config([
            'services.whatsapp.driver' => 'fonnte',
            'services.whatsapp.fonnte_token' => 'token-perangkat',
        ]);

        $this->app->forgetInstance(WhatsAppSender::class);

        $this->assertInstanceOf(FonnteWhatsAppSender::class, app(WhatsAppSender::class));
    }
}
