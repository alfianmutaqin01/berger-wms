{{-- Skrip form Buat Pesanan. Dipisah dari berkas view agar bagian markup
     dan bagian perilaku tidak saling menyulitkan saat dibaca. --}}

{{-- Kolom ketik-lalu-pilih diangkat ke partial bersama sejak halaman Booking
     memakainya juga: penundaan ketikan dan penanda permintaan terakhir harus
     diperbaiki di SATU tempat, bukan dua. --}}
@include('partials.pencarian-ketik')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ITEM_AWAL = @json($itemLama);
    const PRODUK_TERPILIH = @json($produkTerpilih);

    // Tidak ada lagi pemilih gudang di halaman ini: gudang Sales ditentukan
    // akunnya dan dibaca server dari sesi, bukan dikirim formulir.
    const daftar = document.getElementById('daftarItem');
    const cetakan = document.getElementById('templateItem');
    const pakaiDokumen = document.getElementById('pakaiDokumen');
    const sourceManual = document.getElementById('sourceManual');
    const blokDokumen = document.getElementById('blokDokumen');
    const blokItem = document.getElementById('blokItem');

    /* ---------------------------------------------------------- Customer */

    pasangPencarian(document.getElementById('cariCustomer'), {
        url: function (q) {
            return '/sales/lookup/customers?q=' + encodeURIComponent(q);
        },
        tampilan: function (c) {
            return '<span class="badge bg-light text-dark border font-monospace me-1">'
                + c.code + '</span>' + c.name
                + (c.menunggak > 0
                    ? ' <span class="badge bg-danger-subtle text-danger-emphasis border border-danger ms-1">⚠ Menunggak</span>'
                    : '');
        },
        label: function (c) { return c.code + ' — ' + c.name; },
        setelahPilih: function (c) {
            const peringatan = document.getElementById('peringatanPiutang');
            const menunggak = c && c.menunggak > 0;

            peringatan.classList.toggle('d-none', !menunggak);
            document.getElementById('hariPiutang').textContent = menunggak ? c.menunggak : 0;
        },
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
            badge.textContent = '—';
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
                // Tanpa warehouse_id: server memakai gudang akun Sales.
                // Mengirimnya dari sini berarti menyediakan lagi parameter
                // yang bisa diganti untuk mengintip stok gudang lain.
                return '/sales/lookup/products?q=' + encodeURIComponent(q);
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

    // Dahulu di sini ada pengosongan badge setiap kali gudang diganti.
    // Gudang tidak bisa diganti lagi, jadi indikatornya tidak pernah basi.

    /* --------------------------------------------------- Keadaan awal */

    if (ITEM_AWAL.length > 0) {
        ITEM_AWAL.forEach(function (item) { tambahBaris(item.product_id, item.qty); });
    } else {
        tambahBaris();
    }

    perbaruiMetode();
});
</script>
