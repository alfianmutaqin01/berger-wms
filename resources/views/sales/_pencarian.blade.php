{{-- Skrip form Buat Pesanan. Dipisah dari berkas view agar bagian markup
     dan bagian perilaku tidak saling menyulitkan saat dibaca. --}}
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ITEM_AWAL = @json($itemLama);
    const PRODUK_TERPILIH = @json($produkTerpilih);
    const MIN_CARI = 2;

    const gudang = document.getElementById('warehouseSelect');
    const daftar = document.getElementById('daftarItem');
    const cetakan = document.getElementById('templateItem');
    const pakaiDokumen = document.getElementById('pakaiDokumen');
    const sourceManual = document.getElementById('sourceManual');
    const blokDokumen = document.getElementById('blokDokumen');
    const blokItem = document.getElementById('blokItem');

    /* ==================================================================
     | Kolom pencarian — dipakai customer maupun produk.
     |
     | Daftarnya TIDAK ada di halaman; tiap ketikan menanyakannya ke server.
     | Itulah sebabnya ada penundaan (debounce) dan penanda permintaan
     | terakhir: tanpa keduanya, mengetik "APKO" mengirim empat permintaan,
     | dan jawaban untuk "A" yang datang belakangan bisa menimpa jawaban
     | "APKO" yang sudah benar.
     ================================================================== */
    function pasangPencarian(wadah, opsi) {
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
                kosong.textContent = 'Tidak ada yang cocok.';
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

            fetch(opsi.url(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (hasil) {
                    if (ini !== permintaanKe) return;   // jawaban terlambat, abaikan
                    tampilkan(hasil);
                })
                .catch(function () { if (ini === permintaanKe) tutup(); });
        }

        teks.addEventListener('input', function () {
            // Mengubah ketikan membatalkan pilihan sebelumnya, supaya teks
            // yang terlihat tidak pernah berbeda dari id yang terkirim.
            nilai.value = '';
            if (opsi.setelahPilih) opsi.setelahPilih(null);

            clearTimeout(tunda);
            tunda = setTimeout(cari, 250);
        });

        teks.addEventListener('focus', cari);
        teks.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') tutup();
        });

        // Klik di luar menutup saran; tanpa ini daftarnya menggantung
        // menutupi kolom di bawahnya.
        document.addEventListener('click', function (e) {
            if (!wadah.contains(e.target)) tutup();
        });
    }

    /* ---------------------------------------------------------- Customer */

    pasangPencarian(document.getElementById('cariCustomer'), {
        url: function (q) {
            return '/sales/lookup/customers?q=' + encodeURIComponent(q);
        },
        tampilan: function (c) {
            return '<span class="badge bg-light text-dark border font-monospace me-1">'
                + c.code + '</span>' + c.name;
        },
        label: function (c) { return c.code + ' — ' + c.name; },
    });

    /* -------------------------------------------------- Metode pemesanan */

    function perbaruiMetode() {
        const dokumen = pakaiDokumen.checked;

        blokDokumen.classList.toggle('d-none', !dokumen);
        blokItem.classList.toggle('d-none', dokumen);

        // Checkbox yang tidak dicentang tidak terkirim; input tersembunyi
        // 'manual' dimatikan saat dicentang supaya hanya satu nilai
        // order_source yang sampai ke server.
        sourceManual.disabled = dokumen;

        // Item yang tersembunyi tidak boleh ikut terkirim maupun divalidasi
        // browser: `required` pada elemen tersembunyi membuat form macet
        // tanpa pesan apa pun.
        daftar.querySelectorAll('input').forEach(function (el) {
            el.disabled = dokumen;
        });
    }

    pakaiDokumen.addEventListener('change', perbaruiMetode);

    /* ------------------------------------------------------- Baris item */

    function pasangBadge(baris, produk) {
        const badge = baris.querySelector('.badge-indikator');

        if (!produk || !produk.indicator) {
            badge.className = 'badge bg-secondary badge-indikator flex-grow-1 text-center';
            badge.textContent = gudang.value ? '—' : 'Pilih gudang';
            return;
        }

        badge.className = 'badge badge-indikator flex-grow-1 text-center ' + produk.badge;
        badge.textContent = produk.label;
    }

    function nomoriUlang() {
        // Nama input dinomori ULANG setelah tiap penambahan/penghapusan.
        // Kalau nomornya ikut baris yang dihapus, array items[] di server
        // jadi bolong dan baris terakhir hilang diam-diam.
        daftar.querySelectorAll('.baris-item').forEach(function (baris, i) {
            baris.querySelector('.pilih-produk').name = 'items[' + i + '][product_id]';
            baris.querySelector('.isi-qty').name = 'items[' + i + '][qty]';
        });
    }

    function tambahBaris(produkId, qty) {
        const baris = cetakan.content.firstElementChild.cloneNode(true);

        if (qty) baris.querySelector('.isi-qty').value = qty;

        pasangPencarian(baris.querySelector('.cari-produk'), {
            url: function (q) {
                return '/sales/lookup/products?q=' + encodeURIComponent(q)
                    + '&warehouse_id=' + encodeURIComponent(gudang.value || '');
            },
            tampilan: function (p) {
                const badge = p.label
                    ? '<span class="badge ' + p.badge + ' ms-1 flex-shrink-0">' + p.label + '</span>'
                    : '';

                return '<div class="d-flex justify-content-between align-items-center gap-2">'
                    + '<span><span class="font-monospace small text-muted">' + p.sku + '</span>'
                    + '<br>' + p.name + '</span>' + badge + '</div>';
            },
            label: function (p) { return p.sku + ' — ' + p.name; },
            setelahPilih: function (p) { pasangBadge(baris, p); },
        });

        baris.querySelector('.hapus-item').addEventListener('click', function () {
            baris.remove();
            nomoriUlang();
        });

        daftar.appendChild(baris);

        // Baris yang sudah punya produk (draft dibuka, atau kembali dari
        // validasi yang gagal): labelnya diisi dari data yang dikirim
        // server, bukan dicari ulang ke jaringan.
        if (produkId && PRODUK_TERPILIH[produkId]) {
            const p = PRODUK_TERPILIH[produkId];
            baris.querySelector('.cari-nilai').value = p.id;
            baris.querySelector('.cari-teks').value = p.sku + ' — ' + p.name;
        }

        nomoriUlang();
        pasangBadge(baris, null);
    }

    document.getElementById('tambahItem').addEventListener('click', function () {
        tambahBaris();
    });

    // Ganti gudang = indikator lama tidak berlaku lagi. Badge dikosongkan,
    // bukan dibiarkan menampilkan keadaan gudang sebelumnya yang menyesatkan.
    gudang.addEventListener('change', function () {
        daftar.querySelectorAll('.baris-item').forEach(function (baris) {
            pasangBadge(baris, null);
        });
    });

    /* --------------------------------------------------- Keadaan awal */

    if (ITEM_AWAL.length > 0) {
        ITEM_AWAL.forEach(function (item) { tambahBaris(item.product_id, item.qty); });
    } else {
        tambahBaris();
    }

    perbaruiMetode();
});
</script>
