<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRD §6.1 F-AUTH-04: idle timeout 1 jam, dan sesi yang dievict oleh login
 * dari device lain harus langsung tertolak pada request berikutnya — bukan
 * baru ketahuan saat device itu mencoba login ulang.
 *
 * Baris di tabel `user_sessions`, dicocokkan lewat cookie `device_token`
 * (lihat AuthController::trackNewSession()), adalah sumber kebenaran —
 * BUKAN keberadaan `Auth::check()` semata. Session Laravel milik device yang
 * sudah dievict tetap valid di sisi Laravel sampai middleware ini menolaknya.
 */
class TrackUserSession
{
    private const DEVICE_COOKIE = 'device_token';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $token = $request->cookie(self::DEVICE_COOKIE);
        $session = $token ? UserSession::where('session_id', $token)->first() : null;

        if (! $session) {
            return $this->forceLogout($request, 'Sesi Anda telah berakhir karena login di perangkat lain.');
        }

        if ($session->last_activity_at->diffInMinutes(now()) >= 60) {
            $session->delete();

            return $this->forceLogout($request, 'Sesi berakhir karena tidak ada aktivitas selama 1 jam.');
        }

        $session->update(['last_activity_at' => now()]);

        return $next($request);
    }

    private function forceLogout(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', $message)->withoutCookie(self::DEVICE_COOKIE);
    }
}
