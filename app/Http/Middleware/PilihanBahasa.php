<?php

namespace App\Http\Middleware;

use App\Support\Bahasa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memasang bahasa pilihan pengguna pada SETIAP permintaan web.
 *
 * KENAPA SETIAP PERMINTAAN, BUKAN SEKALI SAAT MEMILIH. Bahasa aplikasi adalah
 * keadaan satu permintaan; ia lahir dari config dan mati bersama permintaan
 * itu. Kalau hanya dipasang saat tombolnya ditekan, permintaan berikutnya —
 * termasuk yang tidak kelihatan seperti pencarian ketik, penyegaran lonceng,
 * dan unduhan — kembali ke bahasa Indonesia. Hasilnya layar berbahasa Inggris
 * dengan potongan Indonesia yang muncul-hilang, dan itu terbaca sebagai
 * sistem yang rusak, bukan sebagai terjemahan yang belum lengkap.
 *
 * TIDAK MENULIS APA PUN. Middleware ini hanya membaca session. Yang menyimpan
 * pilihan cuma BahasaController, lewat satu permintaan POST yang disengaja
 * pengguna — sehingga tidak ada jalan lain yang bisa diam-diam menggeser
 * bahasa orang di tengah pekerjaannya.
 *
 * BELUM MEMILIH BERARTI BAHASA INDONESIA, dan itu datang dari config
 * (APP_LOCALE=id), bukan dipaksa di sini. Satu tempat yang memutuskan bahasa
 * bawaan, bukan dua.
 */
class PilihanBahasa
{
    public function handle(Request $request, Closure $next): Response
    {
        // hasSession(): perintah konsol dan permintaan tanpa session (mis. uji
        // unit yang memanggil middleware langsung) tidak boleh jatuh di sini.
        if ($request->hasSession()) {
            $kode = Bahasa::pilihan($request);

            if ($kode !== null) {
                app()->setLocale($kode);
            }
        }

        return $next($request);
    }
}
