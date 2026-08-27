<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Login, logout, dan penegakan sesi. PRD §6.1 F-AUTH-01/02/03/04/05.
 *
 * Verifikasi Anti-Bot (F-AUTH-02, Google reCAPTCHA v2) menyatu di form login
 * yang sama — BUKAN halaman verifikasi terpisah seperti rancangan MFA lama.
 * Kegagalannya (token tidak valid/kedaluwarsa/kosong) masuk ke counter lockout
 * yang sama dengan password salah, lihat User::registerFailedLogin().
 */
class AuthController extends Controller
{
    /** Nama cookie token device. Lihat catatan panjang di trackNewSession(). */
    private const DEVICE_COOKIE = 'device_token';

    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect($this->redirectPathFor(Auth::user()));
        }

        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();

        $user = User::with('role')->where('email', $credentials['email'])->first();

        if (! $user) {
            $this->logAttempt($credentials['email'], false, 'wrong_password', $request);

            return $this->invalidCredentialsResponse();
        }

        if (! $user->is_active) {
            $this->logAttempt($user->email, false, 'inactive', $request);

            return back()->withErrors([
                'email' => 'Akun Anda tidak aktif. Hubungi Administrator.',
            ])->onlyInput('email');
        }

        if ($user->isCurrentlyLocked()) {
            $this->logAttempt($user->email, false, 'locked', $request);

            return back()->withErrors([
                'email' => 'Akun terkunci sampai pukul '.$user->locked_until->translatedFormat('H:i').
                    ' karena terlalu banyak percobaan gagal.',
            ])->onlyInput('email');
        }

        if (! $this->verifyRecaptcha((string) $request->input('g-recaptcha-response'))) {
            $user->registerFailedLogin();
            $this->logAttempt($user->email, false, 'recaptcha_failed', $request);

            return $this->invalidCredentialsResponse();
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $user->registerFailedLogin();
            $this->logAttempt($user->email, false, 'wrong_password', $request);

            return $this->invalidCredentialsResponse();
        }

        $user->registerSuccessfulLogin();
        $this->logAttempt($user->email, true, null, $request);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $deviceToken = $this->trackNewSession($user, $request);

        return redirect($this->redirectPathFor($user))
            ->withCookie(cookie(self::DEVICE_COOKIE, $deviceToken, 60 * 24 * 365, httpOnly: true));
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($token = $request->cookie(self::DEVICE_COOKIE)) {
            UserSession::where('session_id', $token)->delete();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withoutCookie(self::DEVICE_COOKIE);
    }

    /**
     * PRD §6.1 F-AUTH-05: routing berdasarkan role. Tim Sales -> Portal Sales,
     * seluruh role lain -> Portal Warehouse/Admin (dashboard berbeda per role
     * operasional, lihat DashboardController).
     */
    private function redirectPathFor(User $user): string
    {
        if ($user->hasRole(Role::SALES)) {
            return '/sales/dashboard';
        }

        return match ($user->role?->slug) {
            Role::PRODUCTION => '/wms/dashboard/produksi',
            Role::WAREHOUSE_OPERATOR => '/wms/dashboard/operator',
            default => '/wms/dashboard/admin',
        };
    }

    /**
     * PRD §6.1 F-AUTH-04: maksimal 2 device aktif bersamaan. Begitu device
     * ke-3 login, sesi paling tua (berdasarkan waktu login) dipaksa berakhir.
     *
     * Sengaja TIDAK memakai `$request->session()->getId()` sebagai identitas
     * device: ID sesi Laravel berubah tiap kali `regenerate()` dipanggil (baris
     * di atas, untuk mencegah session fixation) dan tidak konsisten lintas
     * request tanpa cookie sesi — keduanya membuat pelacakan multi-device jadi
     * rapuh. Sebagai gantinya kita terbitkan token kita sendiri, disimpan di
     * cookie terpisah (`device_token`) yang independen dari siklus sesi
     * Laravel, dan dikecualikan dari enkripsi cookie (lihat bootstrap/app.php)
     * supaya nilainya stabil untuk dibandingkan langsung.
     */
    private function trackNewSession(User $user, Request $request): string
    {
        $activeSessions = $user->sessions()->oldest('created_at')->get();

        while ($activeSessions->count() >= 2) {
            $activeSessions->shift()->delete();
        }

        $token = Str::random(64);

        UserSession::create([
            'user_id' => $user->id,
            'session_id' => $token,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'last_activity_at' => now(),
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * PRD §6.1 F-AUTH-02: verifikasi token widget "Saya bukan robot" ke Google
     * siteverify. Dipanggil dari request POST /login yang sama — bukan rute
     * terpisah — sehingga kegagalannya bisa langsung masuk ke alur lockout.
     *
     * Secret key kosong DIANGGAP LULUS di luar production, supaya development
     * lokal tanpa kredensial reCAPTCHA sendiri tidak ikut terkunci (mengikuti
     * pola pagar environment yang sama dengan CurrentActor). Di production,
     * secret key kosong berarti verifikasi ke Google gagal terkirim -> token
     * tidak pernah tervalidasi -> login tertahan, bukan diam-diam dilewati.
     */
    private function verifyRecaptcha(string $token): bool
    {
        $secret = config('services.recaptcha.secret_key');

        if (blank($secret)) {
            return ! app()->environment('production');
        }

        if (blank($token)) {
            return false;
        }

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secret,
            'response' => $token,
        ]);

        return $response->successful() && $response->json('success') === true;
    }

    private function logAttempt(string $email, bool $successful, ?string $reason, Request $request): void
    {
        LoginAttempt::create([
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'is_successful' => $successful,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** Pesan generik disengaja — supaya tidak membocorkan email mana yang terdaftar. */
    private function invalidCredentialsResponse(): RedirectResponse
    {
        return back()->withErrors([
            'email' => 'Email atau Password salah.',
        ])->onlyInput('email');
    }
}
