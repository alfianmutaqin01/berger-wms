@extends('layouts.wms')

@section('title', 'Data Stok')
@section('page_title', 'Data Stok')

@section('content')
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('warning'))
{{-- Berhasil, tapi ada yang perlu diperhatikan — mis. sebagian stok yang baru
     ditambahkan langsung tersedot ke pesanan yang menunggu. --}}
<div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-circle-fill me-2"></i>{{ session('warning') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Ada isian yang perlu diperbaiki:</strong>
    <ul class="mb-0 mt-2 small">
        @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
    </ul>
</div>
@endif

@canany(['reports.view', 'inventory.adjust'])
<div class="d-flex justify-content-end gap-2 mb-3 flex-wrap">
    @can('reports.view')
    {{-- TIDAK ADA logika unduhan sendiri di halaman ini. Kedua tautan
         mengarah ke PRATINJAU laporan yang sudah ada, persis seperti kartu
         Pergerakan Stok di menu Laporan & Analisis: lihat dulu 25 baris
         pertama beserta jumlah baris sebenarnya, baru tekan unduh.

         Kalau halaman ini menulis query-nya sendiri, akan ada dua definisi
         "stok" — dan suatu hari salah satunya berubah tanpa yang lain ikut.

         Hanya gudang yang diteruskan. Penyaring lain (kategori, batch, rak)
         SENGAJA tidak ikut: halaman laporan tidak punya isian itu, jadi
         meneruskannya hanya membuat penyaring yang tak terlihat dan tak bisa
         dibatalkan siapa pun yang membuka pratinjaunya.

         Gate-nya reports.view, BUKAN inventory.view seperti halaman ini:
         Produksi & Operator boleh melihat stok di layar, tetapi membawa
         keluar seluruh isi gudang dalam satu berkas adalah hal lain. --}}
    @php
        $gudangKini = request()->query('warehouse_id');
    @endphp
    <div class="btn-group">
        <button type="button" class="btn btn-outline-success rounded-3 dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li>
                <a class="dropdown-item py-2" href="{{ route('wms.reports.show', array_filter(['key' => 'posisi-stok', 'warehouse_id' => $gudangKini])) }}">
                    <i class="bi bi-boxes me-2 text-primary"></i>Posisi Stok
                    <small class="d-block text-muted ms-4">Isi rak saat ini, satu baris per batch</small>
                </a>
            </li>
            <li>
                <a class="dropdown-item py-2" href="{{ route('wms.reports.show', array_filter(['key' => 'pergerakan-stok', 'warehouse_id' => $gudangKini])) }}">
                    <i class="bi bi-arrow-left-right me-2 text-danger"></i>Pergerakan Stok
                    <small class="d-block text-muted ms-4">Item ledger: tiap tambah &amp; kurang beserta pelakunya</small>
                </a>
            </li>
        </ul>
    </div>
    @endcan

    @can('inventory.adjust')
    {{-- Dua pintu memasukkan stok tanpa dokumen inbound. Keduanya hanya untuk
         Manager & Super Admin, dan keduanya WAJIB mencatat alasan ke ledger. --}}
    <button type="button" class="btn btn-outline-primary rounded-3" data-bs-toggle="modal" data-bs-target="#modalImporStok">
        <i class="bi bi-upload me-1"></i> Impor Stok Awal
    </button>
    <button type="button" class="btn btn-primary rounded-3" data-bs-toggle="modal" data-bs-target="#modalTambahStok">
        <i class="bi bi-plus-lg me-1"></i> Tambah Stok
    </button>
    @endcan
</div>
@endcanany

<!-- Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Good Stock</h6>
                <h3 class="mb-0 fw-bold text-success">{{ number_format($stats['good']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-primary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Teralokasi</h6>
                <h3 class="mb-0 fw-bold text-primary">{{ number_format($stats['dialokasikan']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        {{-- Karantina BUKAN DDP: masih boleh dijual, cuma ditahan menunggu
             jangka waktunya lewat. Diberi warna sendiri (kuning) supaya tidak
             terbaca sebagai "rusak" seperti Stok DDP di sebelahnya. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Karantina</h6>
                <h3 class="mb-0 fw-bold text-warning-emphasis">{{ number_format($stats['karantina']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-secondary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Stok DDP</h6>
                <h3 class="mb-0 fw-bold text-secondary">{{ number_format($stats['ddp']) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        {{-- Batch yang umurnya tinggal <= 90 hari; ini yang harus dijual duluan. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Hampir Kedaluwarsa</h6>
                <h3 class="mb-0 fw-bold text-danger">{{ number_format($stats['kritis']) }} <small class="fs-6 fw-normal text-muted">batch</small></h3>
            </div>
        </div>
    </div>
</div>

@include('wms.inventory._tabel-stok')

@include('wms.inventory._modal-stok')
@endsection

@push('styles')
<style>
    /* Panah baris SKU berputar saat accordion terbuka. */
    .chevron-sku { transition: transform 0.2s ease; }
    [aria-expanded="true"] .chevron-sku { transform: rotate(90deg); }
</style>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Satu handler per modal, mengambil nilai dari tombol pemicunya —
        // lebih ringan daripada menyalin form ke setiap baris tabel.
        const adjust = document.getElementById('modalAdjust');
        if (adjust) {
            adjust.addEventListener('show.bs.modal', function (e) {
                const b = e.relatedTarget;
                const alloc = parseInt(b.dataset.alloc, 10) || 0;

                adjust.querySelector('#adjStockId').value = b.dataset.stock;
                adjust.querySelector('#adjSku').textContent = b.dataset.sku || '—';
                adjust.querySelector('#adjBatch').textContent = b.dataset.batch || '—';
                adjust.querySelector('#adjQtyOld').value = b.dataset.qty;
                adjust.querySelector('#adjQtyNew').value = b.dataset.qty;
                // Yang dikoreksi hanya stok BEBAS. Unit teralokasi tersimpan
                // terpisah dan tidak ikut berubah, jadi batas bawahnya nol —
                // bukan jumlah alokasi (temuan SQA).
                adjust.querySelector('#adjAllocHint').textContent = alloc > 0
                    ? alloc + ' unit lain sudah dialokasikan untuk pesanan dan tidak ikut dikoreksi. Batch ini tidak bisa ditandai DDP selama alokasi itu ada.'
                    : '';
            });
        }

        const transfer = document.getElementById('modalTransfer');
        if (transfer) {
            transfer.addEventListener('show.bs.modal', function (e) {
                const b = e.relatedTarget;

                transfer.querySelector('#trfStockId').value = b.dataset.stock;
                transfer.querySelector('#trfSku').textContent = b.dataset.sku || '—';
                transfer.querySelector('#trfBatch').textContent = b.dataset.batch || '—';
                transfer.querySelector('#trfLoc').textContent = b.dataset.loc || '—';
                transfer.querySelector('#trfQty').max = b.dataset.qty;
                transfer.querySelector('#trfQty').value = b.dataset.qty;
                transfer.querySelector('#trfQtyHint').textContent = 'Maksimal ' + b.dataset.qty + ' (stok tersedia di rak ini).';
            });
        }

        const karantina = document.getElementById('modalKarantina');
        if (karantina) {
            karantina.addEventListener('show.bs.modal', function (e) {
                const b = e.relatedTarget;

                karantina.querySelector('#krtStockId').value = b.dataset.stock;
                karantina.querySelector('#krtSku').textContent = b.dataset.sku || '—';
                karantina.querySelector('#krtBatch').textContent = b.dataset.batch || '—';
            });
        }

        const prioritas = document.getElementById('modalPrioritas');
        if (prioritas) {
            prioritas.addEventListener('show.bs.modal', function (e) {
                const b = e.relatedTarget;

                prioritas.querySelector('#prtStockId').value = b.dataset.stock;
                prioritas.querySelector('#prtSku').textContent = b.dataset.sku || '—';
                prioritas.querySelector('#prtBatch').textContent = b.dataset.batch || '—';
            });
        }
    });
</script>
@endpush
