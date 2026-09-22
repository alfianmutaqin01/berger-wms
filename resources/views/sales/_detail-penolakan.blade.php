        {{-- ============ PENOLAKAN CUSTOMER (Fase 7) ============

             DITARUH TEPAT DI BAWAH BUKTI SURAT JALAN, dan itu seluruh
             alasannya ada di sini: keduanya dikerjakan dalam SATU kunjungan.
             Sales berdiri di depan toko, memotret Surat Jalan, dan pada saat
             itu juga tahu barang mana yang tidak diterima. Memisahkannya ke
             halaman lain berarti ia harus mengingat lalu kembali lagi nanti —
             dan yang tidak dilaporkan hari itu biasanya tidak pernah
             dilaporkan sama sekali. --}}
        @if($laporanTolak)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-arrow-return-left text-danger me-2"></i>Penolakan Customer
                </h6>
                <small class="text-muted">{{ $laporanTolak->reference }}</small>
            </div>
            <div class="card-body px-3 px-md-4">
                <span class="badge bg-{{ $laporanTolak->status_color }}-subtle text-{{ $laporanTolak->status_color }}-emphasis rounded-pill mb-2">
                    {{ $laporanTolak->status_label }}
                </span>

                <ul class="list-unstyled small mb-2">
                    @foreach($laporanTolak->details as $b)
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span>{{ $b->product?->sku }}</span>
                            <span class="fw-semibold">
                                {{ $b->qty_rejected }}
                                @if($b->qty_approved !== null && $b->qty_approved !== $b->qty_rejected)
                                    <span class="text-muted">&rarr; disetujui {{ $b->qty_approved }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <p class="small text-muted mb-0">{{ $laporanTolak->reason }}</p>

                {{-- Alasan Logistik menolak laporan WAJIB terlihat di sini.
                     Tanpa itu, satu-satunya cara Sales tahu kenapa klaimnya
                     tidak diterima adalah menelepon. --}}
                @if($laporanTolak->status === \App\Models\SalesReturn::STATUS_REJECTED && $laporanTolak->approval_note)
                    <div class="alert alert-danger border-0 small mt-2 mb-0 py-2">
                        <strong>Laporan ditolak Logistik:</strong> {{ $laporanTolak->approval_note }}
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Foto Surat Jalan belum ada. Formulirnya BELUM DIBUKA, dan
             alasannya dikatakan — bukan dibiarkan jadi kartu yang hilang.
             Laporan penolakan adalah tagihan barang kembali ke gudang, dan
             Logistik yang menilainya tidak ikut ke toko: satu-satunya hal
             yang bisa ia periksa adalah Surat Jalan bertanda tangan. --}}
        @if($perluBuktiDulu && ! $laporanTolak)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body px-3 px-md-4 py-3">
                <div class="d-flex gap-2">
                    <i class="bi bi-arrow-return-left text-muted fs-5"></i>
                    <div class="small">
                        <div class="fw-semibold text-dark mb-1">Ada barang yang ditolak customer?</div>
                        <span class="text-muted">
                            Unggah dulu foto Surat Jalan yang sudah ditandatangani pelanggan di kartu
                            di atas. Logistik menilai laporan penolakan bersama foto itu, jadi
                            formulirnya baru terbuka setelah fotonya ada.
                        </span>
                    </div>
                </div>
            </div>
        </div>
        @endif

        @if($bolehLaporTolak && ! $laporanTolak)
        @php
            /*
             * Hanya barang yang BENAR-BENAR BERANGKAT yang bisa ditolak.
             * Daftar ini juga jadi satu-satunya sumber pilihan di formulir,
             * sehingga Sales tidak mungkin melaporkan produk yang tidak
             * pernah ada di Surat Jalan pesanan ini.
             */
            $bisaDitolak = $order->details
                ->filter(fn ($d) => (int) ($d->qty_shipped ?? 0) > 0)
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'sku' => (string) $d->product?->sku,
                    'nama' => (string) $d->product?->name,
                    'uom' => (string) $d->product?->uom,
                    'max' => (int) $d->qty_shipped,
                ])
                ->values();
        @endphp
        @if($bisaDitolak->isNotEmpty())
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-arrow-return-left text-danger me-2"></i>Penolakan Customer
                </h6>
                <small class="text-muted">Isi hanya kalau ada barang yang tidak diterima.</small>
            </div>
            <div class="card-body px-3 px-md-4">
                {{-- TERTUTUP DULU, dan itu bukan sekadar soal rapi.
                     Pesanan dua puluh item berarti dua puluh kolom angka
                     yang harus digulir setiap kali halaman ini dibuka,
                     padahal penolakan itu perkara yang jarang. Yang sering
                     terjadi harus pendek; yang jarang boleh butuh satu klik. --}}
                <button type="button" id="bukaTolak" class="btn btn-outline-danger rounded-3 w-100">
                    <i class="bi bi-exclamation-triangle me-1"></i> Ada barang yang ditolak
                </button>

                <form method="POST" action="/sales/report-return" id="formTolak" class="d-none">
                    @csrf
                    <input type="hidden" name="order_id" value="{{ $order->id }}">

                    {{-- Sales MENGETIK item yang ditolak, bukan memilih dari
                         seluruh isi pesanan. Yang ditolak biasanya satu-dua
                         baris; menampilkan semuanya membuat Sales mencari
                         di antara belasan baris yang tidak ia butuhkan. --}}
                    <div id="daftarTolak" class="mt-3"></div>

                    <button type="button" class="btn btn-outline-danger btn-sm rounded-3 w-100 mb-3" id="tambahTolak">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Item
                    </button>

                    <label class="form-label small fw-semibold">
                        Kenapa ditolak? <span class="text-danger">*</span>
                    </label>
                    {{-- Wajib dan tidak boleh sepatah kata: Logistik yang
                         menilai klaim ini tidak ikut ke toko, dan "ditolak"
                         saja tidak memberinya apa pun untuk dinilai. --}}
                    <textarea name="reason" class="form-control mb-2" rows="3" minlength="10"
                              placeholder="Mis. warna tidak sesuai contoh, tutup penyok saat diturunkan."></textarea>

                    <div class="alert alert-warning border-0 small py-2 mb-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Barangnya akan ditagih kembali ke gudang. Logistik memeriksa laporan ini
                        bersama foto Surat Jalan Anda.
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-3" id="batalTolak">Batal</button>
                        <button class="btn btn-danger rounded-3 flex-grow-1">
                            <i class="bi bi-send me-1"></i> Laporkan Penolakan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Cetakan satu baris. Ditaruh di <template> supaya baris kosongnya
             tidak ikut terkirim sebagai isian saat form disubmit. --}}
        <template id="templateTolak">
            <div class="border rounded-3 p-2 mb-2 baris-tolak">
                <div class="d-flex gap-2 align-items-start">
                    <div class="flex-grow-1 position-relative" style="min-width:0">
                        <input type="text" class="form-control form-control-sm tolak-cari" autocomplete="off"
                               placeholder="Ketik SKU atau nama produk...">
                        <div class="list-group position-absolute w-100 shadow tolak-saran d-none"
                             style="z-index:1050; max-height:220px; overflow-y:auto;"></div>
                        <small class="text-muted tolak-info d-none d-block mt-1"></small>
                    </div>
                    <input type="number" class="form-control form-control-sm text-center tolak-qty flex-shrink-0"
                           style="width:74px" min="1" placeholder="Qty" inputmode="numeric" disabled>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0 tolak-hapus"
                            title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
        </template>
        @endif
        @endif

@if($bolehLaporTolak && ! $laporanTolak && ($bisaDitolak ?? collect())->isNotEmpty())
@push('scripts')
<script>
/*
 * Formulir lapor penolakan.
 *
 * Sumber pilihannya adalah isi Surat Jalan pesanan INI, bukan katalog
 * produk. Jadi pencariannya cukup di sisi klien — datanya paling banyak
 * sejumlah baris pesanan, dan tidak ada gunanya menembak server untuk
 * daftar yang sudah ada di halaman.
 */
(function () {
    const ITEM = @json($bisaDitolak);

    const kartu = document.getElementById('formTolak');
    const buka = document.getElementById('bukaTolak');
    const batal = document.getElementById('batalTolak');
    const daftar = document.getElementById('daftarTolak');
    const tambah = document.getElementById('tambahTolak');
    const cetakan = document.getElementById('templateTolak');

    if (!kartu || !buka || !daftar || !tambah || !cetakan) return;

    // Item yang sudah dipakai baris lain. Satu produk tidak boleh dilaporkan
    // dua kali — dua baris untuk SKU yang sama akan saling menimpa di server
    // (kuncinya id baris pesanan), dan Sales tidak akan tahu mana yang menang.
    function terpakai() {
        return Array.from(daftar.querySelectorAll('.tolak-qty'))
            .map((q) => q.dataset.detailId)
            .filter(Boolean);
    }

    function tutupSemuaSaran() {
        daftar.querySelectorAll('.tolak-saran').forEach((s) => s.classList.add('d-none'));
    }

    function pasangBaris() {
        const baris = cetakan.content.firstElementChild.cloneNode(true);
        const cari = baris.querySelector('.tolak-cari');
        const saran = baris.querySelector('.tolak-saran');
        const qty = baris.querySelector('.tolak-qty');
        const info = baris.querySelector('.tolak-info');

        function pilih(item) {
            cari.value = item.sku + ' — ' + item.nama;
            qty.disabled = false;
            qty.max = item.max;
            qty.dataset.detailId = item.id;
            qty.name = 'qty[' + item.id + ']';
            info.textContent = 'Terkirim ' + item.max + ' ' + item.uom + ' — maksimal sebanyak itu yang bisa ditolak.';
            info.classList.remove('d-none');
            saran.classList.add('d-none');
            qty.focus();
        }

        function lupakanPilihan() {
            qty.disabled = true;
            qty.value = '';
            qty.removeAttribute('name');
            delete qty.dataset.detailId;
            info.classList.add('d-none');
        }

        cari.addEventListener('input', function () {
            lupakanPilihan();

            const kata = cari.value.trim().toLowerCase();
            const dipakai = terpakai();
            const cocok = ITEM.filter((i) =>
                !dipakai.includes(String(i.id))
                && (kata === '' || i.sku.toLowerCase().includes(kata) || i.nama.toLowerCase().includes(kata)));

            saran.innerHTML = '';

            if (cocok.length === 0) {
                saran.classList.add('d-none');
                return;
            }

            cocok.slice(0, 8).forEach((i) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'list-group-item list-group-item-action py-2';
                b.innerHTML = '<span class="fw-semibold small d-block text-truncate"></span>'
                    + '<small class="text-muted font-monospace"></small>';
                b.querySelector('.fw-semibold').textContent = i.nama;
                b.querySelector('small').textContent = i.sku + ' · terkirim ' + i.max + ' ' + i.uom;
                b.addEventListener('mousedown', (e) => { e.preventDefault(); pilih(i); });
                saran.appendChild(b);
            });

            saran.classList.remove('d-none');
        });

        // Klik kolomnya langsung menampilkan seluruh sisa item: pesanan kecil
        // tidak perlu diketik sama sekali.
        cari.addEventListener('focus', function () {
            cari.dispatchEvent(new Event('input'));
        });

        cari.addEventListener('blur', function () {
            setTimeout(() => saran.classList.add('d-none'), 120);
        });

        baris.querySelector('.tolak-hapus').addEventListener('click', function () {
            baris.remove();
            if (daftar.children.length === 0) pasangBaris();
        });

        daftar.appendChild(baris);
        return baris;
    }

    buka.addEventListener('click', function () {
        buka.classList.add('d-none');
        kartu.classList.remove('d-none');
        if (daftar.children.length === 0) pasangBaris();
        daftar.querySelector('.tolak-cari')?.focus();
    });

    batal?.addEventListener('click', function () {
        kartu.classList.add('d-none');
        buka.classList.remove('d-none');
        daftar.innerHTML = '';
        kartu.querySelector('textarea[name="reason"]').value = '';
    });

    tambah.addEventListener('click', function () {
        tutupSemuaSaran();
        pasangBaris().querySelector('.tolak-cari').focus();
    });

    // Menahan submit yang pasti ditolak server. Pesan "jumlah yang ditolak
    // wajib diisi" setelah halaman ter-reload jauh lebih membingungkan
    // daripada dikatakan di tempat.
    kartu.addEventListener('submit', function (e) {
        const terisi = Array.from(daftar.querySelectorAll('.tolak-qty'))
            .filter((q) => q.dataset.detailId && parseInt(q.value || '0', 10) > 0);

        if (terisi.length === 0) {
            e.preventDefault();
            alert('Pilih dulu barang yang ditolak dan isi jumlahnya.');
            return;
        }

        const lebih = terisi.find((q) => parseInt(q.value, 10) > parseInt(q.max, 10));

        if (lebih) {
            e.preventDefault();
            alert('Jumlah yang ditolak tidak boleh lebih banyak daripada yang dikirim.');
        }
    });
})();
</script>
@endpush
@endif
