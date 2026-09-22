<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabel = document.getElementById('tabelPicking');
    const total = document.querySelectorAll('.baris-ambil').length;

    const angkaSelesai = document.getElementById('angkaSelesai');
    const angkaKurang = document.getElementById('angkaKurang');
    const lencanaKurang = document.getElementById('lencanaKurang');
    const bilah = document.getElementById('bilahKemajuan');
    const tombolSelesai = document.getElementById('tombolSiapLoading');
    const catatanBelum = document.getElementById('catatanBelumLengkap');
    const sembunyikan = document.getElementById('sembunyikanSelesai');

    const kabarEl = document.getElementById('kabar');
    const modalEl = document.getElementById('modalSelisih');
    const formSelisih = document.getElementById('formSelisih');
    const galatSelisih = document.getElementById('selisihGalat');

    let pewaktuKabar = null;

    /*
     | Toast dan Modal ditangani tanpa API JavaScript Bootstrap.
     |
     | Menampilkan toast cukup dengan menambah kelas .show (Bootstrap
     | menanganinya lewat CSS), dan menutup modal cukup dengan menekan
     | tombol dismiss-nya sendiri.
     */
    function beriKabar(pesan, jenis) {
        if (! kabarEl) { return; }

        kabarEl.className = 'toast show align-items-center border-0 shadow text-bg-' +
            (jenis === 'error' ? 'danger' : (jenis === 'warning' ? 'warning' : 'success'));
        document.getElementById('kabarPesan').textContent = pesan;

        clearTimeout(pewaktuKabar);
        pewaktuKabar = setTimeout(function () {
            kabarEl.classList.remove('show');
        }, jenis === 'error' ? 6000 : 2500);
    }

    function tutupModal() {
        const dismiss = modalEl ? modalEl.querySelector('[data-bs-dismiss="modal"]') : null;
        if (dismiss) { dismiss.click(); }
    }

    /** Memperbarui satu baris di tempatnya, tanpa menyentuh yang lain. */
    function perbaruiBaris(data) {
        const baris = document.getElementById('baris-' + data.id);
        if (! baris) { return; }

        baris.dataset.status = data.status;
        baris.classList.remove('table-success', 'table-warning');

        const tampilkan = function (pemilih, tampil) {
            const el = baris.querySelector(pemilih);
            if (el) { el.hidden = ! tampil; }
        };

        tampilkan('.status-pending', data.status === 'pending');
        tampilkan('.status-picked', data.status === 'picked');
        tampilkan('.status-short', data.status === 'short');
        tampilkan('.sel-ditemukan', data.status === 'short');
        tampilkan('.aksi-pending', data.status === 'pending');
        tampilkan('.aksi-ditandai', data.status !== 'pending');

        if (data.status === 'picked') { baris.classList.add('table-success'); }
        if (data.status === 'short') {
            baris.classList.add('table-warning');
            const ditemukan = baris.querySelector('.nilai-ditemukan');
            const alasan = baris.querySelector('.nilai-alasan');
            if (ditemukan) { ditemukan.textContent = data.qty_picked; }
            if (alasan) { alasan.textContent = data.alasan || ''; }
        }

        terapkanPenyembunyian();
    }

    function perbaruiRingkasan(r) {
        angkaSelesai.textContent = r.selesai;
        angkaKurang.textContent = r.kurang;
        lencanaKurang.hidden = r.kurang < 1;
        bilah.style.width = (r.total > 0 ? Math.round(r.selesai / r.total * 100) : 0) + '%';

        const lengkap = r.selesai >= r.total;
        if (tombolSelesai) { tombolSelesai.disabled = ! lengkap; }
        if (catatanBelum) { catatanBelum.hidden = lengkap; }
    }

    /**
     * Membawa baris BERIKUTNYA yang belum ditandai ke tengah layar.
     *
     * Ini inti perbaikannya. Bukan sekadar "jangan kembali ke atas" —
     * sasaran operator selanjutnya yang datang menghampiri, sehingga
     * tangannya tidak perlu meninggalkan tombol.
     */
    function lompatKeBerikutnya(dariId) {
        const semua = Array.from(document.querySelectorAll('.baris-ambil'));
        const mulai = semua.findIndex(b => b.id === 'baris-' + dariId);

        const berikut = semua.slice(mulai + 1).find(b => b.dataset.status === 'pending')
            || semua.find(b => b.dataset.status === 'pending');

        if (berikut) {
            berikut.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // Tidak ada lagi yang tersisa: yang dicari operator sekarang adalah
        // tombol Siap Loading, bukan baris.
        if (tombolSelesai) {
            tombolSelesai.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function terapkanPenyembunyian() {
        if (! sembunyikan) { return; }
        document.querySelectorAll('.baris-ambil').forEach(function (baris) {
            baris.hidden = sembunyikan.checked && baris.dataset.status !== 'pending';
        });
    }

    if (sembunyikan) { sembunyikan.addEventListener('change', terapkanPenyembunyian); }

    /** Mengirim satu aksi baris tanpa memuat ulang halaman. */
    function kirim(form, idBaris) {
        const tombol = form.querySelector('button[type="submit"], button:not([type])');
        if (tombol) { tombol.disabled = true; }

        return fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: new FormData(form),
        })
            .then(function (respons) {
                return respons.json().then(function (data) {
                    return { ok: respons.ok, data: data };
                });
            })
            .then(function (hasil) {
                if (! hasil.ok) {
                    // Galat validasi datang sebagai {errors:{...}}, penolakan
                    // aturan sebagai {pesan}. Keduanya bukan alasan untuk
                    // menandai baris di layar.
                    const errors = hasil.data.errors;
                    const pesan = errors
                        ? Object.values(errors).flat().join(' ')
                        : (hasil.data.pesan || 'Gagal menyimpan. Coba lagi.');
                    throw new Error(pesan);
                }

                perbaruiBaris(hasil.data.item);
                perbaruiRingkasan(hasil.data.ringkas);
                beriKabar(hasil.data.pesan, hasil.data.jenis);

                if (hasil.data.item.status !== 'pending') {
                    lompatKeBerikutnya(idBaris);
                }

                return true;
            })
            .catch(function (galat) {
                beriKabar(galat.message || 'Gagal menyimpan. Periksa jaringan lalu coba lagi.', 'error');

                return false;
            })
            .finally(function () {
                if (tombol) { tombol.disabled = false; }
            });
    }

    // Tombol Ambil dan Batal tanda di dalam tabel.
    if (tabel) {
        tabel.addEventListener('submit', function (peristiwa) {
            const form = peristiwa.target.closest('.aksi-picking');
            if (! form) { return; }

            peristiwa.preventDefault();
            kirim(form, form.dataset.baris);
        });
    }

    // Pintu selisih.
    document.querySelectorAll('.tombol-selisih').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            formSelisih.action = tombol.dataset.aksi;
            formSelisih.dataset.baris = tombol.dataset.baris;
            document.getElementById('selisihSku').textContent = tombol.dataset.sku || '';
            document.getElementById('selisihRak').textContent = 'Rak ' + (tombol.dataset.rak || '—');
            document.getElementById('selisihTertulis').textContent = tombol.dataset.qty;
            galatSelisih.hidden = true;

            // Batas atas mengikuti baris yang ditekan: mengambil LEBIH banyak
            // daripada yang dicadangkan berarti mengambil jatah pesanan lain
            // dari batch yang sama.
            const qty = document.getElementById('selisihQty');
            qty.max = Number(tombol.dataset.qty) - 1;
            qty.value = '';
            document.getElementById('selisihAlasan').value = '';
        });
    });

    if (formSelisih) {
        formSelisih.addEventListener('submit', function (peristiwa) {
            peristiwa.preventDefault();

            kirim(formSelisih, formSelisih.dataset.baris).then(function (berhasil) {
                if (berhasil) {
                    tutupModal();
                } else {
                    // Galatnya ditulis DI DALAM modal juga: kabar mengambang
                    // di sudut layar mudah terlewat ketika perhatian sedang
                    // tertuju ke kotak isian yang baru saja ditolak.
                    galatSelisih.textContent = document.getElementById('kabarPesan').textContent;
                    galatSelisih.hidden = false;
                }
            });
        });
    }
});
</script>
