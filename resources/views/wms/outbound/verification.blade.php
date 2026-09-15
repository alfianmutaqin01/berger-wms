@extends('layouts.wms')

@section('title', 'Verifikasi Bukti SJ')
@section('page_title', 'Verifikasi Bukti Surat Jalan')

@section('content')
{{-- TIGA TAB, SATU STATUS PESANAN.

     Pemilik produk memilih TIDAK menambah status baru antara "sampai tujuan"
     dan "menunggu verifikasi bukti", supaya tampilan di HP Sales tetap satu
     label. Konsekuensinya di sini: pesanan yang fotonya belum ada dan pesanan
     yang fotonya sudah menunggu berstatus SAMA, jadi tab TIDAK boleh dibagi
     menurut status — melainkan menurut ada-tidaknya foto yang menunggu. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@php
    $tabs = [
        \App\Http\Controllers\Wms\ProofVerificationController::TAB_PERLU_DIPERIKSA => [
            'judul' => 'Perlu Diperiksa', 'warna' => 'danger',
            'sub' => 'Foto sudah masuk, menunggu Anda.',
        ],
        \App\Http\Controllers\Wms\ProofVerificationController::TAB_MENUNGGU_BUKTI => [
            'judul' => 'Menunggu Bukti', 'warna' => 'secondary',
            'sub' => 'Belum ada foto dari Sales.',
        ],
        \App\Http\Controllers\Wms\ProofVerificationController::TAB_RIWAYAT => [
            'judul' => 'Selesai', 'warna' => 'secondary',
            'sub' => 'Sudah diverifikasi.',
        ],
    ];
@endphp

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-1">
            <i class="bi bi-shield-check text-primary me-2"></i> Bukti Surat Jalan bertanda tangan
        </h5>
        <small class="text-muted d-block mb-3">
            Sales memotret Surat Jalan yang sudah ditandatangani pelanggan. Pesanan baru dinyatakan
            selesai setelah fotonya Anda periksa.
        </small>

        <ul class="nav nav-tabs border-0 gap-1">
            @foreach($tabs as $kunci => $t)
            <li class="nav-item">
                <a class="nav-link rounded-top-3 {{ $tab === $kunci ? 'active fw-semibold' : 'text-muted' }}"
                   href="{{ route('wms.verification.index', array_filter(['tab' => $kunci, 'search' => $filters['search']])) }}">
                    {{ $t['judul'] }}
                    @if($jumlah[$kunci] > 0)
                        <span class="badge bg-{{ $tab === $kunci ? $t['warna'] : 'secondary' }}-subtle text-{{ $tab === $kunci ? $t['warna'] : 'secondary' }}-emphasis ms-1">
                            {{ $jumlah[$kunci] }}
                        </span>
                    @endif
                </a>
            </li>
            @endforeach
        </ul>
    </div>

    <div class="card-body p-4">
        <form method="GET" class="row g-2 mb-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-12 col-md-6">
                <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control rounded-3"
                       placeholder="Cari nomor pesanan, nomor SO, atau pelanggan…">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary rounded-3"><i class="bi bi-search me-1"></i> Cari</button>
            </div>
            @if($filters['search'])
            <div class="col-auto d-flex align-items-center">
                <a href="{{ route('wms.verification.index', ['tab' => $tab]) }}" class="small">Tampilkan semua</a>
            </div>
            @endif
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Pesanan</th>
                        <th>No. SO (BC)</th>
                        <th>Pelanggan</th>
                        <th>Surat Jalan</th>
                        <th>Sampai</th>
                        <th>Bukti</th>
                        <th class="text-end">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td>
                            <div class="fw-semibold font-monospace">{{ $order->order_number }}</div>
                            <small class="text-muted">{{ $order->paymentTerm?->name }}</small>
                        </td>
                        <td class="font-monospace fw-semibold">{{ $order->bc_so_number ?? '—' }}</td>
                        <td>
                            <div>{{ $order->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $order->customer?->code }}</small>
                        </td>
                        <td class="font-monospace">
                            {{ $order->deliveryNotes->pluck('document_no')->join(', ') ?: '—' }}
                        </td>
                        <td>{{ $order->delivered_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td>
                            @if($order->bukti_menunggu > 0)
                                <span class="badge bg-danger-subtle text-danger-emphasis">
                                    {{ $order->bukti_menunggu }} foto menunggu
                                </span>
                            @elseif($order->bukti_ditolak > 0)
                                {{-- Yang membedakan "belum kirim" dari "sudah kirim tapi salah":
                                     tanpa penanda ini keduanya terlihat identik, padahal yang kedua
                                     berarti Sales sedang menunggu kabar dari kita. --}}
                                <span class="badge bg-warning-subtle text-warning-emphasis">
                                    Ditolak, menunggu foto ulang
                                </span>
                            @elseif(in_array($order->status, [\App\Models\SalesOrder::STATUS_COMPLETED, \App\Models\SalesOrder::STATUS_COMPLETED_BILLING], true))
                                <span class="badge bg-success-subtle text-success-emphasis">Terverifikasi</span>
                            @else
                                <span class="text-muted small">Belum ada</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('wms.verification.show', $order) }}"
                               class="btn btn-sm rounded-3 {{ $order->bukti_menunggu > 0 ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $order->bukti_menunggu > 0 ? 'Periksa' : 'Lihat' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                            {{ $tabs[$tab]['sub'] }} Tidak ada yang perlu ditampilkan di sini.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
