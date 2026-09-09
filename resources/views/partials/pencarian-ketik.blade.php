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
     halus sebuah formulir bisa berbohong. --}}
<script>
window.pasangPencarian = function (wadah, opsi) {
    if (!wadah) return;

    const MIN_CARI = opsi.minimal || 2;

    const teks = wadah.querySelector('.cari-teks');
    const nilai = wadah.querySelector('.cari-nilai');
    const saran = wadah.querySelector('.cari-saran');

    let tunda = null;
    let permintaanKe = 0;

    function tutup() {
        saran.classList.add('d-none');
        saran.innerHTML = '';
    }

    function tampilkan(hasil) {
        saran.innerHTML = '';

        if (hasil.length === 0) {
            const kosong = document.createElement('div');
            kosong.className = 'list-group-item small text-muted';
            kosong.textContent = opsi.kosong || 'Tidak ada yang cocok.';
            saran.appendChild(kosong);
            saran.classList.remove('d-none');
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

        saran.classList.remove('d-none');
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
    // kolom di bawahnya.
    document.addEventListener('click', function (e) {
        if (!wadah.contains(e.target)) tutup();
    });
};
</script>
