@extends('layouts.wms')
@section('title', 'Penerimaan Retur')
@section('page_title', 'Inbound Retur (Reverse Logistics)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Proses barang retur yang dibawa kembali oleh armada pengiriman. Lakukan pengecekan fisik dan tentukan apakah barang layak kembali ke Stok (GR) atau harus masuk Karantina/Rusak (DDP).</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-arrow-return-left text-danger me-2"></i>Antrean Barang Retur</h6>
        <div class="input-group input-group-sm w-25">
            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" class="form-control bg-light border-start-0" placeholder="Cari No. Dokumen / SKU...">
        </div>
    </div>
    
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small">
                    <tr>
                        <th class="ps-4">Dokumen Asal</th>
                        <th>Waktu Pelaporan</th>
                        <th>SKU Bermasalah</th>
                        <th>Qty (Pail)</th>
                        <th>Alasan Retur</th>
                        <th class="text-center pe-4">Aksi Gudang</th>
                    </tr>
                </thead>
                <tbody class="border-top-0">
                    @forelse($pendingReturns as $retur)
                    <tr>
                        <td class="ps-4">
                            <span class="fw-bold text-dark d-block">{{ $retur['po'] }}</span>
                            <small class="text-muted">{{ $retur['customer'] }}</small>
                        </td>
                        <td>
                            <span class="d-block">{{ $retur['date'] }}</span>
                            <small class="text-muted">{{ $retur['time'] }}</small>
                        </td>
                        <td>
                            <span class="fw-semibold text-dark d-block">{{ $retur['sku'] }}</span>
                        </td>
                        <td>
                            <span class="badge bg-danger-subtle text-danger rounded-pill px-3 py-2 fw-bold">{{ $retur['qty'] }} Pail</span>
                        </td>
                        <td>
                            <span class="badge bg-warning text-dark"><i class="bi bi-info-circle me-1"></i> {{ $retur['reason'] }}</span>
                        </td>
                        <td class="text-center pe-4">
                            <button class="btn btn-sm btn-primary rounded-pill px-3" onclick="processReturn('{{ $retur['id'] }}', '{{ $retur['sku'] }}', {{ $retur['qty'] }})"><i class="bi bi-box-seam me-1"></i> Proses Fisik</button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-check-circle fs-3 d-block mb-2 text-success"></i>
                            Tidak ada antrean laporan retur saat ini.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white border-top text-center py-3 rounded-bottom-4">
        <small class="text-muted">Menampilkan {{ count($pendingReturns) }} laporan retur yang menunggu pengecekan gudang.</small>
    </div>
</div>

@endsection

@push('scripts')
<script>
    function processReturn(retId, sku, qty) {
        Swal.fire({
            title: 'Verifikasi Fisik Retur',
            html: `
                <div class="text-start mt-3">
                    <p class="mb-3 text-muted">Berdasarkan hasil pengecekan fisik atas barang <strong>${sku}</strong> (${qty} Pail), alokasikan barang tersebut:</p>
                    
                    <form id="formRetur" action="/wms/inbound/returns/${retId}" method="POST">
                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Alokasi / Keputusan</label>
                            <div class="form-check border p-3 rounded-3 mb-2 bg-light">
                                <input class="form-check-input" type="radio" name="alokasi" id="aloGR" value="GR" checked>
                                <label class="form-check-label fw-semibold text-success ms-1" for="aloGR">
                                    <i class="bi bi-check-circle-fill me-1"></i> Masukkan ke Good Stock (GR)
                                </label>
                                <small class="text-muted d-block ms-4 mt-1">Lecet sangat minor. Kemasan masih layak dijual kembali ke pelanggan lain.</small>
                            </div>
                            <div class="form-check border p-3 rounded-3 mb-2 bg-light">
                                <input class="form-check-input" type="radio" name="alokasi" id="aloDDP" value="DDP">
                                <label class="form-check-label fw-semibold text-danger ms-1" for="aloDDP">
                                    <i class="bi bi-x-circle-fill me-1"></i> Masukkan ke Stok DDP (Rusak)
                                </label>
                                <small class="text-muted d-block ms-4 mt-1">Barang penyok parah, bocor, atau kemasan hancur. Tidak layak jual.</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Catatan Pengecekan</label>
                            <textarea class="form-control bg-light" rows="2" placeholder="Contoh: Kaleng sedikit penyok di bawah, isi aman." required></textarea>
                        </div>
                    </form>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-save me-1"></i> Konfirmasi Alokasi',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#1e3a8a',
            width: '600px',
            preConfirm: () => {
                document.getElementById('formRetur').submit();
                return false; // Prevent sweetalert from closing before form submission
            }
        });
    }
</script>
@endpush