@extends('layouts.wms')

@php($mrf = $mrf ?? null)

@section('title', $mrf ? 'Perbaiki MRF '.$mrf->mrf_number : 'Buat Permintaan Material (MRF)')
@section('page_title', $mrf
    ? 'Perbaiki & Ajukan Ulang '.$mrf->mrf_number
    : 'Buat Permintaan Material (MRF)')

@push('styles')
<style>
    /* =========================================================
       MRF CREATE / EDIT FORM - MODERN ERGONOMIC STYLING
       ========================================================= */
    .mrf-step-banner {
        background: linear-gradient(135deg, #0d2540 0%, #123962 60%, #1a4f85 100%);
        border-radius: 0.85rem;
        color: #ffffff;
        position: relative;
        overflow: hidden;
    }
    .mrf-step-banner::before {
        content: '';
        position: absolute;
        top: -40px; right: -30px;
        width: 140px; height: 140px;
        background: radial-gradient(circle, rgba(232, 135, 30, .2) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    /* Type of Requisition Radio Cards */
    .mrf-type-card {
        cursor: pointer;
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        transition: all 0.18s ease;
        position: relative;
    }
    .mrf-type-card:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
    }
    .btn-check:checked + .mrf-type-card {
        border-color: #123962;
        background: #f0f7ff;
        box-shadow: 0 2px 8px rgba(18, 57, 98, 0.08);
    }
    .btn-check:checked + .mrf-type-card .type-check-icon {
        display: block !important;
    }
    .btn-check:checked + .mrf-type-card .type-icon-box {
        background: #123962 !important;
        color: #ffffff !important;
    }

    /* WhatsApp Approver Card */
    .wa-approver-card {
        border-top: 3.5px solid #25D366 !important;
        background: #ffffff;
    }

    /* Saved Contact Chips */
    .contact-item-chip {
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        border-radius: 0.65rem;
        transition: all 0.15s ease;
    }
    .contact-item-chip:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }

    /* Autocomplete Dropdown */
    .cari-saran {
        border-radius: 0.65rem !important;
        border: 1px solid #cbd5e1 !important;
        overflow: hidden;
    }
    .cari-saran .list-group-item {
        border: none;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.82rem;
        padding: 0.55rem 0.75rem;
        transition: background-color 0.12s ease;
    }
    .cari-saran .list-group-item:last-child {
        border-bottom: none;
    }
    .cari-saran .list-group-item:hover, .cari-saran .list-group-item:focus {
        background-color: #f0f7ff;
    }

    /* Items Row Card */
    .baris-produk {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0.65rem;
        padding: 0.65rem 0.75rem;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .baris-produk:hover {
        border-color: #cbd5e1;
        box-shadow: 0 2px 6px rgba(18, 57, 98, 0.04);
    }
    .baris-num-badge {
        width: 24px;
        height: 24px;
        font-size: 0.72rem;
        font-weight: 700;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #e2e8f0;
        color: #475569;
    }
</style>
@endpush

@section('content')
{{-- FORMULIR PRODUKSI:
     Menggantikan formulir kertas yang selama ini dipakai. Keperluan WAJIB ditulis
     untuk jejak audit, dan nomor WhatsApp atasan disimpan agar pengajuan berikutnya cepat. --}}

{{-- Session Flash Alerts --}}
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
    {{-- ALASAN PENOLAKAN DITARUH DI ATAS FORMULIR --}}
    <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-3">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-arrow-counterclockwise fs-5 text-warning-emphasis flex-shrink-0 mt-0.5"></i>
            <div class="flex-grow-1">
                <strong>{{ $mrf->mrf_number }} dikembalikan kepada Anda untuk perbaikan.</strong>
                <div class="small text-muted mt-0.5">
                    Perbaiki isinya lalu ajukan lagi — <strong>nomornya tetap sama</strong> dan riwayat penolakan terbaca oleh peninjau.
                </div>
                @php($alasan = $mrf->status === \App\Models\MaterialRequisition::STATUS_REJECTED_APPROVAL
                    ? $mrf->approver_rejection_reason
                    : $mrf->logistics_rejection_reason)
                @if($alasan)
                    <div class="mt-2 p-2.5 bg-white bg-opacity-75 rounded-2 border border-warning-subtle">
                        <span class="small fw-bold text-dark d-block">Catatan Penolakan:</span>
                        <span class="text-body small">{{ $alasan }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
@else
    {{-- Step Workflow Banner --}}
    <div class="mrf-step-banner p-3 px-3.5 mb-3 shadow-sm">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="badge bg-white text-dark rounded-pill px-2.5 py-0.5 small fw-semibold mb-1" style="font-size: 0.68rem;">
                    Material Requisition Form
                </span>
                <h5 class="fw-bold mb-0 text-white" style="font-size: 1.05rem;">Buat Permintaan Pengeluaran Material</h5>
            </div>
            <div class="d-none d-md-flex align-items-center gap-2 text-white-50 small" style="font-size: 0.76rem;">
                <span class="text-white fw-medium"><i class="bi bi-1-circle-fill text-warning me-1"></i>Isi Data</span>
                <i class="bi bi-chevron-right text-white-50"></i>
                <span><i class="bi bi-2-circle me-1"></i>Persetujuan WA</span>
                <i class="bi bi-chevron-right text-white-50"></i>
                <span><i class="bi bi-3-circle me-1"></i>Ambil di Logistik</span>
            </div>
        </div>
    </div>
@endif

<form method="POST" action="{{ $mrf ? route('wms.mrf.update', $mrf) : route('wms.mrf.store') }}" id="formMrf">
    @csrf
    @if($mrf) @method('PUT') @endif

    <div class="row g-3">
        {{-- ============================================ KOLOM KIRI (7 Kolom) ============================================ --}}
        <div class="col-12 col-lg-7">
            {{-- Data Pemohon --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-3 p-md-3.5">
                    <div class="d-flex align-items-center justify-content-between mb-2.5 pb-2 border-bottom">
                        <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2 small" style="font-size: 0.88rem;">
                            <i class="bi bi-person-badge-fill text-primary"></i> Data Pemohon (Akun Anda)
                        </h6>
                        <span class="badge bg-light text-muted border rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                            Otomatis dari Akun
                        </span>
                    </div>

                    <div class="row g-2">
                        <div class="col-12 col-sm-6">
                            <div class="p-2.5 rounded-3 bg-light border border-light-subtle d-flex align-items-center gap-2.5">
                                <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center flex-shrink-0"
                                     style="width: 36px; height: 36px;">
                                    <i class="bi bi-person-fill fs-5"></i>
                                </div>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="text-muted small" style="font-size: 0.7rem;">Nama Lengkap</div>
                                    <div class="fw-bold text-dark text-truncate small" style="font-size: 0.85rem;">{{ auth()->user()->full_name }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="p-2.5 rounded-3 bg-light border border-light-subtle d-flex align-items-center gap-2.5">
                                <div class="rounded-circle bg-info bg-opacity-10 text-info d-flex align-items-center justify-content-center flex-shrink-0"
                                     style="width: 36px; height: 36px;">
                                    <i class="bi bi-diagram-3-fill fs-5"></i>
                                </div>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="text-muted small" style="font-size: 0.7rem;">Departemen / Divisi</div>
                                    <div class="fw-bold text-dark text-truncate small" style="font-size: 0.85rem;">
                                        {{ auth()->user()->department?->name ?? 'Produksi' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Detail Permintaan Material --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-3 p-md-3.5">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2 small" style="font-size: 0.88rem;">
                            <i class="bi bi-clipboard2-check-fill text-primary"></i> Spesifikasi Permintaan
                        </h6>
                        <span class="text-muted small" style="font-size: 0.7rem;">Wajib Lengkap</span>
                    </div>

                    {{-- Gudang Tujuan --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">
                            Gudang Logistik Tujuan <span class="text-danger">*</span>
                        </label>
                        @if($gudang)
                            <input type="hidden" name="warehouse_id" value="{{ $gudang->id }}">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted border-end-0 rounded-start-3"><i class="bi bi-building"></i></span>
                                <input type="text" class="form-control rounded-end-3 bg-light fw-medium" value="{{ $gudang->kode_pendek }} — {{ $gudang->name ?? 'Gudang Utama' }}" disabled>
                            </div>
                        @else
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white text-muted border-end-0 rounded-start-3"><i class="bi bi-building"></i></span>
                                <select name="warehouse_id" class="form-select border-start-0 rounded-end-3" required>
                                    <option value="">Pilih gudang logistik...</option>
                                    @foreach($gudangOptions as $g)
                                        <option value="{{ $g->id }}" @selected(old('warehouse_id') == $g->id)>{{ $g->kode_pendek }} — {{ $g->name ?? '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>

                    {{-- Type of Requisition (Selectable Radio Cards) --}}
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark mb-1.5">
                            Jenis Permintaan (Type of Requisition) <span class="text-danger">*</span>
                        </label>
                        <div class="row g-2">
                            @foreach($jenisOptions as $nilai => $jenis)
                            <div class="col-12 col-sm-6">
                                <input type="radio" class="btn-check" name="request_type" id="jenis-{{ $nilai }}"
                                       value="{{ $nilai }}" @checked(old('request_type', $mrf?->request_type) === $nilai) required>
                                <label class="mrf-type-card w-100 p-2.5 rounded-3 d-flex align-items-start gap-2.5 h-100" for="jenis-{{ $nilai }}">
                                    <div class="type-icon-box rounded-3 bg-light text-primary d-flex align-items-center justify-content-center flex-shrink-0" style="width:34px;height:34px;">
                                        @switch($nilai)
                                            @case('production') <i class="bi bi-gear-wide-connected fs-6"></i> @break
                                            @case('rework')     <i class="bi bi-arrow-repeat fs-6"></i> @break
                                            @case('sample')     <i class="bi bi-droplet-half fs-6"></i> @break
                                            @default            <i class="bi bi-box fs-6"></i>
                                        @endswitch
                                    </div>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <span class="fw-bold text-dark small" style="font-size: 0.8rem;">{{ $jenis['label'] }}</span>
                                            <i class="bi bi-check-circle-fill text-primary type-check-icon d-none small"></i>
                                        </div>
                                        <small class="text-muted d-block lh-sm mt-0.5" style="font-size: 0.7rem;">{{ $jenis['bantuan'] }}</small>
                                    </div>
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Purpose / Keperluan --}}
                    <div>
                        <label class="form-label small fw-semibold text-dark mb-1">
                            Keperluan &amp; Keterangan (Purpose) <span class="text-danger">*</span>
                        </label>
                        <textarea name="purpose" rows="3" class="form-control rounded-3 small" required
                                  placeholder="Contoh: Reproses 300 pcs DDP batch Juli menjadi warna Off White untuk memenuhi jadwal produksi batch #B-104.">{{ old('purpose', $mrf?->purpose) }}</textarea>
                        <div class="form-text small text-muted mt-1" style="font-size: 0.7rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            Keterangan ini menjadi dasar pertanggungjawaban keluarnya barang dari gudang saat audit stok.
                        </div>
                    </div>
                </div>
            </div>

            {{-- Barang yang Diminta --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-3 p-md-3.5">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2 small" style="font-size: 0.88rem;">
                                <i class="bi bi-box-seam-fill text-primary"></i> Daftar Barang yang Diminta
                            </h6>
                            <span class="text-muted small" style="font-size: 0.7rem;">Ketik minimal 2 karakter SKU atau nama material</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 shadow-sm d-inline-flex align-items-center gap-1 fw-semibold" id="tambahBaris" style="font-size: 0.78rem;">
                            <i class="bi bi-plus-lg"></i> Tambah Baris
                        </button>
                    </div>

                    {{-- Desktop Header Labels --}}
                    <div class="row g-2 px-2 py-1.5 bg-light rounded-2 text-secondary small fw-bold mb-2 d-none d-md-flex" style="font-size: 0.72rem;">
                        <div class="col-md-5">PRODUK / SKU MATERIAL</div>
                        <div class="col-md-2">JUMLAH (QTY)</div>
                        <div class="col-md-4">CATATAN / USULAN BATCH</div>
                        <div class="col-md-1 text-end">AKSI</div>
                    </div>

                    {{-- Container Baris Produk --}}
                    <div id="barisProduk" class="d-flex flex-column gap-2"></div>

                    {{-- Kosong State --}}
                    <div class="text-center py-4 border rounded-3 bg-light bg-opacity-50 text-muted small mt-2" id="kosongProduk">
                        <i class="bi bi-inbox fs-3 d-block mb-1 opacity-50"></i>
                        Belum ada material yang ditambahkan.<br>
                        Tekan tombol <strong>Tambah Baris</strong> di atas untuk memasukkan barang.
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-2.5 mt-2.5 border-top">
                        <small class="text-muted" style="font-size: 0.7rem;">
                            <i class="bi bi-shield-check me-1 text-success"></i>
                            Batch fisik akan diverifikasi &amp; diambilkan oleh tim Logistik Gudang.
                        </small>
                        <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1 small" id="totalBarisBadge" style="font-size: 0.7rem;">
                            0 baris material
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================ KOLOM KANAN (5 Kolom) ============================================ --}}
        <div class="col-12 col-lg-5">
            {{-- WhatsApp Approver Card --}}
            <div class="card border-0 shadow-sm rounded-4 wa-approver-card mb-3">
                <div class="card-body p-3 p-md-3.5">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center"
                             style="width: 36px; height: 36px;">
                            <i class="bi bi-whatsapp fs-5"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark small" style="font-size: 0.88rem;">Persetujuan Atasan (WhatsApp)</h6>
                            <span class="text-muted small" style="font-size: 0.7rem;">Link verifikasi interaktif</span>
                        </div>
                    </div>

                    <div class="p-2.5 bg-success bg-opacity-10 rounded-3 text-success-emphasis small mb-3" style="font-size: 0.72rem; line-height: 1.35;">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        Setelah disimpan, notifikasi tautan persetujuan dikirim langsung ke WhatsApp atasan. Logistik baru dapat memproses setelah disetujui.
                    </div>

                    {{-- Daftar Kontak Tersimpan --}}
                    @if($kontak->isNotEmpty())
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-muted d-block mb-1.5" style="font-size: 0.72rem;">
                            <i class="bi bi-clock-history me-1"></i> Kontak Tersimpan (Tinggal Klik):
                        </label>
                        <div class="d-flex flex-column gap-1.5" style="max-height: 180px; overflow-y: auto;">
                            @foreach($kontak as $k)
                            <div class="contact-item-chip d-flex justify-content-between align-items-center p-2">
                                <button type="button" class="btn btn-link p-0 text-start text-decoration-none flex-grow-1 pilihKontak d-flex align-items-center gap-2"
                                        data-nama="{{ $k->name }}" data-nomor="{{ $k->phone }}">
                                    <div class="rounded-circle bg-white text-secondary border d-flex align-items-center justify-content-center flex-shrink-0"
                                         style="width: 26px; height: 26px; font-size: 0.72rem;">
                                        <i class="bi bi-person"></i>
                                    </div>
                                    <div class="min-w-0 flex-grow-1">
                                        <span class="fw-bold d-block text-dark small lh-1" style="font-size: 0.78rem;">{{ $k->name }}</span>
                                        <small class="text-muted font-monospace" style="font-size: 0.68rem;">{{ $k->phone_label }}</small>
                                    </div>
                                    <span class="badge bg-white text-primary border rounded-pill px-2 py-0.5 small me-1" style="font-size: 0.65rem;">
                                        Pilih
                                    </span>
                                </button>
                                <button type="button" class="btn btn-sm btn-link text-danger p-1 hapusKontak"
                                        data-action="{{ route('wms.mrf.contacts.destroy', $k) }}"
                                        data-nama="{{ $k->name }}" title="Hapus kontak dari daftar tersimpan">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- Input Nama & Nomor Atasan --}}
                    <div class="mb-2.5">
                        <label class="form-label small fw-semibold text-dark mb-1">
                            Nama Atasan Penyetuju <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-muted border-end-0 rounded-start-3"><i class="bi bi-person"></i></span>
                            <input type="text" name="approver_name" id="approverNama" class="form-control border-start-0 rounded-end-3"
                                   value="{{ old('approver_name', $mrf?->approver_name) }}" maxlength="100" required placeholder="Mis. Pak Ganti / Bu Rina">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark mb-1">
                            Nomor WhatsApp Atasan <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-success border-end-0 rounded-start-3"><i class="bi bi-whatsapp"></i></span>
                            <input type="text" name="approver_phone" id="approverNomor" class="form-control border-start-0 rounded-end-3 font-monospace"
                                   value="{{ old('approver_phone', $mrf?->approver_phone) }}" maxlength="25" required placeholder="081234567890">
                        </div>
                        <div class="form-text small text-muted mt-1" style="font-size: 0.7rem;">
                            Gunakan format 08... atau 62... (satu nomor tujuan).
                        </div>
                    </div>

                    <div class="form-check p-2 rounded-2 bg-light border">
                        <input type="checkbox" name="simpan_kontak" value="1" class="form-check-input ms-0 me-2" id="simpanKontak"
                               @checked(old('simpan_kontak'))>
                        <label class="form-check-label small" for="simpanKontak" style="font-size: 0.75rem;">
                            <strong>Simpan nomor ini</strong> untuk pengajuan berikutnya
                        </label>
                    </div>
                </div>
            </div>

            {{-- Submit / Action Card --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body p-3">
                    <button type="submit" class="btn btn-primary rounded-3 py-2.5 w-100 shadow-sm d-flex align-items-center justify-content-center gap-2 fw-bold" style="font-size: 0.88rem;">
                        <i class="bi bi-send-fill"></i>
                        <span>{{ $mrf ? 'Simpan & Ajukan Ulang '.$mrf->mrf_number : 'Simpan & Minta Persetujuan' }}</span>
                    </button>
                    <div class="text-center mt-2">
                        <a href="{{ $mrf ? route('wms.mrf.show', $mrf) : route('wms.mrf.index') }}"
                           class="btn btn-sm btn-link text-decoration-none text-muted" style="font-size: 0.78rem;">
                            <i class="bi bi-arrow-left me-1"></i> Batal &amp; Kembali
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Form hapus kontak luar --}}
<form method="POST" id="formHapusKontak" class="d-none">
    @csrf
    @method('DELETE')
</form>

{{-- Template Baris Item --}}
<template id="templateBaris">
    <div class="baris-produk">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-md-5">
                <div class="d-flex align-items-center gap-2 mb-1 d-md-none">
                    <span class="baris-num-badge">__NUM__</span>
                    <span class="small fw-bold text-dark">Material / SKU:</span>
                </div>
                <div class="cari-produk position-relative">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0 text-muted rounded-start-3"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control form-control-sm border-start-0 rounded-end-3 cari-teks" placeholder="Ketik SKU atau nama produk..." autocomplete="off">
                    </div>
                    <input type="hidden" class="cari-nilai" name="items[__I__][product_id]">
                    <div class="list-group cari-saran d-none position-absolute w-100 shadow-lg" style="z-index:30;max-height:240px;overflow-y:auto"></div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="small fw-bold text-dark mb-1 d-md-none">Jumlah (Qty):</div>
                <input type="number" name="items[__I__][qty]" class="form-control form-control-sm rounded-3" min="1" placeholder="Qty" required>
            </div>
            <div class="col-5 col-md-4">
                <div class="small fw-bold text-dark mb-1 d-md-none">Catatan / Usulan Batch:</div>
                <input type="text" name="items[__I__][note]" class="form-control form-control-sm rounded-3" maxlength="500" placeholder="Usulan batch (opsional)">
            </div>
            <div class="col-1 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger border-0 rounded-circle p-1 hapusBaris" title="Hapus baris ini">
                    <i class="bi bi-trash fs-6"></i>
                </button>
            </div>
        </div>
    </div>
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

    function perbaruiKosong() {
        const count = wadah.children.length;
        kosong.classList.toggle('d-none', count > 0);
        if (totalBadge) {
            totalBadge.textContent = count + ' baris material';
        }
        // Update line numbers for mobile view
        Array.from(wadah.children).forEach((baris, idx) => {
            const badge = baris.querySelector('.baris-num-badge');
            if (badge) badge.textContent = String(idx + 1);
        });
    }

    function tambahBaris() {
        const count = wadah.children.length + 1;
        let html = template.innerHTML.replaceAll('__I__', String(urut++));
        html = html.replaceAll('__NUM__', String(count));

        const pembungkus = document.createElement('div');
        pembungkus.innerHTML = html;
        const baris = pembungkus.firstElementChild;

        wadah.appendChild(baris);

        window.pasangPencarian(baris.querySelector('.cari-produk'), {
            url: function (q) {
                return '{{ route('wms.mrf.lookup.products') }}?q=' + encodeURIComponent(q);
            },
            minimal: 2,
            kosong: 'Produk tidak ditemukan. Coba ketik bagian dari SKU.',
            tampilan: function (item) {
                return '<div class="d-flex justify-content-between align-items-center">'
                    + '<div><span class="fw-bold font-monospace text-dark">' + item.sku + '</span>'
                    + '<div class="small text-muted">' + item.name + '</div></div>'
                    + '<span class="badge bg-light text-secondary border rounded-pill px-2 py-0.5 ms-2">' + (item.uom || '-') + '</span>'
                    + '</div>';
            },
            label: function (item) { return item.sku + ' — ' + item.name; },
        });

        baris.querySelector('.hapusBaris').addEventListener('click', function () {
            baris.remove();
            perbaruiKosong();
        });

        perbaruiKosong();
        return baris;
    }

    document.getElementById('tambahBaris').addEventListener('click', tambahBaris);

    /*
     | Baris permintaan yang sudah ada, pada perbaikan MRF yang ditolak.
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
            alert('Belum ada material yang dipilih. Ketik SKU-nya lalu klik salah satu dari hasil pencarian.');
        }
    });
})();
</script>
@endpush
