@extends('layouts.wms')

@section('title', $paket->code)
@section('page_title', __('Periksa Paket').' — '.$paket->code)

@section('content')
{{-- TIGA PILIHAN PER BARIS, dan yang ketiga yang paling penting.

     "Tidak ada di amplop" bukan varian halus dari "bermasalah": hanya pilihan
     itu yang mengembalikan Surat Jalan ke daftar kirim di gudang. Tanpa dia,
     lembar yang hilang di jalan tercatat sudah dikirim selamanya dan tidak
     pernah muncul lagi di layar siapa pun. --}}

@if(session('error'))
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
</div>
@endif

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}
</div>
@endif

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <a href="{{ route('wms.sj-fisik.masuk') }}" class="btn btn-sm btn-outline-secondary rounded-3">
        <i class="bi bi-arrow-left me-1"></i> {{ __('Kembali') }}
    </a>
    <span class="badge bg-{{ $paket->status_badge }}-subtle text-{{ $paket->status_badge }}-emphasis fs-6">
        {{ $paket->status_label }}
    </span>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body px-4 py-3">
        <div class="row g-3 small">
            <div class="col-6 col-md-3">
                <div class="text-muted">{{ __('Nomor paket') }}</div>
                <div class="fw-semibold font-monospace">{{ $paket->code }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">{{ __('Gudang asal') }}</div>
                <div class="fw-semibold">{{ $paket->warehouse?->name ?? '—' }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">{{ __('Dikirim lewat') }}</div>
                <div class="fw-semibold">{{ $paket->carrier_name }}</div>
                <div class="text-muted">{{ $paket->carrier_label }}{{ $paket->tracking_no ? ' · '.$paket->tracking_no : '' }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted">{{ __('Seharusnya berisi') }}</div>
                <div class="fw-bold fs-5">{{ $paket->items->count() }} {{ __('lembar') }}</div>
            </div>
        </div>
        @if($paket->notes)
        <div class="border-top mt-3 pt-3 small">
            <span class="text-muted">{{ __('Catatan gudang') }}:</span> {{ $paket->notes }}
        </div>
        @endif
    </div>
</div>

@php
    $terkunci = ! $paket->dalamPerjalanan();
    $pilihan = \App\Models\DeliveryNoteHandoverItem::CHECK_LABELS;
@endphp

<form method="POST" action="{{ route('wms.sj-fisik.konfirmasi', $paket) }}" id="formPeriksa">
    @csrf

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom-0 pt-4 px-4">
            <h6 class="fw-bold mb-0"><i class="bi bi-ui-checks text-primary me-2"></i> {{ __('Isi amplop') }}</h6>
            <small class="text-muted">
                {{ $terkunci
                    ? __('Paket ini sudah ditutup.')
                    : __('Cocokkan tiap lembar dengan daftar. Baris yang tidak beres wajib diberi keterangan.') }}
            </small>
        </div>

        <div class="card-body px-4">
            @foreach($paket->items as $i => $item)
            <div class="border rounded-3 p-3 mb-2 {{ $item->check_status === \App\Models\DeliveryNoteHandoverItem::CHECK_MISSING ? 'border-danger' : '' }}">
                <div class="d-flex justify-content-between flex-wrap gap-2">
                    <div>
                        <span class="text-muted small me-2">{{ $i + 1 }}.</span>
                        <span class="fw-semibold font-monospace">{{ $item->deliveryNote?->document_no ?? '—' }}</span>
                        <div class="small text-muted ms-4">
                            {{ $item->deliveryNote?->customer?->name ?? '—' }}
                            @if($item->deliveryNote?->bc_so_number)
                                &middot; <span class="font-monospace">{{ $item->deliveryNote->bc_so_number }}</span>
                            @endif
                            &middot; {{ __('sampai') }} {{ $item->deliveryNote?->delivered_at?->translatedFormat('d M Y') ?? '—' }}
                        </div>
                    </div>

                    @if($terkunci && $item->check_status !== null)
                    <div class="text-end">
                        <span class="badge bg-{{ $item->check_badge }}-subtle text-{{ $item->check_badge }}-emphasis">
                            {{ $item->check_label }}
                        </span>
                        <div class="small text-muted mt-1">{{ $item->checkedBy?->full_name }}</div>
                    </div>
                    @endif
                </div>

                @if($terkunci)
                    @if($item->check_note)
                    <div class="small text-muted mt-2 ms-4">{{ $item->check_note }}</div>
                    @endif
                @else
                <div class="ms-4 mt-2">
                    <div class="d-flex gap-3 flex-wrap">
                        @foreach($pilihan as $kode => $label)
                        <div class="form-check">
                            <input class="form-check-input periksaStatus" type="radio"
                                   name="periksa[{{ $item->id }}][status]"
                                   id="periksa{{ $item->id }}{{ $kode }}"
                                   value="{{ $kode }}"
                                   data-baris="{{ $item->id }}"
                                   {{ old('periksa.'.$item->id.'.status') === $kode ? 'checked' : '' }}>
                            <label class="form-check-label small" for="periksa{{ $item->id }}{{ $kode }}">
                                {{ $label }}
                            </label>
                        </div>
                        @endforeach
                    </div>

                    <div class="mt-2 {{ in_array(old('periksa.'.$item->id.'.status'), ['issue', 'missing'], true) ? '' : 'd-none' }}"
                         id="catatan{{ $item->id }}">
                        <input type="text" name="periksa[{{ $item->id }}][note]" maxlength="500"
                               value="{{ old('periksa.'.$item->id.'.note') }}"
                               class="form-control form-control-sm rounded-3"
                               placeholder="{{ __('Sebutkan apa yang tidak beres, mis. tanda tangan pelanggan tidak ada') }}">
                        @error('periksa.'.$item->id.'.note')
                            <div class="small text-danger mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                @endif
            </div>
            @endforeach
        </div>

        @if(! $terkunci)
        <div class="card-footer bg-white border-top px-4 py-3">
            <div class="mb-3">
                <label for="received_notes" class="form-label small fw-semibold">
                    {{ __('Catatan penerimaan') }} <span class="text-muted fw-normal">({{ __('boleh kosong') }})</span>
                </label>
                <textarea name="received_notes" id="received_notes" rows="2" maxlength="1000"
                          class="form-control rounded-3">{{ old('received_notes') }}</textarea>
            </div>

            <div class="d-flex align-items-center gap-3 flex-wrap">
                <button type="submit" class="btn btn-success rounded-3 px-4" id="tombolKonfirmasi" disabled>
                    <i class="bi bi-check2-circle me-1"></i> {{ __('Konfirmasi Diterima') }}
                </button>
                <small class="text-muted" id="sisaPeriksa"></small>
            </div>
        </div>
        @else
        <div class="card-footer bg-success-subtle border-0 px-4 py-3 small">
            <i class="bi bi-check2-circle me-1"></i>
            {{ __('Ditutup') }} {{ $paket->received_at?->translatedFormat('d M Y H:i') }}
            @if($paket->receivedBy) {{ __('oleh') }} {{ $paket->receivedBy->full_name }} @endif
            @if($paket->received_notes)
                <div class="mt-1">{{ $paket->received_notes }}</div>
            @endif
        </div>
        @endif
    </div>
</form>

@if(! $terkunci)
<script>
    (function () {
        var tombol = document.getElementById('tombolKonfirmasi');
        var sisa = document.getElementById('sisaPeriksa');
        var radio = Array.prototype.slice.call(document.querySelectorAll('.periksaStatus'));
        var total = {{ $paket->items->count() }};

        var teksSisa = @json(__('Masih ada :n Surat Jalan yang belum ditandai.'));
        var teksSiap = @json(__('Semua sudah ditandai.'));

        function perbarui() {
            var sudah = {};

            radio.forEach(function (r) {
                if (r.checked) { sudah[r.dataset.baris] = r.value; }
            });

            radio.forEach(function (r) {
                if (!r.checked) { return; }
                var kotak = document.getElementById('catatan' + r.dataset.baris);
                if (kotak) { kotak.classList.toggle('d-none', r.value === 'ok'); }
            });

            var jumlah = Object.keys(sudah).length;
            var kurang = total - jumlah;

            tombol.disabled = kurang > 0;
            sisa.textContent = kurang > 0 ? teksSisa.replace(':n', kurang) : teksSiap;
        }

        radio.forEach(function (r) { r.addEventListener('change', perbarui); });
        perbarui();
    })();
</script>
@endif
@endsection
