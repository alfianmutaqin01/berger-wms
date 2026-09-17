<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persetujuan Permintaan Material — Berger Paints</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <style>
        body { background: #f4f6fa; }
        .kartu { max-width: 560px; }
    </style>
</head>
<body class="py-4 px-3">
{{-- HALAMAN ATASAN — TANPA LOGIN.

     Dibuka dari WhatsApp, di HP, sering sambil berjalan di lantai produksi.
     Karena itu:

       - berdiri sendiri, tidak memakai layout WMS (tidak ada sidebar, tidak
         ada menu, tidak ada yang bisa salah tekan)
       - dua tombol besar, dua keputusan, tidak ada yang ketiga
       - hanya menampilkan yang perlu untuk MEMUTUSKAN: siapa meminta, apa,
         berapa, untuk keperluan apa. Bukan isi gudang, bukan stok, bukan
         permintaan orang lain. Halaman ini terbuka ke internet. --}}

<div class="kartu mx-auto">
    <div class="text-center mb-4">
        <h5 class="fw-bold mb-0">Berger Paints Indonesia</h5>
        <small class="text-muted">Persetujuan Permintaan Material</small>
    </div>

    @foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
        @if(session($jenis))
        <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} border-0 rounded-4 shadow-sm">
            <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        </div>
        @endif
    @endforeach

    @if($errors->any())
    <div class="alert alert-danger border-0 rounded-4 shadow-sm">
        @foreach($errors->all() as $galat)<div>{{ $galat }}</div>@endforeach
    </div>
    @endif

    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="font-monospace fw-bold fs-5">{{ $mrf->mrf_number }}</div>
                    <small class="text-muted">{{ $mrf->created_at->format('d F Y, H:i') }}</small>
                </div>
                <span class="badge {{ $mrf->status_badge }}">{{ $mrf->status_label }}</span>
            </div>

            <dl class="row mb-0 small">
                <dt class="col-4 text-muted fw-normal">Pemohon</dt>
                <dd class="col-8">
                    {{ $mrf->nama_pemohon }}
                    @if($mrf->department_name)
                        <span class="text-muted">({{ $mrf->department_name }})</span>
                    @endif
                </dd>

                <dt class="col-4 text-muted fw-normal">Gudang</dt>
                <dd class="col-8">{{ $mrf->warehouse?->name ?? '—' }}</dd>

                <dt class="col-4 text-muted fw-normal">Jenis</dt>
                <dd class="col-8">{{ $mrf->jenis_label }}</dd>

                <dt class="col-4 text-muted fw-normal">Keperluan</dt>
                <dd class="col-8">{{ $mrf->purpose }}</dd>
            </dl>

            <hr class="my-3">

            <div class="fw-semibold small mb-2">Barang yang diminta</div>
            <ul class="list-group list-group-flush">
                @foreach($mrf->items as $item)
                <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="font-monospace small fw-semibold">{{ $item->product?->sku }}</div>
                        <div class="small text-muted">{{ $item->product?->name }}</div>
                        @if(filled($item->note))
                            <div class="small text-primary">{{ $item->note }}</div>
                        @endif
                    </div>
                    <div class="text-end text-nowrap">
                        <span class="fw-bold">{{ number_format($item->qty_requested) }}</span>
                        <span class="small text-muted">{{ $item->product?->uom }}</span>
                    </div>
                </li>
                @endforeach
            </ul>
        </div>
    </div>

    @if($mrf->status === \App\Models\MaterialRequisition::STATUS_PENDING_APPROVAL)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <form method="POST" action="{{ route('mrf.approval.approve', request()->route('token')) }}" class="card-body p-4">
                @csrf
                <label class="form-label small text-muted">Catatan (boleh dikosongkan)</label>
                <textarea name="note" rows="2" class="form-control rounded-3 mb-3" maxlength="500"
                          placeholder="Mis. Setuju, tapi ambil dulu yang batch lama."></textarea>

                <button class="btn btn-success btn-lg w-100 rounded-4 py-3">
                    <i class="bi bi-check2-circle me-2"></i> SETUJUI
                </button>
            </form>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <form method="POST" action="{{ route('mrf.approval.reject', request()->route('token')) }}" class="card-body p-4">
                @csrf
                <label class="form-label small text-muted">
                    Alasan penolakan <span class="text-danger">*</span>
                </label>
                <textarea name="reason" rows="2" class="form-control rounded-3 mb-3" required minlength="5" maxlength="500"
                          placeholder="Wajib diisi, supaya Produksi tahu apa yang harus diperbaiki."></textarea>

                <button class="btn btn-outline-danger w-100 rounded-4 py-2">
                    <i class="bi bi-x-circle me-2"></i> Tolak
                </button>
            </form>
        </div>
    @else
        {{-- Sudah diputus. Tombolnya HILANG, bukan sekadar tidak aktif: tautan
             yang sama bisa dibuka lagi berhari-hari kemudian, dan tombol yang
             masih terlihat membuat orang menekannya lalu menerima galat yang
             tidak ia mengerti. --}}
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4 text-center">
                <i class="bi bi-check2-all fs-1 text-muted opacity-50 d-block mb-2"></i>
                <p class="mb-1">Permintaan ini sudah diputus dan tidak menunggu apa-apa lagi dari Anda.</p>
                <div class="small text-muted">Keadaan sekarang: <strong>{{ $mrf->status_label }}</strong></div>

                @if($mrf->status === \App\Models\MaterialRequisition::STATUS_REJECTED_APPROVAL && filled($mrf->approver_rejection_reason))
                    <div class="small text-muted mt-2">Alasan penolakan: {{ $mrf->approver_rejection_reason }}</div>
                @endif
            </div>
        </div>
    @endif

    <p class="text-center text-muted small mt-4 mb-0">
        Tautan ini pribadi. Jangan diteruskan ke orang lain — siapa pun yang membukanya bisa memutuskan
        permintaan ini atas nama Anda.
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
</body>
</html>
