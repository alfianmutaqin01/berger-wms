<?php

namespace App\Http\Controllers;

use App\Support\Bahasa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mengganti bahasa tampilan.
 *
 * POST, BUKAN TAUTAN. Ia mengubah keadaan session, jadi ia butuh CSRF. Sebagai
 * GET, alamatnya bisa disematkan orang lain di gambar atau tautan dan bahasa
 * seseorang berubah tanpa ia menyentuh apa pun — kecil akibatnya, tetapi tidak
 * ada alasan membiarkannya.
 *
 * KEMBALI KE HALAMAN YANG SAMA, bukan ke dashboard. Yang menekan tombol ini
 * sedang mengerjakan sesuatu; melemparnya ke beranda berarti ia kehilangan
 * saringan, halaman, dan posisi gulir yang sudah ia susun — dan ia akan
 * belajar untuk tidak menyentuh tombol itu lagi.
 */
class BahasaController extends Controller
{
    public function ubah(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bahasa' => ['required', 'string', Rule::in(array_keys(Bahasa::TERSEDIA))],
        ]);

        Bahasa::simpan($request, $data['bahasa']);

        // setLocale di sini juga, supaya pesan di bawah SUDAH dalam bahasa yang
        // baru dipilih. Middleware baru akan memasangnya pada permintaan
        // berikutnya, dan pesan "bahasa diubah" yang datang dalam bahasa lama
        // terbaca seperti tombolnya gagal.
        app()->setLocale($data['bahasa']);

        return back()->with('success', __('Bahasa tampilan diubah ke :bahasa.', [
            'bahasa' => Bahasa::TERSEDIA[$data['bahasa']]['nama'],
        ]));
    }
}
