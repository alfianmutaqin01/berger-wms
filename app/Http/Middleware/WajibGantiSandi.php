<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemilik sandi sementara dari admin tidak bisa memakai sistem sebelum
 * membuat sandinya sendiri.
 *
 * DIPASANG DI GRUP `web`, bukan di tiap grup rute. Aplikasi ini punya empat
 * grup rute terautentikasi, dan grup kelima yang ditambahkan kelak tidak akan
 * ingat memasang penjaga ini. Di grup `web` ia berlaku untuk semuanya, dan
 * pengunjung yang belum login dilewatkan begitu saja — halaman login, ePOD
 * supir, dan tautan persetujuan MRF tidak tersentuh.
 *
 * URUTANNYA DI DEPAN session.track, dan itu tidak apa-apa. Kalau sesinya
 * ternyata sudah dicabut, orangnya diarahkan ke halaman ganti sandi — yang
 * dijaga session.track — lalu dikeluarkan di sana. Hasil akhirnya sama.
 */
class WajibGantiSandi
{
    /**
     * Satu-satunya yang boleh dibuka selama sandinya masih sementara:
     * halaman gantinya, pintu simpannya, dan pintu keluar. Keluar WAJIB ada —
     * tanpa itu, orang yang tidak ingat sandi sementaranya terperangkap di
     * satu halaman tanpa jalan kembali ke login.
     */
    private const BOLEH = [
        'password.wajib-ganti',
        'profile.password',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::BOLEH, true)) {
            return $next($request);
        }

        // Permintaan fetch() — penghitungan stocktake, lonceng — tidak bisa
        // mengikuti redirect ke halaman HTML. Jawaban yang jujur lebih
        // berguna daripada halaman login yang muncul di dalam JSON.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Kata sandi Anda masih sandi sementara dari admin. Buat sandi baru dulu sebelum melanjutkan.',
            ], 403);
        }

        return redirect()->route('password.wajib-ganti');
    }
}
