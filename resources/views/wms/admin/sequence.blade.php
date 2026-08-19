@extends('layouts.wms')

@section('title', 'Manajemen Penomoran')
@section('page_title', 'Pengaturan Dokumen')

@section('content')
<div class="row mb-4">
    <div class="col-12 col-md-8">
        <h4 class="fw-bold text-dark mb-0">Manajemen Penomoran Otomatis</h4>
        <p class="text-muted">Atur format *prefix* (awalan) dan nomor urut selanjutnya untuk setiap dokumen sistem.</p>
    </div>
    <div class="col-12 col-md-4 text-md-end">
        <button class="btn btn-primary fw-semibold shadow-sm rounded-pill px-4">
            <i class="bi bi-save me-2"></i>Simpan Perubahan
        </button>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm rounded-4 mb-4" role="alert">
    <h6 class="fw-bold mb-2"><i class="bi bi-info-circle-fill me-2"></i>Panduan Variabel Format</h6>
    <p class="mb-0 small">Gunakan variabel berikut di dalam kolom Format Prefix agar nomor ter-generate otomatis sesuai waktu nyata:</p>
    <ul class="mb-0 mt-2 small">
        <li><code>{YYYY}</code> = Tahun 4 digit (contoh: 2026)</li>
        <li><code>{YY}</code> = Tahun 2 digit (contoh: 26)</li>
        <li><code>{MM}</code> = Bulan 2 digit (contoh: 08)</li>
        <li><code>{DD}</code> = Tanggal 2 digit (contoh: 19)</li>
    </ul>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom pt-4 px-4 pb-3">
        <h6 class="fw-bold mb-0 text-dark">Daftar Urutan Dokumen (Sequence)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Jenis Dokumen</th>
                        <th style="width: 25%;">Format Prefix</th>
                        <th style="width: 15%;">Next Number</th>
                        <th style="width: 15%;">Preview (Contoh)</th>
                        <th>Reset Otomatis</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Purchase Order -->
                    <tr>
                        <td class="ps-4">
                            <h6 class="mb-0 fw-bold text-dark">Purchase Order (PO)</h6>
                            <small class="text-muted">Digunakan oleh Sales Portal</small>
                        </td>
                        <td>
                            <input type="text" class="form-control" value="PO-{YYYY}-{MM}-">
                        </td>
                        <td>
                            <input type="number" class="form-control fw-bold text-primary" value="146">
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace px-3 py-2">PO-2026-08-00146</span>
                        </td>
                        <td>
                            <select class="form-select">
                                <option>Setiap Awal Bulan</option>
                                <option>Setiap Awal Tahun</option>
                                <option>Jangan Pernah Reset</option>
                            </select>
                        </td>
                    </tr>
                    
                    <!-- Surat Jalan -->
                    <tr>
                        <td class="ps-4">
                            <h6 class="mb-0 fw-bold text-dark">Surat Jalan (SJ)</h6>
                            <small class="text-muted">Digunakan saat Outbound Delivery</small>
                        </td>
                        <td>
                            <input type="text" class="form-control" value="SJ/{YY}{MM}/">
                        </td>
                        <td>
                            <input type="number" class="form-control fw-bold text-primary" value="89">
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace px-3 py-2">SJ/2608/00089</span>
                        </td>
                        <td>
                            <select class="form-select">
                                <option>Setiap Awal Bulan</option>
                                <option selected>Setiap Awal Tahun</option>
                                <option>Jangan Pernah Reset</option>
                            </select>
                        </td>
                    </tr>

                    <!-- Inbound Receipt -->
                    <tr>
                        <td class="ps-4">
                            <h6 class="mb-0 fw-bold text-dark">Penerimaan Barang (IN)</h6>
                            <small class="text-muted">Digunakan saat barang masuk dari Pabrik</small>
                        </td>
                        <td>
                            <input type="text" class="form-control" value="IN-">
                        </td>
                        <td>
                            <input type="number" class="form-control fw-bold text-primary" value="1025">
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace px-3 py-2">IN-01025</span>
                        </td>
                        <td>
                            <select class="form-select">
                                <option>Setiap Awal Bulan</option>
                                <option>Setiap Awal Tahun</option>
                                <option selected>Jangan Pernah Reset</option>
                            </select>
                        </td>
                    </tr>

                    <!-- Transfer Order -->
                    <tr>
                        <td class="ps-4">
                            <h6 class="mb-0 fw-bold text-dark">Transfer Antar Gudang (TR)</h6>
                            <small class="text-muted">Pergerakan stok antar lokasi</small>
                        </td>
                        <td>
                            <input type="text" class="form-control" value="TRF-{YYYY}-">
                        </td>
                        <td>
                            <input type="number" class="form-control fw-bold text-primary" value="12">
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border font-monospace px-3 py-2">TRF-2026-00012</span>
                        </td>
                        <td>
                            <select class="form-select">
                                <option>Setiap Awal Bulan</option>
                                <option selected>Setiap Awal Tahun</option>
                                <option>Jangan Pernah Reset</option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection