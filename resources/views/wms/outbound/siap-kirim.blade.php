@extends('layouts.wms')

@section('title', 'Siap Kirim')
@section('page_title', 'Sudah Dipicking, Belum Berangkat')

@section('content')
{{-- BARANGNYA SUDAH BERDIRI DI DERMAGA. Tiap baris di sini adalah pesanan
     yang stoknya sudah turun dari rak tetapi belum berangkat ke mana pun.
     Sebelum halaman ini ada, jumlahnya terlihat sebagai angka di layar Surat
     Jalan tanpa ada cara mengetahui pesanan MANA — dan mencocokkannya sendiri
     lewat Daftar Picking baru mungkin selama datanya sedikit. --}}

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="small text-muted">Sudah dipicking, belum berangkat</div>
                <div class="h3 fw-bold mb-0">{{ $stats['semua'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        {{-- Yang paling perlu ditindak: dokumennya belum terbit di BC, jadi
             tidak ada satu pun layar lain yang akan menyebutnya. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 {{ $stats['belum_sj'] > 0 ? 'bg-warning-subtle' : '' }}">
            <div class="card-body">
                <div class="small text-muted">Belum ada Surat Jalan</div>
                <div class="h3 fw-bold mb-0">{{ $stats['belum_sj'] }}</div>
                @if($stats['belum_sj'] > 0 && ! $filters['belum_sj'])
                    <a href="{{ route('wms.delivery.siap-kirim', ['belum_sj' => 1]) }}"
                       class="small text-decoration-none">Tampilkan hanya ini</a>
                @endif
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 h-100 {{ $stats['terlama_hari'] >= 2 ? 'bg-danger-subtle' : '' }}">
            <div class="card-body">
                <div class="small text-muted">Menunggu paling lama</div>
                <div class="h3 fw-bold mb-0">
                    {{ $stats['terlama_hari'] }} <span class="fs-6 fw-normal text-muted">hari</span>
                </div>
                <div class="small text-muted">Terhitung sejak operator menekan Siap Loading.</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-8 col-md-5">
                <label class="form-label small text-muted mb-1">Cari</label>
                <input type="text" name="search" value="{{ $filters['search'] }}"
                       class="form-control rounded-3" placeholder="No. PO, No. SO (BC), atau nama customer">
            </div>
            <div class="col-4 col-md-2 d-grid">
                <button class="btn btn-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
            @if($filters['belum_sj'])
                <input type="hidden" name="belum_sj" value="1">
                <div class="col-12">
                    <span class="badge bg-warning-subtle text-warning-emphasis">Hanya yang belum ada Surat Jalan</span>
                    <a href="{{ route('wms.delivery.siap-kirim') }}" class="small ms-2">Tampilkan semua</a>
                </div>
            @endif
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PO</th>
                        <th>No. SO (BC)</th>
                        <th>Customer</th>
                        <th class="text-center">Baris</th>
                        <th class="text-center">Unit</th>
                        <th>Selesai Dipicking</th>
                        <th>Surat Jalan</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($pesanan as $order)
                    @php
                        // Umur dihitung di sini, bukan di controller: ia hanya
                        // dipakai untuk mewarnai barisnya.
                        $hari = $order->picking_completed_at
                            ? (int) $order->picking_completed_at->startOfDay()->diffInDays(now()->startOfDay())
                            : 0;
                    @endphp
                    <tr class="{{ ! $order->punya_sj && $hari >= 2 ? 'table-warning' : '' }}">
                        <td class="font-monospace fw-semibold">{{ $order->order_number }}</td>
                        <td class="font-monospace">{{ $order->bc_so_number ?? '—' }}</td>
                        <td>
                            <div class="fw-semibold">{{ $order->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $order->customer?->code }}</small>
                        </td>
                        <td class="text-center">{{ $order->details_count }}</td>
                        <td class="text-center">{{ number_format((int) $order->unit_disetujui) }}</td>
                        <td>
                            {{ $order->picking_completed_at?->format('d M Y H:i') ?? '—' }}
                            @if($hari > 0)
                                <div class="small text-muted">menunggu {{ $hari }} hari</div>
                            @endif
                        </td>
                        <td>
                            @if($order->punya_sj)
                                {{-- Dokumennya sudah masuk; yang tersisa hanya
                                     menekan Berangkat di layar Surat Jalan. --}}
                                <a href="{{ route('wms.delivery.index', ['search' => $order->bc_so_number ?: $order->order_number]) }}"
                                   class="badge bg-info-subtle text-info-emphasis text-decoration-none">
                                    Sudah ada — tinggal diberangkatkan
                                </a>
                            @else
                                <span class="badge bg-warning-subtle text-warning-emphasis">Belum ada</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-truck display-6 d-block mb-2 opacity-50"></i>
                            Tidak ada pesanan yang menggantung — semua yang sudah dipicking sudah berangkat.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $pesanan->links() }}</div>
    </div>
</div>
@endsection
