@extends('layouts.wms')

@section('title', 'MRF Picked')
@section('page_title', 'MRF Picked')

@section('content')
{{-- LAYAR YANG MENJAWAB PERTANYAAN YANG SELAMA INI TIDAK PUNYA JAWABAN.

     Dari 300 pcs yang diminta untuk direproses, baru 150 yang dikerjakan —
     dan sisa 150 itu hanya diingat, sampai ingatannya habis. Di sini sisa itu
     punya baris, punya umur, dan punya tanggal.

     YANG PALING LAMA DI ATAS, bukan yang terbaru. Yang berbahaya justru yang
     tua; daftar yang menaruh yang terbaru di atas akan menenggelamkannya
     persis saat ia paling perlu dilihat. --}}

@foreach(['success' => 'check-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
@endforeach

<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold">{{ number_format($stats['baris_berjalan']) }}</div>
                <small class="text-muted">Batch yang masih ada sisanya</small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="fs-3 fw-bold text-primary">{{ number_format($stats['unit_sisa']) }}</div>
                <small class="text-muted">Unit belum dipakai</small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm rounded-4 h-100 {{ $stats['menunggak'] > 0 ? 'border border-2 border-danger' : '' }}">
            <div class="card-body">
                <div class="fs-3 fw-bold {{ $stats['menunggak'] > 0 ? 'text-danger' : '' }}">
                    {{ number_format($stats['menunggak']) }}
                </div>
                <small class="text-muted">Lebih dari {{ $ambangMenunggak }} hari belum habis</small>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-5">
                <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm rounded-3"
                       placeholder="Cari SKU, batch, nomor MRF, atau lokasi…">
            </div>
            <div class="col-6 col-md-3">
                <select name="keadaan" class="form-select form-select-sm rounded-3">
                    <option value="berjalan" @selected($filters['keadaan'] === 'berjalan')>Masih ada sisa</option>
                    <option value="habis" @selected($filters['keadaan'] === 'habis')>Sudah habis</option>
                    <option value="semua" @selected($filters['keadaan'] === 'semua')>Semua</option>
                </select>
            </div>
            @if($gudangOptions->count() > 1)
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select form-select-sm rounded-3">
                    <option value="">Semua gudang</option>
                    @foreach($gudangOptions as $g)
                        <option value="{{ $g->id }}" @selected($filters['warehouse_id'] == $g->id)>{{ $g->code }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-12 col-md-auto d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary rounded-3">Terapkan</button>
                <a href="{{ route('wms.material-produksi.index') }}" class="btn btn-sm btn-link text-decoration-none">Reset</a>
                {{-- Hanya untuk yang boleh membacanya. Divisi peminta berhenti
                     di daftar ini; riwayatnya lintas divisi. --}}
                @can(\App\Support\Permission::MRF_HISTORY)
                    <a href="{{ route('wms.material-produksi.riwayat') }}"
                       class="btn btn-sm btn-outline-primary rounded-3 ms-auto text-nowrap">
                        <i class="bi bi-clock-history me-1"></i> Riwayat Pemakaian
                    </a>
                @endcan
            </div>
        </form>

        {{-- Saran rak untuk isian "pindahkan ke". Satu daftar untuk seluruh
             halaman: mencetaknya per baris berarti ribuan pilihan yang sama
             diulang sebanyak jumlah material. --}}
        <datalist id="rakPenyimpanan">
            @foreach($rakPenyimpanan as $kode)
                <option value="{{ $kode }}"></option>
            @endforeach
        </datalist>

        @forelse($halaman as $holding)
        @php($menunggak = ! $holding->sudahHabis() && $holding->umur_hari >= $ambangMenunggak)
        <div class="border rounded-4 p-3 mb-3 {{ $menunggak ? 'border-danger border-2' : '' }}">
            <div class="row g-3 align-items-start">
                <div class="col-12 col-lg-5">
                    {{-- SKU dan deskripsi BERDAMPINGAN, bukan bertumpuk. Yang
                         dicari di daftar ini adalah satu SKU tertentu, dan mata
                         membacanya sebagai satu kalimat: kode lalu namanya. --}}
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="font-monospace fw-semibold">{{ $holding->product?->sku }}</span>
                        <span class="text-muted">—</span>
                        <span>{{ $holding->product?->name }}</span>
                        @if($holding->sudahHabis())
                            <span class="badge bg-success-subtle text-success-emphasis">Habis</span>
                        @elseif($menunggak)
                            <span class="badge bg-danger">{{ $holding->umur_hari }} hari belum habis</span>
                        @endif
                    </div>
                    <div class="small text-muted mt-1">
                        Batch <span class="font-monospace">{{ $holding->batch_no ?? '—' }}</span>
                        · di <strong>{{ $holding->production_area }}</strong>
                        @unless($holding->sudahHabis())
                            {{-- Area yang tertulis berasal dari titik transit yang
                                 dipilih operator saat serah terima — keterangan
                                 pembuka, bukan keputusan akhir. Barangnya hampir
                                 selalu berpindah ke lantai tempat ia benar-benar
                                 dikerjakan, dan yang tahu itu Produksi. --}}
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-decoration-none"
                                    data-bs-toggle="collapse" data-bs-target="#pindah{{ $holding->id }}"
                                    title="Pindahkan ke area lain">
                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <form method="POST" action="{{ route('wms.material-produksi.move', $holding) }}"
                                  class="collapse mt-2 d-flex gap-1" id="pindah{{ $holding->id }}">
                                @csrf
                                {{-- Rak penyimpanan gudang ditawarkan sebagai saran,
                                     tetapi isiannya tetap bebas: material produksi
                                     sering berdiri di tempat yang bukan rak sama
                                     sekali ("Lantai 2 Tinting"). Rak transit tidak
                                     ikut disarankan — ia titik serah terima, bukan
                                     tempat menyimpan. --}}
                                <input type="text" name="production_area" maxlength="100" required
                                       list="rakPenyimpanan"
                                       value="{{ $holding->production_area }}"
                                       class="form-control form-control-sm rounded-3"
                                       placeholder="Mis. Lantai 2 Tinting atau kode rak">
                                <button class="btn btn-sm btn-outline-primary rounded-3 text-nowrap">Pindahkan</button>
                            </form>
                        @endunless
                    </div>
                    {{-- SIAPA MENGERJAKAN APA. Produksi bukan satu orang: yang
                         meminta, yang menerima, dan yang memindahkan sering
                         tiga orang berbeda, dan pertanyaan yang muncul
                         berbulan-bulan kemudian selalu berbentuk "siapa yang
                         memegang ini terakhir". --}}
                    <div class="small text-muted">
                        Dari <a href="{{ route('wms.mrf.show', $holding->material_requisition_id) }}" class="font-monospace">{{ $holding->requisition?->mrf_number }}</a>
                        · diminta {{ $holding->requisition?->requestedBy?->full_name ?? '—' }}
                    </div>
                    <div class="small text-muted">
                        Diterima {{ $holding->receivedBy?->full_name ?? '—' }},
                        {{ $holding->received_at?->format('d/m/Y H:i') }}
                        @if($holding->area_moved_at)
                            <div>
                                Dipindahkan {{ $holding->areaMovedBy?->full_name ?? '—' }},
                                {{ $holding->area_moved_at->format('d/m/Y H:i') }}
                            </div>
                        @endif
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Diterima</span><span>{{ number_format($holding->qty_received) }}</span>
                    </div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Dipakai</span><span>{{ number_format($holding->qty_consumed) }}</span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold border-top pt-1 mt-1">
                        <span>Sisa</span>
                        <span class="{{ $holding->qty_sisa > 0 ? 'text-primary' : 'text-success' }}">
                            {{ number_format($holding->qty_sisa) }}
                        </span>
                    </div>
                    <div class="progress mt-2" style="height:6px">
                        <div class="progress-bar bg-success"
                             style="width: {{ $holding->qty_received > 0 ? round($holding->qty_consumed / $holding->qty_received * 100) : 0 }}%"></div>
                    </div>
                </div>

                <div class="col-6 col-lg-4">
                    @if(! $holding->sudahHabis())
                    <form method="POST" action="{{ route('wms.material-produksi.consume', $holding) }}" class="d-flex gap-2 align-items-start">
                        @csrf
                        <div class="flex-grow-1">
                            <input type="number" name="qty" class="form-control form-control-sm rounded-3 mb-1"
                                   min="1" max="{{ $holding->qty_sisa }}" required
                                   placeholder="Berapa yang dipakai?">
                            <input type="text" name="note" class="form-control form-control-sm rounded-3" maxlength="500"
                                   placeholder="Keterangan (opsional)">
                        </div>
                        <div class="d-grid gap-1">
                            <button class="btn btn-sm btn-primary rounded-3 text-nowrap">Catat</button>
                            <button class="btn btn-sm btn-outline-primary rounded-3 text-nowrap pakaiSemua"
                                    data-sisa="{{ $holding->qty_sisa }}" type="button">
                                Pakai Semua Sisa
                            </button>
                        </div>
                    </form>
                    @else
                    <div class="small text-muted">
                        Habis pada {{ $holding->finished_at?->format('d/m/Y') }}.
                    </div>
                    @endif

                    @if($holding->consumptions->isNotEmpty())
                    <details class="mt-2">
                        <summary class="small text-muted" style="cursor:pointer">
                            Riwayat pemakaian ({{ $holding->consumptions->count() }}&times;)
                        </summary>
                        <ul class="list-unstyled small mt-2 mb-0">
                            <li class="text-muted">
                                <i class="bi bi-dot"></i>
                                {{ $holding->received_at->format('d/m/Y') }} — masuk Produksi
                                <strong>{{ number_format($holding->qty_received) }}</strong>
                                lewat {{ $holding->receivedBy?->full_name ?? '—' }}
                            </li>
                            @foreach($holding->consumptions as $pakai)
                            <li class="text-muted">
                                <i class="bi bi-dot"></i>
                                {{ $pakai->consumed_at->format('d/m/Y') }} — dipakai
                                <strong>{{ number_format($pakai->qty) }}</strong>
                                oleh {{ $pakai->consumedBy?->full_name ?? '—' }}
                                @if(filled($pakai->note)) · {{ $pakai->note }} @endif
                            </li>
                            @endforeach
                        </ul>
                    </details>
                    @endif
                </div>
            </div>
        </div>
        @empty
        <div class="text-center text-muted py-5">
            <i class="bi bi-inboxes fs-1 d-block mb-2 opacity-25"></i>
            Tidak ada material yang cocok dengan penyaring ini.
        </div>
        @endforelse

        <div class="mt-3">{{ $halaman->links() }}</div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.pakaiSemua').forEach(function (tombol) {
    tombol.addEventListener('click', function () {
        const form = tombol.closest('form');
        form.querySelector('input[name=qty]').value = tombol.dataset.sisa;
        form.submit();
    });
});
</script>
@endpush
