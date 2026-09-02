@extends('layouts.soms')

@section('title', 'Detail Pesanan')
@section('page_title', 'Detail Pesanan')

@php
    // Kemajuan garis stepper: berhenti tepat di TENGAH bulatan terakhir yang
    // sudah selesai. Tiap bulatan berada di tengah kolomnya sendiri, jadi
    // pusatnya ada di (indeks + 0,5) / jumlah tahap — memakai indeks saja
    // membuat garisnya berhenti di tepi kolom, bukan di bulatannya.
    //
    // Dihitung di sini, bukan di JavaScript, supaya garisnya sudah benar pada
    // cat pertama: di jaringan lambat, garis yang melompat setelah skrip
    // jalan terbaca sebagai halaman yang belum selesai dimuat.
    $jumlahTahap = count($timeline);
    $tahapSelesai = collect($timeline)->filter(fn ($t) => $t['selesai'])->count();
    $persen = $tahapSelesai > 0
        ? ((($tahapSelesai - 1) + 0.5) / $jumlahTahap) * 100
        : 0;
    $adaYangGagal = collect($timeline)->contains(fn ($t) => $t['gagal']);
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-9">

        <a href="{{ url('/sales/my-orders') }}" class="btn btn-sm btn-link text-decoration-none ps-0 mb-2">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke Pesanan Saya
        </a>

        <!-- ============ Kepala pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <div style="min-width: 0;">
                        <div class="fw-bold font-monospace text-dark">{{ $order->order_number }}</div>
                        <div class="text-dark text-truncate">{{ $order->customer?->name }}</div>
                        <div class="text-muted small">{{ $order->customer?->code }}</div>
                    </div>
                    <span class="badge bg-{{ $order->status_color }} flex-shrink-0">{{ $order->status_label }}</span>
                </div>

                @if($order->isEditable())
                    {{-- Seluruh stepper masih abu-abu pada draft — perjalanan
                         pesanan baru dimulai saat dikirim. Tanpa keterangan
                         ini, layar penuh bulatan kosong terbaca sebagai
                         halaman yang gagal memuat data. --}}
                    <div class="alert alert-secondary border-0 small py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Draft belum dikirim. Perjalanan pesanan dimulai setelah dikirim ke Logistik.
                    </div>
                @endif

                <!-- ============ Stepper status ============ -->
                {{-- Mendatar dan muat satu layar. Garis digambar sebagai dua
                     batang bertumpuk: abu-abu penuh sebagai latar, lalu batang
                     berwarna selebar kemajuannya. --}}
                <div class="position-relative px-1 pt-2">
                    <div class="position-absolute w-100 rounded-pill"
                         style="height: 4px; background-color: #e9ecef; top: 22px; left: 0; z-index: 1;"></div>
                    <div class="position-absolute rounded-pill"
                         style="height: 4px; background-color: {{ $adaYangGagal ? '#dc3545' : '#198754' }};
                                top: 22px; left: 0; width: {{ $persen }}%; z-index: 2;
                                transition: width .3s ease;"></div>

                    <div class="d-flex justify-content-between position-relative" style="z-index: 3;">
                        @foreach($timeline as $tahap)
                            @php
                                [$warnaBulat, $warnaTeks, $ikon] = match (true) {
                                    $tahap['gagal'] => ['bg-danger text-white', 'text-danger', 'bi-x-lg'],
                                    $tahap['selesai'] => ['bg-success text-white', 'text-success', 'bi-check-lg'],
                                    $tahap['menunggu'] => ['bg-warning text-dark', 'text-warning-emphasis', 'bi-hourglass-split'],
                                    default => ['bg-light text-muted border', 'text-muted', $tahap['ikon']],
                                };
                            @endphp
                            {{-- Lebar dibagi rata dari jumlah tahap, bukan angka
                                 tetap: menambah tahap di controller langsung
                                 terpasang benar tanpa menyentuh berkas ini. --}}
                            <div class="text-center" style="width: {{ 100 / count($timeline) }}%;">
                                <div class="{{ $warnaBulat }} rounded-circle d-inline-flex align-items-center justify-content-center mb-1 shadow-sm"
                                     style="width: 32px; height: 32px; border: 4px solid #fff !important;">
                                    <i class="bi {{ $ikon }}" style="font-size: 0.8rem;"></i>
                                </div>
                                <div class="fw-semibold {{ $warnaTeks }}" style="font-size: 0.68rem; line-height: 1.1;">
                                    {{ $tahap['judul'] }}
                                </div>
                                <div class="text-muted" style="font-size: 0.6rem; line-height: 1.2;">
                                    @if($tahap['waktu'])
                                        {{ $tahap['waktu']->translatedFormat('d M') }}<br>{{ $tahap['waktu']->format('H:i') }}
                                    @elseif($tahap['menunggu'])
                                        <span class="text-warning-emphasis fw-semibold">Menunggu</span>
                                    @else
                                        —
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if($order->sla_hours)
                    <div class="alert alert-success border-0 small mb-0 mt-3 py-2">
                        <i class="bi bi-stopwatch me-1"></i>
                        Selesai dalam {{ number_format((float) $order->sla_hours, 1) }} jam.
                    </div>
                @endif

                @if($order->rejection_reason)
                    <div class="alert alert-danger border-0 small mb-0 mt-3 py-2">
                        <strong>Alasan penolakan:</strong> {{ $order->rejection_reason }}
                    </div>
                @endif
            </div>
        </div>

        <!-- ============ Item pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Item Pesanan</h6>
            </div>

            @if($order->details->isEmpty())
                <div class="card-body text-center text-muted py-4">
                    @if($order->isDocumentBased())
                        <i class="bi bi-file-earmark-arrow-up fs-2 d-block mb-2 opacity-50"></i>
                        Rincian item diisi tim Logistik berdasarkan dokumen yang Anda unggah.
                    @else
                        <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                        Draft ini belum punya item pesanan.
                    @endif
                </div>
            @else
                {{-- Daftar, BUKAN tabel. Tabel enam kolom memaksa gulir
                     mendatar di layar HP dan angka qty-nya jatuh di luar
                     layar — justru angka itu yang paling dicari. --}}
                <ul class="list-group list-group-flush">
                    @foreach($order->details as $d)
                        <li class="list-group-item px-3 px-md-4 py-3">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div style="min-width: 0;">
                                    <div class="fw-semibold text-dark">{{ $d->product?->name }}</div>
                                    <small class="font-monospace text-muted">{{ $d->product?->sku }}</small>
                                </div>
                                <span class="badge bg-primary rounded-pill flex-shrink-0">
                                    {{ number_format($d->qty_ordered) }}
                                </span>
                            </div>

                            @if($order->approved_at)
                                {{-- Baris ini hanya muncul SESUDAH approval. Sebelum
                                     itu qty_approved bernilai 0, dan 0 di layar akan
                                     terbaca "tidak ada yang disetujui" padahal
                                     artinya "belum dinilai". --}}
                                <div class="d-flex gap-3 mt-2 small">
                                    <span class="text-muted">
                                        Disetujui <strong class="text-dark">{{ number_format($d->qty_approved) }}</strong>
                                    </span>
                                    @if($d->outstanding_qty > 0)
                                        <span class="text-danger">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                            Tidak terpenuhi {{ number_format($d->outstanding_qty) }}
                                        </span>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <!-- ============ Rincian pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-info-circle text-primary me-2"></i>Rincian Pesanan</h6>
            </div>
            <div class="card-body p-3 p-md-4 pt-2">
                {{-- Pasangan label/nilai berjajar, bukan tabel: di layar sempit
                     tiap baris tetap terbaca tanpa gulir mendatar. --}}
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">Gudang Tujuan</span>
                    <span class="fw-semibold text-end">{{ $order->warehouse?->name }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">Pembayaran</span>
                    <span class="fw-semibold text-end">{{ $order->paymentTerm?->name }}</span>
                </div>
                @if($order->customer_po_number)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">No. PO Customer</span>
                    <span class="fw-semibold font-monospace text-end">{{ $order->customer_po_number }}</span>
                </div>
                @endif
                @if($order->bc_so_number)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">No. SO (BC)</span>
                    <span class="fw-semibold font-monospace text-end">{{ $order->bc_so_number }}</span>
                </div>
                @endif
                <div class="d-flex justify-content-between {{ $order->notes ? 'border-bottom' : '' }} py-2 small">
                    <span class="text-muted">Dibuat</span>
                    <span class="fw-semibold text-end">{{ $order->created_at->translatedFormat('d M Y, H:i') }}</span>
                </div>
                @if($order->notes)
                <div class="py-2 small">
                    <div class="text-muted mb-1">Catatan</div>
                    <div>{{ $order->notes }}</div>
                </div>
                @endif

                @if($order->document_name)
                    <a href="{{ url('/sales/orders/'.$order->id.'/document') }}"
                       class="btn btn-sm btn-outline-secondary w-100 mt-3 text-truncate">
                        <i class="bi bi-paperclip me-1"></i>{{ $order->document_name }}
                    </a>
                @endif
            </div>
        </div>

        {{-- Aksi draft diletakkan di BAWAH, dalam jangkauan ibu jari saat
             HP dipegang satu tangan. --}}
        @if($order->isEditable())
            <div class="d-flex gap-2 mt-3">
                <a href="{{ url('/sales/orders/'.$order->id.'/edit') }}" class="btn btn-outline-secondary flex-grow-1">
                    <i class="bi bi-pencil me-1"></i>Ubah Draft
                </a>
                <form method="POST" action="{{ url('/sales/orders/'.$order->id.'/submit') }}" class="flex-grow-1">
                    @csrf
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="bi bi-send me-1"></i>Kirim
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
