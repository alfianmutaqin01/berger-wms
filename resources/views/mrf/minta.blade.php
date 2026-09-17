<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permintaan Material — Berger Paints</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <style>
        body { background: #f4f6fa; }
        .kartu { max-width: 620px; }
    </style>
</head>
<body class="py-4 px-3">
{{-- FORMULIR DIVISI TANPA AKUN — TANPA LOGIN.

     Dibuka QC atau R&D dari tautan yang diberikan sekali, sering dari HP.
     Aturannya sama dengan halaman persetujuan atasan:

       - berdiri sendiri, tidak memakai layout WMS (tidak ada sidebar, tidak
         ada menu, tidak ada yang bisa salah tekan)
       - satu hal yang bisa dilakukan: mengajukan permintaan. Tidak ada daftar
         permintaan lama, tidak ada milik orang lain
       - tidak menampilkan isi gudang, stok, atau harga. Halaman ini terbuka
         ke internet.

     Yang menahan halaman ini kalau tautannya bocor bukan kerahasiaan
     alamatnya, melainkan dua pintu persetujuan yang tetap harus dilewati —
     atasan divisi lewat WhatsApp, lalu Logistik. Keduanya bukan pengisi. --}}

<div class="kartu mx-auto">
    <div class="text-center mb-4">
        <h5 class="fw-bold mb-0">Berger Paints Indonesia</h5>
        <small class="text-muted">Permintaan Material — {{ $tautan->department?->name }}</small>
    </div>

    @if(session('error'))
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <strong>Formulirnya belum bisa dikirim:</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $galat)<li>{{ $galat }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('mrf.minta.store', $tautan->token) }}" id="formMinta">
        @csrf

        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-person-badge me-2"></i>Pemohon</h6>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted" for="nama">Nama Anda <span class="text-danger">*</span></label>
                        <input type="text" name="requester_name" id="nama" maxlength="100" required
                               value="{{ old('requester_name') }}" class="form-control rounded-3"
                               placeholder="Nama yang akan tertulis di dokumen">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted" for="hp">Nomor WhatsApp Anda <span class="text-danger">*</span></label>
                        <input type="text" name="requester_phone" id="hp" maxlength="25" required
                               value="{{ old('requester_phone') }}" class="form-control rounded-3 font-monospace"
                               placeholder="081234567890">
                        <div class="form-text">
                            Ke sinilah kabar <strong>"barang sudah bisa diambil"</strong> dikirim.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Divisi</label>
                        <input type="text" class="form-control rounded-3 bg-light" disabled
                               value="{{ $tautan->department?->name }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted">Gudang</label>
                        <input type="text" class="form-control rounded-3 bg-light" disabled
                               value="{{ $tautan->warehouse?->kode_pendek }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-clipboard2-check me-2"></i>Permintaan</h6>

                <div class="mb-3">
                    <label class="form-label">Jenis permintaan <span class="text-danger">*</span></label>
                    @foreach($jenisOptions as $nilai => $jenis)
                        <div class="form-check">
                            <input type="radio" class="form-check-input" name="request_type" required
                                   id="jenis-{{ $nilai }}" value="{{ $nilai }}"
                                   @checked(old('request_type') === $nilai)>
                            <label class="form-check-label" for="jenis-{{ $nilai }}">
                                <span class="fw-semibold">{{ $jenis['label'] }}</span>
                                <small class="text-muted d-block">{{ $jenis['bantuan'] }}</small>
                            </label>
                        </div>
                    @endforeach
                </div>

                <div>
                    <label class="form-label" for="keperluan">Keperluan <span class="text-danger">*</span></label>
                    <textarea name="purpose" id="keperluan" rows="3" class="form-control rounded-3" required
                              placeholder="Mis. Uji tahan cuaca untuk klaim pelanggan PT ABC.">{{ old('purpose') }}</textarea>
                    <div class="form-text">
                        Ditulis untuk orang yang membacanya enam bulan lagi, bukan untuk yang sudah tahu.
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-box-seam me-2"></i>Barang yang Diminta</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-3" id="tambahBaris">
                        <i class="bi bi-plus-lg me-1"></i> Tambah
                    </button>
                </div>

                <div id="barisProduk"></div>

                <div class="text-muted small mt-2" id="kosongProduk">
                    Ketik minimal dua huruf SKU atau nama produk.
                </div>
            </div>
        </div>

        @unless($tautan->atasanTerkunci())
            {{-- Hanya muncul bila Manager BELUM menetapkan atasannya di tautan.
                 Kalau sudah, isian ini tidak ada sama sekali — bukan sekadar
                 disembunyikan — supaya tidak ada yang bisa menyebutkan atasan
                 lain lewat permintaan HTTP langsung. --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-whatsapp me-2 text-success"></i>Atasan yang Menyetujui</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted" for="atasanNama">Nama <span class="text-danger">*</span></label>
                            <input type="text" name="approver_name" id="atasanNama" maxlength="100" required
                                   value="{{ old('approver_name') }}" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted" for="atasanHp">Nomor WhatsApp <span class="text-danger">*</span></label>
                            <input type="text" name="approver_phone" id="atasanHp" maxlength="25" required
                                   value="{{ old('approver_phone') }}" class="form-control rounded-3 font-monospace"
                                   placeholder="081234567890">
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="alert alert-light border shadow-sm rounded-3 small">
                <i class="bi bi-shield-check me-2"></i>
                Permintaan ini akan dikirim ke <strong>{{ $tautan->approver_name }}</strong> untuk disetujui.
            </div>
        @endunless

        <div class="d-grid">
            <button class="btn btn-primary btn-lg rounded-3">
                <i class="bi bi-send me-1"></i> Kirim Permintaan
            </button>
        </div>

        <p class="text-muted small text-center mt-3 mb-0">
            Permintaan ini belum menggerakkan barang apa pun. Ia baru diproses setelah disetujui atasan
            dan dipastikan ketersediaannya oleh Logistik.
        </p>
    </form>
</div>

<template id="templateBaris">
    <div class="row g-2 align-items-start mb-2 baris-produk">
        <div class="col-12 col-md-6">
            <div class="cari-produk position-relative">
                <input type="text" class="form-control form-control-sm rounded-3 cari-teks"
                       placeholder="Ketik SKU atau nama produk…" autocomplete="off">
                <input type="hidden" class="cari-nilai" name="items[__I__][product_id]">
                <div class="list-group cari-saran d-none position-absolute w-100 shadow"
                     style="z-index:20;max-height:240px;overflow:auto"></div>
            </div>
        </div>
        <div class="col-5 col-md-2">
            <input type="number" name="items[__I__][qty]" class="form-control form-control-sm rounded-3"
                   min="1" placeholder="Qty" required>
        </div>
        <div class="col-6 col-md-3">
            <input type="text" name="items[__I__][note]" class="form-control form-control-sm rounded-3"
                   maxlength="500" placeholder="Catatan (opsional)">
        </div>
        <div class="col-1 text-end">
            <button type="button" class="btn btn-sm btn-link text-danger p-0 hapusBaris" title="Hapus baris">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>
</template>

@include('partials.pencarian-ketik')
<script>
(function () {
    const wadah = document.getElementById('barisProduk');
    const template = document.getElementById('templateBaris');
    const kosong = document.getElementById('kosongProduk');
    let urut = 0;

    function perbaruiKosong() {
        kosong.classList.toggle('d-none', wadah.children.length > 0);
    }

    function tambahBaris() {
        const pembungkus = document.createElement('div');
        pembungkus.innerHTML = template.innerHTML.replaceAll('__I__', String(urut++));
        const baris = pembungkus.firstElementChild;

        wadah.appendChild(baris);

        window.pasangPencarian(baris.querySelector('.cari-produk'), {
            url: function (q) {
                return '{{ route('mrf.minta.produk', $tautan->token) }}?q=' + encodeURIComponent(q);
            },
            minimal: 2,
            kosong: 'Produk tidak ketemu. Coba potongan SKU-nya.',
            tampilan: function (item) {
                return '<span class="fw-semibold font-monospace">' + item.sku + '</span>'
                    + '<span class="small text-muted d-block">' + item.name + ' · ' + (item.uom || '-') + '</span>';
            },
            label: function (item) { return item.sku + ' — ' + item.name; },
        });

        baris.querySelector('.hapusBaris').addEventListener('click', function () {
            baris.remove();
            perbaruiKosong();
        });

        perbaruiKosong();
    }

    document.getElementById('tambahBaris').addEventListener('click', tambahBaris);
    tambahBaris();

    // Baris yang SKU-nya belum dipilih tidak boleh ikut terkirim: qty-nya
    // terisi tetapi produknya kosong, dan servernya menolak seluruh formulir
    // dengan pesan yang tidak menunjuk baris mana yang salah.
    document.getElementById('formMinta').addEventListener('submit', function (e) {
        let adaYangSah = false;

        wadah.querySelectorAll('.baris-produk').forEach(function (baris) {
            if (baris.querySelector('.cari-nilai').value === '') {
                baris.remove();

                return;
            }

            adaYangSah = true;
        });

        if (! adaYangSah) {
            e.preventDefault();
            perbaruiKosong();
            alert('Pilih dulu minimal satu produk dari daftar yang muncul saat mengetik.');
        }
    });
})();
</script>
</body>
</html>
