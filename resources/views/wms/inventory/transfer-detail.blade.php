@extends('layouts.wms')

@section('title', 'Transfer '.$transfer->transfer_number)
@section('page_title', 'Transfer '.$transfer->transfer_number)

@section('content')
{{-- Riwayat satu kiriman antar gudang. Dibaca KEDUA gudang yang terlibat —
     yang mengirim maupun yang menerima. --}}
@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@php $hilang = $transfer->total_missing; @endphp

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
            <div>
                <h4 class="fw-bold font-monospace mb-1">{{ $transfer->transfer_number }}</h4>
                <div class="text-muted">
                    {{ $transfer->fromWarehouse?->display_label }}
                    <i class="bi bi-arrow-right mx-1"></i>
                    {{ $transfer->toWarehouse?->display_label }}
                </div>
            </div>
            <span class="badge {{ $transfer->status_badge }} fs-6">{{ $transfer->status_label }}</span>
        </div>

        @if($transfer->isInTransit())
            <div class="alert alert-warning border-0 rounded-3 mb-3">
                <i class="bi bi-truck me-2"></i>
                Stok ini sudah <strong>keluar</strong> dari {{ $transfer->fromWarehouse?->name }} tetapi
                <strong>belum masuk</strong> ke {{ $transfer->toWarehouse?->name }}. Selama masih di
                perjalanan, barangnya tidak bisa dijual atau dipicking di gudang mana pun.
            </div>
        @endif

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="small text-muted">Dikirim</div>
                <div class="fw-semibold">{{ $transfer->shipped_at?->format('d M Y H:i') ?? '—' }}</div>
                <div class="small text-muted">{{ $transfer->shippedBy?->full_name }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Diterima</div>
                <div class="fw-semibold">{{ $transfer->received_at?->format('d M Y H:i') ?? '—' }}</div>
                <div class="small text-muted">{{ $transfer->receivedBy?->full_name }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Total Dikirim</div>
                <div class="fw-semibold">{{ number_format($transfer->total_shipped) }} unit</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Tidak Sampai</div>
                <div class="fw-semibold {{ $hilang ? 'text-danger' : '' }}">
                    {{-- NULL berarti "belum dihitung", 0 berarti "sudah
                         dihitung dan tidak ada yang hilang". Dua hal berbeda. --}}
                    {{ $hilang === null ? 'Belum dihitung' : number_format($hilang).' unit' }}
                </div>
            </div>
        </div>

        @if($transfer->notes)
            <div class="mt-3 small"><span class="text-muted">Catatan pengirim:</span> {{ $transfer->notes }}</div>
        @endif

        @if($transfer->status === \App\Models\StockTransfer::STATUS_CANCELLED)
            <div class="alert alert-secondary border-0 rounded-3 mt-3 mb-0">
                <i class="bi bi-x-circle me-2"></i>
                Dibatalkan {{ $transfer->cancelled_at?->format('d M Y H:i') }}
                oleh {{ $transfer->cancelledBy?->full_name }} — {{ $transfer->cancellation_reason }}.
                Stoknya sudah dikembalikan ke rak asal.
            </div>
        @endif
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-list-ul text-primary me-2"></i> Batch dalam Kiriman
        </h5>
        <small class="text-muted">Batch dan tanggal produksi ikut pindah apa adanya; hanya raknya yang berganti.</small>
    </div>

    <div class="card-body px-4 pt-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>SKU / Produk</th>
                        <th>Batch</th>
                        <th class="text-end">Dikirim</th>
                        <th class="text-end">Diterima</th>
                        <th>Rak Tujuan</th>
                        <th>Keterangan Selisih</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($transfer->details as $d)
                    <tr>
                        <td>
                            <div class="font-monospace small text-muted">{{ $d->product?->sku }}</div>
                            <div>{{ $d->product?->name }}</div>
                            @if($d->status === \App\Models\InventoryStock::STATUS_DDP)
                                <span class="badge bg-danger-subtle text-danger-emphasis">DDP</span>
                            @endif
                        </td>
                        <td>
                            <div class="font-monospace">{{ $d->batch_no }}</div>
                            <div class="small text-muted">
                                Prod {{ $d->production_date?->format('d M Y') }} &middot;
                                Exp {{ $d->expiry_date?->format('d M Y') }}
                            </div>
                        </td>
                        <td class="text-end fw-semibold">{{ number_format($d->qty_shipped) }}</td>
                        <td class="text-end fw-semibold">
                            {{ $d->qty_received === null ? '—' : number_format($d->qty_received) }}
                        </td>
                        <td class="font-monospace">{{ $d->toLocation?->code ?? '—' }}</td>
                        <td class="small">
                            @if($d->missing_qty)
                                <span class="text-danger fw-semibold">Kurang {{ number_format($d->missing_qty) }}.</span>
                                {{ $d->discrepancy_reason }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer bg-white border-top px-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <a href="{{ route('wms.transfers.index') }}" class="btn btn-outline-secondary rounded-3">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>

        @if($transfer->isInTransit())
            <div class="d-flex gap-2">
                {{-- Membatalkan hanya hak gudang ASAL: yang menghendaki
                     pembatalan adalah yang mengirim. Gudang tujuan yang tidak
                     menerima kiriman mencatatnya sebagai qty 0 beserta
                     alasannya — itu meninggalkan jejak bahwa barangnya pernah
                     berangkat, sedangkan pembatalan tidak. --}}
                @if(auth()->user()?->warehouse_id === null || auth()->user()?->warehouse_id === $transfer->from_warehouse_id)
                    @can(\App\Support\Permission::TRANSFER_SEND)
                        <button type="button" class="btn btn-outline-danger rounded-3" data-bs-toggle="modal" data-bs-target="#modalBatal">
                            <i class="bi bi-x-circle me-1"></i> Batalkan
                        </button>
                    @endcan
                @endif

                @if(auth()->user()?->warehouse_id === null || auth()->user()?->warehouse_id === $transfer->to_warehouse_id)
                    @can(\App\Support\Permission::TRANSFER_RECEIVE)
                        <a href="{{ route('wms.transfers.receive.form', $transfer) }}" class="btn btn-success rounded-3">
                            <i class="bi bi-box-arrow-in-down me-1"></i> Terima Kiriman
                        </a>
                    @endcan
                @endif
            </div>
        @endif
    </div>
</div>

@if($transfer->isInTransit())
<div class="modal fade" id="modalBatal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.transfers.cancel', $transfer) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Batalkan Transfer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Seluruh stok dalam kiriman ini akan dikembalikan ke rak asalnya di
                    {{ $transfer->fromWarehouse?->name }}. Pakai ini hanya bila barangnya
                    memang <strong>belum berangkat</strong>.
                </p>
                <label class="form-label small fw-semibold">Alasan pembatalan <span class="text-danger">*</span></label>
                <textarea name="cancellation_reason" class="form-control" rows="3" minlength="10" maxlength="500" required
                          placeholder="Minimal 10 karakter, mis. truk batal berangkat"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-danger rounded-3">Batalkan Transfer</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
