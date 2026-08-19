@extends('layouts.wms')
@section('page_title', 'Detail Riwayat Produksi')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4" id="printableArea">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start">
                <div>
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-file-earmark-text text-primary me-2"></i> Dokumen: {{ $inbound['doc_no'] }}</h5>
                    <p class="text-muted small mt-1">Detail rincian palet untuk hasil produksi ini.</p>
                </div>
                <button class="btn btn-danger fw-bold shadow-sm d-print-none" onclick="window.print()">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Cetak PDF
                </button>
            </div>
            <div class="card-body p-4">
                
                <div class="row mb-4 bg-light rounded-3 p-3 mx-0">
                    <div class="col-md-3 col-6 mb-3 mb-md-0">
                        <small class="text-muted d-block fw-semibold">TANGGAL PRODUKSI</small>
                        <span class="fw-bold text-dark">{{ $inbound['date'] }}</span>
                    </div>
                    <div class="col-md-3 col-6 mb-3 mb-md-0">
                        <small class="text-muted d-block fw-semibold">BATCH NO</small>
                        <span class="font-monospace fw-bold text-dark">{{ $inbound['batch_no'] }}</span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block fw-semibold">TOTAL PALET</small>
                        <span class="fw-bold text-dark">{{ $inbound['total_pallets'] }} Palet</span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block fw-semibold">STATUS</small>
                        @if($inbound['status'] == 'Selesai')
                            <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check-circle me-1"></i> Selesai (Rak)</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning border border-warning"><i class="bi bi-hourglass-split me-1"></i> Menunggu Put-away</span>
                        @endif
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 border">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold">PALET NO</th>
                                <th class="text-secondary small fw-semibold">SKU</th>
                                <th class="text-secondary small fw-semibold">DESKRIPSI PRODUK</th>
                                <th class="text-secondary small fw-semibold">UoM</th>
                                <th class="text-secondary small fw-semibold text-end">QTY / MAKS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pallets as $p)
                                <tr>
                                    <td class="fw-bold">#{{ $p['pallet_no'] }}</td>
                                    <td><span class="badge bg-light text-dark border">{{ $p['sku'] }}</span></td>
                                    <td><small>{{ $p['description'] }}</small></td>
                                    <td>{{ $p['uom'] }}</td>
                                    <td class="text-end">
                                        @php
                                            $isFull = $p['qty'] == $p['max_cap'];
                                            $badgeClass = $isFull ? 'bg-primary-subtle text-primary border-primary' : 'bg-warning-subtle text-warning border-warning';
                                        @endphp
                                        <span class="badge border {{ $badgeClass }} px-2 py-1">{{ $p['qty'] }} / {{ $p['max_cap'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 pt-3 border-top d-flex justify-content-between d-print-none">
                    <a href="/wms/inbound/history" class="btn btn-outline-secondary px-4"><i class="bi bi-arrow-left me-1"></i> Kembali ke Riwayat</a>
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