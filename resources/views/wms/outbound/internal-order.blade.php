@extends('layouts.wms')

@section('title', 'Buat Pesanan')
@section('page_title', 'Buat Pesanan Atas Nama Sales')

@section('content')
{{-- JALUR INTERNAL — Admin & Manager.

     Portal Sales tetap tertutup untuk peran Warehouse/Admin (PRD §5.2). Ini
     pintu terpisah, dan bedanya dikatakan terang-terangan di layar: pesanan
     yang dibuat di sini tetap MILIK seorang Sales, dan nama yang mengetiknya
     ikut tercatat. Yang memakainya harus tahu itu sebelum menekan simpan,
     bukan menemukannya belakangan. --}}

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Ada isian yang perlu diperbaiki:</strong>
    <ul class="mb-0 mt-2 small">
        @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
    </ul>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
</div>
@endif

<div class="alert alert-warning border-0 rounded-4 d-flex gap-3 align-items-start">
    <i class="bi bi-person-badge fs-4 mt-1"></i>
    <div class="small">
        <strong class="d-block mb-1">Pesanan ini akan tercatat atas nama Sales yang Anda pilih.</strong>
        Dialah yang melihatnya di daftar pesanannya, yang mengunggah foto Surat Jalan
        bertanda tangan nanti, dan yang angkanya masuk laporan Kinerja Sales.
        Nama Anda tersimpan terpisah sebagai pembuatnya, dan Sales tersebut
        <strong>menerima pemberitahuan</strong> begitu pesanannya dikirim.
    </div>
</div>

@unless($cutoffOpen)
<div class="alert alert-danger border-0 rounded-4 small">
    <i class="bi bi-clock-history me-2"></i>
    Batas jam pemesanan ({{ $cutoffLabel }}) sudah lewat. Draft masih bisa disimpan,
    tetapi pengirimannya ke antrean baru bisa dilakukan besok — batas ini berlaku sama
    untuk jalur internal, karena gudang menyusun rencana picking dari situ.
</div>
@endunless

<form method="POST" action="{{ route('wms.internal-order.store') }}" enctype="multipart/form-data" id="formPesanan">
    @csrf
    <input type="hidden" name="action" id="aksiForm" value="submit">
    <input type="hidden" name="order_source" id="sourceManual" value="manual">

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            {{-- ---------------------------------------------- Siapa & di mana --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-0 pt-3 px-4">
                    <h6 class="fw-bold mb-0"><i class="bi bi-person-vcard text-primary me-2"></i>Atas Nama & Tujuan</h6>
                </div>
                <div class="card-body px-4 pt-2">
                    <div class="row g-3">
                        @if($bolehPilihGudang)
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Gudang <span class="text-danger">*</span></label>
                                {{-- Hanya untuk akun lintas gudang. Super Admin tidak
                                     punya gudang sama sekali, jadi tanpa kolom ini
                                     pesanannya tidak bisa dibuat sama sekali. --}}
                                <select name="warehouse_id" id="pilihGudang" class="form-select" required>
                                    @foreach($gudangPilihan as $w)
                                        <option value="{{ $w->id }}" @selected((int) $gudangTerpilih === $w->id)>
                                            {{ $w->display_label ?? $w->code.' — '.$w->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">Menentukan Sales mana yang bisa dipilih dan pelanggan mana yang terlayani.</div>
                            </div>
                        @endif

                        <div class="col-12 {{ $bolehPilihGudang ? 'col-md-6' : '' }}">
                            <label class="form-label small fw-semibold">Atas nama Sales <span class="text-danger">*</span></label>
                            <select name="sales_user_id" class="form-select" required>
                                <option value="">— Pilih Sales —</option>
                                @foreach($salesTersedia as $s)
                                    <option value="{{ $s->id }}" @selected(old('sales_user_id') == $s->id)>
                                        {{ $s->full_name }}@if($s->employee_id) ({{ $s->employee_id }})@endif
                                    </option>
                                @endforeach
                            </select>
                            @if($salesTersedia->isEmpty())
                                {{-- Dikatakan, bukan dibiarkan jadi dropdown kosong yang
                                     membuat orang mengira halamannya rusak. --}}
                                <div class="form-text text-danger">
                                    Belum ada akun Sales aktif di gudang ini. Pesanan tidak bisa dibuat
                                    tanpa Sales yang menanggungnya.
                                </div>
                            @endif
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">
                                Alasan dibuat dari sini <span class="text-danger">*</span>
                            </label>
                            <textarea name="reason" class="form-control" rows="2" minlength="10" maxlength="500" required
                                      placeholder="mis. Sales sedang cuti dan pelanggan minta barangnya hari ini juga">{{ old('reason') }}</textarea>
                            {{-- Alasannya bukan formalitas: pemilik produk memutuskan
                                 pembuat boleh menyetujui pesanannya sendiri, jadi tidak
                                 ada mata kedua di rantai ini. Kalimat inilah yang dibaca
                                 orang kalau suatu hari pesanan ini dipersoalkan. --}}
                            <div class="form-text">
                                Tersimpan di pesanannya dan terbaca Logistik saat menilai. Minimal 10 huruf.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------------ Pelanggan --}}
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-0 pt-3 px-4">
                    <h6 class="fw-bold mb-0"><i class="bi bi-shop text-primary me-2"></i>Pelanggan</h6>
                </div>
                <div class="card-body px-4 pt-2">
                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label small fw-semibold">Customer <span class="text-danger">*</span></label>
                            <div class="cari-wadah position-relative" id="cariCustomer">
                                <input type="text" class="form-control cari-teks" autocomplete="off"
                                       placeholder="Ketik nama atau kode customer..."
                                       value="{{ $customerTerpilih ? $customerTerpilih['code'].' — '.$customerTerpilih['name'] : '' }}">
                                <input type="hidden" name="customer_id" class="cari-nilai"
                                       value="{{ old('customer_id') }}" required>
                                <div class="cari-saran list-group position-absolute w-100 shadow d-none"
                                     style="z-index:1050; max-height:260px; overflow-y:auto;"></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label small fw-semibold">Syarat pembayaran <span class="text-danger">*</span></label>
                            <select name="payment_term_id" class="form-select" required>
                                <option value="">— Pilih —</option>
                                @foreach($paymentTerms as $t)
                                    <option value="{{ $t->id }}" @selected(old('payment_term_id') == $t->id)>
                                        {{ $t->name }}@if($t->days > 0) ({{ $t->days }} hari)@endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Catatan untuk Logistik (opsional)</label>
                            <input type="text" name="notes" class="form-control" maxlength="1000"
                                   value="{{ old('notes') }}" placeholder="mis. kirim sebelum jam 3, pelanggan tutup sore">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ----------------------------------------------------------- Item --}}
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-0 pt-3 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Item Pesanan</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="tambahItem">
                        <i class="bi bi-plus-lg me-1"></i>Tambah
                    </button>
                </div>
                <div class="card-body px-4 pt-2">
                    <div id="daftarItem"></div>
                    <div class="form-text">
                        Qty yang benar-benar dikirim tetap diputuskan Logistik saat menerima pesanan.
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body px-4 py-3 d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-3" id="tombolKirim">
                        <i class="bi bi-send me-1"></i>Buat & Kirim ke Antrean
                    </button>
                    <button type="submit" class="btn btn-outline-secondary rounded-3" id="tombolDraft">
                        Simpan sebagai draft
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- Cetakan satu baris item. Di dalam <template> supaya baris kosongnya tidak
     ikut terkirim sebagai isian. --}}
<template id="templateItem">
    <div class="card border rounded-3 mb-2 baris-item">
        <div class="card-body p-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-8">
                    <label class="form-label small fw-semibold mb-1">Produk</label>
                    <div class="cari-wadah position-relative cari-produk">
                        <input type="text" class="form-control form-control-sm cari-teks" autocomplete="off"
                               placeholder="Ketik SKU atau nama produk...">
                        <input type="hidden" class="cari-nilai pilih-produk" required>
                        <div class="cari-saran list-group position-absolute w-100 shadow d-none"
                             style="z-index:1050; max-height:260px; overflow-y:auto;"></div>
                    </div>
                </div>
                <div class="col-8 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Qty</label>
                    <input type="number" class="form-control form-control-sm isi-qty" min="1" value="1" required>
                </div>
                <div class="col-4 col-md-1 d-flex justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-danger hapus-item" title="Hapus item">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

@include('partials.pencarian-ketik')

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ITEM_LAMA = @json(old('items', []));
    const PRODUK_TERPILIH = @json($produkTerpilih);

    const daftar = document.getElementById('daftarItem');
    const cetakan = document.getElementById('templateItem');
    const gudang = document.getElementById('pilihGudang');

    function urlGudang() {
        return gudang ? '&warehouse_id=' + encodeURIComponent(gudang.value) : '';
    }

    pasangPencarian(document.getElementById('cariCustomer'), {
        url: (q) => '{{ route('wms.internal-order.lookup.customers') }}?q=' + encodeURIComponent(q),
        tampilan: (c) => '<span class="badge bg-light text-dark border font-monospace me-1">'
            + aman(c.code) + '</span>' + aman(c.name)
            // F-BILL-03: informasi saja — customer menunggak tetap bisa dipilih.
            + (c.menunggak > 0
                ? ' <span class="badge bg-danger-subtle text-danger-emphasis border border-danger ms-1">⚠ Menunggak ' + aman(c.menunggak) + ' hari</span>'
                : ''),
        label: (c) => c.code + ' — ' + c.name,
        kosong: 'Tidak ada customer yang cocok.',
    });

    // Nama produk dan customer berasal dari master data yang diketik orang.
    // Menyisipkannya sebagai HTML mentah membuat satu nama bertanda kurung
    // siku bisa merusak — atau menyetir — halaman ini bagi semua yang membukanya.
    function aman(teks) {
        const d = document.createElement('div');
        d.textContent = teks == null ? '' : String(teks);

        return d.innerHTML;
    }

    // Nama input dinomori ULANG setiap kali baris ditambah atau dihapus.
    // Kalau nomornya ikut baris yang dihapus, array items[] di server jadi
    // bolong dan baris terakhir hilang diam-diam.
    function nomoriUlang() {
        daftar.querySelectorAll('.baris-item').forEach(function (baris, i) {
            baris.querySelector('.pilih-produk').name = 'items[' + i + '][product_id]';
            baris.querySelector('.isi-qty').name = 'items[' + i + '][qty]';
        });
    }

    function tambahBaris(produkId, qty) {
        const baris = cetakan.content.firstElementChild.cloneNode(true);

        if (qty) baris.querySelector('.isi-qty').value = qty;

        if (produkId && PRODUK_TERPILIH[produkId]) {
            const p = PRODUK_TERPILIH[produkId];
            baris.querySelector('.pilih-produk').value = p.id;
            baris.querySelector('.cari-teks').value = p.sku + ' — ' + p.name;
        }

        baris.querySelector('.hapus-item').addEventListener('click', function () {
            baris.remove();
            if (daftar.children.length === 0) tambahBaris();
            nomoriUlang();
        });

        daftar.appendChild(baris);

        pasangPencarian(baris.querySelector('.cari-produk'), {
            url: (q) => '{{ route('wms.internal-order.lookup.products') }}?q=' + encodeURIComponent(q) + urlGudang(),
            tampilan: (p) => '<span class="fw-semibold small d-block text-truncate">' + aman(p.name) + '</span>'
                + '<small class="text-muted font-monospace">' + aman(p.sku) + ' · ' + aman(p.uom || '') + '</small>',
            label: (p) => p.sku + ' — ' + p.name,
            kosong: 'Tidak ada produk yang cocok.',
        });

        nomoriUlang();
    }

    // Isian yang kembali setelah validasi gagal dipasang lagi apa adanya;
    // tanpa ini seluruh baris item hilang dan harus diketik ulang.
    if (ITEM_LAMA.length > 0) {
        ITEM_LAMA.forEach((i) => tambahBaris(i.product_id, i.qty));
    } else {
        tambahBaris();
    }

    document.getElementById('tambahItem').addEventListener('click', () => tambahBaris());

    // Tombol mana yang ditekan menentukan draft atau kirim. Dibaca dari
    // tombolnya, bukan dari nilai tetap: dua tombol submit dalam satu form
    // akan mengirim nilai yang sama kalau tidak dibedakan di sini.
    document.getElementById('tombolDraft').addEventListener('click', function () {
        document.getElementById('aksiForm').value = 'draft';
    });
    document.getElementById('tombolKirim').addEventListener('click', function () {
        document.getElementById('aksiForm').value = 'submit';
    });

    // Ganti gudang berarti daftar Sales-nya ikut berubah. Dimuat ulang dari
    // server, bukan disaring di layar: daftar Sales tiap gudang tidak ikut
    // dikirim ke halaman ini, dan menyaring yang tidak ada hanya menghasilkan
    // dropdown kosong yang membingungkan.
    gudang?.addEventListener('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('warehouse_id', gudang.value);
        window.location = url.toString();
    });
});
</script>
@endsection
