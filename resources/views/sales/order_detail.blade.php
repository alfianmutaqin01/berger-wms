@extends('layouts.soms')

@section('title', 'Detail Pesanan')
@section('page_title', 'Detail Pesanan')

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-9">

        <a href="{{ url('/sales/my-orders') }}" class="btn btn-sm btn-link text-decoration-none ps-0 mb-2">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke Pesanan Saya
        </a>

        <!-- ============ Kepala pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h5 class="fw-bold font-monospace mb-1">{{ $order->order_number }}</h5>
                        <div class="text-dark">{{ $order->customer?->name }}</div>
                        <div class="text-muted small">{{ $order->customer?->code }}</div>
                    </div>
                    <span class="badge bg-{{ $order->status_color }} fs-6">{{ $order->status_label }}</span>
                </div>

                <hr>

                <div class="row g-3 small">
                    <div class="col-6 col-md-3">
                        <div class="text-muted">Gudang Tujuan</div>
                        <div class="fw-semibold">{{ $order->warehouse?->code }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted">Pembayaran</div>
                        <div class="fw-semibold">{{ $order->paymentTerm?->name }}</div>
                    </div>
                    @if($order->customer_po_number)
                    <div class="col-6 col-md-3">
                        <div class="text-muted">Nomor PO Customer</div>
                        <div class="fw-semibold font-monospace">{{ $order->customer_po_number }}</div>
                    </div>
                    @endif
                    @if($order->bc_so_number)
                    <div class="col-6 col-md-3">
                        <div class="text-muted">Nomor SO (BC)</div>
                        <div class="fw-semibold font-monospace">{{ $order->bc_so_number }}</div>
                    </div>
                    @endif
                </div>

                @if($order->notes)
                    <div class="mt-3 small">
                        <div class="text-muted">Catatan</div>
                        <div>{{ $order->notes }}</div>
                    </div>
                @endif

                @if($order->rejection_reason)
                    <div class="alert alert-danger border-0 mt-3 mb-0 small">
                        <strong>Alasan penolakan:</strong> {{ $order->rejection_reason }}
                    </div>
                @endif

                @if($order->document_name)
                    <div class="mt-3">
                        <a href="{{ url('/sales/orders/'.$order->id.'/document') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-paperclip me-1"></i>{{ $order->document_name }}
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <!-- ============ Item pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Item Pesanan</h6>
            </div>
            <div class="card-body p-4 pt-3">
                @if($order->details->isEmpty())
                    <div class="text-center text-muted py-4">
                        @if($order->isDocumentBased())
                            <i class="bi bi-file-earmark-arrow-up fs-2 d-block mb-2 opacity-50"></i>
                            Rincian item diisi tim Logistik berdasarkan dokumen yang Anda unggah.
                        @else
                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                            Draft ini belum punya item pesanan.
                        @endif
                    </div>
                @else
                    {{-- Tabel dibuat rapat dan tanpa hiasan agar isinya bisa
                         disalin langsung ke Excel tanpa merapikan ulang. --}}
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="small fw-semibold text-secondary">SKU</th>
                                    <th class="small fw-semibold text-secondary">DESKRIPSI</th>
                                    <th class="small fw-semibold text-secondary text-end">QTY PESAN</th>
                                    <th class="small fw-semibold text-secondary text-end">QTY DISETUJUI</th>
                                    <th class="small fw-semibold text-secondary text-end">LOST SALES</th>
                                    <th class="small fw-semibold text-secondary">UOM</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($order->details as $d)
                                <tr>
                                    <td class="font-monospace small">{{ $d->product?->sku }}</td>
                                    <td class="small">{{ $d->product?->name }}</td>
                                    <td class="text-end fw-semibold">{{ number_format($d->qty_ordered) }}</td>
                                    <td class="text-end">
                                        {{-- Sebelum disetujui, qty_approved masih 0 dan itu
                                             berarti "belum dinilai", bukan "nol disetujui". --}}
                                        @if($order->approved_at)
                                            {{ number_format($d->qty_approved) }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($order->approved_at && $d->lost_qty > 0)
                                            <span class="text-danger fw-semibold">{{ number_format($d->lost_qty) }}</span>
                                        @elseif($order->approved_at)
                                            <span class="text-muted">0</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted">{{ $d->product?->uom }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <!-- ============ Timeline ============ -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-geo-alt text-primary me-2"></i>Timeline Status</h6>
            </div>
            <div class="card-body p-4 pt-3">
                <div class="ps-2">
                    @foreach($timeline as $tahap)
                        @php
                            $gagal = $tahap['gagal'] ?? false;
                            $warna = $gagal ? 'danger' : ($tahap['selesai'] ? 'success' : 'secondary');
                        @endphp
                        <div class="d-flex gap-3 {{ ! $loop->last ? 'pb-4' : '' }} position-relative">
                            {{-- Garis penghubung antar node, digambar di belakang
                                 titiknya dan dihentikan pada tahap terakhir. --}}
                            @if(! $loop->last)
                                <div class="position-absolute bg-light"
                                     style="left: 7px; top: 18px; bottom: 0; width: 2px;"></div>
                            @endif
                            <div class="flex-shrink-0 position-relative" style="z-index: 1;">
                                <span class="d-inline-block rounded-circle bg-{{ $warna }}"
                                      style="width: 16px; height: 16px;"></span>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold {{ $tahap['selesai'] ? 'text-dark' : 'text-muted' }}">
                                    {{ $tahap['judul'] }}
                                </div>
                                @if($tahap['waktu'])
                                    <div class="small text-muted">
                                        {{ $tahap['waktu']->translatedFormat('d M Y, H:i') }}
                                        @if(! empty($tahap['oleh'])) — {{ $tahap['oleh'] }} @endif
                                    </div>
                                @else
                                    <div class="small text-muted fst-italic">Belum</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @if($order->sla_hours)
                    <div class="alert alert-success border-0 small mb-0 mt-2">
                        <i class="bi bi-stopwatch me-1"></i>
                        Selesai dalam {{ number_format((float) $order->sla_hours, 1) }} jam.
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
