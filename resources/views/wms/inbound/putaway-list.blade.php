@extends('layouts.wms')
@section('page_title', 'Daftar Put-away')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i> Daftar Menunggu Put-away</h5>
                    <p class="text-muted small mt-1">Role: Operator Gudang. Silakan pilih dokumen Inbound untuk menempatkan palet ke rak.</p>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold">NO DOKUMEN</th><th class="text-secondary small fw-semibold">BATCH NO</th>
                                <th class="text-secondary small fw-semibold">TANGGAL</th>
                                <th class="text-secondary small fw-semibold text-center">TOTAL PALET</th>
                                <th class="text-secondary small fw-semibold">STATUS</th>
                                <th class="text-secondary small fw-semibold text-center">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($dummyInbounds as $inbound)
                            <tr>
                                <td><span class="badge bg-light text-dark border">{{ $inbound['doc_no'] }}</span></td><td class="fw-bold text-primary font-monospace">{{ $inbound['batch_no'] }}</td>
                                <td>{{ $inbound['date'] }}</td>
                                <td class="text-center">
                                    <span class="badge bg-secondary rounded-pill px-3 py-2">{{ $inbound['total_pallets'] }} Palet</span>
                                </td>
                                <td>
                                    <span class="badge bg-warning-subtle text-warning border border-warning px-2 py-1">
                                        <i class="bi bi-hourglass-split me-1"></i> {{ $inbound['status'] }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="/wms/inbound/putaway/{{ $inbound['doc_no'] }}" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">
                                        Proses <i class="bi bi-arrow-right ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Tidak ada dokumen yang menunggu put-away.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
