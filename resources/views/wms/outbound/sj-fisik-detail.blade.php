@extends('layouts.wms')

@section('title', $paket->code)
@section('page_title', __('Paket Surat Jalan').' — '.$paket->code)

@section('content')
@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Tutup') }}"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}
</div>
@endif

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="{{ route('wms.sj-fisik.index') }}" class="btn btn-sm btn-outline-secondary rounded-3">
        <i class="bi bi-arrow-left me-1"></i> {{ __('Kembali') }}
    </a>
    <span class="badge bg-{{ $paket->status_badge }}-subtle text-{{ $paket->status_badge }}-emphasis fs-6">
        {{ $paket->status_label }}
    </span>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body px-4 py-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-envelope-paper text-primary me-2"></i> {{ __('Pengiriman') }}</h6>
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">{{ __('Nomor paket') }}</dt>
                    <dd class="col-7 font-monospace fw-semibold">{{ $paket->code }}</dd>

                    <dt class="col-5 text-muted fw-normal">{{ __('Gudang asal') }}</dt>
                    <dd class="col-7">{{ $paket->warehouse?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">{{ __('Isi') }}</dt>
                    <dd class="col-7">{{ $paket->items->count() }} {{ __('lembar') }}</dd>

                    <dt class="col-5 text-muted fw-normal">{{ __('Cara kirim') }}</dt>
                    <dd class="col-7">{{ $paket->carrier_label }}</dd>

                    <dt class="col-5 text-muted fw-normal">{{ __('Pembawa') }}</dt>
                    <dd class="col-7">{{ $paket->carrier_name }}</dd>

                    @if($paket->tracking_no)
                    <dt class="col-5 text-muted fw-normal">{{ __('Nomor resi') }}</dt>
                    <dd class="col-7 font-monospace">{{ $paket->tracking_no }}</dd>
                    @endif

                    <dt class="col-5 text-muted fw-normal">{{ __('Berangkat') }}</dt>
                    <dd class="col-7">
                        {{ $paket->sent_at?->translatedFormat('d M Y H:i') }}
                        <div class="text-muted">{{ $paket->sentBy?->full_name }}</div>
                    </dd>

                    @if($paket->notes)
                    <dt class="col-5 text-muted fw-normal">{{ __('Catatan') }}</dt>
                    <dd class="col-7">{{ $paket->notes }}</dd>
                    @endif

                    @if($paket->status === \App\Models\DeliveryNoteHandover::STATUS_RECEIVED)
                    <dt class="col-5 text-muted fw-normal">{{ __('Diterima') }}</dt>
                    <dd class="col-7">
                        {{ $paket->received_at?->translatedFormat('d M Y H:i') }}
                        <div class="text-muted">{{ $paket->receivedBy?->full_name }}</div>
                    </dd>
                        @if($paket->received_notes)
                        <dt class="col-5 text-muted fw-normal">{{ __('Catatan HO') }}</dt>
                        <dd class="col-7">{{ $paket->received_notes }}</dd>
                        @endif
                    @endif

                    @if($paket->status === \App\Models\DeliveryNoteHandover::STATUS_CANCELLED)
                    <dt class="col-5 text-muted fw-normal">{{ __('Dibatalkan') }}</dt>
                    <dd class="col-7">
                        {{ $paket->cancelled_at?->translatedFormat('d M Y H:i') }}
                        <div class="text-muted">{{ $paket->cancelledBy?->full_name }}</div>
                        <div class="text-danger mt-1">{{ $paket->cancel_reason }}</div>
                    </dd>
                    @endif
                </dl>
            </div>
            <div class="card-footer bg-white border-top px-4 py-3 d-flex gap-2 flex-wrap">
                <a href="{{ route('wms.sj-fisik.cetak', $paket) }}" target="_blank" rel="noopener"
                   data-tanpa-pemuat class="btn btn-outline-primary rounded-3">
                    <i class="bi bi-printer me-1"></i> {{ __('Cetak Lembar Serah Terima') }}
                </a>
                @if($paket->bolehDibatalkan())
                <button type="button" class="btn btn-outline-danger rounded-3"
                        data-bs-toggle="modal" data-bs-target="#modalBatal">
                    <i class="bi bi-x-circle me-1"></i> {{ __('Batalkan Paket') }}
                </button>
                @endif
            </div>
        </div>

        @if($paket->terlambat())
        <div class="alert alert-warning border-0 rounded-3 small">
            <i class="bi bi-clock-history me-1"></i>
            {{ __('Sudah :n hari berjalan dan Kantor Pusat belum mengonfirmasi. Hubungi pembawanya selagi masih ingat.', ['n' => $paket->umurHari()]) }}
        </div>
        @endif
    </div>

    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-list-ol text-primary me-2"></i> {{ __('Isi paket') }}</h6>
                <small class="text-muted">
                    {{ $paket->dalamPerjalanan()
                        ? __('Kolom hasil periksa terisi setelah Kantor Pusat membuka amplopnya.')
                        : __('Hasil pemeriksaan Kantor Pusat.') }}
                </small>
            </div>
            <div class="card-body px-4">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px">#</th>
                                <th>{{ __('Surat Jalan') }}</th>
                                <th>{{ __('Pelanggan') }}</th>
                                <th>{{ __('Hasil periksa') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($paket->items as $i => $item)
                            <tr>
                                <td class="text-muted small">{{ $i + 1 }}</td>
                                <td class="font-monospace fw-semibold">
                                    {{ $item->deliveryNote?->document_no ?? '—' }}
                                    <div class="small text-muted">{{ $item->deliveryNote?->bc_so_number }}</div>
                                </td>
                                <td class="small">{{ $item->deliveryNote?->customer?->name ?? '—' }}</td>
                                <td>
                                    @if($item->check_status === null)
                                        <span class="text-muted small">{{ __('belum diperiksa') }}</span>
                                    @else
                                        <span class="badge bg-{{ $item->check_badge }}-subtle text-{{ $item->check_badge }}-emphasis">
                                            {{ $item->check_label }}
                                        </span>
                                        @if($item->check_note)
                                            <div class="small text-muted mt-1">{{ $item->check_note }}</div>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@if($paket->bolehDibatalkan())
<div class="modal fade" id="modalBatal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.sj-fisik.batal', $paket) }}" class="modal-content border-0 rounded-4">
            @csrf
            <div class="modal-header border-bottom-0 px-4 pt-4">
                <h5 class="modal-title fw-bold">{{ __('Batalkan paket') }} {{ $paket->code }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Tutup') }}"></button>
            </div>
            <div class="modal-body px-4">
                <p class="text-muted small">
                    {{ __('Seluruh Surat Jalan di dalamnya kembali ke daftar belum dikirim. Sebutkan alasannya — besok pagi orang lain akan melihat lembar yang sama muncul lagi dan bertanya kenapa.') }}
                </p>
                <textarea name="reason" rows="3" required minlength="10" maxlength="255"
                          class="form-control rounded-3"
                          placeholder="{{ __('mis. salah pilih, lembar SJ 206215 ternyata belum ada di tangan') }}"></textarea>
            </div>
            <div class="modal-footer border-top-0 px-4 pb-4">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">
                    {{ __('Tutup') }}
                </button>
                <button type="submit" class="btn btn-danger rounded-3">{{ __('Batalkan Paket') }}</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
