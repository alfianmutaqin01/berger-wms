@extends('layouts.wms')

@php($mrf = $mrf ?? null)

@section('title', $mrf ? 'Perbaiki MRF '.$mrf->mrf_number : 'Buat Permintaan Material (MRF)')
@section('page_title', $mrf
    ? 'Perbaiki & Ajukan Ulang '.$mrf->mrf_number
    : 'Buat Permintaan Material (MRF)')

@push('styles')
<style>
    /* Yang ditata di sini hanya yang memang khas formulir MRF.

       Warna dan tipografi kepala tabel dibiarkan ikut soms-style.css supaya
       seragam dengan layar WMS lain; yang ditimpa cuma paddingnya, karena
       tabel ini berisi kolom input yang diisi cepat, bukan teks yang dibaca,
       sehingga barisnya wajar lebih rapat. */
    .table-mrf-items th {
        padding: 0.6rem 0.75rem;
    }
    .table-mrf-items td {
        padding: 0.5rem 0.6rem;
        vertical-align: middle;
    }

    /* Pilihan Jenis Permintaan, berupa kartu yang bisa diklik. */
    .mrf-type-radio-label {
        cursor: pointer;
        border: 1.5px solid var(--bs-border-color);
        background-color: #ffffff;
        transition: border-color 0.15s ease, background-color 0.15s ease;
    }
    .mrf-type-radio-label:hover {
        border-color: var(--bs-secondary-border-subtle);
        background-color: var(--bs-tertiary-bg);
    }
    .btn-check:checked + .mrf-type-radio-label {
        border-color: var(--primary);
        background-color: rgba(var(--bs-primary-rgb), .06);
        box-shadow: 0 0 0 1px var(--primary);
    }
    .btn-check:checked + .mrf-type-radio-label .check-indicator {
        display: inline-block !important;
    }

    /* Saran pencarian produk, muncul mengambang di dalam sel tabel. */
    .cari-saran {
        border-radius: 0.5rem !important;
        border: 1px solid var(--bs-border-color) !important;
        box-shadow: 0 8px 24px rgba(var(--bs-primary-rgb), .12) !important;
        z-index: 1050;
    }
    .cari-saran .list-group-item {
        font-size: 0.8rem;
        padding: 0.45rem 0.75rem;
        border-bottom: 1px solid var(--bs-border-color-translucent);
    }
    .cari-saran .list-group-item:hover, .cari-saran .list-group-item:focus {
        background-color: rgba(var(--bs-primary-rgb), .06);
    }
</style>
@endpush

@section('content')
{{-- FORMULIR PRODUKSI (DESKTOP WORKSTATION OPTIMIZED)
     Dirancang untuk entri data cepat pada layar monitor desktop/laptop staf produksi. --}}

{{-- Header bar & navigasi kembali --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-plus text-primary"></i>
            {{ $mrf ? 'Perbaiki MRF '.$mrf->mrf_number : 'Buat Permintaan Material (MRF)' }}
        </h4>
        <p class="text-muted small mb-0">Formulir pengeluaran material dari gudang logistik untuk kebutuhan produksi dan operasional.</p>
    </div>
    <div>
        <a href="{{ $mrf ? route('wms.mrf.show', $mrf) : route('wms.mrf.index') }}" class="btn btn-outline-secondary btn-sm rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar
        </a>
    </div>
</div>

@foreach(['success' => 'check-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3 d-flex align-items-center mb-3">
        <i class="bi bi-{{ $ikon }} fs-5 me-2 flex-shrink-0"></i>
        <div>{{ session($jenis) }}</div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-3">
    <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <strong>Formulir belum dapat disimpan:</strong>
    </div>
    <ul class="mb-0 mt-1 small ps-3">
        @foreach($errors->all() as $galat)<li>{{ $galat }}</li>@endforeach
    </ul>
</div>
@endif

@if($mrf)
    <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-3">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-arrow-counterclockwise fs-5 text-warning-emphasis flex-shrink-0 mt-1"></i>
            <div class="flex-grow-1">
                <strong>{{ $mrf->mrf_number }} dikembalikan kepada Anda untuk perbaikan.</strong>
                <div class="small text-muted mt-1">
                    Perbaiki isinya lalu ajukan lagi — <strong>nomornya tetap sama</strong> dan riwayat penolakan terbaca oleh peninjau.
                </div>
                @php($alasan = $mrf->status === \App\Models\MaterialRequisition::STATUS_REJECTED_APPROVAL
                    ? $mrf->approver_rejection_reason
                    : $mrf->logistics_rejection_reason)
                @if($alasan)
                    <div class="mt-2 p-2 bg-white bg-opacity-75 rounded-2 border border-warning-subtle">
                        <span class="small fw-bold text-dark d-block">Alasan penolakan:</span>
                        <span class="text-body small">{{ $alasan }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif

<form method="POST" action="{{ $mrf ? route('wms.mrf.update', $mrf) : route('wms.mrf.store') }}" id="formMrf">
    @csrf
    @if($mrf) @method('PUT') @endif

    <div class="row g-3">
        {{-- ============================================ KOLOM UTAMA (KIRI - 8 Kolom) ============================================ --}}
        <div class="col-12 col-xl-8">
            {{-- Card 1: Informasi Permintaan & Pemohon --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-bottom py-3 px-3 px-md-4">
                    <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-info-circle text-primary"></i> Informasi Permintaan &amp; Pemohon
                    </h6>
                </div>
                <div class="card-body p-3 p-md-4">
                    {{-- Row 1: Pemohon, Divisi, Gudang --}}
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-muted mb-1">Nama Pemohon</label>
                            <input type="text" class="form-control form-control-sm rounded-2 bg-light fw-bold text-dark" value="{{ auth()->user()->full_name }}" disabled>
                            <span class="text-muted small" style="font-size: 0.68rem;">Otomatis dari data login akun</span>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-muted mb-1">Departemen / Divisi</label>
                            <input type="text" class="form-control form-control-sm rounded-2 bg-light" value="{{ auth()->user()->department?->name ?? 'Produksi' }}" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-dark mb-1">Gudang Logistik Tujuan <span class="text-danger">*</span></label>
                            @if($gudang)
                                <input type="hidden" name="warehouse_id" value="{{ $gudang->id }}">
                                <input type="text" class="form-control form-control-sm rounded-2 bg-light fw-semibold" value="{{ $gudang->kode_pendek }} — {{ $gudang->name ?? 'Gudang Utama' }}" disabled>
                            @else
                                <select name="warehouse_id" class="form-select form-select-sm rounded-2" required>
                                    <option value="">Pilih gudang logistik...</option>
                                    @foreach($gudangOptions as $g)
                                        <option value="{{ $g->id }}" @selected(old('warehouse_id') == $g->id)>{{ $g->kode_pendek }} — {{ $g->name ?? '' }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    </div>

                    {{-- Row 2: Type of Requisition (Grid Options) --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark mb-2">
                            Jenis Permintaan (Type of Requisition) <span class="text-danger">*</span>
                        </label>
                        <div class="row g-2">
                            @foreach($jenisOptions as $nilai => $jenis)
                            <div class="col-12 col-sm-6 col-lg-3">
                                <input type="radio" class="btn-check" name="request_type" id="jenis-{{ $nilai }}"
                                       value="{{ $nilai }}" @checked(old('request_type', $mrf?->request_type) === $nilai) required>
                                <label class="mrf-type-radio-label w-100 p-3 rounded-3 h-100 d-flex flex-column justify-content-between" for="jenis-{{ $nilai }}">
                                    <div>
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="fw-bold text-dark small" style="font-size: 0.8rem;">{{ $jenis['label'] }}</span>
                                            <i class="bi bi-check-circle-fill text-primary check-indicator d-none small"></i>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.7rem; line-height: 1.3;">{{ $jenis['bantuan'] }}</div>
                                    </div>
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Row 3: Purpose / Keperluan --}}
                    <div>
                        <label class="form-label small fw-semibold text-dark mb-1">
                            Keperluan &amp; Keterangan (Purpose) <span class="text-danger">*</span>
                        </label>
                        <textarea name="purpose" rows="2" class="form-control form-control-sm rounded-2" required
                                  placeholder="Tuliskan alasan pengeluaran material (contoh: Reproses 300 pcs DDP batch Juli menjadi warna Off White untuk memenuhi jadwal produksi batch #B-104).">{{ old('purpose', $mrf?->purpose) }}</textarea>
                        <div class="form-text small text-muted mt-1" style="font-size: 0.7rem;">
                            <i class="bi bi-info-circle me-1"></i> Keterangan ini menjadi dasar audit keluarnya barang dari gudang logistik.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Card 2: Daftar Barang yang Diminta (Tabular Data Grid) --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-bottom py-3 px-3 px-md-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                            <i class="bi bi-boxes text-primary"></i> Daftar Material yang Diminta
                        </h6>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary rounded-3 px-3 py-1 fw-semibold d-inline-flex align-items-center gap-2" id="tambahBaris">
                        <i class="bi bi-plus-lg"></i> Tambah Baris
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-mrf-items align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 48px;" class="text-center">No</th>
                                    <th>Material / SKU Produk <span class="text-danger">*</span></th>
                                    <th style="width: 130px;" class="text-center">Jumlah (Qty) <span class="text-danger">*</span></th>
                                    <th style="width: 240px;">Usulan Batch / Catatan</th>
                                    <th style="width: 48px;" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="barisProduk">
                                {{-- Baris tabel di-generate via JavaScript --}}
                            </tbody>
                            <tbody id="kosongProdukWrap">
                                <tr id="kosongProduk" class="d-none">
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-1 opacity-50"></i>
                                        Belum ada material yang ditambahkan. Klik tombol <strong>Tambah Baris</strong> di atas untuk memilih barang.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 bg-light bg-opacity-50 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <small class="text-muted" style="font-size: 0.72rem;">
                            <i class="bi bi-check2-shield text-success me-1"></i>
                            Pilihan fisik batch barang aktual akan dialokasikan oleh tim Logistik Gudang sesuai rak penyimpanan.
                        </small>
                        <span class="badge bg-white text-dark border rounded-pill px-3 py-1 fw-semibold small" id="totalBarisBadge">
                            0 baris material
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================ KOLOM SAMPING (KANAN - 4 Kolom) ============================================ --}}
        <div class="col-12 col-xl-4">
            {{-- Card 3: Persetujuan Atasan via WhatsApp --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-bottom py-3 px-3 px-md-4 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-whatsapp text-success fs-5"></i>
                        <h6 class="fw-bold mb-0 text-dark small" style="font-size: 0.88rem;">Persetujuan Atasan</h6>
                    </div>
                    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle rounded-pill px-2 py-1" style="font-size: 0.68rem;">
                        Tautan WhatsApp
                    </span>
                </div>
                <div class="card-body p-3 p-md-4">
                    <p class="small text-muted mb-3" style="font-size: 0.74rem; line-height: 1.4;">
                        Setelah disimpan, notifikasi tautan persetujuan dikirim otomatis ke nomor WhatsApp atasan. Logistik baru memproses setelah disetujui.
                    </p>

                    {{-- Daftar Kontak Tersimpan --}}
                    @if($kontak->isNotEmpty())
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted d-block mb-2" style="font-size: 0.72rem;">
                            <i class="bi bi-clock-history me-1"></i> Nomor Tersimpan (Klik untuk Memilih):
                        </label>
                        <div class="list-group list-group-flush border rounded-2" style="max-height: 160px; overflow-y: auto;">
                            @foreach($kontak as $k)
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                <button type="button" class="btn btn-link p-0 text-start text-decoration-none flex-grow-1 pilihKontak"
                                        data-nama="{{ $k->name }}" data-nomor="{{ $k->phone }}">
                                    <span class="fw-bold d-block text-dark small" style="font-size: 0.78rem;">{{ $k->name }}</span>
                                    <small class="text-muted font-monospace" style="font-size: 0.68rem;">{{ $k->phone_label }}</small>
                                </button>
                                <button type="button" class="btn btn-sm btn-link text-danger p-0 hapusKontak ms-2"
                                        data-action="{{ route('wms.mrf.contacts.destroy', $k) }}"
                                        data-nama="{{ $k->name }}" title="Hapus kontak dari daftar tersimpan">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- Input Nama Atasan --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark mb-1">
                            Nama Atasan Penyetuju <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="approver_name" id="approverNama" class="form-control form-control-sm rounded-2"
                               value="{{ old('approver_name', $mrf?->approver_name) }}" maxlength="100" required placeholder="Contoh: Pak Gandhi / Bu Rina">
                    </div>

                    {{-- Input Nomor WhatsApp --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark mb-1">
                            Nomor WhatsApp Atasan <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-success border-end-0"><i class="bi bi-whatsapp"></i></span>
                            <input type="text" name="approver_phone" id="approverNomor" class="form-control font-monospace border-start-0"
                                   value="{{ old('approver_phone', $mrf?->approver_phone) }}" maxlength="25" required placeholder="081234567890">
                        </div>
                        <div class="form-text small text-muted mt-1" style="font-size: 0.7rem;">
                            Boleh ditulis format 08... atau 62... (satu nomor tujuan).
                        </div>
                    </div>

                    {{-- Checkbox Simpan Kontak --}}
                    <div class="form-check p-2 rounded-2 bg-light border">
                        <input type="checkbox" name="simpan_kontak" value="1" class="form-check-input ms-0 me-2" id="simpanKontak"
                               @checked(old('simpan_kontak'))>
                        <label class="form-check-label small" for="simpanKontak" style="font-size: 0.74rem;">
                            <strong>Simpan nomor ini</strong> agar tinggal diklik untuk pengajuan berikutnya
                        </label>
                    </div>
                </div>
            </div>

            {{-- Card 4: Tombol Aksi Submit --}}
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-3">
                    <button type="submit" class="btn btn-primary rounded-3 py-3 w-100 shadow-sm d-flex align-items-center justify-content-center gap-2 fw-bold" style="font-size: 0.88rem;">
                        <i class="bi bi-send-check-fill"></i>
                        <span>{{ $mrf ? 'Simpan & Ajukan Ulang '.$mrf->mrf_number : 'Simpan & Minta Persetujuan' }}</span>
                    </button>
                    <div class="text-center mt-2">
                        <a href="{{ $mrf ? route('wms.mrf.show', $mrf) : route('wms.mrf.index') }}"
                           class="btn btn-sm btn-link text-decoration-none text-muted" style="font-size: 0.78rem;">
                            <i class="bi bi-x-circle me-1"></i> Batal &amp; Kembali
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Form hapus kontak berdiri sendiri di luar form MRF --}}
<form method="POST" id="formHapusKontak" class="d-none">
    @csrf
    @method('DELETE')
</form>

{{-- Template Table Row Baris Item --}}
<template id="templateBaris">
    <tr class="baris-produk">
        <td class="text-center text-muted fw-semibold baris-no" style="font-size: 0.82rem;">__NUM__</td>
        <td>
            <div class="cari-produk position-relative">
                <input type="text" class="form-control form-control-sm rounded-2 cari-teks" placeholder="Ketik minimal 2 huruf SKU atau nama produk..." autocomplete="off">
                <input type="hidden" class="cari-nilai" name="items[__I__][product_id]">
                <div class="list-group cari-saran d-none position-absolute w-100 shadow" style="max-height:220px;overflow-y:auto"></div>
            </div>
        </td>
        <td>
            <input type="number" name="items[__I__][qty]" class="form-control form-control-sm rounded-2 text-end fw-bold" min="1" placeholder="Qty" required>
        </td>
        <td>
            <input type="text" name="items[__I__][note]" class="form-control form-control-sm rounded-2" maxlength="500" placeholder="Usulan batch (opsional)">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-link text-danger p-0 hapusBaris" title="Hapus baris ini">
                <i class="bi bi-trash3 fs-6"></i>
            </button>
        </td>
    </tr>
</template>
@endsection

@push('scripts')
@include('partials.pencarian-ketik')
<script>
(function () {
    const wadah = document.getElementById('barisProduk');
    const template = document.getElementById('templateBaris');
    const kosong = document.getElementById('kosongProduk');
    const totalBadge = document.getElementById('totalBarisBadge');
    let urut = 0;

    function perbaruiNomor() {
        const barisList = wadah.querySelectorAll('.baris-produk');
        kosong.classList.toggle('d-none', barisList.length > 0);
        if (totalBadge) {
            totalBadge.textContent = barisList.length + ' baris material';
        }
        barisList.forEach((b, idx) => {
            const noCell = b.querySelector('.baris-no');
            if (noCell) noCell.textContent = String(idx + 1);
        });
    }

    function tambahBaris() {
        const count = wadah.querySelectorAll('.baris-produk').length + 1;
        let html = template.innerHTML.replaceAll('__I__', String(urut++));
        html = html.replaceAll('__NUM__', String(count));

        const tb = document.createElement('tbody');
        tb.innerHTML = html;
        const baris = tb.firstElementChild;

        wadah.appendChild(baris);

        window.pasangPencarian(baris.querySelector('.cari-produk'), {
            url: function (q) {
                return '{{ route('wms.mrf.lookup.products') }}?q=' + encodeURIComponent(q);
            },
            minimal: 2,
            kosong: 'Produk tidak ditemukan. Coba ketik potongan SKU atau nama.',
            tampilan: function (item) {
                return '<div class="d-flex justify-content-between align-items-center py-1">'
                    + '<div><span class="fw-bold font-monospace text-dark">' + item.sku + '</span> — <span class="text-secondary small">' + item.name + '</span></div>'
                    + '<span class="badge bg-light text-dark border rounded-pill px-2 py-1 ms-2">' + (item.uom || '-') + '</span>'
                    + '</div>';
            },
            label: function (item) { return item.sku + ' — ' + item.name; },
        });

        baris.querySelector('.hapusBaris').addEventListener('click', function () {
            baris.remove();
            perbaruiNomor();
        });

        perbaruiNomor();
        return baris;
    }

    document.getElementById('tambahBaris').addEventListener('click', tambahBaris);

    /*
     | Baris permintaan yang sudah ada (edit mode)
     */
    const barisAwal = @json($barisAwal ?? []);

    barisAwal.forEach(function (isi) {
        const baris = tambahBaris();

        baris.querySelector('.cari-nilai').value = isi.id;
        baris.querySelector('.cari-teks').value = isi.label;
        baris.querySelector('[name$="[qty]"]').value = isi.qty;
        baris.querySelector('[name$="[note]"]').value = isi.note ?? '';
    });

    if (barisAwal.length === 0) {
        tambahBaris();
    }

    document.querySelectorAll('.pilihKontak').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            document.getElementById('approverNama').value = tombol.dataset.nama;
            document.getElementById('approverNomor').value = tombol.dataset.nomor;
            document.getElementById('simpanKontak').checked = false;
        });
    });

    document.querySelectorAll('.hapusKontak').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            if (!confirm('Hapus ' + tombol.dataset.nama + ' dari daftar nomor tersimpan?')) return;
            const form = document.getElementById('formHapusKontak');
            form.action = tombol.dataset.action;
            form.submit();
        });
    });

    document.getElementById('formMrf').addEventListener('submit', function (e) {
        let adaYangSah = false;

        wadah.querySelectorAll('.baris-produk').forEach(function (baris) {
            const produk = baris.querySelector('.cari-nilai');
            const qty = baris.querySelector('input[type=number]');

            if (!produk.value) {
                baris.remove();
                return;
            }
            if (qty.value) adaYangSah = true;
        });

        if (!adaYangSah) {
            e.preventDefault();
            alert('Belum ada material yang dipilih. Ketik SKU lalu pilih dari daftar saran produk.');
        }
    });
})();
</script>
@endpush
