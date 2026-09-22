<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Wms\DashboardController;
use App\Jobs\ProsesPermintaanLupaSandi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Lupa sandi?" — permintaan ke admin, BUKAN reset mandiri lewat email.
 *
 * Keputusan pemilik produk. Reset lewat email berarti siapa pun yang
 * menguasai kotak masuk seseorang menguasai akun WMS-nya, termasuk hak
 * menggerakkan stok; dan tidak semua akun gudang di sini punya kotak masuk
 * yang benar-benar dibaca pemiliknya. Yang dibangun di sini hanyalah jalur
 * terstruktur ke admin: permintaannya sampai ke orang yang tepat, tercatat,
 * dan admin yang memastikan orangnya sebelum mengisi sandi sementara.
 *
 * JAWABANNYA SELALU SAMA, apa pun email yang diisi — terdaftar, tidak
 * terdaftar, non-aktif. Alasannya di ProsesPermintaanLupaSandi.
 */
class LupaSandiController extends Controller
{
    public function show(): View|RedirectResponse
    {
        // Sama dengan halaman login: yang sudah masuk tidak perlu formulir ini.
        if (Auth::check()) {
            return redirect(DashboardController::pathFor(Auth::user()));
        }

        return view('auth.lupa-sandi');
    }

    public function kirim(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
        ], [
            'email.required' => 'Isi alamat email yang Anda pakai untuk masuk.',
            'email.email' => 'Alamat email tidak terbaca.',
        ]);

        // Diantrekan, tidak dikerjakan di sini — supaya lama jawabannya tidak
        // membocorkan apakah emailnya terdaftar.
        ProsesPermintaanLupaSandi::dispatch($data['email'], $request->ip());

        return redirect()->route('password.lupa')->with('terkirim', true);
    }
}
