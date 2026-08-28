@extends('layouts.soms')

@section('title', 'Dashboard Sales')
@section('page_title', 'Overview Kinerja Sales')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold text-dark mb-0">Performa Pribadi Anda</h4>
        <p class="text-muted">Pantau target penjualan bulanan dan status pesanan kustomer Anda secara real-time.</p>
    </div>
</div>

<!-- Statistic Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0 bg-primary text-white">
            <div class="card-body">
                <h6 class="fw-normal mb-2 opacity-75">Pesanan Dibuat (Bulan Ini)</h6>
                <h3 class="mb-0 fw-bold">45 <span class="fs-6 fw-normal">PO</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Menunggu Diterima</h6>
                <h3 class="mb-0 fw-bold text-warning">8 <span class="fs-6 fw-normal">PO</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0 border-start border-info border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Sedang Dikirim</h6>
                <h3 class="mb-0 fw-bold text-info">12 <span class="fs-6 fw-normal">PO</span></h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100 shadow-sm border-0 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Sukses Terkirim</h6>
                <h3 class="mb-0 fw-bold text-success">25 <span class="fs-6 fw-normal">PO</span></h3>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Chart Area -->
    <div class="col-12 col-xl-8">
        <div class="card shadow-sm border-0 h-100 rounded-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center pt-4 px-4 pb-0">
                <h6 class="fw-bold mb-0">Target vs Realisasi (Qty Item Terjual)</h6>
                <select class="form-select form-select-sm w-auto bg-light border-0 rounded-pill">
                    <option>Bulan Ini</option>
                    <option>Kuartal Ini</option>
                </select>
            </div>
            <div class="card-body px-4" style="min-height: 300px;">
                <canvas id="mySalesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Need Action Table -->
    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0 h-100 rounded-4 border-top border-warning border-4">
            <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-exclamation-circle text-warning me-2"></i>Butuh Tindakan Anda</h6>
            </div>
            <div class="card-body p-4 mt-2">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold">CV Bangun Jaya</h6>
                            <small class="text-muted">Supir sudah tiba (Arrived)</small>
                        </div>
                        <button onclick="simulateUploadBukti(this, 'CV Bangun Jaya')" class="btn btn-sm btn-outline-primary rounded-pill px-3">Upload Bukti</button>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 fw-bold">Toko Merah</h6>
                            {{-- PRD v1.1 §6.6: piutang bersifat INFORMATIF. Pesanan
                                 tetap boleh dibuat; keputusan meneruskan ada di tangan
                                 Tim Logistik saat approval, bukan diblokir sistem. --}}
                            <small class="text-warning">⚠ Menunggak — pesanan tetap dapat dibuat</small>
                        </div>
                        <button onclick="simulateUploadBukti(this, 'Toko Merah')" class="btn btn-sm btn-outline-primary rounded-pill px-3">Upload Bukti</button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('mySalesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4'],
                datasets: [
                    {
                        label: 'Realisasi (Qty)',
                        data: [500, 800, 600, 1200],
                        borderColor: '#1B4F8A',
                        backgroundColor: 'rgba(27, 79, 138, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Target (Qty)',
                        data: [700, 700, 700, 700],
                        borderColor: '#E8871E',
                        borderDash: [5, 5],
                        borderWidth: 2,
                        fill: false,
                        tension: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    });
    function simulateUploadBukti(btnElement, customerName) {
        Swal.fire({
            title: 'Upload Bukti Pengiriman',
            html: `
                <div class="text-start mt-2">
                    <p class="mb-3 text-muted">Unggah foto bukti tanda terima atau surat jalan yang sudah ditandatangani oleh <strong>${customerName}</strong>.</p>
                    <div class="border border-2 border-dashed rounded-3 p-4 text-center bg-light" style="cursor: pointer;" onclick="document.getElementById('fileInputMock').click()">
                        <i class="bi bi-cloud-arrow-up display-6 text-muted mb-2"></i>
                        <p class="mb-0 text-muted fw-semibold">Pilih atau Seret Foto ke Sini</p>
                        <small class="text-secondary">Max 5MB (JPG, PNG, PDF)</small>
                        <input type="file" id="fileInputMock" class="d-none" accept="image/png, image/jpeg, application/pdf" onchange="document.getElementById('fileNameDisplay').textContent = this.files[0]?.name || ''">
                    </div>
                    <p id="fileNameDisplay" class="text-primary mt-2 text-center fw-semibold small mb-0"></p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-upload"></i> Unggah Sekarang',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#0d6efd',
            preConfirm: () => {
                const file = document.getElementById('fileInputMock').files[0];
                if (!file) {
                    Swal.showValidationMessage('Anda belum memilih file!');
                    return false;
                }
                return true;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Show loading
                Swal.fire({
                    title: 'Mengunggah...',
                    html: 'Mohon tunggu sebentar',
                    timerProgressBar: true,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                // Simulate upload delay
                setTimeout(() => {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: 'Bukti pengiriman berhasil diunggah. Status pesanan akan segera di-update menjadi Selesai.',
                        confirmButtonColor: '#198754'
                    });
                    
                    // Ganti tombol menjadi Selesai
                    btnElement.className = 'btn btn-sm btn-success rounded-pill px-3';
                    btnElement.innerHTML = '<i class="bi bi-check-circle me-1"></i>Selesai';
                    btnElement.disabled = true;
                    btnElement.onclick = null;
                }, 1500);
            }
        });
    }
</script>
@endpush