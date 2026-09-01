@extends('layouts.wms')

@section('title', 'Semua Notifikasi')
@section('page_title', 'Notifikasi Sistem')

@section('content')
<div class="row mb-4">
    <div class="col-12 col-md-8">
        <h4 class="fw-bold text-dark mb-0">Riwayat Notifikasi</h4>
        <p class="text-muted">Lihat semua pemberitahuan dan aktivitas terbaru dalam sistem WMS.</p>
    </div>
    <div class="col-12 col-md-4 text-md-end">
        <button class="btn btn-outline-primary fw-bold rounded-pill px-4 shadow-sm" onclick="Swal.fire('Berhasil', 'Semua notifikasi telah ditandai sebagai sudah dibaca.', 'success')">
            <i class="bi bi-check2-all me-1"></i> Tandai Semua Dibaca
        </button>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="list-group list-group-flush rounded-4">
            
            <!-- Notifikasi 1 (Unread) -->
            <a href="#" class="list-group-item list-group-item-action p-4 border-bottom bg-light border-start border-4 border-primary" style="transition: all 0.2s;">
                <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-bell-fill text-primary fs-5"></i>
                        <h6 class="mb-0 fw-bold text-primary">Pesanan Baru Membutuhkan Approval</h6>
                    </div>
                    <small class="text-muted fw-semibold"><i class="bi bi-clock me-1"></i> 21 Ags 2026, 09:30 WIB</small>
                </div>
                <p class="mb-1 text-dark ms-4 ps-2">Sales <strong>Budi Santoso</strong> telah mengajukan Pesanan Baru (PO-00145) untuk Toko Cat Makmur Jaya sejumlah 120 Pail. Pesanan ini sedang Menunggu Diterima Anda untuk diteruskan ke tahap Picking.</p>
                <div class="mt-3 ms-4 ps-2">
                    <span class="badge bg-primary px-3 py-2 rounded-pill">Status: Menunggu Diterima</span>
                </div>
            </a>

            <!-- Notifikasi 2 (Read) -->
            <a href="#" class="list-group-item list-group-item-action p-4 border-bottom" style="transition: all 0.2s;">
                <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-check-circle-fill text-success fs-5"></i>
                        <h6 class="mb-0 fw-bold text-dark">Proses Retur Selesai (Good Stock)</h6>
                    </div>
                    <small class="text-muted"><i class="bi bi-clock me-1"></i> 20 Ags 2026, 14:15 WIB</small>
                </div>
                <p class="mb-1 text-muted ms-4 ps-2">Proses validasi Inbound Retur untuk dokumen <strong>RTN-0081</strong> telah selesai dilakukan oleh tim Gudang. 50 Pail barang telah berhasil dikembalikan ke status Good Stock di rak penyimpanan A-01-02.</p>
                <div class="mt-3 ms-4 ps-2">
                    <span class="badge bg-secondary px-3 py-2 rounded-pill"><i class="bi bi-check me-1"></i> Selesai</span>
                </div>
            </a>

            <!-- Notifikasi 3 (Read) -->
            <a href="#" class="list-group-item list-group-item-action p-4 border-bottom" style="transition: all 0.2s;">
                <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-truck text-info fs-5"></i>
                        <h6 class="mb-0 fw-bold text-dark">Surat Jalan DO-00219 Diterbitkan</h6>
                    </div>
                    <small class="text-muted"><i class="bi bi-clock me-1"></i> 19 Ags 2026, 11:45 WIB</small>
                </div>
                <p class="mb-1 text-muted ms-4 ps-2">Surat Jalan DO-00219 untuk pelanggan <strong>CV. Bintang Abadi</strong> telah berhasil di-generate. Dokumen siap dicetak dan diserahkan kepada driver pengiriman.</p>
            </a>
            
            <!-- Notifikasi 4 (Read - Alert) -->
            <a href="#" class="list-group-item list-group-item-action p-4 border-bottom" style="transition: all 0.2s;">
                <div class="d-flex w-100 justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-triangle-fill text-warning fs-5"></i>
                        <h6 class="mb-0 fw-bold text-dark">Stok Minimum Tercapai</h6>
                    </div>
                    <small class="text-muted"><i class="bi bi-clock me-1"></i> 18 Ags 2026, 08:10 WIB</small>
                </div>
                <p class="mb-1 text-muted ms-4 ps-2">Peringatan: Stok untuk SKU <strong>BPI-1002 (Berger Weathercoat 20L)</strong> saat ini menyentuh batas minimum (Tersisa: 15 Pail). Harap segera koordinasikan dengan bagian produksi.</p>
                <div class="mt-3 ms-4 ps-2">
                    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill">Peringatan Stok</span>
                </div>
            </a>
            
        </div>
        
        <!-- Pagination Dummy -->
        <div class="d-flex justify-content-center p-4 bg-white border-top" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item disabled"><a class="page-link" href="#">Sebelumnya</a></li>
                    <li class="page-item active"><a class="page-link" href="#">1</a></li>
                    <li class="page-item"><a class="page-link" href="#">2</a></li>
                    <li class="page-item"><a class="page-link" href="#">3</a></li>
                    <li class="page-item"><a class="page-link" href="#">Selanjutnya</a></li>
                </ul>
            </nav>
        </div>
        
    </div>
</div>
@endsection