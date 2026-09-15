<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk setiap halaman aplikasi.
 *
 * CONTENT-SECURITY-POLICY
 * -----------------------
 * Membatasi DARI MANA browser mau memuat skrip, gaya, font, dan bingkai.
 * Seandainya ada celah yang membuat teks buatan pengguna (nama customer,
 * catatan pesanan) lolos sebagai HTML, skrip yang disisipkan tetap tidak bisa
 * memuat kode dari server penyerang atau mengirim data ke sana.
 *
 * Daftar sumbernya persis yang dipakai view:
 *   cdn.jsdelivr.net      Bootstrap, Bootstrap Icons, SweetAlert2, Chart.js
 *   fonts.googleapis.com  CSS font Inter; berkas fontnya dari fonts.gstatic.com
 *   www.google.com,
 *   www.gstatic.com       widget reCAPTCHA di halaman login
 *
 * 'unsafe-inline' MASIH diizinkan untuk skrip dan gaya. Puluhan view memakai
 * <script> sebaris dan atribut onclick; memindahkan semuanya ke nonce adalah
 * pekerjaan tersendiri. Yang sudah ditutup: memuat skrip dari domain lain,
 * connect-src ke luar (pencurian data lewat fetch), <object>/<embed>,
 * pembajakan <base>, form yang dikirim ke domain lain, dan halaman ini
 * dibingkai situs lain (clickjacking).
 *
 * MENAMBAH CDN BARU? Tambahkan domainnya di sini, atau skripnya diam-diam
 * diblokir browser — gejalanya tombol yang tidak bereaksi, bukan galat.
 *
 * PERMISSIONS-POLICY
 * ------------------
 * Kamera hanya untuk halaman kita sendiri (foto barang sampai oleh supir,
 * foto Surat Jalan oleh Sales). Mikrofon, lokasi, dan pembayaran tidak
 * dipakai, jadi ditolak.
 */
class SecurityHeaders
{
    public const CSP = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://www.google.com https://www.gstatic.com",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
        "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net",
        "img-src 'self' data: blob:",
        "media-src 'self' blob:",
        "connect-src 'self'",
        'frame-src https://www.google.com https://recaptcha.google.com',
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', self::CSP));
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=()');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
