@extends('layouts.wms')
@section('title', 'Billing & Piutang')
@section('page_title', 'Manajemen Penagihan & Piutang')

@push('styles')
<style>
    .stat-card { transition: transform 0.2s ease, box-shadow 0.2s ease; border: none !important; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05) !important; }
    .bg-gradient-danger { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
    .table-hover tbody tr:hover { background-color: #f8fafc; }
    .badge-soft-danger { background-color: #fee2e2; color: #991b1b; }
    .badge-soft-warning { background-color: #fef3c7; color: #92400e; }
    .badge-soft-success { background-color: #d1fae5; color: #065f46; }
</style>
@endpush

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted">Pantau status piutang, kelola termin pembayaran, dan catat pelunasan faktur secara terpusat tanpa melihat rincian finansial.</p>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center" role="alert">
    <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i>
    <div>
        <h6 class="fw-bold mb-0 text-success">Pelunasan Berhasil</h6>
        <span class="small">{{ session('success') }}</span>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

<!-- Premium Stat Cards -->
<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="card stat-card rounded-4 shadow-sm bg-gradient-danger text-white h-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-3 opacity-25">
                <i class="bi bi-exclamation-octagon-fill" style="font-size: 5rem; margin-top: -1rem; margin-right: -1rem;"></i>
            </div>
            <div class="card-body p-4 position-relative z-1">
                <h6 class="fw-semibold opacity-75 mb-3 text-uppercase tracking-wider" style="font-size: 0.8rem;">Piutang Macet (Overdue)</h6>
                <h2 class="fw-bold mb-2">2 Faktur</h2>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-white text-danger rounded-pill px-2 py-1"><i class="bi bi-box"></i> 265 Qty</span>
                    <small class="opacity-75">Terkait 2 Kustomer</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card rounded-4 shadow-sm bg-gradient-warning text-white h-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-3 opacity-25">
                <i class="bi bi-hourglass-split" style="font-size: 5rem; margin-top: -1rem; margin-right: -1rem;"></i>
            </div>
            <div class="card-body p-4 position-relative z-1">
                <h6 class="fw-semibold opacity-75 mb-3 text-uppercase tracking-wider" style="font-size: 0.8rem;">Menunggu Pembayaran (Outstanding)</h6>
                <h2 class="fw-bold mb-2">32 Faktur</h2>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-white text-warning rounded-pill px-2 py-1"><i class="bi bi-box"></i> 5,120 Qty</span>
                    <small class="opacity-75">Dokumen Sedang Berjalan</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card rounded-4 shadow-sm bg-gradient-success text-white h-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-3 opacity-25">
                <i class="bi bi-check-all" style="font-size: 5rem; margin-top: -1rem; margin-right: -1rem;"></i>
            </div>
            <div class="card-body p-4 position-relative z-1">
                <h6 class="fw-semibold opacity-75 mb-3 text-uppercase tracking-wider" style="font-size: 0.8rem;">Lunas (Bulan Ini)</h6>
                <h2 class="fw-bold mb-2">128 Faktur</h2>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-white text-success rounded-pill px-2 py-1"><i class="bi bi-box"></i> 15,200 Qty</span>
                    <small class="opacity-75">Kinerja Penagihan Sangat Baik</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Table -->
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
        <div>
            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Buku Besar Piutang (Berdasarkan Surat Jalan)</h6>
            <small class="text-muted">Daftar pesanan yang telah diserahkan dan menunggu penyelesaian bukti bayar.</small>
        </div>
        <div class="d-flex gap-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control bg-light border-0" placeholder="Cari Faktur / PO...">
            </div>
            <select class="form-select form-select-sm bg-light border-0 w-auto text-muted">
                <option value="">Semua Status</option>
                <option value="overdue">Overdue</option>
                <option value="outstanding">Outstanding</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.9rem;">
                <thead class="table-light text-muted text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <tr>
                        <th class="text-start ps-4 py-3">No. Faktur / PO</th>
                        <th class="text-start py-3">Customer</th>
                        <th class="py-3">Tgl. Kirim (SJ)</th>
                        <th class="py-3">Termin (Tempo)</th>
                        <th class="text-end py-3">Total Qty (Billed)</th>
                        <th class="py-3">Status</th>
                        <th class="text-end pe-4 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Item 1: Overdue -->
                    <tr>
                        <td class="text-start ps-4">
                            <span class="fw-bold text-dark d-block mb-1">INV-2606-088</span>
                            <small class="text-muted font-monospace">PO-2606-088</small>
                        </td>
                        <td class="text-start">
                            <span class="fw-semibold text-dark d-block mb-1">Toko Merah</span>
                            <small class="text-muted">Surabaya, Jawa Timur</small>
                        </td>
                        <td>15 Jun 2026</td>
                        <td>
                            <span class="d-block mb-1">30 Hari</span>
                            <span class="text-danger fw-bold small"><i class="bi bi-exclamation-circle-fill me-1"></i>15 Jul 2026</span>
                        </td>
                        <td class="text-end fw-bold font-monospace">150 Pcs</td>
                        <td><span class="badge badge-soft-danger px-3 py-2 rounded-pill">Overdue (36 Hari)</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm text-white rounded-pill px-3 shadow-sm" style="background-color: #1e3a8a;" data-bs-toggle="modal" data-bs-target="#confirmModal" data-po="PO-2606-088" data-cust="Toko Merah" data-qty="150 Pcs">
                                <i class="bi bi-check-circle me-1"></i> Lunas
                            </button>
                        </td>
                    </tr>
                    
                    <!-- Item 2: Outstanding (Dari simulasi Verifikasi kita) -->
                    <tr>
                        <td class="text-start ps-4">
                            <span class="fw-bold text-dark d-block mb-1">INV-2608-011</span>
                            <small class="text-muted font-monospace">PO-1508-011</small>
                        </td>
                        <td class="text-start">
                            <span class="fw-semibold text-dark d-block mb-1">PT Sentosa Abadi</span>
                            <small class="text-muted">Jakarta Selatan</small>
                        </td>
                        <td>18 Ags 2026</td>
                        <td>
                            <span class="d-block mb-1">60 Hari</span>
                            <span class="text-muted small">17 Okt 2026</span>
                        </td>
                        <td class="text-end fw-bold font-monospace">150 Pcs</td>
                        <td><span class="badge badge-soft-warning px-3 py-2 rounded-pill text-dark">Menunggu Pembayaran</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm text-white rounded-pill px-3 shadow-sm" style="background-color: #1e3a8a;" data-bs-toggle="modal" data-bs-target="#confirmModal" data-po="PO-1508-011" data-cust="PT Sentosa Abadi" data-qty="150 Pcs">
                                <i class="bi bi-check-circle me-1"></i> Lunas
                            </button>
                        </td>
                    </tr>

                    <!-- Item 3: Lunas -->
                    <tr>
                        <td class="text-start ps-4">
                            <span class="fw-bold text-dark d-block mb-1">INV-2608-055</span>
                            <small class="text-muted font-monospace">PO-1008-055</small>
                        </td>
                        <td class="text-start">
                            <span class="fw-semibold text-dark d-block mb-1">Toko Maju Terus</span>
                            <small class="text-muted">Bandung, Jawa Barat</small>
                        </td>
                        <td>11 Ags 2026</td>
                        <td>
                            <span class="d-block mb-1">Cash (COD)</span>
                            <span class="text-muted small">-</span>
                        </td>
                        <td class="text-end fw-bold font-monospace text-muted text-decoration-line-through">120 Pcs</td>
                        <td><span class="badge badge-soft-success px-3 py-2 rounded-pill">Lunas (11 Ags)</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light text-muted rounded-pill px-3 shadow-none" disabled>
                                <i class="bi bi-check-all me-1"></i> Selesai
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection

@push('modals')
<!-- Modal Konfirmasi Pelunasan (F-BILL-02) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-check-circle text-success me-2"></i> Konfirmasi Bukti Bayar (Faktur)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="/wms/billing/confirm/PO-MOCK" method="POST" id="billingForm">
                @csrf
                <div class="modal-body p-4">
                    <div class="alert bg-light border text-dark mb-4 rounded-3">
                        <div class="row text-center">
                            <div class="col-6 border-end">
                                <small class="text-muted d-block mb-1">Customer</small>
                                <strong id="modalCust" class="text-primary">Nama Customer</strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block mb-1">Volume Billed</small>
                                <strong id="modalQty" class="font-monospace text-dark">0 Qty</strong>
                            </div>
                        </div>
                    </div>

                    {{-- PRD v1.1 §6.6: tidak ada blokir order akibat piutang, jadi
                         tidak ada blokir yang perlu "dicabut" — pelunasan hanya
                         menghapus penanda ⚠ Menunggak pada customer. --}}
                    <p class="text-muted small mb-3">Tindakan ini akan memverifikasi bahwa bukti pembayaran fisik/digital telah sah dan <strong>menghapus penandaan ⚠ Menunggak</strong> pada kustomer bersangkutan.</p>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Tanggal Bukti Bayar Diterima <span class="text-danger">*</span></label>
                        <input type="date" class="form-control bg-light" name="payment_date" required value="{{ date('Y-m-d') }}">
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-dark">Metode Pelunasan Terlampir <span class="text-danger">*</span></label>
                        <select class="form-select bg-light" required>
                            <option value="transfer">Bukti Transfer (Bank Draft)</option>
                            <option value="giro">Bukti Giro / Cek Fisik</option>
                            <option value="tunai">Tanda Terima Tunai (Cash)</option>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4 px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success px-4 rounded-pill fw-bold shadow-sm"><i class="bi bi-shield-check me-1"></i> Verifikasi Bukti Lunas</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const confirmModal = document.getElementById('confirmModal');
        confirmModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const po = button.getAttribute('data-po');
            const cust = button.getAttribute('data-cust');
            const qty = button.getAttribute('data-qty');
            
            document.getElementById('modalCust').textContent = cust;
            document.getElementById('modalQty').textContent = qty;
            document.getElementById('billingForm').action = '/wms/billing/confirm/' + po;
        });

        document.getElementById('billingForm').addEventListener('submit', function(e) {
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
            btn.disabled = true;
        });
    });
</script>
@endpush