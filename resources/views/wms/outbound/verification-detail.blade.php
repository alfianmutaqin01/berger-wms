@extends('layouts.wms')

@section('title', 'Verifikasi '.$order->order_number)
@section('page_title', 'Verifikasi Bukti — '.$order->order_number)

@section('content')
@php
    $selesai = in_array($order->status, [
        \App\Models\SalesOrder::STATUS_COMPLETED,
        \App\Models\SalesOrder::STATUS_COMPLETED_BILLING,
    ], true);

    $sj = $order->deliveryNotes->first();
    $ditolakTerakhir = $bukti->firstWhere('status', \App\Models\DeliveryProof::STATUS_REJECTED);
@endphp

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="{{ route('wms.verification.index') }}" class="btn btn-sm btn-outline-secondary rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Kembali
    </a>
    <span class="badge bg-{{ $order->status_color }}-subtle text-{{ $order->status_color }}-emphasis fs-6">
        {{ $order->status_label }}
    </span>
</div>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}
</div>
@endif

<div class="row g-3">
    {{-- FOTO LEBIH DULU, keterangan menyusul. Yang dikerjakan di halaman ini
         adalah MELIHAT — menaruh tabel di atas berarti pekerjaan utamanya
         berada di bawah lipatan layar. --}}
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-images text-primary me-2"></i> Foto Surat Jalan</h6>
                <small class="text-muted">Klik foto untuk melihat ukuran penuh.</small>
            </div>
            <div class="card-body px-4">
                @if($bukti->isEmpty())
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-camera display-6 d-block mb-2 opacity-50"></i>
                        Sales belum mengunggah foto Surat Jalan untuk pesanan ini.
                    </div>
                @else
                <div class="row g-3">
                    @foreach($bukti as $foto)
                    <div class="col-6 col-md-4">
                        <div class="border rounded-3 overflow-hidden h-100 d-flex flex-column">
                            <a href="{{ route('wms.verification.preview', $foto) }}" target="_blank" rel="noopener">
                                <img src="{{ route('wms.verification.preview', $foto) }}"
                                     alt="Bukti {{ $foto->original_name }}"
                                     class="w-100" style="height:150px;object-fit:cover">
                            </a>
                            <div class="p-2 small">
                                <span class="badge bg-{{ $foto->status_color }}-subtle text-{{ $foto->status_color }}-emphasis">
                                    {{ $foto->status_label }}
                                </span>
                                <div class="text-muted mt-1">
                                    {{ $foto->uploaded_at?->format('d M H:i') }} · {{ $foto->ukuran_ringkas }}
                                </div>
                                <div class="text-muted text-truncate" title="{{ $foto->uploadedBy?->full_name }}">
                                    {{ $foto->uploadedBy?->full_name ?? '—' }}
                                </div>
                                <a href="{{ route('wms.verification.download', $foto) }}" class="d-inline-block mt-1">
                                    <i class="bi bi-download me-1"></i> Unduh
                                </a>
                                @if($foto->status === \App\Models\DeliveryProof::STATUS_REJECTED)
                                    <div class="text-danger mt-1">{{ $foto->rejection_reason }}</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            @if(! $selesai)
            <div class="card-footer bg-white border-top px-4 py-3">
                @if($adaMenunggu)
                <div class="d-flex gap-2 flex-wrap">
                    <form method="POST" action="{{ route('wms.verification.complete', $order) }}">
                        @csrf
                        <button class="btn btn-success rounded-3 px-3">
                            <i class="bi bi-check2-circle me-1"></i> Bukti Sah — Selesaikan Pesanan
                        </button>
                    </form>
                    <button type="button" class="btn btn-outline-danger rounded-3"
                            data-bs-toggle="modal" data-bs-target="#modalTolak">
                        <i class="bi bi-x-circle me-1"></i> Tolak Bukti
                    </button>
                </div>
                @if(! $order->paymentTerm?->isImmediate())
                    {{-- Termin tempo TIDAK berhenti di 'Complete': tagihannya
                         masih berjalan, dan menyamakan keduanya membuat piutang
                         lenyap dari layar begitu barang sampai. --}}
                    <small class="text-muted d-block mt-2">
                        Termin {{ $order->paymentTerm?->name }} — pesanan akan berstatus
                        <strong>Complete (Menunggu Bayar)</strong> dan tetap muncul di Billing.
                    </small>
                @endif
                @else
                <div class="text-muted small">
                    <i class="bi bi-hourglass-split me-1"></i>
                    Tidak ada foto yang menunggu diperiksa.
                    @if($ditolakTerakhir)
                        Foto terakhir ditolak — Sales sedang diminta mengunggah ulang.
                    @else
                        Menunggu Sales mengunggah foto Surat Jalan.
                    @endif
                </div>
                @endif
            </div>
            @else
            <div class="card-footer bg-success-subtle border-0 px-4 py-3 small">
                <i class="bi bi-check2-circle me-1"></i>
                Diselesaikan {{ $order->completed_at?->format('d M Y H:i') }}
                @if($order->completedBy) oleh {{ $order->completedBy->full_name }} @endif
                @if($order->sla_hours) · SLA {{ number_format((float) $order->sla_hours, 1) }} jam @endif
            </div>
            @endif
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body px-4 py-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-truck text-primary me-2"></i> Pengiriman</h6>
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">No. SO (BC)</dt>
                    <dd class="col-7 font-monospace fw-semibold">{{ $order->bc_so_number ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Surat Jalan</dt>
                    <dd class="col-7 font-monospace">{{ $sj?->document_no ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Pelanggan</dt>
                    <dd class="col-7">{{ $order->customer?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Supir</dt>
                    <dd class="col-7">{{ $sj?->driver_name ?? '—' }} {{ $sj?->vehicle_plate ? '('.$sj->vehicle_plate.')' : '' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Berangkat</dt>
                    <dd class="col-7">{{ $order->shipped_at?->format('d M Y H:i') ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Sampai</dt>
                    <dd class="col-7">{{ $order->delivered_at?->format('d M Y H:i') ?? 'belum dikonfirmasi' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Diterima</dt>
                    <dd class="col-7">{{ $sj?->received_by_name ?? '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-list-ul text-primary me-2"></i> Isi Pesanan</h6>
                <small class="text-muted">Untuk dicocokkan dengan foto di sebelah.</small>
            </div>
            <div class="card-body px-4">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>SKU</th><th>Produk</th><th class="text-end">Qty</th></tr>
                        </thead>
                        <tbody>
                        @forelse($order->details as $baris)
                            <tr>
                                <td class="font-monospace small">{{ $baris->product?->sku }}</td>
                                <td class="small">{{ $baris->product?->name }}</td>
                                <td class="text-end">{{ $baris->qty_approved }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">Tidak ada rincian.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if(! $selesai && $adaMenunggu)
<div class="modal fade" id="modalTolak" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.verification.reject', $order) }}" class="modal-content border-0 rounded-4">
            @csrf
            <div class="modal-header border-bottom-0 px-4 pt-4">
                <h5 class="modal-title fw-bold">Tolak bukti</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body px-4">
                <p class="text-muted small">
                    Alasannya akan dibaca Sales di HP-nya, dan hanya itu yang memberitahunya
                    harus memotret apa lagi. Sebutkan yang kurang, bukan sekadar "tidak sesuai".
                </p>
                <textarea name="reason" rows="3" required minlength="10" maxlength="1000"
                          class="form-control rounded-3"
                          placeholder="mis. tanda tangan pelanggan tidak terlihat, foto terpotong di bagian qty"></textarea>
            </div>
            <div class="modal-footer border-top-0 px-4 pb-4">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger rounded-3">Tolak &amp; Minta Foto Ulang</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
