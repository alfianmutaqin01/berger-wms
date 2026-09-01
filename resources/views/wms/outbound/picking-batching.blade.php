@extends('layouts.wms')
@section('title', 'Pembuatan Daftar Picking')
@section('page_title', 'Pembuatan Daftar Picking (Batching)')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Kelola dan gabungkan pesanan (PO) yang sudah disetujui menjadi satu Daftar Picking (Batch) agar pengambilan barang oleh operator lebih efisien.</p>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-ui-checks text-primary me-2"></i>Pesanan Menunggu Picking</h6>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary px-3 shadow-sm" id="btnBuatBatch"><i class="bi bi-collection me-1"></i> Buat Daftar Picking</button>
        </div>
    </div>
    <div class="card-body p-4">
        
        <div class="alert alert-info d-flex align-items-center rounded-3 p-3 mb-4">
            <i class="bi bi-info-circle-fill fs-4 me-3 text-info"></i>
            <div>
                <strong>Tips Batch Picking</strong><br>
                <span class="small">Pilih beberapa pesanan sekaligus dan klik "Buat Daftar Picking" di atas tabel untuk menggabungkannya menjadi 1 tugas pengambilan bagi operator.</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light text-muted small">
                    <tr>
                        <th class="text-center" style="width: 50px;">
                            <input class="form-check-input" type="checkbox" id="checkAll">
                        </th>
                        <th>NO. PO</th>
                        <th>CUSTOMER</th>
                        <th>DISPATCH CODE</th>
                        <th>TOTAL ITEM / QTY</th>
                        <th>WAKTU APPROVAL</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-center"><input class="form-check-input po-checkbox" type="checkbox" value="PO-2608-001"></td>
                        <td><span class="fw-semibold text-primary">PO-2608-001</span></td>
                        <td>CV Bangun Jaya</td>
                        <td><span class="badge bg-secondary">WH-01</span></td>
                        <td>2 Item (120 Qty)</td>
                        <td>Hari ini, 14:05</td>
                    </tr>
                    <tr>
                        <td class="text-center"><input class="form-check-input po-checkbox" type="checkbox" value="PO-2608-002"></td>
                        <td><span class="fw-semibold text-primary">PO-2608-002</span></td>
                        <td>Toko Merah</td>
                        <td><span class="badge bg-secondary">WH-01</span></td>
                        <td>1 Item (50 Qty)</td>
                        <td>Hari ini, 14:20</td>
                    </tr>
                    <tr>
                        <td class="text-center"><input class="form-check-input po-checkbox" type="checkbox" value="PO-2608-005"></td>
                        <td><span class="fw-semibold text-primary">PO-2608-005</span></td>
                        <td>PT Mitra Bangunan</td>
                        <td><span class="badge bg-secondary">WH-02</span></td>
                        <td>1 Item (50 Qty)</td>
                        <td>Kemarin, 16:30</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkAll = document.getElementById('checkAll');
        const checkboxes = document.querySelectorAll('.po-checkbox');
        const btnBuatBatch = document.getElementById('btnBuatBatch');

        checkAll.addEventListener('change', function() {
            checkboxes.forEach(cb => {
                cb.checked = checkAll.checked;
            });
        });

        btnBuatBatch.addEventListener('click', function() {
            const selected = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
            
            if(selected.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Tidak Ada Pesanan Terpilih',
                    text: 'Silakan centang minimal satu pesanan (PO) untuk dibuatkan daftar picking.',
                    confirmButtonColor: '#6c757d'
                });
                return;
            }

            Swal.fire({
                title: 'Konfirmasi Batch Picking',
                html: 'Anda akan menggabungkan <b>' + selected.length + '</b> pesanan menjadi 1 Daftar Picking untuk Operator.<br><br><small class="text-muted">' + selected.join(', ') + '</small>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Buat Daftar',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Memproses...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                    
                    // Simulasi API call / redirect
                    setTimeout(() => {
                        window.location.href = '/wms/outbound/picking?new_batch=true';
                    }, 1500);
                }
            });
        });
    });
</script>
@endpush