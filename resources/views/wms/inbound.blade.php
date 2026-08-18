@extends('layouts.wms')

@section('title', 'Inbound & Put-away')
@section('page_title', 'Inbound & Put-away')

@section('content')
<div class="container-fluid p-0">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0">Antrean Penerimaan Barang</h5>
        <button class="btn btn-primary-custom"><i class="bi bi-plus-lg me-2"></i>Terima Barang Baru</button>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">No. Penerimaan</th>
                            <th>Tanggal</th>
                            <th>Sumber</th>
                            <th>Jml Item</th>
                            <th>Status Put-away</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="ps-4 fw-medium">#INB-2608-001</td>
                            <td>15 Aug 2026</td>
                            <td>Pabrik Utama (Tangerang)</td>
                            <td>120 Pail</td>
                            <td><span class="badge bg-warning text-dark">Menunggu Lokasi</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary">Alokasikan Rak</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 fw-medium">#INB-2608-002</td>
                            <td>15 Aug 2026</td>
                            <td>Vendor Eksternal (Thinner)</td>
                            <td>50 Drum</td>
                            <td><span class="badge bg-success">Selesai (A1-B2)</span></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> Detail</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
