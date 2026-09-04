@extends('layouts.wms')

@section('title', 'Surat Jalan')
@section('page_title', 'Surat Jalan & Pengiriman')

@section('content')
{{-- SISTEM INI TIDAK MENERBITKAN SURAT JALAN. Dokumen resminya keluar dari
     sistem BC (keputusan pemilik produk); di sini ia disalin, dicocokkan
     dengan hasil picking, lalu dinyatakan berangkat.

     Karena itu tidak ada tombol "Cetak Surat Jalan" di halaman ini, dan tidak
     ada nomor dokumen yang dibangkitkan sistem. Menyediakan tombol cetak akan
     melahirkan dokumen kedua yang bersaing dengan dokumen resminya. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="small text-muted">Menunggu berangkat</div>
                <div class="h3 fw-bold mb-0">{{ $stats['menunggu'] }}</div>
                @if($stats['menunggu'] > 0)
                    <a href="{{ route('wms.delivery.index', ['status' => \App\Models\DeliveryNote::STATUS_IMPORTED]) }}"
                       class="small text-decoration-none">Lihat daftarnya</a>
                @endif
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="small text-muted">Sudah dipicking, siap kirim</div>
                <div class="h3 fw-bold mb-0">{{ $stats['siap_kirim'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        {{-- SJ tanpa pasangan diberi kartunya sendiri, bukan disembunyikan di
             dalam saringan. Kalau sebuah SJ seharusnya berpasangan dan
             ternyata tidak, artinya nomor SO di BC berbeda dari yang diketik
             Logistik — dan itu ketahuan di sini atau tidak sama sekali. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 {{ $stats['tanpa_pasangan'] > 0 ? 'bg-warning-subtle' : '' }}">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted">Belum menemukan pesanannya</div>
                    <div class="h3 fw-bold mb-0">{{ $stats['tanpa_pasangan'] }}</div>
                    <div class="small text-muted">Nomor SO di BC mungkin berbeda dari yang diketik saat menerima pesanan.</div>
                </div>
                @if($stats['tanpa_pasangan'] > 0)
                <a href="{{ route('wms.delivery.index', ['tanpa_pasangan' => 1]) }}"
                   class="btn btn-sm btn-warning rounded-3">Periksa</a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0">
                <i class="bi bi-file-earmark-text text-primary me-2"></i> Surat Jalan dari BC
            </h5>
            <small class="text-muted">
                Disalin dari sistem BC. Nomor dan qty-nya milik dokumen resmi, bukan dibangkitkan di sini.
            </small>
        </div>
        <button type="button" class="btn btn-primary rounded-3 px-3" data-bs-toggle="modal" data-bs-target="#modalImporSj">
            <i class="bi bi-upload me-1"></i> Impor Surat Jalan
        </button>
    </div>

    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                           placeholder="Cari nomor dokumen, nomor SO, atau kode customer...">
                </div>
            </div>
            <div class="col-8 col-md-4">
                <select name="status" class="form-select">
                    <option value="">Semua status</option>
                    @foreach($statuses as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($filters['status'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-md-2 d-grid">
                <button class="btn btn-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
            @if($filters['tanpa_pasangan'])
                <input type="hidden" name="tanpa_pasangan" value="1">
                <div class="col-12">
                    <span class="badge bg-warning-subtle text-warning-emphasis">
                        Hanya yang belum berpasangan
                    </span>
                    <a href="{{ route('wms.delivery.index') }}" class="small ms-2">Tampilkan semua</a>
                </div>
            @endif
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Dokumen</th>
                        <th>No. SO (BC)</th>
                        <th>Customer</th>
                        <th>Pesanan</th>
                        <th class="text-center">Baris</th>
                        <th>Tgl Kirim</th>
                        <th>Status</th>
                        <th class="text-end">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($notes as $note)
                    <tr class="{{ $note->sales_order_id === null ? 'table-warning' : '' }}">
                        <td>
                            <a href="{{ route('wms.delivery.show', $note) }}"
                               class="fw-semibold font-monospace text-decoration-none">{{ $note->document_no }}</a>
                        </td>
                        <td class="font-monospace">{{ $note->bc_so_number }}</td>
                        <td>
                            <div class="fw-semibold">{{ $note->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $note->customer_code }}</small>
                        </td>
                        <td>
                            @if($note->salesOrder)
                                <span class="font-monospace">{{ $note->salesOrder->order_number }}</span>
                            @else
                                <span class="badge bg-warning-subtle text-warning-emphasis">Belum berpasangan</span>
                            @endif
                        </td>
                        <td class="text-center">{{ $note->lines_count }}</td>
                        <td>{{ $note->shipment_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            <span class="badge bg-{{ $note->status_color }}-subtle text-{{ $note->status_color }}-emphasis">
                                {{ $note->status_label }}
                            </span>
                        </td>
                        {{-- Pintu masuk ke rincian. Tanpa kolom ini, halaman
                             "Nyatakan Berangkat" tidak bisa dicapai sama sekali
                             dari daftar — cacat yang ditemukan saat uji coba. --}}
                        <td class="text-end">
                            @if($note->status === \App\Models\DeliveryNote::STATUS_IMPORTED && $note->sales_order_id !== null)
                                <a href="{{ route('wms.delivery.show', $note) }}" class="btn btn-sm btn-primary rounded-3">
                                    <i class="bi bi-send me-1"></i> Kirim
                                </a>
                            @elseif($note->sales_order_id === null)
                                <a href="{{ route('wms.delivery.show', $note) }}" class="btn btn-sm btn-outline-warning rounded-3">
                                    Periksa
                                </a>
                            @else
                                <a href="{{ route('wms.delivery.show', $note) }}" class="btn btn-sm btn-outline-secondary rounded-3">
                                    Lihat
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-file-earmark-text display-6 d-block mb-2 opacity-50"></i>
                            Belum ada Surat Jalan yang disalin dari BC.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $notes->links() }}</div>
    </div>
</div>

{{-- Impor Surat Jalan: ekspor harian BC, atau per container yang mau jalan. --}}
<div class="modal fade" id="modalImporSj" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="{{ route('wms.delivery.import.preview') }}"
              enctype="multipart/form-data" class="modal-content border-0 rounded-4">
            @csrf
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-upload text-primary me-2"></i>Impor Surat Jalan dari BC
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Unggah ekspor Surat Jalan dari sistem BC — boleh sekaligus satu hari, atau satu container
                    yang akan berangkat. Berkas .xlsx / .xls, maksimal 10 MB. Baris pertama harus berisi judul kolom.
                    <strong>Berkasnya tidak disimpan</strong>; yang tersimpan hanya isinya.
                </p>

                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0 small font-monospace">
                        <thead class="table-light">
                            <tr>
                                <th>Document No.</th><th>SO Number</th><th>Sell-to Customer No.</th>
                                <th>No.</th><th>Quantity</th><th>Shipment Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>206215</td><td>SO260903</td><td>IDR13302</td>
                                <td>ID1-F0017X002820</td><td>1,</td><td>31/08/2026</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="small text-muted">
                    Kolom <em>Description</em>, <em>Location Code</em>, <em>Unit of Measure Code</em>, dan
                    <em>Quantity Invoiced</em> ikut dibaca bila ada. Judul kolom boleh apa adanya dari ekspor BC.
                </p>

                <input type="file" name="file" class="form-control" accept=".xlsx,.xls" required>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary rounded-3">Lanjut ke Pratinjau</button>
            </div>
        </form>
    </div>
</div>
@endsection
