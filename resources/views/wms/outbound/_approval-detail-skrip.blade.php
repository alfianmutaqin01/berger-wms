<script>
(function () {
    'use strict';

    const BERBASIS_DOKUMEN = @json($order->isDocumentBased());
    const URL_RESOLVE = @json(route('wms.approval.resolve', $order));
    const URL_CEK_SO = @json(route('wms.approval.check-so', $order));
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;

    /*
        Satu sumber kebenaran untuk isi kisi. Baris DOM dibangun ulang dari
        array ini, bukan disunting di tempat — menyunting DOM langsung berarti
        indeks name="item[i][...]" bisa bolong setelah baris dihapus, dan
        Laravel menerima array bolong itu tanpa keluhan.
    */
    let baris = @json($baris);

    const isiKisi = document.getElementById('isiKisi');
    const kisiKosong = document.getElementById('kisiKosong');
    const peringatan = document.getElementById('peringatanStok');

    const angka = (n) => Number.isFinite(n) ? n : 0;

    function gambar() {
        isiKisi.innerHTML = '';

        baris.forEach((b, i) => {
            const setuju = angka(parseInt(b.setuju ?? b.usul ?? 0, 10));
            const kurang = Math.max(0, setuju - angka(b.stok));

            const tr = document.createElement('tr');
            if (kurang > 0) tr.classList.add('kurang');

            tr.innerHTML = `
                <td class="text-muted">${i + 1}</td>
                <td class="font-monospace">${lolos(b.sku)}</td>
                <td>${lolos(b.nama)}</td>
                <td>${lolos(b.uom ?? '')}</td>
                <td class="angka">${b.qty_ordered}</td>
                <td class="angka ${angka(b.stok) === 0 ? 'text-danger fw-semibold' : ''}">
                    ${angka(b.stok)}
                    ${gudangLain(b)}
                </td>
                <td class="angka">
                    <input type="number" min="0" max="${b.qty_ordered}" step="1"
                        value="${setuju}" data-i="${i}" class="setuju"
                        name="item[${i}][qty_approved]" aria-label="Qty disetujui baris ${i + 1}">
                    <input type="hidden" name="item[${i}][product_id]" value="${b.product_id}">
                    <input type="hidden" name="item[${i}][qty_ordered]" value="${b.qty_ordered}">
                </td>
                <td>${badge(setuju, kurang, b)}</td>
                ${BERBASIS_DOKUMEN ? `<td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger p-0 hapus" data-i="${i}" aria-label="Hapus baris ${i + 1}"><i class="bi bi-trash"></i></button></td>` : ''}
            `;

            // Input tersembunyi menumpang di sel "Setuju" — sel yang SELALU
            // ada — bukan di sel tombol hapus yang hanya digambar untuk metode
            // dokumen, dan bukan langsung di bawah <tr>. HTML tidak
            // mengizinkan elemen selain sel sebagai anak baris tabel: parser
            // browser memindahkan input semacam itu ke LUAR tabel (foster
            // parenting), dan di markup ini artinya keluar dari <form> —
            // product_id-nya diam-diam tidak pernah ikut terkirim.
            isiKisi.appendChild(tr);
        });

        kisiKosong.classList.toggle('d-none', baris.length > 0);
        hitungTotal();
    }

    // "Stok nol di gudang ini" dan "produknya tidak ada di mana pun" adalah dua
    // keadaan yang sangat berbeda, dan yang pertama sering berarti barangnya
    // salah gudang — bukan benar-benar habis. Tanpa keterangan ini Logistik
    // menolak baris pesanan padahal barangnya ada, cuma di gudang sebelah.
    function gudangLain(b) {
        const lain = b.gudang_lain || [];
        if (lain.length === 0) return '';

        const rincian = lain
            .map((g) => lolos(g.gudang) + ' ' + angka(g.qty))
            .join(', ');

        return '<div class="text-warning-emphasis fw-normal" style="font-size:.7rem">'
            + 'ada di ' + rincian + ' (gudang lain)</div>';
    }

    function badge(setuju, kurang, b) {
        if (setuju === 0) {
            return '<span class="badge bg-danger-subtle text-danger-emphasis">tidak dikirim</span>';
        }
        if (kurang > 0) {
            return `<span class="badge bg-warning-subtle text-warning-emphasis">${kurang} menunggu stok</span>`;
        }
        if (setuju < b.qty_ordered) {
            return `<span class="badge bg-info-subtle text-info-emphasis">${b.qty_ordered - setuju} outstanding</span>`;
        }
        return '<span class="badge bg-success-subtle text-success-emphasis">siap</span>';
    }

    function hitungTotal() {
        let pesan = 0, setuju = 0, kurang = 0;

        baris.forEach((b) => {
            const s = angka(parseInt(b.setuju ?? b.usul ?? 0, 10));
            pesan += angka(b.qty_ordered);
            setuju += s;
            kurang += Math.max(0, s - angka(b.stok));
        });

        document.getElementById('totalPesan').textContent = pesan;
        document.getElementById('totalSetuju').textContent = setuju;
        document.getElementById('jumlahKurang').textContent = kurang;
        peringatan.classList.toggle('d-none', kurang === 0);
    }

    /* Teks dari tempelan dan dari basis data sama-sama masuk innerHTML. */
    function lolos(teks) {
        const d = document.createElement('div');
        d.textContent = teks ?? '';
        return d.innerHTML;
    }

    isiKisi.addEventListener('input', (e) => {
        if (!e.target.classList.contains('setuju')) return;
        const i = parseInt(e.target.dataset.i, 10);
        baris[i].setuju = parseInt(e.target.value, 10) || 0;
        gambar();
        const ulang = isiKisi.querySelector(`.setuju[data-i="${i}"]`);
        if (ulang) { ulang.focus(); ulang.setSelectionRange(ulang.value.length, ulang.value.length); }
    });

    isiKisi.addEventListener('click', (e) => {
        const tombol = e.target.closest('.hapus');
        if (!tombol) return;
        baris.splice(parseInt(tombol.dataset.i, 10), 1);
        gambar();
    });

    /* ------------------------------------------------ Salin ke Excel */
    document.getElementById('btnSalin').addEventListener('click', async () => {
        const judul = ['SKU', 'Deskripsi', 'UOM', 'Pesan', 'Stok', 'Setuju'];
        const isi = baris.map((b) => [
            b.sku, b.nama, b.uom ?? '', b.qty_ordered, angka(b.stok),
            angka(parseInt(b.setuju ?? b.usul ?? 0, 10)),
        ]);
        const tsv = [judul, ...isi].map((r) => r.join('\t')).join('\n');

        try {
            await navigator.clipboard.writeText(tsv);
            lapor('btnSalin', 'Tersalin!');
        } catch (err) {
            // clipboard API butuh HTTPS atau localhost. Di jaringan kantor
            // lewat http:// ia gagal diam-diam, jadi disediakan jalan mundur.
            const ta = document.createElement('textarea');
            ta.value = tsv;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            lapor('btnSalin', 'Tersalin!');
        }
    });

    function lapor(id, teks) {
        const b = document.getElementById(id);
        const asli = b.innerHTML;
        b.innerHTML = `<i class="bi bi-check2 me-1"></i> ${teks}`;
        setTimeout(() => { b.innerHTML = asli; }, 1500);
    }

    /* ------------------------------------- Tempelan dari sistem BC */
    const btnProses = document.getElementById('btnProsesTempel');
    const kotakSku = document.getElementById('tempelSku');
    const kotakQty = document.getElementById('tempelQty');

    /* Memecah isi kotak menjadi daftar baris, mengabaikan baris kosong. */
    function barisDari(teks) {
        return (teks || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    }

    function perbaruiHitungan() {
        if (!kotakSku) return;

        const sku = barisDari(kotakSku.value);
        const qty = barisDari(kotakQty.value);

        document.getElementById('hitungSku').textContent = `${sku.length} baris`;
        document.getElementById('hitungQty').textContent = `${qty.length} baris`;

        const beda = sku.length !== qty.length && sku.length > 0 && qty.length > 0;
        document.getElementById('selisihBaris').classList.toggle('d-none', !beda);

        if (beda) {
            document.getElementById('rincianSelisih').textContent =
                `SKU ${sku.length} baris, Qty ${qty.length} baris`;
        }
    }

    if (kotakSku) {
        /*
            Menempel DUA kolom sekaligus ke kotak SKU tetap dilayani: Logistik
            yang menyeleksi dua kolom di Excel akan mendapat teks berpemisah
            Tab, dan menolaknya hanya akan terasa seperti kerusakan. Isinya
            dipecah sendiri ke dua kotak.
        */
        kotakSku.addEventListener('paste', (e) => {
            const teks = (e.clipboardData || window.clipboardData).getData('text');

            if (!teks || !/\t/.test(teks)) return;

            e.preventDefault();

            const pasangan = barisDari(teks)
                .map((l) => l.split('\t').map((s) => s.trim()).filter(Boolean))
                .filter((k) => k.length >= 2);

            if (pasangan.length === 0) return;

            kotakSku.value = pasangan.map((k) => k[0]).join('\n');
            kotakQty.value = pasangan.map((k) => k[k.length - 1]).join('\n');
            perbaruiHitungan();
        });

        kotakSku.addEventListener('input', perbaruiHitungan);
        kotakQty.addEventListener('input', perbaruiHitungan);
        kotakQty.addEventListener('paste', () => setTimeout(perbaruiHitungan, 0));

        document.getElementById('btnKosongkan').addEventListener('click', () => {
            kotakSku.value = '';
            kotakQty.value = '';
            document.getElementById('hasilTempel').innerHTML = '';
            perbaruiHitungan();
        });

        perbaruiHitungan();
    }

    if (btnProses) {
        btnProses.addEventListener('click', async () => {
            const kotak = document.getElementById('hasilTempel');
            const daftarSku = barisDari(kotakSku.value);
            const daftarQty = barisDari(kotakQty.value);

            if (daftarSku.length === 0 || daftarQty.length === 0) {
                kotak.innerHTML = '<div class="alert alert-warning py-2 mb-0 small">Kedua kolom harus diisi.</div>';
                return;
            }

            /*
                Jumlah baris yang tidak sama DITOLAK, bukan dipotong sepanjang
                yang terpendek. Memotong diam-diam berarti qty menempel ke SKU
                yang salah — kesalahan yang tidak menimbulkan galat apa pun dan
                baru ketahuan saat barang salah sampai ke customer.
            */
            if (daftarSku.length !== daftarQty.length) {
                kotak.innerHTML = `<div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i> Tidak diproses: SKU ${daftarSku.length} baris tetapi Qty ${daftarQty.length} baris. Samakan dulu jumlahnya.</div>`;
                return;
            }

            const pasangan = [];
            const qtyTidakTerbaca = [];

            daftarSku.forEach((sku, i) => {
                const mentah = daftarQty[i];
                // Ribuan bergaya Excel ("1.200" / "1,200") dan satuan yang ikut
                // tersalin dibuang; yang tersisa harus angka murni.
                const angkaQty = parseInt(String(mentah).replace(/[^\d]/g, ''), 10);

                if (!Number.isFinite(angkaQty) || angkaQty < 1) {
                    qtyTidakTerbaca.push(`baris ${i + 1} ("${mentah}")`);
                    return;
                }

                pasangan.push({ sku: sku.toUpperCase(), qty: angkaQty });
            });

            if (qtyTidakTerbaca.length > 0) {
                kotak.innerHTML = `<div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i> Qty tidak terbaca sebagai angka pada ${lolos(qtyTidakTerbaca.join(', '))}. Kemungkinan baris judul ikut tersalin.</div>`;
                return;
            }

            btnProses.disabled = true;

            try {
                const jawab = await fetch(URL_RESOLVE, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                    },
                    body: JSON.stringify({ sku: pasangan.map((p) => p.sku) }),
                });

                if (!jawab.ok) throw new Error('gagal');

                const { produk } = await jawab.json();
                const tidakDikenal = [];
                const baru = [];

                pasangan.forEach((p) => {
                    const info = produk[p.sku];

                    if (!info || !info.ditemukan) {
                        tidakDikenal.push(p.sku);
                        return;
                    }

                    // SKU kembar di tempelan digabung, bukan dibuat dua baris:
                    // sales_order_details punya unique(order, product).
                    const ada = baru.find((b) => b.product_id === info.product_id);

                    if (ada) {
                        ada.qty_ordered += p.qty;
                        ada.setuju += p.qty;
                        return;
                    }

                    baru.push({
                        product_id: info.product_id,
                        sku: info.sku,
                        nama: info.nama,
                        uom: info.uom,
                        stok: info.stok,
                        qty_ordered: p.qty,
                        // Qty dari BC dipakai apa adanya (keputusan pemilik
                        // produk), bukan dipotong sesuai stok.
                        setuju: p.qty,
                    });
                });

                baris = baru;
                gambar();

                kotak.innerHTML = tidakDikenal.length === 0
                    ? `<div class="alert alert-success py-2 mb-0 small"><i class="bi bi-check-circle me-1"></i> ${baru.length} baris terbaca.</div>`
                    : `<div class="alert alert-warning py-2 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i> ${baru.length} baris terbaca. SKU tidak dikenal dan dilewati: <span class="font-monospace">${lolos(tidakDikenal.join(', '))}</span></div>`;
            } catch (err) {
                kotak.innerHTML = '<div class="alert alert-danger py-2 mb-0 small">Gagal memeriksa SKU ke server. Coba lagi.</div>';
            } finally {
                btnProses.disabled = false;
            }
        });
    }

    /* ==================================================================
       | Pemeriksaan nomor SO — tiga jawaban, tiga tindak lanjut berbeda.
       |
       |   bebas          : lanjut seperti biasa
       |   dapat_digabung : pelanggan SAMA, tawarkan penggabungan invoice
       |   terpakai       : pelanggan LAIN, tidak ada jalan selain periksa BC
       |
       | Ini KENYAMANAN, bukan pengamanan. Aturan yang sama ditegakkan ulang
       | di AcceptSalesOrderRequest saat menyimpan, karena apa pun yang
       | diputuskan di layar bisa diubah sebelum dikirim.
       ================================================================== */
    const kotakSo = document.getElementById('kotakSo');
    const inputSo = document.getElementById('bcSo');
    const gabungInvoice = document.getElementById('gabungInvoice');
    const mergeWithOrderId = document.getElementById('mergeWithOrderId');

    function resetGabung() {
        gabungInvoice.value = '0';
        mergeWithOrderId.value = '';
        kotakSo.classList.add('d-none');
        kotakSo.innerHTML = '';
    }

    async function periksaNomorSo() {
        const nomor = inputSo.value.trim();

        resetGabung();

        if (nomor === '') return;

        let hasil;

        try {
            const jawab = await fetch(URL_CEK_SO, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ bc_so_number: nomor }),
            });

            if (!jawab.ok) return;

            hasil = await jawab.json();
        } catch (e) {
            // Jaringan bermasalah: diamkan. Validasi saat menyimpan tetap
            // menangkapnya, jadi tidak ada yang lolos karena kotak ini gagal.
            return;
        }

        if (hasil.status === 'bebas') return;

        kotakSo.classList.remove('d-none');

        if (hasil.status === 'terpakai') {
            kotakSo.innerHTML =
                '<div class="alert alert-danger py-2 px-3 small mb-0 rounded-3">'
                + '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
                + 'Nomor ini sedang dipakai pesanan <strong>' + hasil.pesanan.nomor + '</strong> '
                + 'milik pelanggan <strong>lain</strong> (' + (hasil.pesanan.customer || '—') + ').<br>'
                + 'Penggabungan invoice hanya untuk pelanggan yang sama. Periksa lagi di sistem BC.'
                + '</div>';
            return;
        }

        // Pelanggan sama: tawarkan penggabungan, tapi JANGAN dicentang
        // otomatis. Mencentangkannya sendiri berarti sistem yang memutuskan
        // dua pesanan itu satu invoice, padahal hanya Logistik yang tahu.
        kotakSo.innerHTML =
            '<div class="alert alert-info py-2 px-3 small mb-0 rounded-3">'
            + '<i class="bi bi-info-circle-fill me-1"></i>'
            + 'Nomor ini sedang dipakai pesanan <strong>' + hasil.pesanan.nomor + '</strong>, '
            + 'pelanggan yang <strong>sama</strong>'
            + (hasil.pesanan.diterima ? ' (diterima ' + hasil.pesanan.diterima + ')' : '') + '.'
            + '<div class="form-check mt-2">'
            + '<input class="form-check-input" type="checkbox" id="centangGabung">'
            + '<label class="form-check-label" for="centangGabung">'
            + 'Ini <strong>pesanan tambahan</strong>, gabung ke invoice pesanan tersebut'
            + '</label>'
            + '</div>'
            + '</div>';

        document.getElementById('centangGabung').addEventListener('change', (e) => {
            gabungInvoice.value = e.target.checked ? '1' : '0';
            mergeWithOrderId.value = e.target.checked ? hasil.pesanan.id : '';
        });
    }

    inputSo.addEventListener('blur', periksaNomorSo);
    if (inputSo.value.trim() !== '') periksaNomorSo();

    /* Cegah kirim ganda: klik dua kali pada Terima berarti dua transaksi. */
    document.getElementById('formTerima').addEventListener('submit', (e) => {
        if (baris.length === 0) {
            e.preventDefault();
            alert('Rincian item masih kosong.');
            return;
        }
        const btn = document.getElementById('btnTerima');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Memproses...';
    });

    gambar();
})();
</script>
