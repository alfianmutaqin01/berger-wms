@extends('layouts.wms')

@section('title', 'Buat Permintaan Material')
@section('page_title', 'Buat Permintaan Material (MRF)')

@section('content')
{{-- FORMULIR PRODUKSI.

     Menggantikan formulir kertas yang selama ini dipakai. Yang ditambahkan
     dibanding kertasnya cuma dua, dan keduanya menjawab keluhan yang sudah
     ada sejak lama: keperluan WAJIB ditulis (kertas sering kosong di bagian
     ini), dan nomor atasan disimpan supaya tidak diketik ulang tiap kali. --}}

@foreach(['success' => 'check-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Formulirnya belum bisa disimpan:</strong>
    <ul class="mb-0 mt-2">
        @foreach($errors->all() as $galat)<li>{{ $galat }}</li>@endforeach
    </ul>
</div>
@endif

<form method="POST" action="{{ route('wms.mrf.store') }}" id="formMrf">
    @csrf

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-person-badge me-2"></i>Pemohon</h6>

                    {{-- Dibaca dari akun, TIDAK diisi sendiri. Pemohon yang bisa
                         mengetik namanya sendiri berarti dokumen ini berhenti bisa
                         menjawab siapa yang benar-benar meminta. --}}
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Nama</label>
                            <input type="text" class="form-control rounded-3 bg-light" value="{{ auth()->user()->full_name }}" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Departemen</label>
                            <input type="text" class="form-control rounded-3 bg-light"
                                   value="{{ auth()->user()->department?->name ?? 'Belum diisi di data akun' }}" disabled>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-clipboard2-check me-2"></i>Permintaan</h6>

                    <div class="mb-3">
                        <label class="form-label">Gudang yang diminta <span class="text-danger">*</span></label>
                        @if($gudang)
                            <input type="hidden" name="warehouse_id" value="{{ $gudang->id }}">
                            <input type="text" class="form-control rounded-3 bg-light" value="{{ $gudang->code }} — {{ $gudang->name }}" disabled>
                        @else
                            <select name="warehouse_id" class="form-select rounded-3" required>
                                <option value="">Pilih gudang…</option>
                                @foreach($gudangOptions as $g)
                                    <option value="{{ $g->id }}" @selected(old('warehouse_id') == $g->id)>{{ $g->code }} — {{ $g->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Type of Requisition <span class="text-danger">*</span></label>
                        <div class="row g-2">
                            @foreach($jenisOptions as $nilai => $jenis)
                            <div class="col-12 col-md-6">
                                <input type="radio" class="btn-check" name="request_type" id="jenis-{{ $nilai }}"
                                       value="{{ $nilai }}" @checked(old('request_type') === $nilai) required>
                                <label class="btn btn-outline-secondary w-100 text-start rounded-3 py-2" for="jenis-{{ $nilai }}">
                                    <span class="fw-semibold d-block">{{ $jenis['label'] }}</span>
                                    <small class="text-muted">{{ $jenis['bantuan'] }}</small>
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Purpose / Keperluan <span class="text-danger">*</span></label>
                        <textarea name="purpose" rows="3" class="form-control rounded-3" required
                                  placeholder="Mis. Reproses 300 pcs DDP batch Juli menjadi warna Off White untuk stok ulang.">{{ old('purpose') }}</textarea>
                        <div class="form-text">
                            Inilah satu-satunya keterangan yang menjelaskan kenapa barang keluar dari gudang.
                            Ditulis untuk orang yang membacanya enam bulan lagi, bukan untuk yang sudah tahu.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0"><i class="bi bi-box-seam me-2"></i>Barang yang Diminta</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-3" id="tambahBaris">
                            <i class="bi bi-plus-lg me-1"></i> Tambah Baris
                        </button>
                    </div>

                    {{-- Batch TIDAK dipilih di sini. Produksi tidak melihat isi rak
                         dan tidak perlu melihatnya; yang menerjemahkan permintaan ini
                         menjadi batch sungguhan adalah Logistik, yang berdiri di depan
                         raknya. Yang bisa ditulis Produksi cuma usulan di kolom
                         keterangan — mis. "batch DDP Juli". --}}
                    <div id="barisProduk"></div>

                    <div class="text-muted small mt-2" id="kosongProduk">
                        Belum ada baris. Tekan <strong>Tambah Baris</strong> lalu ketik minimal dua huruf SKU atau nama produk.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-whatsapp me-2 text-success"></i>Persetujuan Atasan</h6>

                    <p class="small text-muted">
                        Setelah disimpan, tautan persetujuan dikirim ke nomor WhatsApp di bawah ini.
                        Logistik baru bisa memprosesnya setelah atasan menekan Setuju.
                    </p>

                    @if($kontak->isNotEmpty())
                    <label class="form-label small text-muted">Nomor tersimpan — tinggal klik</label>
                    <div class="list-group mb-3 rounded-3">
                        @foreach($kontak as $k)
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2 py-2">
                            <button type="button" class="btn btn-link p-0 text-start text-decoration-none flex-grow-1 pilihKontak"
                                    data-nama="{{ $k->name }}" data-nomor="{{ $k->phone }}">
                                <span class="fw-semibold d-block text-body">{{ $k->name }}</span>
                                <small class="text-muted font-monospace">{{ $k->phone_label }}</small>
                            </button>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 hapusKontak"
                                    data-action="{{ route('wms.mrf.contacts.destroy', $k) }}"
                                    data-nama="{{ $k->name }}" title="Hapus dari daftar tersimpan">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        @endforeach
                    </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label">Nama atasan <span class="text-danger">*</span></label>
                        <input type="text" name="approver_name" id="approverNama" class="form-control rounded-3"
                               value="{{ old('approver_name') }}" maxlength="100" required placeholder="Mis. Pak Ganti">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nomor WhatsApp <span class="text-danger">*</span></label>
                        <input type="text" name="approver_phone" id="approverNomor" class="form-control rounded-3 font-monospace"
                               value="{{ old('approver_phone') }}" maxlength="25" required placeholder="081234567890">
                        <div class="form-text">Satu nomor saja. Boleh ditulis 08… atau 62….</div>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" name="simpan_kontak" value="1" class="form-check-input" id="simpanKontak"
                               @checked(old('simpan_kontak'))>
                        <label class="form-check-label" for="simpanKontak">
                            Simpan nomor ini
                            <small class="d-block text-muted">
                                Lain kali tinggal diklik dari daftar di atas. Nomor yang sama tidak akan tersimpan dua kali.
                            </small>
                        </label>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary rounded-3 py-2">
                    <i class="bi bi-send me-1"></i> Simpan &amp; Minta Persetujuan
                </button>
                <a href="{{ route('wms.mrf.index') }}" class="btn btn-link text-decoration-none">Batal</a>
            </div>
        </div>
    </div>
</form>

{{-- Formulir hapus kontak berdiri di luar formulir MRF: formulir di dalam
     formulir tidak sah di HTML, dan browser menanganinya dengan cara yang
     berbeda-beda — salah satunya mengirimkan permintaan hapus bersama seluruh
     isian MRF yang belum selesai. --}}
<form method="POST" id="formHapusKontak" class="d-none">
    @csrf
    @method('DELETE')
</form>

<template id="templateBaris">
    <div class="row g-2 align-items-start mb-2 baris-produk">
        <div class="col-12 col-md-6">
            <div class="cari-produk position-relative">
                <input type="text" class="form-control form-control-sm rounded-3 cari-teks" placeholder="Ketik SKU atau nama produk…" autocomplete="off">
                <input type="hidden" class="cari-nilai" name="items[__I__][product_id]">
                <div class="list-group cari-saran d-none position-absolute w-100 shadow" style="z-index:20;max-height:240px;overflow:auto"></div>
            </div>
        </div>
        <div class="col-5 col-md-2">
            <input type="number" name="items[__I__][qty]" class="form-control form-control-sm rounded-3" min="1" placeholder="Qty" required>
        </div>
        <div class="col-6 col-md-3">
            <input type="text" name="items[__I__][note]" class="form-control form-control-sm rounded-3" maxlength="500" placeholder="Usulan batch (opsional)">
        </div>
        <div class="col-1 text-end">
            <button type="button" class="btn btn-sm btn-link text-danger p-0 hapusBaris" title="Hapus baris">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>
</template>
@endsection

@push('scripts')
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
        const html = template.innerHTML.replaceAll('__I__', String(urut++));
        const pembungkus = document.createElement('div');
        pembungkus.innerHTML = html;
        const baris = pembungkus.firstElementChild;

        wadah.appendChild(baris);

        window.pasangPencarian(baris.querySelector('.cari-produk'), {
            url: function (q) {
                return '{{ route('wms.mrf.lookup.products') }}?q=' + encodeURIComponent(q);
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

    // Satu baris langsung disiapkan: formulir yang dibuka dan masih kosong
    // menuntut satu ketukan tambahan sebelum bisa dipakai, setiap kali.
    tambahBaris();

    document.querySelectorAll('.pilihKontak').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            document.getElementById('approverNama').value = tombol.dataset.nama;
            document.getElementById('approverNomor').value = tombol.dataset.nomor;
            // Nomor yang dipilih dari daftar sudah tersimpan; mencentangnya
            // lagi cuma menulis ulang baris yang sama.
            document.getElementById('simpanKontak').checked = false;
        });
    });

    document.querySelectorAll('.hapusKontak').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            if (!confirm('Hapus ' + tombol.dataset.nama + ' dari daftar nomor tersimpan?')) return;
            const form = document.getElementById('formHapusKontak');
            form.action = tombol.dataset.action;
            form.submit();
        });
    });

    // Baris yang SKU-nya belum dipilih tidak boleh ikut terkirim: qty-nya
    // terisi tetapi produknya kosong, dan servernya menolak seluruh formulir
    // dengan pesan yang tidak menunjuk baris mana yang salah.
    document.getElementById('formMrf').addEventListener('submit', function (e) {
        let adaYangSah = false;

        wadah.querySelectorAll('.baris-produk').forEach(function (baris) {
            const produk = baris.querySelector('.cari-nilai');
            const qty = baris.querySelector('input[type=number]');

            if (!produk.value) {
                baris.remove();
                return;
            }
            if (qty.value) adaYangSah = true;
        });

        if (!adaYangSah) {
            e.preventDefault();
            alert('Belum ada produk yang dipilih. Ketik SKU-nya lalu pilih dari daftar saran.');
        }
    });
})();
</script>
@endpush
