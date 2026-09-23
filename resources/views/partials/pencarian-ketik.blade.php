{{-- Kolom "ketik lalu pilih" — dipakai formulir Buat Pesanan dan Booking.

     KENAPA DIANGKAT JADI SATU BERKAS
     --------------------------------
     Perilakunya kelihatan sepele tetapi punya dua jebakan yang keduanya
     baru terlihat saat dipakai orang sungguhan, dan keduanya harus
     diperbaiki di SATU tempat kalau ketahuan salah:

       1. PENUNDAAN (debounce). Tanpa ini, mengetik "APKO" mengirim empat
          permintaan ke server untuk satu kata.
       2. PENANDA PERMINTAAN TERAKHIR. Jawaban untuk "A" bisa datang
          setelah jawaban "APKO" — dan menimpanya. Yang terlihat di layar
          jadi daftar yang tidak ada hubungannya dengan yang sedang
          diketik, dan orang memilih dari daftar yang salah tanpa curiga.

     STRUKTUR MARKUP YANG DIHARAPKAN
     -------------------------------
       <div id="...">                       <- wadah
         <input class="cari-teks">          <- yang diketik orang
         <input type="hidden" class="cari-nilai" name="..."> <- id terkirim
         <div class="list-group cari-saran d-none"></div>
       </div>

     `cari-nilai` yang kosong berarti BELUM ADA yang dipilih. Mengubah
     ketikan selalu mengosongkannya, supaya teks yang terlihat tidak pernah
     berbeda dari id yang benar-benar terkirim — salah satu cara paling
     halus sebuah formulir bisa berbohong.

     KENAPA DAFTAR SARANNYA MELAYANG (position: fixed) DI <body>
     -----------------------------------------------------------
     Daftar ini sering berada di dalam kotak yang memotong isinya: sel tabel
     di `.table-responsive` (yang punya overflow demi geser mendatar), kartu
     berujung membulat, atau panel yang bisa digulir. Dengan `position:
     absolute`, daftar saran ikut terpotong oleh kotak itu — di layar MRF
     hanya dua baris teratas yang terlihat, dan orang memilih produk sambil
     menebak.

     `fixed` saja tidak cukup, dan kegagalannya jauh lebih membingungkan
     daripada terpotong: `soms-style.css` memberi `.card:hover` sebuah
     `transform`, dan elemen ber-transform menjadi containing block bagi
     keturunannya yang `position: fixed`. Padahal kartu itu SELALU sedang
     di-hover saat orang mengetik di dalamnya. Akibatnya koordinat layar yang
     kita hitung dibaca sebagai koordinat terhadap kartu, dan daftar saran
     melompat ke kanan sejauh lebar sidebar lalu naik ke atas formulir.
     (Jebakan yang sama pernah terjadi lewat animasi `<main>` — lihat
     komentarnya di `public/css/soms-style.css`.)

     Karena itu daftar saran DIPINDAHKAN ke <body> selama terbuka, dan
     dikembalikan ke tempat asalnya saat ditutup. Di <body> tidak ada leluhur
     ber-transform, sehingga koordinat layar berlaku apa adanya. Posisinya
     dihitung sendiri terhadap kolom ketiknya, dan dihitung ulang setiap kali
     halaman atau wadahnya digulir. --}}
<style>
    /* LATARNYA WAJIB PEKAT.

       Daftar ini melayang di atas isi halaman, jadi warna setengah tembus apa
       pun akan menampilkan tulisan di belakangnya — tepat pada saat orang
       membaca nama produk yang mau diklik. Sebelumnya warna sorotnya
       `rgba(primary, .06)`: tembus pandang, tetapi tidak ketahuan selama
       daftarnya masih tertanam di dalam kartu putih. */
    .cari-saran,
    .cari-saran .list-group-item {
        background-color: #ffffff;
    }

    /* #f1f3f6 = rupa akhir dari rgba(18, 57, 98, .06) di atas putih — warnanya
       sama dengan rancangan semula, hanya saja pekat. */
    .cari-saran .list-group-item:hover,
    .cari-saran .list-group-item:focus,
    .cari-saran .list-group-item:active {
        background-color: #f1f3f6;
        color: inherit;
    }
</style>
<script>
window.pasangPencarian = function (wadah, opsi) {
    if (!wadah) return;

    const MIN_CARI = opsi.minimal || 2;

    const teks = wadah.querySelector('.cari-teks');
    const nilai = wadah.querySelector('.cari-nilai');
    const saran = wadah.querySelector('.cari-saran');

    // Tempat pulang daftar saran setelah ditutup. Dengan begini baris tabel
    // yang dihapus membawa serta daftarnya, tidak meninggalkan sisa di <body>.
    const indukAsli = saran.parentNode;

    let tunda = null;
    let permintaanKe = 0;

    // Jarak minimal daftar saran dari tepi layar, supaya baris terakhir tidak
    // menempel persis di batas bawah jendela.
    const SELA = 8;

    // Gaya yang kita pasang sendiri, untuk dibersihkan lagi saat ditutup.
    const GAYA = ['position', 'left', 'top', 'bottom', 'width', 'max-height', 'overflow-y', 'z-index'];

    /*
     | WAJIB `!important`. Daftar saran di setiap formulir memakai kelas
     | Bootstrap `position-absolute` dan `w-100`, dan kedua kelas utilitas itu
     | sendiri ber-`!important`. Gaya inline biasa KALAH melawannya: posisinya
     | tetap absolute (jadi tetap terpotong wadahnya) dan lebarnya menjadi
     | selebar layar. Keduanya gagal tanpa satu pun pesan galat.
     */
    function atur(sifat, nilaiGaya) {
        saran.style.setProperty(sifat, nilaiGaya, 'important');
    }

    function tempatkan() {
        if (saran.classList.contains('d-none')) return;

        const kotak = teks.getBoundingClientRect();

        // Kolom ketiknya sendiri sudah tergulir keluar layar. Daftar yang
        // melayang tidak ikut hilang dengan sendirinya — ia akan menggantung
        // di atas bagian halaman yang tidak ada hubungannya.
        if (kotak.bottom < 0 || kotak.top > window.innerHeight) {
            tutup();
            return;
        }

        const ruangBawah = window.innerHeight - kotak.bottom - SELA;
        const ruangAtas = kotak.top - SELA;

        // Di bawah kolom ketik, kecuali ruang di sana sudah sempit DAN ruang di
        // atasnya lebih lega — mis. baris terakhir tabel di layar pendek.
        const keAtas = ruangBawah < 160 && ruangAtas > ruangBawah;
        const ruang = keAtas ? ruangAtas : ruangBawah;

        // Di layar sempit, kolom ketik bisa berada dekat tepi kanan.
        const kiri = Math.max(SELA, Math.min(kotak.left, window.innerWidth - kotak.width - SELA));

        atur('position', 'fixed');
        atur('left', kiri + 'px');
        atur('width', kotak.width + 'px');
        atur('max-height', Math.max(96, Math.min(260, ruang)) + 'px');
        atur('overflow-y', 'auto');
        // Di atas kartu dan tabel, di bawah modal Bootstrap (1055).
        atur('z-index', '1050');

        if (keAtas) {
            atur('top', 'auto');
            atur('bottom', (window.innerHeight - kotak.top + 4) + 'px');
        } else {
            atur('bottom', 'auto');
            atur('top', (kotak.bottom + 4) + 'px');
        }
    }

    // `true` = ikut mendengar guliran di dalam wadah (tabel, panel), bukan
    // hanya guliran halaman. Tanpa itu daftar saran tertinggal di tempatnya.
    function dengarkanGeser(pasang) {
        const cara = pasang ? 'addEventListener' : 'removeEventListener';
        window[cara]('scroll', tempatkan, true);
        window[cara]('resize', tempatkan);
    }

    function tutup() {
        saran.classList.add('d-none');
        saran.innerHTML = '';
        GAYA.forEach(function (sifat) { saran.style.removeProperty(sifat); });
        if (saran.parentNode !== indukAsli) indukAsli.appendChild(saran);
        dengarkanGeser(false);
    }

    function buka() {
        const tadinyaTertutup = saran.classList.contains('d-none');
        if (saran.parentNode !== document.body) document.body.appendChild(saran);
        saran.classList.remove('d-none');
        if (tadinyaTertutup) dengarkanGeser(true);
        tempatkan();
    }

    function tampilkan(hasil) {
        saran.innerHTML = '';

        if (hasil.length === 0) {
            const kosong = document.createElement('div');
            kosong.className = 'list-group-item small text-muted';
            kosong.textContent = opsi.kosong || 'Tidak ada yang cocok.';
            saran.appendChild(kosong);
            buka();
            return;
        }

        hasil.forEach(function (item) {
            const baris = document.createElement('button');
            baris.type = 'button';
            baris.className = 'list-group-item list-group-item-action py-2';
            baris.innerHTML = opsi.tampilan(item);
            baris.addEventListener('click', function () {
                nilai.value = item.id;
                teks.value = opsi.label(item);
                tutup();
                if (opsi.setelahPilih) opsi.setelahPilih(item);
            });
            saran.appendChild(baris);
        });

        buka();
    }

    function cari() {
        const q = teks.value.trim();

        if (q.length < MIN_CARI) {
            tutup();
            return;
        }

        const ini = ++permintaanKe;

        fetch(opsi.url(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (hasil) {
                if (ini !== permintaanKe) return;   // jawaban terlambat, abaikan
                tampilkan(hasil);
            })
            .catch(function () { if (ini === permintaanKe) tutup(); });
    }

    teks.addEventListener('input', function () {
        // Mengubah ketikan membatalkan pilihan sebelumnya, supaya teks yang
        // terlihat tidak pernah berbeda dari id yang terkirim.
        nilai.value = '';
        if (opsi.setelahPilih) opsi.setelahPilih(null);

        clearTimeout(tunda);
        tunda = setTimeout(cari, 250);
    });

    teks.addEventListener('focus', cari);
    teks.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') tutup();
    });

    // Klik di luar menutup saran; tanpa ini daftarnya menggantung menutupi
    // kolom di bawahnya. Daftar saran ikut diperiksa terpisah karena selama
    // terbuka ia tinggal di <body>, bukan lagi di dalam `wadah`.
    document.addEventListener('click', function (e) {
        if (!wadah.contains(e.target) && !saran.contains(e.target)) tutup();
    });
};
</script>
