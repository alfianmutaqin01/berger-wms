@extends('layouts.wms')
@section('title', 'Penolakan Customer')

{{--
    PENOLAKAN CUSTOMER — antrean gudang atas barang yang balik.

    Dulu bernama "Penerimaan Retur" dan isinya array kosong. Namanya diganti
    karena "retur" tidak menyebut peristiwanya: yang terjadi adalah customer
    MENOLAK barang di depan tokonya, dan itu yang perlu dikenali orang gudang
    saat membaca daftar ini.

    Satu daftar dibaca tiga peran. Logistik menyetujui klaim lalu
    memverifikasi barangnya; Operator menaikkan ke rak di antara keduanya.
    Yang dipisah adalah tombolnya, bukan daftarnya — Operator perlu tahu ada
    barang menunggu tanpa harus ditelepon.
--}}

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h4 class="fw-bold text-dark mb-0"><i class="bi bi-arrow-return-left me-2 text-danger"></i>Penolakan Customer</h4>
        <p class="text-muted mb-0">Barang yang ditolak saat pengiriman dan harus kembali ke rak.</p>
    </div>
</div>

{{-- Tiga angka, tiga antrean, dan tiap kartu menyaring daftarnya. --}}
<div class="row g-2 g-md-3 mb-3">
    <div class="col-4">
        <a href="{{ route('wms.returns.index', ['status' => \App\Models\SalesReturn::STATUS_REPORTED]) }}"
           class="text-decoration-none">
            <div class="card h-100 border-0 shadow-sm rounded-4 border-start border-warning border-4">
                <div class="card-body p-2 p-md-3">
                    <h6 class="text-muted fw-normal mb-1 small">Menunggu Persetujuan</h6>
                    <h4 class="fw-bold mb-0 text-warning">{{ $stats['persetujuan'] }}</h4>
                </div>
            </div>
        </a>
    </div>
    <div class="col-4">
        <a href="{{ route('wms.returns.index', ['status' => \App\Models\SalesReturn::STATUS_PUTAWAY_PENDING]) }}"
           class="text-decoration-none">
            <div class="card h-100 border-0 shadow-sm rounded-4 border-start border-primary border-4">
                <div class="card-body p-2 p-md-3">
                    <h6 class="text-muted fw-normal mb-1 small">Menunggu Naik Rak</h6>
                    <h4 class="fw-bold mb-0 text-primary">{{ $stats['putaway'] }}</h4>
                </div>
            </div>
        </a>
    </div>
    <div class="col-4">
        <a href="{{ route('wms.returns.index', ['status' => \App\Models\SalesReturn::STATUS_VERIFICATION_PENDING]) }}"
           class="text-decoration-none">
            <div class="card h-100 border-0 shadow-sm rounded-4 border-start border-info border-4">
                <div class="card-body p-2 p-md-3">
                    <h6 class="text-muted fw-normal mb-1 small">Menunggu Verifikasi</h6>
                    <h4 class="fw-bold mb-0 text-info">{{ $stats['verifikasi'] }}</h4>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-5">
                <input type="search" name="search" value="{{ $filters['search'] }}"
                       class="form-control" placeholder="Cari nomor laporan, SO, atau customer...">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select">
                    <option value="">Semua keadaan</option>
                    @foreach($statuses as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($filters['status'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($warehouses->count() > 1)
                <div class="col-6 col-md-2">
                    <select name="warehouse" class="form-select">
                        <option value="">Semua gudang</option>
                        @foreach($warehouses as $gudang)
                            <option value="{{ $gudang->id }}" @selected((int) $filters['warehouse'] === $gudang->id)>
                                {{ $gudang->code }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-12 col-md-2">
                <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-muted small">
                    <tr>
                        <th>NO. LAPORAN</th>
                        <th>PESANAN / CUSTOMER</th>
                        <th class="text-center">ITEM</th>
                        <th>DILAPORKAN</th>
                        <th>KEADAAN</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($baris as $retur)
                        <tr>
                            <td class="fw-bold">{{ $retur->reference }}</td>
                            <td>
                                <div class="fw-semibold">{{ $retur->customer?->name }}</div>
                                <small class="text-muted">
                                    {{ $retur->salesOrder?->order_number }}
                                    @if($retur->salesOrder?->bc_so_number)
                                        &middot; SO {{ $retur->salesOrder->bc_so_number }}
                                    @endif
                                </small>
                            </td>
                            <td class="text-center">{{ $retur->details_count }}</td>
                            <td>
                                <div>{{ $retur->reported_at?->format('d M Y') }}</div>
                                <small class="text-muted">{{ $retur->reportedBy?->full_name ?? 'Sistem' }}</small>
                            </td>
                            <td>
                                <span class="badge bg-{{ $retur->status_color }}-subtle text-{{ $retur->status_color }}-emphasis rounded-pill">
                                    {{ $retur->status_label }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('wms.returns.show', $retur) }}"
                                   class="btn btn-sm btn-outline-primary rounded-pill px-3">Buka</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                Belum ada penolakan yang dilaporkan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $baris->links() }}</div>
    </div>
</div>
@endsection
