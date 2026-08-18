@extends('layouts.wms')

@section('title', 'Surat Jalan & Pengiriman')
@section('page_title', 'Surat Jalan (Delivery Order)')

@section('content')
<div class="container-fluid p-0">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0">Antrean Pengiriman</h5>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">No. Surat Jalan</th>
                            <th>No. PO</th>
                            <th>Ekspedisi / Driver</th>
                            <th>Tanggal Kirim</th>
                            <th>Status Pengiriman</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="ps-4 fw-medium"><i class="bi bi-file-text text-primary me-2"></i>SJ-2608-0101</td>
                            <td><a href="/wms/orders" class="text-decoration-none">#PO-00143</a></td>
                            <td>Mobil Box (B 1234 CD) - Budi</td>
                            <td>15 Aug 2026</td>
                            <td><span class="badge bg-secondary"><i class="bi bi-truck me-1"></i> Sedang Dikirim</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="bi bi-camera me-1"></i> Upload Bukti</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium"><i class="bi bi-file-text text-primary me-2"></i>SJ-2608-0100</td>
                            <td><a href="/wms/orders" class="text-decoration-none">#PO-00142</a></td>
                            <td>Lalamove (B 9999 XX) - Anto</td>
                            <td>14 Aug 2026</td>
                            <td><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Terkirim (POD)</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i> Unduh SJ</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold">Upload Bukti Pengiriman (POD)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center p-4">
        <div class="border border-2 border-dashed rounded-3 p-5 mb-3" style="border-color: #dee2e6;">
            <i class="bi bi-cloud-arrow-up fs-1 text-muted mb-2"></i>
            <p class="mb-0 text-muted">Seret & lepas foto Surat Jalan yang telah ditandatangani di sini, atau <a href="#" class="text-primary text-decoration-none">pilih file</a></p>
        </div>
        <div class="form-floating mb-3 text-start">
            <input type="text" class="form-control" id="penerima" placeholder="Nama Penerima">
            <label for="penerima">Nama Penerima di Lokasi</label>
        </div>
      </div>
      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary-custom" data-bs-dismiss="modal">Simpan & Konfirmasi Selesai</button>
      </div>
    </div>
  </div>
</div>

@endsection
