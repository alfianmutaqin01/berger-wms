<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Pengiriman — Berger Paints</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6fa; }
        .kartu { max-width: 520px; }
    </style>
</head>
<body class="py-4 px-3">
{{-- HALAMAN SUPIR — TANPA LOGIN.

     Dibuka di HP, sering di halaman customer, kadang dengan sinyal seadanya
     dan tangan yang baru selesai menurunkan barang. Karena itu:

       - berdiri sendiri, tidak memakai layout WMS (tidak ada sidebar, tidak
         ada menu, tidak ada yang bisa salah tekan)
       - satu tombol besar, satu tugas
       - hanya menampilkan yang perlu supir pastikan bahwa ia membuka
         kiriman yang benar — bukan seluruh isi pesanan berikut harganya.
         Halaman ini terbuka ke internet. --}}

<div class="kartu mx-auto">
    <div class="text-center mb-4">
        <h5 class="fw-bold mb-0">Berger Paints Indonesia</h5>
        <small class="text-muted">Konfirmasi Pengiriman</small>
    </div>

    @foreach(['success' => 'check-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
        @if(session($jenis))
        <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} border-0 rounded-4 shadow-sm">
            <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        </div>
        @endif
    @endforeach

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <dl class="row mb-3">
                <dt class="col-5 text-muted fw-normal small">Surat Jalan</dt>
                <dd class="col-7 fw-bold font-monospace">{{ $note->document_no }}</dd>

                <dt class="col-5 text-muted fw-normal small">Tujuan</dt>
                <dd class="col-7 fw-semibold">{{ $note->customer?->name ?? '—' }}</dd>

                @if($note->vehicle_plate)
                <dt class="col-5 text-muted fw-normal small">Kendaraan</dt>
                <dd class="col-7">{{ $note->vehicle_plate }}</dd>
                @endif
            </dl>

            <h6 class="fw-bold small text-muted text-uppercase">Barang</h6>
            <ul class="list-group list-group-flush mb-3">
                @foreach($note->lines as $line)
                <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                    <div class="me-2">
                        <div class="small fw-semibold">{{ $line->product?->name ?? $line->description ?? $line->sku }}</div>
                        <small class="text-muted font-monospace">{{ $line->sku }}</small>
                    </div>
                    <span class="fw-bold text-nowrap">{{ $line->qty }} {{ $line->product?->uom ?? $line->uom_code }}</span>
                </li>
                @endforeach
            </ul>

            @if($note->status === \App\Models\DeliveryNote::STATUS_DELIVERED)
                {{-- Sudah dikonfirmasi. Tombolnya HILANG, bukan sekadar
                     dinonaktifkan: supir yang membuka tautannya lagi untuk
                     memastikan tidak boleh menemukan tombol yang menggoda
                     ditekan sekali lagi. --}}
                <div class="alert alert-success border-0 rounded-4 mb-0 text-center">
                    <i class="bi bi-check-circle-fill fs-1 d-block mb-2"></i>
                    <div class="fw-bold">Sudah dikonfirmasi sampai</div>
                    <div class="small">{{ $note->delivered_at?->format('d M Y, H:i') }}</div>
                    @if($note->received_by_name)
                        <div class="small">Diterima: {{ $note->received_by_name }}</div>
                    @endif
                </div>
            @else
                <form method="POST" action="{{ route('epod.confirm', $note->epod_token) }}">
                    @csrf
                    <label class="form-label small fw-semibold">Nama penerima <span class="text-muted">(boleh dikosongkan)</span></label>
                    <input type="text" name="received_by_name" class="form-control form-control-lg mb-3"
                           maxlength="100" placeholder="Nama orang yang menerima barang">

                    <div class="d-grid">
                        <button class="btn btn-success btn-lg rounded-4 py-3 fw-bold"
                                onclick="return confirm('Konfirmasi bahwa barang sudah sampai di tujuan?');">
                            <i class="bi bi-check-lg me-2"></i>Barang Sudah Sampai
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <p class="text-center text-muted small mt-4 mb-0">
        Tautan ini khusus untuk pengiriman di atas. Jangan dibagikan.
    </p>
</div>
</body>
</html>
