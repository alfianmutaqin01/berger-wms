@extends('layouts.wms')

@section('title', 'Pengaturan Sistem')
@section('page_title', 'Pengaturan Sistem (Dokumen & Master)')

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
                        <th class="ps-4" style="width: 28%;">Jenis Dokumen</th>
                        <th style="width: 20%;">Format Prefix</th>
                        <th style="width: 12%;">Next Number</th>
                        <th style="width: 18%;">Preview (Contoh)</th>
                        <th style="width: 22%; padding-right: 1.5rem;">Reset Otomatis</th>
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
                            <input type="text" class="form-control" placeholder="PO-{YYYY}-{MM}-" value="PO-{YYYY}-{MM}-" onfocus="this.value=''">
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
                            <input type="text" class="form-control" placeholder="SJ/{YY}{MM}/" value="SJ/{YY}{MM}/" onfocus="this.value=''">
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
                            <input type="text" class="form-control" placeholder="IN-" value="IN-" onfocus="this.value=''">
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
                            <input type="text" class="form-control" placeholder="TRF-{YYYY}-" value="TRF-{YYYY}-" onfocus="this.value=''">
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

<div class="card shadow-sm border-0 rounded-4 mt-4">
    <div class="card-header bg-white border-bottom pt-4 px-4 pb-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">Manajemen Kemasan (Unit of Measure)</h6>
        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="Swal.fire('Fitur Segera Hadir', 'Menambahkan tipe kemasan baru akan disimulasikan di versi berikutnya.', 'info')">
            <i class="bi bi-plus-circle me-1"></i> Tambah Kemasan
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width: 20%;">Kode (Singkatan)</th>
                        <th>Nama Kemasan</th>
                        <th class="text-center">Kapasitas (Opsional)</th>
                        <th class="text-end pe-4" style="width: 20%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ps-4 fw-bold text-dark">PAIL</td>
                        <td>Ember Besar (Pail)</td>
                        <td class="text-center">20 - 25 Ltr</td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light text-secondary me-1"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-dark">TIN</td>
                        <td>Kaleng Sedang (Tin)</td>
                        <td class="text-center">2.5 - 5 Ltr</td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light text-secondary me-1"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-dark">CAN</td>
                        <td>Kaleng Kecil (Can)</td>
                        <td class="text-center">1 Ltr</td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light text-secondary me-1"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-4 fw-bold text-dark">DRUM</td>
                        <td>Drum Besi</td>
                        <td class="text-center">200 Ltr</td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-light text-secondary me-1"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection