@extends('layouts.wms')

@section('title', 'Sales Orders (Pesanan)')
@section('page_title', 'Daftar Pesanan Sales')

@section('content')
<div class="container-fluid p-0">
    <div class="row mb-4">
        <div class="col-md-5">
            <div class="btn-group w-100" role="group">
                <input type="radio" class="btn-check" name="btnradio" id="btnradio1" checked>
                <label class="btn btn-outline-primary" for="btnradio1">Menunggu Proses</label>

                <input type="radio" class="btn-check" name="btnradio" id="btnradio2">
                <label class="btn btn-outline-primary" for="btnradio2">Sedang Diproses</label>
                
                <input type="radio" class="btn-check" name="btnradio" id="btnradio3">
                <label class="btn btn-outline-primary" for="btnradio3">Selesai</label>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">No. PO</th>
                            <th>Tanggal</th>
                            <th>Customer / Toko</th>
                            <th>Total Item</th>
                            <th>Status Gudang</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="ps-4 fw-medium text-primary">#PO-00145</td>
                            <td>15 Aug 2026</td>
                            <td>Toko Makmur (Jkt)</td>
                            <td>12</td>
                            <td><span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i> Menunggu Verifikasi</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-success shadow-sm">Verifikasi (Checker)</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium text-primary">#PO-00146</td>
                            <td>15 Aug 2026</td>
                            <td>TB. Sentosa Bangunan</td>
                            <td>45</td>
                            <td><span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i> Stok Tidak Cukup</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-secondary">Detail Kekurangan</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium text-primary">#PO-00144</td>
                            <td>14 Aug 2026</td>
                            <td>CV. Jaya Warna</td>
                            <td>5</td>
                            <td><span class="badge bg-info"><i class="bi bi-box-seam me-1"></i> Sedang Diambil (Picking)</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary">Selesaikan Picking</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
