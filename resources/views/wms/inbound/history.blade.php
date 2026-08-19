@extends('layouts.wms')
@section('page_title', 'Riwayat Produksi')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-clock-history text-primary me-2"></i> Riwayat Input Produksi</h5>
                <p class="text-muted small mt-1">Role: Tim Produksi. Pantau status put-away dan cetak dokumen PDF dari data produksi yang pernah Anda serahkan.</p>
            </div>
            <div class="card-body p-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="input-group" style="max-width: 300px;">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control bg-light border-start-0" placeholder="Cari No. Dokumen / Batch...">
                    </div>
                    <div>
                        <select class="form-select bg-light border-0">
                            <option value="">Semua Status</option>
                            <option value="Selesai">Selesai</option>
                            <option value="Menunggu">Menunggu Put-away</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold">TGL PRODUKSI</th>
                                <th class="text-secondary small fw-semibold">NO. DOKUMEN</th>
                                <th class="text-secondary small fw-semibold">BATCH NO</th>
                                <th class="text-secondary small fw-semibold text-center">JML PALET</th>
                                <th class="text-secondary small fw-semibold">STATUS</th>
                                <th class="text-secondary small fw-semibold text-center">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($dummyHistory as $h)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="bg-primary-subtle text-primary rounded px-2 py-1 me-2 text-center" style="min-width: 40px;">
                                                <small class="d-block fw-bold" style="line-height: 1;">{{ substr($h['date'], 0, 2) }}</small>
                                                <small class="d-block text-uppercase" style="font-size: 0.65rem;">{{ substr($h['date'], 3, 3) }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="fw-bold text-primary">{{ $h['doc_no'] }}</td>
                                    <td><span class="font-monospace text-muted small">{{ $h['batch_no'] }}</span></td>
                                    <td class="text-center fw-semibold">{{ $h['total_pallets'] }}</td>
                                    <td>
                                        @if($h['status'] == 'Selesai')
                                            <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check-circle me-1"></i> Selesai (Rak)</span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning border border-warning"><i class="bi bi-hourglass-split me-1"></i> Menunggu Put-away</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <a href="/wms/inbound/history/{{ $h['doc_no'] }}" class="btn btn-sm btn-outline-primary fw-semibold"><i class="bi bi-eye me-1"></i> Lihat Detail</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                        Belum ada riwayat produksi.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4">
                    <span class="text-muted small">Menampilkan 1 hingga 2 dari 2 entri</span>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item disabled"><a class="page-link" href="#">Sebelumnya</a></li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item disabled"><a class="page-link" href="#">Selanjutnya</a></li>
                    </ul>
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
    .card, .card * {
        visibility: visible !important;
    }
    .card {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        border: none !important;
        box-shadow: none !important;
    }
    .btn, .input-group, .form-select, .pagination, header, .sidebar {
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