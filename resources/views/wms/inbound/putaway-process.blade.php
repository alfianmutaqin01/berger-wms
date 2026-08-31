@extends('layouts.wms')

@section('title', 'Proses Put-away')
@section('page_title', 'Proses Put-away')

@section('content')
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Ada isian yang perlu diperbaiki:</strong>
    <ul class="mb-0 mt-2 small">
        @foreach($errors->all() as $pesan)
            <li>{{ $pesan }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <a href="{{ route('wms.inbound.putaway') }}" class="text-muted text-decoration-none me-2"><i class="bi bi-arrow-left"></i></a>
                        Put-away: <span class="text-primary font-monospace">{{ $header->document_number }}</span>
                    </h5>
                    <p class="text-muted small mt-1 ms-4 mb-0">Tentukan lokasi rak untuk masing-masing palet di bawah ini.</p>
                </div>
                <div class="text-end">
                    <span class="d-block text-muted small">Gudang: <strong class="text-dark">{{ $header->warehouse?->display_label ?? '—' }}</strong></span>
                    <span class="d-block text-muted small">Tgl Produksi: <strong class="text-dark">{{ $header->production_date->translatedFormat('d M Y') }}</strong></span>
                    <span class="d-block text-muted small">Kemajuan: <strong class="text-dark" id="progressLabel">{{ $totals['ditempatkan'] }} / {{ $totals['palet'] }} palet</strong></span>
                </div>
            </div>

            <div class="card-body p-4">

                <div class="alert alert-info border-0 bg-info-subtle text-info-emphasis d-flex align-items-start rounded-3 p-3 mb-4" role="alert">
                    <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                    <div class="small">
                        Palet yang belum sempat ditempatkan boleh dikosongkan — yang sudah terisi tetap tersimpan dan dokumen ini
                        tetap ada di daftar put-away sampai seluruh paletnya punya lokasi.
                        Satu rak boleh diisi beberapa palet dari <strong>SKU yang sama</strong> sampai kapasitasnya penuh; SKU
                        berbeda tidak bisa berbagi rak. <strong>Qty Aktual</strong> boleh dikoreksi sesuai hitungan fisik;
                        SKU dan batch tidak dapat diubah di sini.
                    </div>
                </div>

                <form method="POST" action="{{ route('wms.inbound.putaway.store', $header->document_number) }}" id="putawayForm">
                    @csrf

                    <div class="table-responsive" style="overflow: visible;">
                        <table class="table table-hover align-middle mb-0" id="putawayTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-secondary small fw-semibold text-center text-nowrap">PALET</th>
                                    <th class="text-secondary small fw-semibold" style="min-width: 230px;">SKU / DESKRIPSI</th>
                                    <th class="text-secondary small fw-semibold text-nowrap">BATCH</th>
                                    <th class="text-secondary small fw-semibold text-center text-nowrap">QTY SISTEM</th>
                                    <th class="text-secondary small fw-semibold text-center" style="width: 130px;">QTY AKTUAL</th>
                                    <th class="text-secondary small fw-semibold" style="min-width: 260px;">LOKASI RAK</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $nomorProduksiSebelumnya = null; @endphp
                                @foreach($details as $detail)
                                    @php
                                        $awalKelompok = $detail->production_order_no !== $nomorProduksiSebelumnya;
                                        $nomorProduksiSebelumnya = $detail->production_order_no;
                                        $kodeLama = old("pallets.{$detail->id}.location_code", $detail->location?->code);
                                        $qtyLama = old("pallets.{$detail->id}.qty_actual", $detail->qty_actual ?? $detail->pallet_qty);
                                    @endphp
                                    <tr class="{{ $awalKelompok && ! $loop->first ? 'border-top border-2' : '' }}"
                                        data-product-id="{{ $detail->product_id }}"
                                        data-capacity="{{ $detail->product?->max_qty_per_pallet }}"
                                        data-uom="{{ $detail->product?->uom }}">
                                        <td class="text-center text-nowrap">
                                            <span class="fw-bold">#{{ $detail->pallet_no }}</span>
                                            @if($detail->location_id)
                                                <i class="bi bi-check-circle-fill text-success ms-1" title="Sudah ditempatkan"></i>
                                            @endif
                                            <small class="d-block text-muted font-monospace" style="font-size: 0.7rem;">
                                                {{ $awalKelompok ? $detail->production_order_no : '' }}
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace mb-1">{{ $detail->product?->sku ?? '—' }}</span><br>
                                            <small class="text-muted">{{ $detail->product?->name ?? '—' }}</small>
                                        </td>
                                        <td><small class="font-monospace text-muted">{{ $detail->batch_no }}</small></td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border px-2 py-1 qty-sistem"
                                                  data-qty="{{ $detail->pallet_qty }}" title="Dari dokumen produksi">
                                                {{ number_format($detail->pallet_qty) }} {{ $detail->product?->uom }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <input type="number"
                                                   name="pallets[{{ $detail->id }}][qty_actual]"
                                                   value="{{ $qtyLama }}" min="0"
                                                   class="form-control form-control-sm text-center fw-bold qty-aktual @error("pallets.{$detail->id}.qty_actual") is-invalid @enderror">
                                        </td>
                                        <td>
                                            <div class="position-relative">
                                                <input type="text"
                                                       name="pallets[{{ $detail->id }}][location_code]"
                                                       value="{{ $kodeLama }}"
                                                       placeholder="Ketik kode rak, mis. B-01-01"
                                                       autocomplete="off"
                                                       class="form-control text-uppercase font-monospace lokasi-input @error("pallets.{$detail->id}.location_code") is-invalid @enderror">
                                                <div class="lokasi-dropdown list-group position-absolute w-100 shadow rounded-3 mt-1"
                                                     style="z-index: 40; max-height: 240px; overflow-y: auto; display: none;"></div>
                                            </div>
                                            <small class="text-muted lokasi-info d-block mt-1" style="min-height: 1rem;"></small>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                        <a href="{{ route('wms.inbound.putaway') }}" class="btn btn-outline-secondary px-4">Batal</a>
                        <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm">
                            <i class="bi bi-check-circle me-1"></i> Simpan Put-away
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ISI_BIN: isi bin SAAT INI di database (dokumen manapun). Kunci = kode
    // rak. Nilai = { product_id, qty, capacity, uom } milik SKU yang sedang
    // menghuni bin itu (capacity/uom bisa null bila produknya belum diisi
    // kapasitas paletnya di Master Produk).
    const ISI_BIN = @json((object) $occupancy);

    // LOKASI: SELURUH bin aktif di gudang dokumen ini. Ketersediaannya untuk
    // tiap baris dihitung di JS (tergantung SKU baris itu), bukan disaring
    // dari server, karena bin yang sama bisa tersedia untuk satu SKU dan
    // tidak tersedia untuk SKU lain.
    const LOKASI = @json($locations->map(fn ($l) => ['code' => $l->code, 'zone' => $l->zone])->values());

    document.addEventListener('DOMContentLoaded', function () {
        const tabel = document.getElementById('putawayTable');

        function semuaBarisLokasi() {
            return Array.from(tabel.querySelectorAll('.lokasi-input'));
        }

        function perbaruiKemajuan() {
            const isian = semuaBarisLokasi();
            let terisi = 0;
            isian.forEach(i => { if (i.value.trim() !== '') terisi++; });
            const label = document.getElementById('progressLabel');
            label.textContent = terisi + ' / ' + isian.length + ' palet';
        }

        /**
         * Total qty SKU `produkId` yang sudah menghuni bin `kode`, dari
         * database DITAMBAH baris lain di formulir ini yang menunjuk bin
         * yang sama dengan SKU yang sama — TIDAK termasuk `kecualiInput`
         * sendiri. Mengembalikan null bila bin itu dihuni SKU LAIN (berarti
         * tidak bisa dipakai sama sekali oleh baris ini).
         */
        function terpakaiUntukBaris(kode, produkId, kecualiInput) {
            let terpakai = 0;
            const isi = ISI_BIN[kode];

            if (isi) {
                if (isi.product_id !== produkId) return null;
                terpakai += isi.qty;
            }

            semuaBarisLokasi().forEach(input => {
                if (input === kecualiInput) return;
                if (input.value.trim().toUpperCase() !== kode) return;

                const row = input.closest('tr');
                if (parseInt(row.dataset.productId, 10) !== produkId) return;

                const qty = parseInt(row.querySelector('.qty-aktual').value, 10);
                terpakai += isNaN(qty) ? 0 : qty;
            });

            return terpakai;
        }

        function infoLokasiElement(input) {
            return input.closest('td').querySelector('.lokasi-info');
        }

        /** Menandai satu baris: kosong / masih ada sisa / penuh / dihuni SKU lain. */
        function perbaruiBaris(input) {
            const row = input.closest('tr');
            const kode = input.value.trim().toUpperCase();
            const info = infoLokasiElement(input);
            const produkId = parseInt(row.dataset.productId, 10);
            const kapasitas = row.dataset.capacity ? parseInt(row.dataset.capacity, 10) : null;
            const uom = row.dataset.uom || '';

            if (kode === '') {
                input.classList.remove('is-invalid');
                info.textContent = '';
                return;
            }

            const isi = ISI_BIN[kode];

            if (isi && isi.product_id !== produkId) {
                input.classList.add('is-invalid');
                info.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Rak ini sudah dihuni produk lain.';
                info.className = 'text-danger lokasi-info d-block mt-1 small';
                return;
            }

            const qtyBaris = parseInt(row.querySelector('.qty-aktual').value, 10) || 0;
            const terpakaiLain = terpakaiUntukBaris(kode, produkId, input) || 0;

            if (kapasitas && (terpakaiLain + qtyBaris) > kapasitas) {
                input.classList.add('is-invalid');
                info.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Melebihi kapasitas: rak sudah terisi '
                    + terpakaiLain + ' ' + uom + ', kapasitas ' + kapasitas + ' ' + uom + '.';
                info.className = 'text-danger lokasi-info d-block mt-1 small';
                return;
            }

            if (kapasitas === null && terpakaiLain > 0) {
                input.classList.add('is-invalid');
                info.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Rak ini sudah terisi, dan kapasitas palet produk ini belum diisi di Master Produk.';
                info.className = 'text-danger lokasi-info d-block mt-1 small';
                return;
            }

            input.classList.remove('is-invalid');

            if (terpakaiLain > 0) {
                info.innerHTML = '<i class="bi bi-check-circle me-1"></i>Terisi ' + terpakaiLain + '/' + kapasitas + ' ' + uom
                    + ' — sisa ' + (kapasitas - terpakaiLain) + ' ' + uom + '.';
                info.className = 'text-warning-emphasis lokasi-info d-block mt-1 small';
            } else {
                info.innerHTML = '<i class="bi bi-check-circle me-1"></i>Kosong.';
                info.className = 'text-success lokasi-info d-block mt-1 small';
            }
        }

        function perbaruiSemuaBaris() {
            // Satu baris berubah bisa memengaruhi sisa kapasitas baris lain
            // yang menunjuk bin yang sama, jadi seluruh baris dihitung ulang.
            semuaBarisLokasi().forEach(perbaruiBaris);
        }

        /** Menampilkan dropdown saran rak untuk `input`, disaring sesuai ketikan. */
        function tampilkanSaran(input) {
            const row = input.closest('tr');
            const dropdown = input.parentElement.querySelector('.lokasi-dropdown');
            const produkId = parseInt(row.dataset.productId, 10);
            const kapasitas = row.dataset.capacity ? parseInt(row.dataset.capacity, 10) : null;
            const uom = row.dataset.uom || '';
            const ketik = input.value.trim().toUpperCase();

            const cocok = LOKASI.filter(l => ketik === '' || l.code.includes(ketik))
                .map(l => ({ ...l, terpakai: terpakaiUntukBaris(l.code, produkId, input) }))
                .filter(l => l.terpakai !== null && (kapasitas === null || l.terpakai < kapasitas))
                .slice(0, 30);

            if (cocok.length === 0) {
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
                return;
            }

            dropdown.innerHTML = cocok.map(l => {
                const keterangan = l.terpakai > 0
                    ? ('Terisi ' + l.terpakai + '/' + kapasitas + ' ' + uom + ' — sisa ' + (kapasitas - l.terpakai) + ' ' + uom)
                    : 'Kosong';

                return '<button type="button" class="list-group-item list-group-item-action py-2 saran-rak" data-kode="' + l.code + '">'
                    + '<span class="font-monospace fw-semibold">' + l.code + '</span>'
                    + '<small class="text-muted d-block">' + (l.zone || '') + ' · ' + keterangan + '</small>'
                    + '</button>';
            }).join('');

            dropdown.style.display = 'block';
        }

        function sembunyikanSemuaSaran() {
            tabel.querySelectorAll('.lokasi-dropdown').forEach(d => {
                d.style.display = 'none';
                d.innerHTML = '';
            });
        }

        // Delegasi: satu listener untuk seluruh baris, berapa pun jumlah palet.
        tabel.addEventListener('input', function (e) {
            if (e.target.classList.contains('lokasi-input')) {
                perbaruiSemuaBaris();
                tampilkanSaran(e.target);
                perbaruiKemajuan();
            }

            if (e.target.classList.contains('qty-aktual')) {
                perbaruiSemuaBaris();

                const baris = e.target.closest('tr');
                const sistem = parseInt(baris.querySelector('.qty-sistem').dataset.qty, 10);
                const aktual = parseInt(e.target.value, 10);
                // Selisih ditandai saat diketik agar salah ketik ketahuan di
                // tempat, bukan baru muncul sebagai temuan saat verifikasi.
                e.target.classList.toggle('border-danger', !isNaN(aktual) && aktual !== sistem);
                e.target.classList.toggle('text-danger', !isNaN(aktual) && aktual !== sistem);
            }
        });

        // Saran muncul begitu field difokus (klik / tab masuk), bukan cuma
        // saat mulai mengetik.
        tabel.addEventListener('focusin', function (e) {
            if (e.target.classList.contains('lokasi-input')) {
                tampilkanSaran(e.target);
            }
        });

        // Memilih satu saran: isi field lalu TUTUP dropdown-nya. Tidak
        // memanggil focus() lagi supaya listener focusin di atas tidak
        // langsung membukanya kembali.
        tabel.addEventListener('click', function (e) {
            const tombol = e.target.closest('.saran-rak');
            if (! tombol) return;

            const dropdown = tombol.closest('.lokasi-dropdown');
            const input = dropdown.previousElementSibling;
            input.value = tombol.dataset.kode;
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';

            perbaruiSemuaBaris();
            perbaruiKemajuan();
        });

        // Klik di luar field & dropdown manapun menutup seluruh dropdown
        // yang sedang terbuka.
        document.addEventListener('click', function (e) {
            if (! e.target.closest('.lokasi-input') && ! e.target.closest('.lokasi-dropdown')) {
                sembunyikanSemuaSaran();
            }
        });

        tabel.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && e.target.classList.contains('lokasi-input')) {
                e.target.parentElement.querySelector('.lokasi-dropdown').style.display = 'none';
            }
        });

        document.getElementById('putawayForm').addEventListener('submit', function (e) {
            const baris = Array.from(tabel.querySelectorAll('tbody tr'));
            const terisi = baris.filter(r => r.querySelector('.lokasi-input').value.trim() !== '');

            if (terisi.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Belum ada lokasi',
                    text: 'Isi minimal satu lokasi rak sebelum menyimpan.',
                    confirmButtonText: 'Mengerti'
                });
                return;
            }

            // Rak bentrok (SKU lain, sudah penuh, atau kelebihan kapasitas)
            // TIDAK BOLEH dikirim sama sekali — bukan sekadar diperingatkan,
            // karena server pasti menolaknya juga.
            const bermasalah = tabel.querySelectorAll('.lokasi-input.is-invalid');
            if (bermasalah.length > 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Ada rak yang bentrok',
                    text: 'Perbaiki dulu baris yang ditandai merah sebelum menyimpan.',
                    confirmButtonText: 'Mengerti'
                });
                return;
            }

            const selisih = terisi.filter(r => {
                const sistem = parseInt(r.querySelector('.qty-sistem').dataset.qty, 10);
                const aktual = parseInt(r.querySelector('.qty-aktual').value, 10);
                return !isNaN(aktual) && aktual !== sistem;
            });

            if (this.dataset.dikonfirmasi === '1') {
                return;
            }

            e.preventDefault();

            let pesan = terisi.length + ' dari ' + baris.length + ' palet akan disimpan.';
            if (selisih.length > 0) {
                pesan += ' ' + selisih.length + ' palet punya selisih antara qty sistem dan qty fisik — selisih ini akan diperiksa saat verifikasi Logistik.';
            }
            if (terisi.length < baris.length) {
                pesan += ' Sisanya bisa dilanjutkan nanti.';
            }

            Swal.fire({
                icon: selisih.length > 0 ? 'warning' : 'question',
                title: 'Simpan put-away?',
                text: pesan,
                showCancelButton: true,
                cancelButtonText: 'Periksa Lagi',
                confirmButtonText: 'Ya, Simpan',
                confirmButtonColor: '#198754'
            }).then(hasil => {
                if (hasil.isConfirmed) {
                    this.dataset.dikonfirmasi = '1';
                    this.submit();
                }
            });
        });

        // Render awal: nilai yang sudah dimuat dari server (put-away
        // sebagian sebelumnya) langsung ditandai.
        perbaruiSemuaBaris();
    });
</script>
@endpush
