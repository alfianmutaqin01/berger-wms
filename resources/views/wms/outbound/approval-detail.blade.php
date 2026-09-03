@extends('layouts.wms')

@section('title', 'Terima Pesanan '.$order->order_number)
@section('page_title', 'Terima Pesanan')

@push('styles')
<style>
    /*
        Kisi mirip lembar Excel.

        Alasannya bukan gaya-gayaan: Logistik menyalin daftar ini ke dokumen
        Excel lain, jadi bentuknya sengaja dibuat rapat, bergaris penuh, dan
        rata kanan untuk angka — sama seperti sheet asalnya. Angka ditulis
        TANPA pemisah ribuan supaya hasil salinan langsung terbaca Excel
        sebagai angka, bukan teks.
    */
    .kisi { border-collapse: collapse; width: 100%; font-size: 0.875rem; }
    .kisi th, .kisi td { border: 1px solid #cbd5e1; padding: 0.35rem 0.5rem; }
    .kisi thead th { background: #f1f5f9; font-weight: 600; white-space: nowrap; }
    .kisi td.angka, .kisi th.angka { text-align: right; font-variant-numeric: tabular-nums; }
    .kisi input { border: 0; width: 100%; text-align: right; background: transparent; padding: 0; }
    .kisi input:focus { outline: 2px solid #2563eb; outline-offset: -2px; background: #fff; }
    .kisi tr.kurang td { background: #fff7ed; }
    .zona-tempel { border: 2px dashed #94a3b8; border-radius: 0.75rem; background: #f8fafc; }
</style>
@endpush

@section('content')
@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Pesanan belum bisa diterima:</strong>
    <ul class="mb-0 mt-2">
        @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
    </ul>
</div>
@endif

<a href="{{ route('wms.approval.index') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke antrean
</a>

<div class="row g-3">
    {{-- ------------------------------------------------ Identitas pesanan --}}
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-1">
                    {{ $order->isDocumentBased() ? 'No. PO Customer' : 'No. PO' }}
                </h6>
                <h4 class="fw-bold font-monospace mb-1">
                    {{ $order->isDocumentBased() ? ($order->customer_po_number ?? '—') : $order->order_number }}
                </h4>
                @if($order->isDocumentBased())
                    {{-- Nomor internal tetap ada dan tetap ditampilkan: itulah
                         yang dipakai seluruh sistem, nomor customer hanya rujukan. --}}
                    <div class="small text-muted mb-3">No. internal: <span class="font-monospace">{{ $order->order_number }}</span></div>
                @else
                    <div class="mb-3"></div>
                @endif

                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted fw-normal">Customer</dt>
                    <dd class="col-7 fw-semibold">{{ $order->customer?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Kode Customer</dt>
                    <dd class="col-7 font-monospace">{{ $order->customer?->code ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Alamat</dt>
                    <dd class="col-7">{{ $order->customer?->full_address ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Telepon</dt>
                    <dd class="col-7 font-monospace">{{ $order->customer?->phone_label ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Sales</dt>
                    <dd class="col-7">{{ $order->user?->full_name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Gudang</dt>
                    <dd class="col-7">{{ $order->warehouse?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Pembayaran</dt>
                    <dd class="col-7">{{ $order->paymentTerm?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Disubmit</dt>
                    <dd class="col-7">{{ $order->submitted_at?->format('d M Y H:i') ?? '—' }}</dd>
                </dl>

                @if(filled($order->notes))
                    <div class="alert alert-light border mt-3 mb-0 small">
                        <i class="bi bi-chat-left-text me-1"></i> {{ $order->notes }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------- Kisi item --}}
    <div class="col-12 col-xl-8">
        <form method="POST" action="{{ route('wms.approval.accept', $order) }}" id="formTerima">
            @csrf

            @if($order->isDocumentBased())
                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body">
                        <h6 class="fw-bold mb-2"><i class="bi bi-paperclip text-primary me-1"></i> Dokumen dari Sales</h6>
                        <p class="small text-muted mb-3">
                            Unduh berkasnya, masukkan rinciannya ke sistem BC, lalu salin hasilnya
                            (kolom <strong>SKU</strong> dan <strong>Qty</strong>) dan tempel ke kisi di bawah.
                        </p>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <a href="{{ route('wms.approval.document', $order) }}" class="btn btn-primary rounded-3">
                                <i class="bi bi-download me-1"></i> Unduh Dokumen
                            </a>
                            <div class="small text-muted">
                                <div class="fw-semibold text-dark">{{ $order->document_name ?? '—' }}</div>
                                {{ $order->document_size ? number_format($order->document_size / 1024, 0).' KB' : '' }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body">
                        <div class="fw-bold mb-1">
                            <i class="bi bi-clipboard-plus text-primary me-1"></i> Tempel dari sistem BC
                        </div>
                        <p class="small text-muted mb-3">
                            Salin kolom <strong>SKU</strong> dari Excel lalu tempel di kiri, kemudian
                            salin kolom <strong>Qty</strong> dan tempel di kanan. Urutan barisnya harus
                            sama. Kalau Anda menyalin dua kolom sekaligus, tempel saja di kotak SKU —
                            sistem memisahkannya sendiri.
                        </p>

                        <div class="row g-3">
                            <div class="col-12 col-md-7">
                                <label for="tempelSku" class="form-label small fw-semibold mb-1">Kolom SKU</label>
                                <textarea id="tempelSku" rows="6" spellcheck="false"
                                          class="form-control zona-tempel font-monospace small"
                                          placeholder="ID1-F00113202225&#10;ID1-F0011B128320"></textarea>
                                <small class="text-muted" id="hitungSku">0 baris</small>
                            </div>
                            <div class="col-12 col-md-5">
                                <label for="tempelQty" class="form-label small fw-semibold mb-1">Kolom Qty</label>
                                <textarea id="tempelQty" rows="6" spellcheck="false"
                                          class="form-control zona-tempel font-monospace small text-end"
                                          placeholder="100&#10;50"></textarea>
                                <small class="text-muted" id="hitungQty">0 baris</small>
                            </div>
                        </div>

                        {{-- Peringatan jumlah baris adalah penjaga terpenting di sini.
                             Menempel dua kolom terpisah bisa meleset satu baris (mis.
                             kolom SKU ikut terbawa judulnya), dan akibatnya BUKAN galat
                             melainkan qty yang menempel diam-diam ke SKU yang salah. --}}
                        <div id="selisihBaris" class="alert alert-danger py-2 mt-3 mb-0 small d-none">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            Jumlah barisnya tidak sama — <span id="rincianSelisih"></span>.
                            Kalau diproses, qty bisa menempel ke SKU yang salah. Periksa dulu, mis. baris judul yang ikut tersalin.
                        </div>

                        <div class="d-flex justify-content-end align-items-center mt-3 gap-2">
                            <button type="button" id="btnKosongkan" class="btn btn-sm btn-link text-muted text-decoration-none">
                                Kosongkan
                            </button>
                            <button type="button" id="btnProsesTempel" class="btn btn-sm btn-outline-primary rounded-3">
                                <i class="bi bi-arrow-down-circle me-1"></i> Proses Tempelan
                            </button>
                        </div>

                        <div id="hasilTempel" class="mt-2"></div>
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-table text-primary me-2"></i> Rincian Pesanan</h5>
                        <small class="text-muted">Kolom <strong>Setuju</strong> bisa diubah. Kolom lain hanya bacaan.</small>
                    </div>
                    <button type="button" id="btnSalin" class="btn btn-sm btn-outline-secondary rounded-3">
                        <i class="bi bi-clipboard me-1"></i> Salin ke Excel
                    </button>
                </div>

                <div class="card-body px-4 pt-3">
                    <div class="table-responsive">
                        <table class="kisi" id="kisi">
                            <thead>
                                <tr>
                                    <th style="width:2.5rem">#</th>
                                    <th>SKU</th>
                                    <th>Deskripsi</th>
                                    <th style="width:4rem">UOM</th>
                                    <th class="angka" style="width:5.5rem">Pesan</th>
                                    <th class="angka" style="width:5.5rem">Stok</th>
                                    <th class="angka" style="width:6rem">Setuju</th>
                                    <th style="width:9rem">Status</th>
                                    {{-- Kolom tombol hapus HANYA untuk metode dokumen. Pada metode
                                         rincian barisnya berasal dari Sales dan tidak boleh dibuang,
                                         jadi kolomnya tidak digambar sama sekali - bukan digambar
                                         kosong yang menyisakan sel menggantung di ujung kanan. --}}
                                    @if($order->isDocumentBased())
                                        <th style="width:2.5rem"></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody id="isiKisi"></tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">Total</td>
                                    <td class="angka" id="totalPesan">0</td>
                                    <td class="angka">—</td>
                                    <td class="angka" id="totalSetuju">0</td>
                                    <td colspan="{{ $order->isDocumentBased() ? 2 : 1 }}"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div id="kisiKosong" class="text-center py-5 text-muted d-none">
                        <i class="bi bi-table display-6 d-block mb-2 opacity-50"></i>
                        Belum ada rincian item. Tempelkan daftar dari sistem BC di atas.
                    </div>

                    <div id="peringatanStok" class="alert alert-warning border-0 rounded-3 mt-3 d-none">
                        <i class="bi bi-exclamation-circle-fill me-2"></i>
                        <strong><span id="jumlahKurang">0</span> unit menunggu stok.</strong>
                        Pesanan tetap bisa diterima, tetapi porsi itu belum bisa dipicking sampai stoknya
                        ditambahkan. Jelaskan alasannya di catatan penerimaan.
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------- Keputusan akhir --}}
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-5">
                            <label for="bcSo" class="form-label fw-semibold">
                                Nomor SO (sistem BC) <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="bc_so_number" id="bcSo" required maxlength="50"
                                   value="{{ old('bc_so_number') }}"
                                   class="form-control font-monospace @error('bc_so_number') is-invalid @enderror"
                                   placeholder="SO-2026-00123">
                            @error('bc_so_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="text-muted" id="petunjukSo">
                                Nomor yang terulang biasanya berarti pesanan ini belum masuk BC.
                            </small>

                            {{-- Diisi oleh pemeriksaan nomor SO sambil diketik.
                                 Tanpa ini, satu-satunya cara tahu nomornya
                                 bentrok adalah menekan Terima lalu ditolak —
                                 dan pada pesanan bermetode dokumen itu berarti
                                 seluruh tempelan dari BC harus diulang. --}}
                            <div id="kotakSo" class="mt-2 d-none"></div>

                            {{-- Terkirim HANYA bila Logistik mencentang
                                 penggabungan. Keduanya diisi JavaScript dari
                                 hasil pemeriksaan, bukan diketik manusia. --}}
                            <input type="hidden" name="gabung_invoice" id="gabungInvoice" value="0">
                            <input type="hidden" name="merge_with_order_id" id="mergeWithOrderId" value="">
                        </div>
                        <div class="col-12 col-md-7">
                            <label for="catatan" class="form-label fw-semibold">Catatan penerimaan</label>
                            <textarea name="approval_note" id="catatan" rows="2" maxlength="1000"
                                      class="form-control @error('approval_note') is-invalid @enderror"
                                      placeholder="mis. 10 unit sudah di gudang tapi belum di-putaway">{{ old('approval_note') }}</textarea>
                            @error('approval_note')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="d-flex justify-content-between gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-danger rounded-3"
                                data-bs-toggle="modal" data-bs-target="#modalTolak">
                            <i class="bi bi-x-circle me-1"></i> Tolak Pesanan
                        </button>
                        <button type="submit" class="btn btn-success rounded-3 px-4" id="btnTerima">
                            <i class="bi bi-check-circle me-1"></i> Terima Pesanan
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('modals')
<div class="modal fade" id="modalTolak" tabindex="-1" aria-labelledby="judulTolak" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.approval.reject', $order) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="judulTolak">Tolak Pesanan {{ $order->order_number }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Alasan ini dibaca Sales di layar pesanannya, jadi tulis yang jelas.
                    Nomor SO tidak diperlukan — pesanan yang ditolak memang tidak masuk sistem BC.
                </p>
                <label for="alasanTolak" class="form-label fw-semibold">
                    Alasan penolakan <span class="text-danger">*</span>
                </label>
                <textarea name="rejection_reason" id="alasanTolak" rows="3" required minlength="10" maxlength="1000"
                          class="form-control" placeholder="mis. Customer masih menunggak dan diminta pelunasan lebih dulu."></textarea>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger rounded-3">
                    <i class="bi bi-x-circle me-1"></i> Tolak Pesanan
                </button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
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
                <td class="angka ${angka(b.stok) === 0 ? 'text-danger fw-semibold' : ''}">${angka(b.stok)}</td>
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
@endpush
