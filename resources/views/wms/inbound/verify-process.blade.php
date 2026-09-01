@extends('layouts.wms')

@section('title', 'Proses Verifikasi')
@section('page_title', 'Proses Verifikasi')

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

@php
    // Maker-Checker: PRD §5.2 mengizinkan Super Admin mengerjakan dua tahap
    // berurutan, TAPI mewajibkan penandaan agar tetap dapat diaudit. Di sini
    // ditandai secara visual; pencatatan audit_logs menyusul di Fase 9.
    $paletSendiri = $details->filter(
        fn ($d) => $d->putaway_by !== null && $d->putaway_by === auth()->id() && ! $d->is_verified
    );
@endphp

@if($paletSendiri->isNotEmpty())
<div class="alert alert-warning border-0 shadow-sm rounded-3 d-flex align-items-start">
    <i class="bi bi-person-exclamation fs-4 me-3"></i>
    <div class="small">
        <strong>Pemisahan tugas gugur pada {{ $paletSendiri->count() }} palet.</strong>
        Anda sendiri yang melakukan put-away palet tersebut, sehingga <em>maker</em> dan <em>checker</em>-nya orang yang sama.
        Verifikasi tetap diizinkan (PRD §5.2), tetapi sebaiknya diserahkan ke petugas lain bila memungkinkan.
    </div>
</div>
@endif

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <a href="{{ route('wms.inbound.verify') }}" class="text-muted text-decoration-none me-2"><i class="bi bi-arrow-left"></i></a>
                        Verifikasi: <span class="text-primary font-monospace">{{ $header->document_number }}</span>
                    </h5>
                    <p class="text-muted small mt-1 ms-4 mb-0">Lakukan pengecekan fisik. Koreksi Qty atau Lokasi bila fisik gudang berbeda dari data sistem.</p>
                </div>
                <div class="text-end">
                    <span class="d-block text-muted small">Gudang: <strong class="text-dark">{{ $header->warehouse?->display_label ?? '—' }}</strong></span>
                    <span class="d-block text-muted small">Tgl Produksi: <strong class="text-dark">{{ $header->production_date->translatedFormat('d M Y') }}</strong></span>
                    <span class="d-block text-muted small">Terverifikasi: <strong class="text-dark" id="progressLabel">{{ $totals['terverifikasi'] }} / {{ $totals['palet'] }} palet</strong></span>
                </div>
            </div>

            <div class="card-body p-4">

                <div class="alert alert-warning border-0 bg-warning-subtle text-warning-emphasis d-flex align-items-start rounded-3 p-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                    <div class="small">
                        <strong>SKU dan Batch bersifat paten</strong> dari Tim Produksi dan tidak dapat diubah di sini — keduanya adalah jejak telusur
                        balik ke dokumen produksi. Anda hanya diizinkan mengoreksi <strong>Qty</strong> dan <strong>Lokasi Rak</strong>.
                        Palet yang sudah diverifikasi <strong>terkunci</strong>; koreksinya lewat Menu Stok oleh Manager/Super Admin.
                    </div>
                </div>

                @if($totals['selisih'] > 0)
                <div class="alert alert-danger border-0 bg-danger-subtle text-danger-emphasis d-flex align-items-start rounded-3 p-3 mb-4" role="alert">
                    <i class="bi bi-clipboard-x fs-4 me-3"></i>
                    <div class="small">
                        <strong>{{ $totals['selisih'] }} palet berselisih</strong> antara qty produksi dan qty hasil hitung Operator.
                        Andalah yang memutuskan angka final sebelum stok diaktifkan — periksa baris bertanda merah dengan teliti.
                    </div>
                </div>
                @endif

                <form method="POST" action="{{ route('wms.inbound.verify.store', $header->document_number) }}" id="verifyForm">
                    @csrf

                    <div class="d-flex justify-content-end mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-3" id="btnCheckAll">
                            <i class="bi bi-check2-square me-1"></i> Centang Semua
                        </button>
                    </div>

                    <div class="table-responsive" style="overflow: visible;">
                        <table class="table table-hover align-middle mb-0" id="verifyTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-secondary small fw-semibold text-center" style="width: 70px;">CEK</th>
                                    <th class="text-secondary small fw-semibold text-center text-nowrap">PALET</th>
                                    <th class="text-secondary small fw-semibold" style="min-width: 220px;">SKU / DESKRIPSI</th>
                                    <th class="text-secondary small fw-semibold text-nowrap">BATCH</th>
                                    <th class="text-secondary small fw-semibold text-center text-nowrap">QTY PRODUKSI</th>
                                    <th class="text-secondary small fw-semibold text-center" style="width: 130px;">QTY FINAL</th>
                                    <th class="text-secondary small fw-semibold" style="min-width: 260px;">LOKASI RAK</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $nomorProduksiSebelumnya = null; @endphp
                                @foreach($details as $detail)
                                    @php
                                        $awalKelompok = $detail->production_order_no !== $nomorProduksiSebelumnya;
                                        $nomorProduksiSebelumnya = $detail->production_order_no;
                                        $terkunci = $detail->is_verified;
                                        $kodeLama = old("pallets.{$detail->id}.location_code", $detail->location?->code);
                                        $qtyLama = old("pallets.{$detail->id}.qty_actual", $detail->qty_actual ?? $detail->pallet_qty);
                                        $berselisih = $detail->qty_variance !== null && $detail->qty_variance !== 0;
                                    @endphp
                                    <tr class="{{ $awalKelompok && ! $loop->first ? 'border-top border-2' : '' }} {{ $terkunci ? 'opacity-75' : '' }}"
                                        data-product-id="{{ $detail->product_id }}"
                                        data-capacity="{{ $detail->product?->max_qty_per_pallet }}"
                                        data-uom="{{ $detail->product?->uom }}"
                                        data-terkunci="{{ $terkunci ? '1' : '0' }}">
                                        <td class="text-center">
                                            @if($terkunci)
                                                <i class="bi bi-lock-fill text-success fs-5"
                                                   title="Terverifikasi oleh {{ $detail->verifiedBy?->full_name ?? '—' }} pada {{ $detail->verified_at?->translatedFormat('d M Y H:i') }}"></i>
                                            @else
                                                <input type="checkbox"
                                                       name="pallets[{{ $detail->id }}][verified]" value="1"
                                                       @checked(old("pallets.{$detail->id}.verified"))
                                                       class="form-check-input verify-checkbox border-secondary"
                                                       style="width: 1.4em; height: 1.4em; cursor: pointer;">
                                            @endif
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <span class="fw-bold">#{{ $detail->pallet_no }}</span>
                                            <small class="d-block text-muted font-monospace" style="font-size: 0.7rem;">
                                                {{ $awalKelompok ? $detail->production_order_no : '' }}
                                            </small>
                                            <small class="d-block text-muted" style="font-size: 0.65rem;" title="Operator put-away">
                                                <i class="bi bi-person-badge"></i> {{ $detail->putawayBy?->full_name ?? '—' }}
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
                                            @if($berselisih)
                                                <small class="d-block text-danger fw-semibold mt-1" style="font-size: 0.7rem;">
                                                    Operator: {{ number_format($detail->qty_actual) }}
                                                    ({{ $detail->qty_variance > 0 ? '+' : '' }}{{ $detail->qty_variance }})
                                                </small>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <input type="number"
                                                   name="pallets[{{ $detail->id }}][qty_actual]"
                                                   value="{{ $qtyLama }}" min="0"
                                                   @readonly($terkunci)
                                                   class="form-control form-control-sm text-center fw-bold qty-aktual @error("pallets.{$detail->id}.qty_actual") is-invalid @enderror {{ $terkunci ? 'bg-light' : '' }}">
                                        </td>
                                        <td>
                                            <div class="position-relative">
                                                <input type="text"
                                                       name="pallets[{{ $detail->id }}][location_code]"
                                                       value="{{ $kodeLama }}"
                                                       placeholder="Ketik kode rak, mis. B-01-01"
                                                       autocomplete="off"
                                                       @readonly($terkunci)
                                                       class="form-control text-uppercase font-monospace lokasi-input @error("pallets.{$detail->id}.location_code") is-invalid @enderror {{ $terkunci ? 'bg-light' : '' }}">
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
                        <a href="{{ route('wms.inbound.verify') }}" class="btn btn-outline-secondary px-4">Kembali</a>
                        <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm">
                            <i class="bi bi-check-circle-fill me-1"></i> Simpan Verifikasi
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
    // Isi bin saat ini di database: kode rak => {product_id, qty, capacity, uom}.
    const ISI_BIN = @json((object) $occupancy);

    // Seluruh bin aktif di gudang dokumen ini. Ketersediaannya per baris
    // dihitung di JS karena tergantung SKU baris itu.
    const LOKASI = @json($locations->map(fn ($l) => ['code' => $l->code, 'zone' => $l->zone])->values());

    document.addEventListener('DOMContentLoaded', function () {
        const tabel = document.getElementById('verifyTable');

        /** Hanya baris yang MASIH BISA disunting; baris terkunci tidak ikut dihitung ulang. */
        function barisAktif() {
            return Array.from(tabel.querySelectorAll('.lokasi-input')).filter(
                i => i.closest('tr').dataset.terkunci !== '1'
            );
        }

        function perbaruiKemajuan() {
            const semua = tabel.querySelectorAll('tbody tr');
            const terkunci = tabel.querySelectorAll('tbody tr[data-terkunci="1"]').length;
            const dicentang = tabel.querySelectorAll('.verify-checkbox:checked').length;
            document.getElementById('progressLabel').textContent =
                (terkunci + dicentang) + ' / ' + semua.length + ' palet';
        }

        /**
         * Produk LAIN yang menghuni bin `kode` — dari database MAUPUN dari
         * baris lain pada formulir ini yang belum disimpan. Baris terkunci
         * sudah terwakili oleh ISI_BIN, jadi tidak dihitung dua kali.
         */
        function produkLainDiBin(kode, produkId, kecualiInput) {
            const isi = ISI_BIN[kode];
            if (isi && isi.product_id !== produkId) return isi.product_id;

            let konflik = null;

            barisAktif().forEach(input => {
                if (konflik !== null || input === kecualiInput) return;
                if (input.value.trim().toUpperCase() !== kode) return;

                const produkBaris = parseInt(input.closest('tr').dataset.productId, 10);
                if (produkBaris !== produkId) konflik = produkBaris;
            });

            return konflik;
        }

        /**
         * Total qty SKU `produkId` yang menghuni bin `kode`.
         *
         * Kontribusi baris yang sedang disunting dikeluarkan dari ISI_BIN
         * supaya palet yang tetap di raknya sendiri tidak dihitung dua kali:
         * sekali dari database, sekali dari nilai yang sedang diketik.
         */
        function terpakaiUntukBaris(kode, produkId, kecualiInput) {
            let terpakai = 0;
            const isi = ISI_BIN[kode];

            if (isi && isi.product_id === produkId) {
                terpakai += isi.qty;
            }

            barisAktif().forEach(input => {
                const row = input.closest('tr');
                const qty = parseInt(row.querySelector('.qty-aktual').value, 10) || 0;
                const kodeAwal = (input.defaultValue || '').trim().toUpperCase();
                const kodeKini = input.value.trim().toUpperCase();

                // Baris ini SUDAH ikut terhitung di ISI_BIN lewat nilai
                // awalnya; keluarkan dulu agar tidak dobel.
                if (kodeAwal === kode) {
                    const qtyAwal = parseInt(row.querySelector('.qty-aktual').defaultValue, 10) || 0;
                    terpakai -= qtyAwal;
                }

                if (input === kecualiInput) return;
                if (kodeKini !== kode) return;
                if (parseInt(row.dataset.productId, 10) !== produkId) return;

                terpakai += qty;
            });

            return Math.max(0, terpakai);
        }

        function perbaruiBaris(input) {
            const row = input.closest('tr');
            const kode = input.value.trim().toUpperCase();
            const info = input.closest('td').querySelector('.lokasi-info');
            const produkId = parseInt(row.dataset.productId, 10);
            const kapasitas = row.dataset.capacity ? parseInt(row.dataset.capacity, 10) : null;
            const uom = row.dataset.uom || '';

            if (kode === '') {
                input.classList.remove('is-invalid');
                info.textContent = '';
                return;
            }

            if (produkLainDiBin(kode, produkId, input) !== null) {
                input.classList.add('is-invalid');
                info.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Rak ini sudah dihuni produk lain.';
                info.className = 'text-danger lokasi-info d-block mt-1 small';
                return;
            }

            const qtyBaris = parseInt(row.querySelector('.qty-aktual').value, 10) || 0;
            const terpakaiLain = terpakaiUntukBaris(kode, produkId, input);

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
            barisAktif().forEach(perbaruiBaris);
        }

        function tampilkanSaran(input) {
            const row = input.closest('tr');
            if (row.dataset.terkunci === '1') return;

            const dropdown = input.parentElement.querySelector('.lokasi-dropdown');
            const produkId = parseInt(row.dataset.productId, 10);
            const kapasitas = row.dataset.capacity ? parseInt(row.dataset.capacity, 10) : null;
            const uom = row.dataset.uom || '';
            const ketik = input.value.trim().toUpperCase();

            const cocok = LOKASI.filter(l => ketik === '' || l.code.includes(ketik))
                .filter(l => produkLainDiBin(l.code, produkId, input) === null)
                .map(l => ({ ...l, terpakai: terpakaiUntukBaris(l.code, produkId, input) }))
                .filter(l => kapasitas === null || l.terpakai < kapasitas)
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

        tabel.addEventListener('input', function (e) {
            if (e.target.classList.contains('lokasi-input')) {
                perbaruiSemuaBaris();
                tampilkanSaran(e.target);
            }

            if (e.target.classList.contains('qty-aktual')) {
                perbaruiSemuaBaris();

                const baris = e.target.closest('tr');
                const sistem = parseInt(baris.querySelector('.qty-sistem').dataset.qty, 10);
                const aktual = parseInt(e.target.value, 10);
                e.target.classList.toggle('border-danger', !isNaN(aktual) && aktual !== sistem);
                e.target.classList.toggle('text-danger', !isNaN(aktual) && aktual !== sistem);
            }
        });

        tabel.addEventListener('change', function (e) {
            if (e.target.classList.contains('verify-checkbox')) {
                perbaruiKemajuan();
            }
        });

        tabel.addEventListener('focusin', function (e) {
            if (e.target.classList.contains('lokasi-input')) {
                tampilkanSaran(e.target);
            }
        });

        tabel.addEventListener('click', function (e) {
            const tombol = e.target.closest('.saran-rak');
            if (! tombol) return;

            const dropdown = tombol.closest('.lokasi-dropdown');
            const input = dropdown.previousElementSibling;
            input.value = tombol.dataset.kode;
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';

            perbaruiSemuaBaris();
        });

        document.addEventListener('click', function (e) {
            if (! e.target.closest('.lokasi-input') && ! e.target.closest('.lokasi-dropdown')) {
                sembunyikanSemuaSaran();
            }
        });

        // "Centang Semua" hanya menyentuh baris yang belum terverifikasi —
        // baris terkunci tidak punya checkbox sama sekali.
        document.getElementById('btnCheckAll').addEventListener('click', function () {
            const checks = tabel.querySelectorAll('.verify-checkbox');
            const semuaTercentang = Array.from(checks).every(c => c.checked);

            checks.forEach(c => { c.checked = ! semuaTercentang; });
            this.innerHTML = semuaTercentang
                ? '<i class="bi bi-check2-square me-1"></i> Centang Semua'
                : '<i class="bi bi-x-square me-1"></i> Batal Centang Semua';

            perbaruiKemajuan();
        });

        document.getElementById('verifyForm').addEventListener('submit', function (e) {
            const dicentang = tabel.querySelectorAll('.verify-checkbox:checked');

            if (dicentang.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Belum ada yang dicentang',
                    text: 'Centang minimal satu palet yang sudah Anda periksa fisiknya.',
                    confirmButtonText: 'Mengerti'
                });
                return;
            }

            // Baris bermasalah hanya diperiksa pada palet yang DICENTANG —
            // baris yang sengaja ditunda tidak perlu menghalangi penyimpanan.
            let adaMasalah = false;
            dicentang.forEach(c => {
                const input = c.closest('tr').querySelector('.lokasi-input');
                if (input.classList.contains('is-invalid') || input.value.trim() === '') adaMasalah = true;
            });

            if (adaMasalah) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Ada lokasi yang bermasalah',
                    text: 'Perbaiki dulu lokasi rak pada baris yang dicentang — ada yang kosong atau bentrok.',
                    confirmButtonText: 'Mengerti'
                });
                return;
            }

            if (this.dataset.dikonfirmasi === '1') {
                return;
            }

            e.preventDefault();

            const total = tabel.querySelectorAll('tbody tr').length;
            const terkunci = tabel.querySelectorAll('tbody tr[data-terkunci="1"]').length;
            const sisa = total - terkunci - dicentang.length;

            let pesan = dicentang.length + ' palet akan diverifikasi dan TIDAK dapat diubah lagi dari layar ini.';
            if (sisa > 0) {
                pesan += ' ' + sisa + ' palet lainnya ditunda dan bisa dilanjutkan nanti.';
            }

            Swal.fire({
                icon: 'question',
                title: 'Simpan verifikasi?',
                text: pesan,
                showCancelButton: true,
                cancelButtonText: 'Periksa Lagi',
                confirmButtonText: 'Ya, Verifikasi',
                confirmButtonColor: '#198754'
            }).then(hasil => {
                if (hasil.isConfirmed) {
                    this.dataset.dikonfirmasi = '1';
                    this.submit();
                }
            });
        });

        perbaruiSemuaBaris();
    });
</script>
@endpush
