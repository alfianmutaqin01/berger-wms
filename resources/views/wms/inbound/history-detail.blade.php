@extends('layouts.wms')

@section('title', 'Detail Riwayat Produksi')
@section('page_title', 'Detail Riwayat Produksi')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4" id="printableArea">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-file-earmark-text text-primary me-2"></i>
                        Dokumen: <span class="font-monospace">{{ $header->document_number }}</span>
                    </h5>
                    <p class="text-muted small mt-1 mb-0">Rincian palet hasil produksi pada dokumen ini.</p>
                </div>
                <button class="btn btn-danger fw-bold shadow-sm d-print-none" onclick="window.print()">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Cetak PDF
                </button>
            </div>

            <div class="card-body p-4">

                <!-- Identitas dokumen -->
                <div class="row mb-4 bg-light rounded-3 p-3 mx-0">
                    <div class="col-md-3 col-6 mb-3 mb-md-0">
                        <small class="text-muted d-block fw-semibold">TANGGAL PRODUKSI</small>
                        <span class="fw-bold text-dark">{{ $header->production_date->translatedFormat('d F Y') }}</span>
                    </div>
                    <div class="col-md-3 col-6 mb-3 mb-md-0">
                        <small class="text-muted d-block fw-semibold">GUDANG TUJUAN</small>
                        <span class="fw-bold text-dark">{{ $header->warehouse?->display_label ?? '—' }}</span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block fw-semibold">DIBUAT OLEH</small>
                        <span class="fw-bold text-dark">{{ $header->creator?->full_name ?? '—' }}</span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block fw-semibold">STATUS</small>
                        @php
                            $warna = match($header->status) {
                                \App\Models\InboundHeader::STATUS_VERIFIED => 'success',
                                \App\Models\InboundHeader::STATUS_PUTAWAY_PENDING => 'warning',
                                default => 'info',
                            };
                            $ikon = match($header->status) {
                                \App\Models\InboundHeader::STATUS_VERIFIED => 'bi-check-circle',
                                \App\Models\InboundHeader::STATUS_PUTAWAY_PENDING => 'bi-hourglass-split',
                                default => 'bi-shield-check',
                            };
                        @endphp
                        <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis border border-{{ $warna }}">
                            <i class="bi {{ $ikon }} me-1"></i>{{ $header->status_label }}
                        </span>
                    </div>
                </div>

                @if($header->notes)
                    <div class="alert alert-light border small mb-4">
                        <i class="bi bi-sticky text-primary me-1"></i><strong>Catatan:</strong> {{ $header->notes }}
                    </div>
                @endif

                <!-- Ringkasan -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Total Palet</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['palet']) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Total Qty</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['qty']) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Jenis Produk</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['produk']) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Jumlah Batch</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['batch']) }}</div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 border">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-nowrap">NO. PRODUKSI</th>
                                <th class="text-secondary small fw-semibold text-nowrap">SKU</th>
                                <th class="text-secondary small fw-semibold" style="min-width: 220px;">DESKRIPSI PRODUK</th>
                                <th class="text-secondary small fw-semibold text-nowrap">BATCH</th>
                                <th class="text-secondary small fw-semibold text-center text-nowrap">PALET</th>
                                <th class="text-secondary small fw-semibold text-end text-nowrap">QTY / MAKS</th>
                                <th class="text-secondary small fw-semibold text-nowrap">LOKASI RAK</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $nomorProduksiSebelumnya = null; @endphp
                            @foreach($details as $detail)
                                @php
                                    // Nomor produksi & SKU hanya ditulis pada palet
                                    // pertama dari kelompoknya, agar deretan palet
                                    // yang berasal dari satu baris produksi terbaca
                                    // sebagai satu kesatuan.
                                    $awalKelompok = $detail->production_order_no !== $nomorProduksiSebelumnya;
                                    $nomorProduksiSebelumnya = $detail->production_order_no;
                                    $penuh = $detail->product?->max_qty_per_pallet
                                        && $detail->pallet_qty === $detail->product->max_qty_per_pallet;
                                @endphp
                                <tr class="{{ $awalKelompok && ! $loop->first ? 'border-top border-2' : '' }}">
                                    <td class="font-monospace small text-muted text-nowrap">
                                        {{ $awalKelompok ? ($detail->production_order_no ?? '—') : '' }}
                                    </td>
                                    <td class="text-nowrap">
                                        @if($awalKelompok)
                                            <span class="badge bg-light text-dark border font-monospace">{{ $detail->product?->sku ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $awalKelompok ? ($detail->product?->name ?? '—') : '' }}</td>
                                    <td class="font-monospace small text-nowrap">{{ $awalKelompok ? $detail->batch_no : '' }}</td>
                                    <td class="text-center fw-bold">#{{ $detail->pallet_no }}</td>
                                    <td class="text-end text-nowrap">
                                        {{-- Palet penuh vs palet sisa dibedakan agar
                                             terlihat mana yang belum terisi penuh. --}}
                                        <span class="badge border px-2 py-1 {{ $penuh ? 'bg-primary-subtle text-primary border-primary' : 'bg-warning-subtle text-warning-emphasis border-warning' }}">
                                            {{ number_format($detail->pallet_qty) }} / {{ number_format($detail->product?->max_qty_per_pallet ?? 0) }}
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        @if($detail->location)
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success font-monospace">{{ $detail->location->code }}</span>
                                        @else
                                            <span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Belum ditempatkan</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 pt-3 border-top d-flex justify-content-between d-print-none">
                    <a href="{{ route('wms.inbound.history') }}" class="btn btn-outline-secondary px-4">
                        <i class="bi bi-arrow-left me-1"></i> Kembali ke Riwayat
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #printableArea, #printableArea * {
        visibility: visible !important;
    }
    #printableArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        border: none !important;
        box-shadow: none !important;
    }
    .d-print-none, header, .sidebar, .btn {
        display: none !important;
    }
    .badge {
        border: 1px solid #000 !important;
        color: #000 !important;
        background: transparent !important;
    }
}
</style>
@endsection
