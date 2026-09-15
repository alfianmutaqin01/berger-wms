<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Pengiriman — Berger Paints</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
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
                @error('photo')
                    <div class="alert alert-danger border-0 rounded-4">
                        <i class="bi bi-camera-fill me-2"></i>{{ $message }}
                    </div>
                @enderror

                {{-- FOTO DULU, TOMBOL KEMUDIAN — Fase 12.

                     Sebelum ini tombolnya berdiri sendiri: menekannya sudah
                     cukup untuk membuat pengiriman tercatat sampai, tanpa
                     lampiran apa pun. Tidak ada yang membedakan barang yang
                     benar-benar diterima pelanggan dari barang yang masih ada
                     di bak mobil, selain perkataan supir.

                     KAMERA DIBUKA DI DALAM HALAMAN, bukan lewat pemilih
                     berkas: yang diinginkan foto keadaan saat itu, bukan
                     gambar mana pun dari galeri. Tombol kirim TETAP MATI
                     sampai ada fotonya — bukan menampilkan galat setelah
                     ditekan, karena supir sudah telanjur mengira selesai. --}}
                <form method="POST" action="{{ route('epod.confirm', $note->epod_token) }}"
                      enctype="multipart/form-data" id="formSampai">
                    @csrf
                    <input type="hidden" name="photo_source" id="asalFoto" value="file">

                    <label class="form-label small fw-semibold">
                        Foto barang di lokasi <span class="text-danger">*</span>
                    </label>

                    <div class="border rounded-4 overflow-hidden mb-2 bg-dark position-relative"
                         id="kotakKamera" style="aspect-ratio: 4/3;">
                        <video id="kamera" class="w-100 h-100" style="object-fit: cover;"
                               autoplay playsinline muted></video>
                        <canvas id="kanvas" class="d-none"></canvas>
                        <img id="pratinjau" class="w-100 h-100 d-none" style="object-fit: cover;" alt="">

                        <div id="kameraMati"
                             class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column
                                    align-items-center justify-content-center text-white-50 p-3 text-center">
                            <i class="bi bi-camera fs-1 mb-2"></i>
                            <small>Menyiapkan kamera…</small>
                        </div>
                    </div>

                    <div class="d-grid gap-2 mb-3">
                        <button type="button" class="btn btn-outline-dark btn-lg rounded-4 py-2" id="tombolJepret" disabled>
                            <i class="bi bi-camera-fill me-2"></i>Ambil Foto
                        </button>
                        <button type="button" class="btn btn-link btn-sm text-decoration-none d-none" id="tombolUlang">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Ambil ulang
                        </button>
                    </div>

                    {{-- JALUR CADANGAN. Kamera dalam halaman hanya hidup di
                         koneksi aman dan setelah supir mengizinkannya. Tanpa
                         jalur ini, satu penolakan izin membuat barang yang
                         sudah diterima pelanggan menggantung selamanya di
                         status "dalam pengiriman" — itu kerusakan, bukan
                         pengerasan. `capture` tetap mengarahkan HP ke kamera
                         belakang, dan barisnya ditandai berasal dari berkas. --}}
                    <div class="d-none" id="kotakCadangan">
                        <div class="alert alert-warning border-0 rounded-4 small">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            <span id="alasanCadangan">Kamera tidak bisa dibuka di peramban ini.</span>
                            Ambil foto lewat aplikasi kamera HP Anda.
                        </div>
                        <input type="file" name="photo" id="fotoCadangan" class="form-control form-control-lg mb-3"
                               accept="image/*" capture="environment">
                    </div>

                    <label class="form-label small fw-semibold">Nama penerima <span class="text-muted">(boleh dikosongkan)</span></label>
                    <input type="text" name="received_by_name" class="form-control form-control-lg mb-3"
                           maxlength="100" placeholder="Nama orang yang menerima barang">

                    <div class="d-grid">
                        <button class="btn btn-success btn-lg rounded-4 py-3 fw-bold" id="tombolKirim" disabled
                                onclick="return confirm('Konfirmasi bahwa barang sudah sampai di tujuan?');">
                            <i class="bi bi-check-lg me-2"></i>Barang Sudah Sampai
                        </button>
                    </div>
                    <p class="text-center text-muted small mt-2 mb-0" id="petunjukKirim">
                        Ambil fotonya dulu untuk mengaktifkan tombol ini.
                    </p>
                </form>
            @endif
        </div>
    </div>

    <p class="text-center text-muted small mt-4 mb-0">
        Tautan ini khusus untuk pengiriman di atas. Jangan dibagikan.
    </p>
</div>

@if($note->status !== \App\Models\DeliveryNote::STATUS_DELIVERED)
<script>
(function () {
    var video     = document.getElementById('kamera');
    var kanvas    = document.getElementById('kanvas');
    var pratinjau = document.getElementById('pratinjau');
    var kotakKam  = document.getElementById('kotakKamera');
    var kamMati   = document.getElementById('kameraMati');
    var jepret    = document.getElementById('tombolJepret');
    var ulang     = document.getElementById('tombolUlang');
    var cadangan  = document.getElementById('kotakCadangan');
    var alasan    = document.getElementById('alasanCadangan');
    var berkas    = document.getElementById('fotoCadangan');
    var asal      = document.getElementById('asalFoto');
    var kirim     = document.getElementById('tombolKirim');
    var petunjuk  = document.getElementById('petunjukKirim');

    var aliran = null;

    // SATU input berkas untuk DUA jalur. Kamera dalam halaman mengisi input
    // yang sama dengan yang dipakai jalur cadangan, jadi tidak pernah ada dua
    // medan bernama "photo" yang membuat server harus menebak mana yang
    // dimaksud.
    function siapKirim(siap) {
        kirim.disabled = !siap;
        petunjuk.classList.toggle('d-none', siap);
    }

    function keCadangan(pesan) {
        if (pesan) { alasan.textContent = pesan; }
        kotakKam.classList.add('d-none');
        jepret.classList.add('d-none');
        ulang.classList.add('d-none');
        cadangan.classList.remove('d-none');
        asal.value = 'file';
    }

    berkas.addEventListener('change', function () {
        // Diisi tangan lewat pemilih berkas: asalnya jujur ditandai 'file',
        // karena bukti dari galeri memang tidak sekuat jepretan saat itu.
        if (berkas.files.length > 0 && asal.value !== 'camera') { asal.value = 'file'; }
        siapKirim(berkas.files.length > 0);
    });

    function mulaiKamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            // Paling sering karena halamannya dibuka lewat http biasa:
            // peramban hanya mengizinkan kamera pada koneksi aman.
            keCadangan(location.protocol === 'https:'
                ? 'Peramban ini tidak mendukung kamera di dalam halaman.'
                : 'Kamera hanya bisa dibuka lewat koneksi aman (https).');
            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },
            audio: false
        }).then(function (s) {
            aliran = s;
            video.srcObject = s;
            kamMati.classList.add('d-none');
            jepret.disabled = false;
        }).catch(function () {
            keCadangan('Izin kamera belum diberikan.');
        });
    }

    jepret.addEventListener('click', function () {
        if (!video.videoWidth) { return; }

        // Dikecilkan ke maksimal 1600px. Supir sering berada di sinyal
        // seadanya; foto 12 MP yang gagal terkirim sama tidak bergunanya
        // dengan tidak ada foto sama sekali.
        var skala = Math.min(1, 1600 / video.videoWidth);
        kanvas.width  = Math.round(video.videoWidth * skala);
        kanvas.height = Math.round(video.videoHeight * skala);
        kanvas.getContext('2d').drawImage(video, 0, 0, kanvas.width, kanvas.height);

        kanvas.toBlob(function (blob) {
            if (!blob) { keCadangan('Foto gagal diambil dari kamera.'); return; }

            var file = new File([blob], 'bukti-sampai.jpg', { type: 'image/jpeg' });
            var dt = new DataTransfer();
            dt.items.add(file);
            berkas.files = dt.files;

            asal.value = 'camera';
            pratinjau.src = URL.createObjectURL(blob);
            pratinjau.classList.remove('d-none');
            video.classList.add('d-none');
            jepret.classList.add('d-none');
            ulang.classList.remove('d-none');
            siapKirim(true);
        }, 'image/jpeg', 0.8);
    });

    ulang.addEventListener('click', function () {
        berkas.value = '';
        asal.value = 'file';
        pratinjau.classList.add('d-none');
        video.classList.remove('d-none');
        jepret.classList.remove('d-none');
        ulang.classList.add('d-none');
        siapKirim(false);
    });

    // Kamera dilepas begitu halamannya ditinggalkan. Lampu kamera yang tetap
    // menyala setelah selesai membuat orang curiga aplikasinya merekam diam-
    // diam — dan kecurigaan itu wajar.
    window.addEventListener('pagehide', function () {
        if (aliran) { aliran.getTracks().forEach(function (t) { t.stop(); }); }
    });

    siapKirim(false);
    mulaiKamera();
})();
</script>
@endif
</body>
</html>
