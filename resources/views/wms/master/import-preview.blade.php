@extends('layouts.wms')

@section('title', 'Pratinjau Impor')
@section('page_title', 'Pratinjau Impor — ' . $title)

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Pratinjau Impor {{ $title }}
                </h5>
                <p class="text-muted small mt-1 mb-0">
                    Berkas: <span class="font-monospace">{{ $originalName }}</span> —
                    <strong>belum ada data yang tersimpan.</strong> Periksa dulu, lalu tekan Jalankan Impor.
                </p>
            </div>

            <div class="card-body p-4">
                <!-- Ringkasan -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Total Baris</div>
                            <div class="fs-4 fw-bold text-dark">{{ $summary['total'] }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border border-success rounded-3 p-3 bg-success-subtle">
                            <div class="text-success-emphasis small mb-1">Akan Ditambahkan</div>
                            <div class="fs-4 fw-bold text-success">{{ $summary['baru'] }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border border-primary rounded-3 p-3 bg-primary-subtle">
                            <div class="text-primary-emphasis small mb-1">Akan Diperbarui</div>
                            <div class="fs-4 fw-bold text-primary">{{ $summary['perbarui'] }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border {{ $summary['gagal'] > 0 ? 'border-danger bg-danger-subtle' : '' }} rounded-3 p-3">
                            <div class="small mb-1 {{ $summary['gagal'] > 0 ? 'text-danger-emphasis' : 'text-muted' }}">Dilewati</div>
                            <div class="fs-4 fw-bold {{ $summary['gagal'] > 0 ? 'text-danger' : 'text-muted' }}">{{ $summary['gagal'] }}</div>
                        </div>
                    </div>
                </div>

                @if($summary['perbarui'] > 0)
                    <div class="alert alert-warning border-0 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>{{ $summary['perbarui'] }} baris akan menimpa data yang sudah ada.</strong>
                        Data lama pada baris tersebut akan digantikan isi berkas ini. Pastikan berkasnya benar.
                    </div>
                @endif

                @if($summary['gagal'] > 0)
                    <div class="alert alert-danger border-0 small">
                        <i class="bi bi-x-circle-fill me-1"></i>
                        {{ $summary['gagal'] }} baris tidak dapat diproses dan <strong>akan dilewati</strong>.
                        Baris lainnya tetap diimpor.
                    </div>
                @endif

                <div class="table-responsive border rounded-3" style="max-height: 460px;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="small text-center" style="width: 70px;">BARIS</th>
                                <th class="small">KUNCI</th>
                                <th class="small">KETERANGAN</th>
                                <th class="small text-center" style="width: 130px;">STATUS</th>
                                <th class="small">CATATAN</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    <td class="text-center text-muted small">{{ $row['line'] }}</td>
                                    <td class="font-monospace small fw-bold text-dark text-nowrap">{{ $row['key'] }}</td>
                                    <td class="small">{{ $row['label'] }}</td>
                                    <td class="text-center">
                                        @if($row['status'] === 'baru')
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success">Baru</span>
                                        @elseif($row['status'] === 'perbarui')
                                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary">Diperbarui</span>
                                        @else
                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger">Dilewati</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted">{{ $row['message'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer bg-light border-top-0 rounded-bottom-4 d-flex justify-content-between align-items-center py-3 px-4">
                <form action="{{ route('wms.' . $type . '.import.cancel') }}" method="POST">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="extension" value="{{ $extension }}">
                    <button type="submit" class="btn btn-outline-secondary px-4">
                        <i class="bi bi-x-lg me-1"></i> Batalkan
                    </button>
                </form>

                <form action="{{ route('wms.' . $type . '.import') }}" method="POST">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="extension" value="{{ $extension }}">
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm"
                            @disabled($summary['baru'] === 0 && $summary['perbarui'] === 0)>
                        <i class="bi bi-database-add me-1"></i>
                        Jalankan Impor ({{ $summary['baru'] + $summary['perbarui'] }} baris)
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
