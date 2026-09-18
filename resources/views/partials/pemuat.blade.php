{{-- PENANDA "SISTEM SEDANG BEKERJA".

     Aplikasi ini berpindah halaman dengan cara biasa: klik tautan, kirim
     formulir, peramban memuat halaman baru. Di antara klik dan halaman baru
     muncul, layarnya diam — satu-satunya tanda bahwa ada yang sedang terjadi
     adalah putaran kecil di tab peramban, yang hampir tidak ada yang melihat.
     Akibatnya orang menekan tombol dua kali, dan di gudang tombol yang
     ditekan dua kali berarti dokumen kembar.

     TIGA LAPIS, MUNCUL BERURUTAN
     ---------------------------
     Garis di atas layar (250 ms), tirai redup dengan tulisan (700 ms), dan
     tombolnya sendiri yang berputar serta mati seketika. Jedanya disengaja:
     halaman yang terbuka dalam 100 ms tidak boleh memunculkan kedipan, karena
     kedipan itu justru terbaca sebagai "ada yang salah". Yang lambatlah yang
     perlu menjelaskan diri.

     BERDIRI SENDIRI
     ---------------
     Berkas ini membawa gaya dan skripnya sendiri, tanpa bergantung pada
     soms-style.css, supaya halaman di luar layout — login, formulir MRF
     publik, ePOD supir — bisa memakainya apa adanya. Warnanya tetap mengikuti
     --primary bila ada, dan jatuh ke navy Berger bila tidak.

     Dipakai juga dari skrip lain lewat window.Pemuat.mulai() / .selesai()
     untuk pekerjaan yang tidak berpindah halaman. --}}
<style>
    .pemuat-garis {
        position: fixed;
        top: 0;
        left: 0;
        height: 3px;
        width: 0;
        z-index: 2000;
        background: var(--primary, #123962);
        box-shadow: 0 0 8px rgba(18, 57, 98, 0.5);
        animation: pemuat-maju 10s cubic-bezier(0.1, 0.6, 0.3, 1) forwards;
    }

    /* Berhenti di 90%, tidak pernah sampai 100% sampai halamannya benar-benar
       berganti. Bar yang penuh lalu diam adalah kebohongan kecil yang membuat
       orang mengira sistemnya menggantung. */
    @keyframes pemuat-maju {
        0%   { width: 0; }
        20%  { width: 45%; }
        50%  { width: 70%; }
        100% { width: 90%; }
    }

    .pemuat-tirai {
        position: fixed;
        inset: 0;
        z-index: 1999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.65);
        backdrop-filter: blur(1.5px);
    }

    .pemuat-kotak {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.85rem 1.25rem;
        border-radius: 0.75rem;
        background: #ffffff;
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.14);
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--primary, #123962);
    }

    .pemuat-putar {
        width: 1.15rem;
        height: 1.15rem;
        border: 2.5px solid rgba(18, 57, 98, 0.2);
        border-top-color: var(--primary, #123962);
        border-radius: 50%;
        animation: pemuat-putar 0.7s linear infinite;
        flex-shrink: 0;
    }

    @keyframes pemuat-putar {
        to { transform: rotate(360deg); }
    }

    /* Tombol yang sedang menunggu: putarannya menggantikan ikonnya, supaya
       lebarnya tidak berubah dan tata letak tidak melompat. */
    .pemuat-tombol > .bi {
        display: none;
    }

    .pemuat-tombol::before {
        content: '';
        display: inline-block;
        width: 0.9rem;
        height: 0.9rem;
        margin-right: 0.4rem;
        vertical-align: -0.1rem;
        border: 2px solid currentColor;
        border-top-color: transparent;
        border-radius: 50%;
        animation: pemuat-putar 0.7s linear infinite;
    }

    /* Yang meminta gerakan dikurangi tetap mendapat kabarnya, hanya tanpa
       yang berputar dan merayap. */
    @media (prefers-reduced-motion: reduce) {
        .pemuat-garis {
            animation: none;
            width: 100%;
            opacity: 0.85;
        }

        .pemuat-putar,
        .pemuat-tombol::before {
            animation: none;
        }
    }
</style>

<div class="pemuat-garis" id="pemuatGaris" hidden></div>
<div class="pemuat-tirai" id="pemuatTirai" hidden role="status" aria-live="polite">
    <div class="pemuat-kotak">
        <span class="pemuat-putar" aria-hidden="true"></span>
        <span>Sedang diproses…</span>
    </div>
</div>

<script>
(function () {
    'use strict';

    var garis = document.getElementById('pemuatGaris');
    var tirai = document.getElementById('pemuatTirai');

    var JEDA_GARIS = 250;    // di bawah ini, kedipannya lebih mengganggu
    var JEDA_TIRAI = 700;    // yang selama ini perlu penjelasan
    var BATAS_AMAN = 20000;  // jaring terakhir; lihat catatan di selesai()

    var pewaktu = [];
    var aktif = false;
    var tombolTertahan = [];

    function bersihkanPewaktu() {
        pewaktu.forEach(clearTimeout);
        pewaktu = [];
    }

    function mulai() {
        if (aktif) return;
        aktif = true;

        pewaktu.push(setTimeout(function () { garis.hidden = false; }, JEDA_GARIS));
        pewaktu.push(setTimeout(function () { tirai.hidden = false; }, JEDA_TIRAI));

        // NAVIGASI BISA BATAL TANPA MEMBERI TAHU SIAPA PUN: unduhan, tautan
        // yang ternyata ditolak server, koneksi yang putus. Tanpa jaring ini
        // layarnya tertutup tirai selamanya dan satu-satunya jalan keluar
        // adalah memuat ulang.
        pewaktu.push(setTimeout(selesai, BATAS_AMAN));
    }

    function selesai() {
        bersihkanPewaktu();
        aktif = false;
        garis.hidden = true;
        tirai.hidden = true;

        tombolTertahan.forEach(function (tombol) {
            tombol.disabled = false;
            tombol.classList.remove('pemuat-tombol');
        });
        tombolTertahan = [];
    }

    function tahanTombol(tombol) {
        if (!tombol || tombol.disabled) return;

        tombol.classList.add('pemuat-tombol');

        // DIMATIKAN SETELAH SATU PUTARAN, BUKAN SEKARANG. Tombol submit yang
        // punya name/value ikut terkirim sebagai data formulir; mematikannya
        // sebelum peramban mengumpulkan datanya akan menghilangkan nilai itu
        // diam-diam, dan server menerima permintaan yang berbeda dari yang
        // ditekan orangnya.
        setTimeout(function () {
            tombol.disabled = true;
            tombolTertahan.push(tombol);
        }, 0);
    }

    /* ------------------------------------------------------------ Pemicunya */

    /* FASE GELEMBUNG, BUKAN TANGKAP — DAN INI PENTING.
       Dengan capture, pendengar ini berjalan SEBELUM handler milik halaman,
       sehingga e.defaultPrevented masih false pada saat diperiksa. Tautan yang
       memunculkan dialog konfirmasi lalu membatalkan navigasinya akan
       menyalakan penanda untuk perpindahan yang tidak pernah terjadi, dan
       tirainya menutup layar sampai jaring pengaman 20 detik membukanya.
       Di gelembung, semua handler halaman sudah selesai bicara. */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-tanpa-pemuat')) return;

        // Pengiriman yang sudah dibatalkan halaman — validasi sendiri, dialog
        // konfirmasi — tidak berpindah ke mana pun.
        if (e.defaultPrevented) return;

        tahanTombol(e.submitter);
        mulai();
    });

    document.addEventListener('click', function (e) {
        var tautan = e.target.closest ? e.target.closest('a[href]') : null;
        if (!tautan) return;

        var href = tautan.getAttribute('href') || '';

        var lewati =
            e.defaultPrevented ||
            e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ||
            tautan.hasAttribute('data-tanpa-pemuat') ||
            tautan.hasAttribute('download') ||
            tautan.target === '_blank' ||
            tautan.hasAttribute('data-bs-toggle') ||
            href === '' || href.charAt(0) === '#' ||
            /^(javascript|mailto|tel):/i.test(href) ||
            // Tautan ke luar: penandanya milik aplikasi ini, dan halaman yang
            // dituju tidak akan pernah memberitahu kita bahwa ia sudah terbuka.
            (tautan.hostname && tautan.hostname !== window.location.hostname) ||
            // UNDUHAN TIDAK MEMBUAT HALAMAN BERPINDAH. Berkas Excel dikirim
            // sebagai lampiran, halamannya tetap di tempat, dan tidak ada satu
            // peristiwa pun yang memberi tahu bahwa unduhannya selesai.
            /\/(unduh|download|export)(\/|\?|$)/i.test(href);

        if (lewati) return;

        mulai();
    });

    // Jaring untuk perpindahan yang tidak lewat klik: form.submit() dari
    // skrip, location.href, tombol kembali.
    window.addEventListener('beforeunload', mulai);

    /* HALAMAN YANG DIAMBIL DARI SIMPANAN PERAMBAN.
       Menekan Kembali tidak memuat ulang halaman, ia mengembalikannya persis
       seperti saat ditinggalkan — termasuk tirai yang menutup dan tombol yang
       mati. Operator gudang memakai tombol Kembali terus-menerus, jadi tanpa
       ini merekalah yang pertama menemukannya. */
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) selesai();
    });

    window.Pemuat = { mulai: mulai, selesai: selesai };
})();
</script>
