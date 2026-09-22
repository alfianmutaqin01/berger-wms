<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Wms\DashboardController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman tempat pemilik sandi sementara membuat sandinya sendiri.
 *
 * HANYA MENAMPILKAN. Penyimpanannya memakai pintu yang sudah ada —
 * ProfileController::updatePassword — supaya aturan sandinya tidak mungkin
 * berbeda antara ganti sukarela dan ganti wajib. Dua pintu dengan aturan
 * yang berbeda adalah pintu belakang yang menunggu ditemukan.
 */
class GantiSandiWajibController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        // Yang sandinya sudah miliknya sendiri tidak punya urusan di sini;
        // ganti sandi sukarela ada di halaman profil.
        if (! $request->user()->must_change_password) {
            return redirect(DashboardController::pathFor($request->user()));
        }

        return view('auth.ganti-sandi-wajib', ['user' => $request->user()]);
    }
}
