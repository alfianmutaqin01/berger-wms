@extends('layouts.wms')

@section('title', __('Kirim SJ Fisik'))
@section('page_title', __('Kirim Surat Jalan Fisik ke Kantor Pusat'))

@section('content')
{{-- DAFTAR KERJANYA ADALAH TURUNAN, bukan kolom status.

     "Belum dikirim" tidak disimpan di mana pun: ia berarti Surat Jalan yang
     buktinya sudah diverifikasi DAN belum terikat pada amplop yang hidup.
     Satu-satunya definisinya ada di DeliveryNote::scopeSiapKeHo(); layar ini
     tidak boleh menyusun syaratnya sendiri, sebab dua daftar yang menghitung
     sendiri-sendiri akan berbeda tepat pada baris yang sedang diperdebatkan. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Tutup') }}"></button>
    </div>
    @endif
@endforeach

@php
    $C = \App\Http\Controllers\Wms\SjHandoverController::class;
    $tabs = [
        $C::TAB_BELUM => [
            'judul' => __('Belum Dikirim'), 'warna' => 'danger',
            'sub' => __('Lembar fisiknya masih di gudang.'),
        ],
        $C::TAB_JALAN => [
            'judul' => __('Dalam Perjalanan'), 'warna' => 'primary',
            'sub' => __('Sudah berangkat, menunggu konfirmasi Kantor Pusat.'),
        ],
        $C::TAB_RIWAYAT => [
            'judul' => __('Riwayat'), 'warna' => 'secondary',
            'sub' => __('Paket yang sudah ditutup.'),
        ],
    ];
@endphp

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-1">
            <i class="bi bi-envelope-paper text-primary me-2"></i> {{ __('Surat Jalan fisik ke Kantor Pusat') }}
        </h5>
        <small class="text-muted d-block mb-3">
            {{ __('Lembar Surat Jalan bertanda tangan dikirim ke HO setelah fotonya diverifikasi. Pilih lembar yang ada di tangan Anda, lalu susun jadi satu paket.') }}
        </small>

        <ul class="nav nav-tabs border-0 gap-1">
            @foreach($tabs as $kunci => $t)
            <li class="nav-item">
                <a class="nav-link rounded-top-3 {{ $tab === $kunci ? 'active fw-semibold' : 'text-muted' }}"
                   href="{{ route('wms.sj-fisik.index', array_filter(['tab' => $kunci, 'search' => $filters['search']])) }}">
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
                       placeholder="{{ $tab === $C::TAB_BELUM ? __('Cari nomor Surat Jalan, nomor SO, atau pelanggan…') : __('Cari nomor paket, nomor Surat Jalan, pembawa, atau resi…') }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary rounded-3"><i class="bi bi-search me-1"></i> {{ __('Cari') }}</button>
            </div>
            @if($filters['search'])
            <div class="col-auto d-flex align-items-center">
                <a href="{{ route('wms.sj-fisik.index', ['tab' => $tab]) }}" class="small">{{ __('Tampilkan semua') }}</a>
            </div>
            @endif
        </form>

        @if($tab === $C::TAB_BELUM)
            <form method="POST" action="{{ route('wms.sj-fisik.pratinjau') }}" id="formPilih">
                @csrf
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px">
                                    <input type="checkbox" class="form-check-input" id="pilihSemua"
                                           aria-label="{{ __('Pilih semua') }}">
                                </th>
                                <th>{{ __('Surat Jalan') }}</th>
                                <th>{{ __('No. SO (BC)') }}</th>
                                <th>{{ __('Pelanggan') }}</th>
                                <th>{{ __('Sampai') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($belum as $sj)
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input pilihSj"
                                           name="delivery_note_id[]" value="{{ $sj->id }}"
                                           aria-label="{{ __('Pilih Surat Jalan') }} {{ $sj->document_no }}">
                                </td>
                                <td>
                                    <span class="fw-semibold font-monospace">{{ $sj->document_no }}</span>
                                    @if($sj->pernah_hilang_count > 0)
                                        {{-- Kembali ke daftar ini BUKAN karena belum pernah dikirim,
                                             melainkan karena pernah dikirim lalu tidak sampai. Tanpa
                                             penanda ini, kertasnya dicentang lagi tanpa ada yang
                                             menyadari bahwa lembarnya memang belum ketemu. --}}
                                        <span class="badge bg-danger-subtle text-danger-emphasis ms-1">
                                            {{ __('pernah tidak sampai') }}
                                        </span>
                                    @endif
                                    <div class="small text-muted font-monospace">{{ $sj->salesOrder?->order_number }}</div>
                                </td>
                                <td class="font-monospace fw-semibold">{{ $sj->bc_so_number ?? '—' }}</td>
                                <td>
                                    <div>{{ $sj->customer?->name ?? '—' }}</div>
                                    <small class="text-muted font-monospace">{{ $sj->customer?->code }}</small>
                                </td>
                                <td>{{ $sj->delivered_at?->translatedFormat('d M Y') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <i class="bi bi-check2-circle display-6 d-block mb-2 opacity-50"></i>
                                    {{ __('Tidak ada Surat Jalan yang menunggu dikirim ke Kantor Pusat.') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($belum->isNotEmpty())
                {{-- Tombolnya MENEMPEL DI BAWAH LAYAR. Daftar ini bisa sepanjang
                     25 baris; tombol yang berada di ujung bawah halaman memaksa
                     orang menggulung balik setelah selesai mencentang. --}}
                <div class="position-sticky bottom-0 bg-white border-top pt-3 mt-3 d-flex align-items-center gap-3 flex-wrap">
                    <button type="submit" class="btn btn-primary rounded-3 px-4" id="tombolProses" disabled>
                        <i class="bi bi-box-seam me-1"></i>
                        {{ __('Proses Pengiriman') }} (<span id="jumlahPilih">0</span>)
                    </button>
                    <small class="text-muted">{{ __('Boleh satu lembar, boleh digabung jadi satu paket.') }}</small>
                </div>
                @endif
            </form>

            <div class="mt-3">{{ $belum->links() }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ __('Paket') }}</th>
                            <th>{{ __('Isi') }}</th>
                            <th>{{ __('Dikirim lewat') }}</th>
                            <th>{{ __('Berangkat') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-end">{{ __('Tindakan') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($paket as $p)
                        <tr>
                            <td class="fw-semibold font-monospace">{{ $p->code }}</td>
                            <td>{{ $p->items_count }} {{ __('lembar') }}</td>
                            <td>
                                <div>{{ $p->carrier_name }}</div>
                                <small class="text-muted">
                                    {{ $p->carrier_label }}{{ $p->tracking_no ? ' · '.$p->tracking_no : '' }}
                                </small>
                            </td>
                            <td>
                                {{ $p->sent_at?->translatedFormat('d M Y') }}
                                <div class="small text-muted">{{ $p->sentBy?->full_name }}</div>
                            </td>
                            <td>
                                <span class="badge bg-{{ $p->status_badge }}-subtle text-{{ $p->status_badge }}-emphasis">
                                    {{ $p->status_label }}
                                </span>
                                @if($p->terlambat())
                                    <div class="small text-danger mt-1">
                                        <i class="bi bi-clock-history me-1"></i>
                                        {{ __('belum dikonfirmasi, :n hari', ['n' => $p->umurHari()]) }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('wms.sj-fisik.show', $p) }}" class="btn btn-sm btn-outline-secondary rounded-3">
                                    {{ __('Lihat') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                                {{ $tabs[$tab]['sub'] }}
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $paket->links() }}</div>
        @endif
    </div>
</div>

@if($tab === $C::TAB_BELUM)
<script>
    (function () {
        var semua = document.getElementById('pilihSemua');
        var kotak = Array.prototype.slice.call(document.querySelectorAll('.pilihSj'));
        var tombol = document.getElementById('tombolProses');
        var angka = document.getElementById('jumlahPilih');

        if (!tombol) { return; }

        function perbarui() {
            var terpilih = kotak.filter(function (k) { return k.checked; }).length;
            angka.textContent = terpilih;
            tombol.disabled = terpilih === 0;
            if (semua) {
                semua.checked = terpilih > 0 && terpilih === kotak.length;
                semua.indeterminate = terpilih > 0 && terpilih < kotak.length;
            }
        }

        if (semua) {
            semua.addEventListener('change', function () {
                kotak.forEach(function (k) { k.checked = semua.checked; });
                perbarui();
            });
        }

        kotak.forEach(function (k) { k.addEventListener('change', perbarui); });
        perbarui();
    })();
</script>
@endif
@endsection
