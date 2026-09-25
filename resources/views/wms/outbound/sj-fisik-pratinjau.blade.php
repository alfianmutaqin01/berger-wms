@extends('layouts.wms')

@section('title', __('Proses Pengiriman SJ Fisik'))
@section('page_title', __('Proses Pengiriman Surat Jalan Fisik'))

@section('content')
{{-- HALAMAN PENGHITUNGAN, bukan sekadar formulir.

     Yang dikerjakan di sini adalah mencocokkan tumpukan kertas di meja dengan
     daftar di layar. Karena itu daftarnya ditaruh lebih dulu dan lengkap
     — bukan diringkas jadi "7 lembar dipilih" — dan jumlahnya ditulis besar
     di atas: satu-satunya angka yang akan dihitung ulang orang di HO. --}}

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

<form method="POST" action="{{ route('wms.sj-fisik.store') }}">
    @csrf

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <h6 class="fw-bold mb-0">
                        <i class="bi bi-list-ol text-primary me-2"></i>
                        {{ __('Isi paket') }} — {{ $terpilih->count() }} {{ __('lembar') }}
                    </h6>
                    <small class="text-muted">{{ __('Hitung lembarnya sekali lagi sebelum amplop ditutup.') }}</small>
                </div>
                <div class="card-body px-4">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:36px">#</th>
                                    <th>{{ __('Surat Jalan') }}</th>
                                    <th>{{ __('No. SO (BC)') }}</th>
                                    <th>{{ __('Pelanggan') }}</th>
                                    <th>{{ __('Sampai') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($terpilih as $i => $sj)
                                <tr>
                                    <td class="text-muted small">{{ $i + 1 }}</td>
                                    <td class="font-monospace fw-semibold">
                                        {{ $sj->document_no }}
                                        <input type="hidden" name="delivery_note_id[]" value="{{ $sj->id }}">
                                    </td>
                                    <td class="font-monospace">{{ $sj->bc_so_number ?? '—' }}</td>
                                    <td class="small">{{ $sj->customer?->name ?? '—' }}</td>
                                    <td class="small">{{ $sj->delivered_at?->translatedFormat('d M Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <h6 class="fw-bold mb-0"><i class="bi bi-send text-primary me-2"></i> {{ __('Dikirim lewat') }}</h6>
                    <small class="text-muted">{{ __('Ini yang ditanyakan pertama kali kalau paketnya tidak sampai.') }}</small>
                </div>
                <div class="card-body px-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">{{ __('Cara kirim') }}</label>
                        @foreach($cara as $kode => $label)
                        <div class="form-check">
                            <input class="form-check-input caraKirim" type="radio" name="carrier_type"
                                   id="cara{{ $kode }}" value="{{ $kode }}"
                                   {{ old('carrier_type', \App\Models\DeliveryNoteHandover::CARRIER_TITIPAN) === $kode ? 'checked' : '' }}>
                            <label class="form-check-label" for="cara{{ $kode }}">{{ $label }}</label>
                        </div>
                        @endforeach
                    </div>

                    <div class="mb-3">
                        <label for="carrier_name" class="form-label small fw-semibold" id="labelPembawa">
                            {{ __('Nama pembawa') }}
                        </label>
                        <input type="text" name="carrier_name" id="carrier_name" maxlength="100" required
                               value="{{ old('carrier_name') }}" class="form-control rounded-3"
                               placeholder="{{ __('mis. Pak Budi (Finance) atau JNE') }}">
                        @error('carrier_name')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3 d-none" id="kotakResi">
                        <label for="tracking_no" class="form-label small fw-semibold">{{ __('Nomor resi') }}</label>
                        <input type="text" name="tracking_no" id="tracking_no" maxlength="50"
                               value="{{ old('tracking_no') }}" class="form-control rounded-3 font-monospace">
                        @error('tracking_no')<div class="small text-danger mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label small fw-semibold">
                            {{ __('Catatan') }} <span class="text-muted fw-normal">({{ __('boleh kosong') }})</span>
                        </label>
                        <textarea name="notes" id="notes" rows="2" maxlength="1000"
                                  class="form-control rounded-3">{{ old('notes') }}</textarea>
                    </div>
                </div>
                <div class="card-footer bg-white border-top px-4 py-3 d-flex gap-2 flex-wrap">
                    <button class="btn btn-primary rounded-3 px-4">
                        <i class="bi bi-check2-circle me-1"></i> {{ __('Catat Berangkat') }}
                    </button>
                    <a href="{{ route('wms.sj-fisik.index') }}" class="btn btn-outline-secondary rounded-3">
                        {{ __('Batal') }}
                    </a>
                </div>
            </div>

            <div class="alert alert-info border-0 rounded-3 small mt-3 mb-0">
                <i class="bi bi-printer me-1"></i>
                {{ __('Setelah tersimpan, cetak Lembar Serah Terima dan masukkan ke dalam amplop. Lembar itulah yang dipakai Kantor Pusat untuk menghitung isinya.') }}
            </div>
        </div>
    </div>
</form>

<script>
    (function () {
        var kotakResi = document.getElementById('kotakResi');
        var labelPembawa = document.getElementById('labelPembawa');
        var nama = document.getElementById('carrier_name');
        var pilihan = Array.prototype.slice.call(document.querySelectorAll('.caraKirim'));

        var teks = {
            'titipan': @json(__('Dititipkan kepada')),
            'ekspedisi': @json(__('Nama ekspedisi')),
            'sendiri': @json(__('Diantar oleh')),
        };

        function perbarui() {
            var terpilih = pilihan.filter(function (p) { return p.checked; })[0];
            if (!terpilih) { return; }

            var ekspedisi = terpilih.value === 'ekspedisi';
            kotakResi.classList.toggle('d-none', !ekspedisi);
            document.getElementById('tracking_no').required = ekspedisi;
            labelPembawa.textContent = teks[terpilih.value] || labelPembawa.textContent;
            nama.placeholder = ekspedisi ? 'JNE, J&T, …' : 'mis. Pak Budi (Finance)';
        }

        pilihan.forEach(function (p) { p.addEventListener('change', perbarui); });
        perbarui();
    })();
</script>
@endsection
