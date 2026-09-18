<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permintaan Terkirim — Berger Paints</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <style>
        body { background: #f4f6fa; }
        .kartu { max-width: 520px; }
    </style>
</head>
<body class="py-5 px-3">
@include('partials.pemuat')
{{-- Pemohon tidak punya layar lain untuk kembali, jadi halaman ini harus
     menjawab sendiri tiga hal: nomornya berapa, sekarang menunggu siapa, dan
     ia akan tahu dari mana kalau barangnya sudah siap. --}}
<div class="kartu mx-auto text-center">
    <i class="bi bi-check-circle-fill text-success" style="font-size:3.5rem"></i>
    <h5 class="fw-bold mt-3">Permintaan terkirim</h5>

    <div class="card border-0 shadow-sm rounded-4 mt-4 text-start">
        <div class="card-body">
            <div class="text-muted small">Nomor permintaan</div>
            <div class="fs-4 fw-bold font-monospace">{{ $mrf->mrf_number }}</div>

            <hr>

            <p class="small mb-2">
                Sekarang menunggu persetujuan <strong>{{ $mrf->approver_name }}</strong>,
                yang sudah dikirimi tautannya lewat WhatsApp.
            </p>
            <p class="small mb-2">
                Setelah disetujui, Logistik memastikan ketersediaan barangnya dan menyiapkannya.
            </p>
            <p class="small mb-0">
                Anda akan dikabari lewat <strong>WhatsApp</strong> saat barangnya sudah bisa diambil,
                beserta tempat pengambilannya. Bawa nomor di atas saat mengambil.
            </p>
        </div>
    </div>

    <a href="{{ route('mrf.minta.show', $tautan->token) }}" class="btn btn-link mt-3 text-decoration-none">
        Ajukan permintaan lain
    </a>
</div>
</body>
</html>
