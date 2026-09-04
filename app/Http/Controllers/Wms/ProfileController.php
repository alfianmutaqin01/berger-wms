<?php

namespace App\Http\Controllers\Wms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wms\UpdatePasswordRequest;
use App\Models\UserSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Profil sendiri: ganti kata sandi dan kelola sesi device — PRD §6.1.
 *
 * SEBELUMNYA HALAMAN INI BERBOHONG DUA KALI, dan keduanya soal keamanan
 * akun — tempat kebohongan paling mahal:
 *
 *   updatePassword() menjawab "Kata sandi berhasil diperbarui" tanpa
 *   menyentuh basis data. Orang yang menggantinya akan menghafal sandi baru
 *   lalu terkunci di luar, sementara sandi lamanya masih berlaku.
 *
 *   Daftar sesi di layar adalah dua baris HTML mati ("Windows - Chrome",
 *   "Android - Berger WMS Mobile") dengan tombol Cabut Akses yang hanya
 *   menghapus barisnya dari layar lalu memunculkan alert. Seseorang yang
 *   curiga akunnya dipakai orang lain akan membuka halaman ini, mencabut
 *   akses, dan percaya dirinya sudah aman.
 *
 * Keduanya kini nyata, dibaca dari tabel `user_sessions` yang memang sudah
 * jadi sumber kebenaran bagi batas 2 device dan idle timeout.
 *
 * DATA CONTRACT
 * -------------
 * index() : $sesi Collection<UserSession>, $tokenSaatIni ?string
 */
class ProfileController extends Controller
{
    /** Sama dengan AuthController::DEVICE_COOKIE — identitas device. */
    private const DEVICE_COOKIE = 'device_token';

    public function index(Request $request): View
    {
        return view('wms.profile', [
            'sesi' => $request->user()->sessions()->latest('last_activity_at')->get(),
            'tokenSaatIni' => $request->cookie(self::DEVICE_COOKIE),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('new_password'),
            // Penghitung gagal login ikut bersih. Orang yang lupa sandinya
            // lalu menggantinya di sini tidak pantas membawa sisa hitungan
            // yang bisa mengunci akunnya pada kesalahan ketik berikutnya.
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        /*
         * SELURUH DEVICE LAIN DIPUTUS. Alasan orang mengganti sandi hampir
         * selalu salah satu dari dua: lupa, atau curiga ada yang memakai
         * akunnya. Pada kemungkinan kedua, mengganti sandi tanpa memutus
         * sesi yang sedang berjalan tidak mengusir siapa pun — penyusupnya
         * tetap masuk sampai sesinya sendiri kedaluwarsa.
         */
        $diputus = $this->sesiLain($request)->count();
        $this->sesiLain($request)->delete();

        return back()->with('success', $diputus > 0
            ? sprintf('Kata sandi diperbarui. %d perangkat lain ikut dikeluarkan.', $diputus)
            : 'Kata sandi diperbarui.');
    }

    /** Mengeluarkan seluruh perangkat lain tanpa mengganti kata sandi. */
    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $jumlah = $this->sesiLain($request)->count();

        if ($jumlah < 1) {
            return back()->with('warning', 'Tidak ada perangkat lain yang sedang masuk.');
        }

        $this->sesiLain($request)->delete();

        return back()->with('success', sprintf('%d perangkat lain dikeluarkan.', $jumlah));
    }

    /** Mencabut satu perangkat. */
    public function revokeSession(Request $request, UserSession $session): RedirectResponse
    {
        // 404, BUKAN 403: sesi milik orang lain keberadaannya bukan urusan
        // pengguna ini, dan 403 mengakui bahwa barisnya ada.
        abort_unless($session->user_id === $request->user()->id, 404);

        if ($session->session_id === $request->cookie(self::DEVICE_COOKIE)) {
            return back()->with('error', 'Itu perangkat yang sedang Anda pakai. Gunakan tombol Keluar.');
        }

        $session->delete();

        return back()->with('success', 'Perangkat dikeluarkan.');
    }

    /* ------------------------------------------------------------- Dalam */

    /**
     * Sesi milik user ini SELAIN device yang sedang dipakai.
     *
     * Dikembalikan sebagai query, bukan koleksi: pemanggilnya menghitung lalu
     * menghapus, dan menghapus dari koleksi yang sudah dimuat akan melewatkan
     * baris yang muncul di antara keduanya.
     */
    private function sesiLain(Request $request)
    {
        return $request->user()->sessions()
            ->where('session_id', '<>', (string) $request->cookie(self::DEVICE_COOKIE));
    }
}
