<?php

namespace Tests\Feature\Wms;

use App\Support\Imaji\SusutkanFoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Penyusutan foto bukti.
 *
 * Yang dijaga di sini ada dua, dan yang kedua lebih penting daripada yang
 * pertama: fotonya memang mengecil, DAN tidak ada keadaan yang membuat foto
 * gagal tersimpan. Bukti yang hilang jauh lebih mahal daripada disk yang
 * penuh — karena itu setiap jalur gagal berakhir dengan foto asli, bukan
 * dengan pengecualian.
 */
class SusutkanFotoTest extends TestCase
{
    private SusutkanFoto $penyusut;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->penyusut = app(SusutkanFoto::class);
    }

    private function simpan(UploadedFile $foto): array
    {
        return $this->penyusut->simpan($foto, 'uji-foto', 'local');
    }

    private function lebar(string $path): int
    {
        $ukuran = getimagesizefromstring(Storage::disk('local')->get($path));

        return (int) $ukuran[0];
    }

    public function test_foto_besar_dikecilkan_ke_sisi_maksimal(): void
    {
        $asli = UploadedFile::fake()->image('sj.jpg', 4000, 3000);
        $ukuranAsli = $asli->getSize();

        $hasil = $this->simpan($asli);

        Storage::disk('local')->assertExists($hasil['path']);
        $this->assertSame(config('wms.foto.sisi_maks'), $this->lebar($hasil['path']));
        $this->assertLessThan($ukuranAsli, $hasil['size']);
    }

    /**
     * Ukuran yang dicatat HARUS ukuran berkas yang benar-benar ada di disk.
     *
     * Kalau yang dicatat ukuran unggahannya, laporan pemakaian tempat
     * berbohong justru pada saat disknya menipis dan angkanya paling dibaca.
     */
    public function test_ukuran_yang_dilaporkan_sama_dengan_berkas_di_disk(): void
    {
        $hasil = $this->simpan(UploadedFile::fake()->image('sj.jpg', 3000, 2000));

        $this->assertSame(
            strlen(Storage::disk('local')->get($hasil['path'])),
            $hasil['size'],
        );
    }

    public function test_foto_yang_sudah_kecil_tidak_dikodekan_ulang(): void
    {
        $asli = UploadedFile::fake()->image('sj.jpg', 800, 600);
        $ukuranAsli = $asli->getSize();

        $hasil = $this->simpan($asli);

        // Sama persis: mengodekan ulang berkas yang sudah hemat hanya
        // menurunkan mutunya tanpa menghemat apa pun yang berarti.
        $this->assertSame($ukuranAsli, $hasil['size']);
        $this->assertSame(800, $this->lebar($hasil['path']));
    }

    public function test_png_tetap_png(): void
    {
        $hasil = $this->simpan(UploadedFile::fake()->image('sj.png', 3000, 2000));

        $this->assertSame('image/png', $hasil['mime']);

        $jenis = getimagesizefromstring(Storage::disk('local')->get($hasil['path']))[2];
        $this->assertSame(IMAGETYPE_PNG, $jenis);
    }

    /**
     * Berkas yang tidak bisa dibaca GD tetap tersimpan utuh.
     *
     * Inilah jalur yang menentukan apakah fitur ini aman dipasang: selama
     * jalur gagalnya menyimpan foto asli, penghematan tempat tidak pernah
     * bisa menghilangkan bukti.
     */
    public function test_berkas_yang_tidak_bisa_diolah_tetap_tersimpan(): void
    {
        $rusak = UploadedFile::fake()->createWithContent('rusak.jpg', 'ini bukan gambar sama sekali');

        $hasil = $this->simpan($rusak);

        Storage::disk('local')->assertExists($hasil['path']);
        $this->assertSame('ini bukan gambar sama sekali', Storage::disk('local')->get($hasil['path']));
    }

    public function test_penyusutan_bisa_dimatikan_lewat_pengaturan(): void
    {
        config(['wms.foto.aktif' => false]);

        $asli = UploadedFile::fake()->image('sj.jpg', 4000, 3000);
        $ukuranAsli = $asli->getSize();

        $hasil = $this->simpan($asli);

        $this->assertSame($ukuranAsli, $hasil['size']);
        $this->assertSame(4000, $this->lebar($hasil['path']));
    }

    public function test_foto_tersimpan_bisa_disusutkan_belakangan(): void
    {
        config(['wms.foto.aktif' => false]);
        $hasil = $this->simpan(UploadedFile::fake()->image('sj.jpg', 4000, 3000));

        config(['wms.foto.aktif' => true]);
        $baru = $this->penyusut->susutkanTersimpan($hasil['path'], 'local', 'image/jpeg');

        $this->assertNotNull($baru);
        $this->assertLessThan($hasil['size'], $baru);
        $this->assertSame(config('wms.foto.sisi_maks'), $this->lebar($hasil['path']));
    }

    public function test_berkas_yang_tidak_ada_dilewati_tanpa_galat(): void
    {
        $this->assertNull(
            $this->penyusut->susutkanTersimpan('uji-foto/tidak-ada.jpg', 'local', 'image/jpeg')
        );
    }
}
