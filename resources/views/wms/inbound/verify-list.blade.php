@extends('layouts.wms')
@section('page_title', 'Daftar Verifikasi Logistik')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-shield-check text-primary me-2"></i> Daftar Menunggu Verifikasi</h5>
                    <p class="text-muted small mt-1">Role: Tim Logistik. Silakan pilih dokumen Inbound untuk melakukan verifikasi fisik akhir.</p>
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
                            @forelse($dummyVerifications as $verify)
                            <tr>
                                <td><span class="badge bg-light text-dark border">{{ $verify['doc_no'] }}</span></td><td class="fw-bold text-primary font-monospace">{{ $verify['batch_no'] }}</td>
                                <td>{{ $verify['date'] }}</td>
                                <td class="text-center">
                                    <span class="badge bg-secondary rounded-pill px-3 py-2">{{ $verify['total_pallets'] }} Palet</span>
                                </td>
                                <td>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info px-2 py-1">
                                        <i class="bi bi-search me-1"></i> {{ $verify['status'] }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="/wms/inbound/verify/{{ $verify['doc_no'] }}" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">
                                        Mulai Verifikasi <i class="bi bi-arrow-right ms-1"></i>
                                    </a>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Tidak ada dokumen yang menunggu verifikasi.</td>
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