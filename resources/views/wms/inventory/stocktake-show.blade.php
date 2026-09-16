@extends('layouts.wms')

@section('title', 'Hitung Stocktake '.$sesi->reference)
@section('page_title', 'Hitung Stocktake '.$sesi->reference)

@section('content')
{{-- Disusun DERET -> RAK, sama seperti denah, supaya orang yang menghitung
     membaca layar dengan urutan yang sama seperti saat ia berjalan menyusuri
     gudang. Mengurutkannya menurut SKU akan menyuruh operator bolak-balik
     melintasi gudang untuk satu produk. --}}

<a href="{{ route('wms.stocktake.index') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke daftar stocktake
</a>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h5 class="fw-bold text-dark mb-1 font-monospace">{{ $sesi->reference }}</h5>
                <div class="text-muted small">
                    {{ $sesi->warehouse?->name }} &middot; {{ $sesi->scope_label }} &middot;
                    dibuka {{ $sesi->opened_at?->translatedFormat('d M Y, H:i') }}
                    oleh {{ $sesi->openedBy?->full_name ?? '—' }}
                </div>
                @if($sesi->note)
                    <div class="text-muted small fst-italic mt-1">{{ $sesi->note }}</div>
                @endif
            </div>

            @if($sesi->sedangDihitung())
                @can(\App\Support\Permission::STOCKTAKE_MANAGE)
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('wms.stocktake.cancel', $sesi) }}"
                          onsubmit="return confirm('Batalkan sesi {{ $sesi->reference }}? Tidak ada angka stok yang berubah.');">
                        @csrf
                        <button class="btn btn-outline-secondary rounded-3">Batalkan Sesi</button>
                    </form>
                    {{-- DUA LANGKAH, bukan satu.
                         Pengesahan tidak ada di layar ini, supaya tidak ada yang
                         mengesahkan angka yang belum pernah ia lihat berjejer. Stocktake lazim dikerjakan
                         beberapa orang, dan kesalahan satu orang baru kelihatan
                         saat seluruh SKU berbaris dalam satu halaman.

                         Sekarang: periksa laporannya dulu, pengesahannya ada di
                         kaki halaman itu. --}}
                    <a href="{{ route('wms.stocktake.report', $sesi) }}" class="btn btn-success fw-bold rounded-3">
                        <i class="bi bi-clipboard-check me-1"></i> Periksa Laporan
                    </a>
                </div>
                @endcan
            @else
                <a href="{{ route('wms.stocktake.report', $sesi) }}" class="btn btn-outline-secondary rounded-3">
                    <i class="bi bi-file-earmark-text me-1"></i> Lihat Laporan
                </a>
            @endif
        </div>

        <div class="row g-3 mt-1">
            {{-- Diberi id supaya ikut diperbarui setiap kali satu baris
                 disimpan. Angka ringkas yang diam-diam basi lebih buruk
                 daripada tidak ada angka sama sekali. --}}
            @php($kartu = [
                ['Baris dihitung', $ringkasan['dihitung'].' / '.$ringkasan['baris'], 'primary', 'kartuDihitung'],
                ['Cocok', $ringkasan['cocok'], 'success', 'kartuCocok'],
                ['Ada selisih', $ringkasan['selisih'], 'danger', 'kartuSelisih'],
                ['Belum dihitung', $ringkasan['belum'], 'secondary', 'kartuBelum'],
            ])
            @foreach($kartu as [$judul, $nilai, $warna, $id])
                <div class="col-6 col-lg-3">
                    <div class="border rounded-3 p-3">
                        <div class="text-muted small">{{ $judul }}</div>
                        <div class="fs-5 fw-bold text-{{ $warna }}" id="{{ $id }}">{{ $nilai }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($sesi->sedangDihitung())
            <div class="alert alert-info border-0 rounded-3 small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Menyimpan hitungan <strong>belum mengubah stok</strong>. Angka gudang baru berubah saat
                laporannya disahkan — dan rak yang belum sempat dihitung tidak akan disentuh sama sekali.
            </div>
        @endif
    </div>
</div>

{{-- PENYARING DERET & PENCARIAN SKU.
     Stocktake lazim dikerjakan beberapa orang dengan pembagian deret. Tanpa
     penyaring, orang yang kebagian deret C harus menggulir melewati deret A
     dan B yang sedang dikerjakan orang lain — dan di situ baris orang lain
     gampang terisi tanpa sengaja.

     Ini MENYEMBUNYIKAN, bukan membagi kepemilikan. Baris yang tersaring tetap
     milik sesi yang sama dan tetap ikut ke laporan; sistem ini tidak
     menugaskan deret kepada orang tertentu, dan layar ini tidak berpura-pura
     melakukannya. --}}
<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-secondary mb-1" for="filterDeret">Deret</label>
                <select name="rak" id="filterDeret" class="form-select form-select-sm">
                    <option value="">Semua deret</option>
                    @foreach($daftarDeret as $namaRak)
                        <option value="{{ $namaRak }}" @selected($filter['rak'] === (string) $namaRak)>Deret {{ $namaRak }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-5">
                <label class="form-label small fw-semibold text-secondary mb-1" for="filterSku">Cari SKU / nama produk</label>
                <input type="search" name="q" id="filterSku" class="form-control form-control-sm"
                       value="{{ $filter['q'] }}" placeholder="mis. ID11 atau Apko">
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Terapkan</button>
                @if($filter['rak'] !== '' || $filter['q'] !== '')
                    <a href="{{ route('wms.stocktake.show', $sesi) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                @endif
            </div>
        </form>

        @if($filter['rak'] !== '' || $filter['q'] !== '')
            <div class="small text-muted mt-2">
                <i class="bi bi-info-circle me-1"></i>
                Layar sedang disaring. Angka ringkas di atas tetap menghitung <strong>seluruh sesi</strong>,
                bukan hanya yang tampil — supaya tidak ada yang menutup sesi karena mengira sudah selesai.
            </div>
        @endif
    </div>
</div>

@if($sesi->sedangDihitung())
@can(\App\Support\Permission::STOCKTAKE_COUNT)
{{-- TEMUAN. Kebalikan dari menghitung 0, dan sampai sekarang satu-satunya arah
     yang tidak punya jalur sama sekali: operator yang menemukan palet di luar
     daftar mencatatnya di kertas, lalu kertasnya hilang. --}}
<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body py-3">
        <button class="btn btn-sm btn-outline-warning fw-semibold" type="button"
                data-bs-toggle="collapse" data-bs-target="#formTemuan">
            <i class="bi bi-plus-circle me-1"></i> Ada barang di rak yang tidak ada di daftar
        </button>

        <div class="collapse mt-3 @if($errors->any() || old('batch_no')) show @endif" id="formTemuan">
            <form method="POST" action="{{ route('wms.stocktake.found', $sesi) }}" class="row g-2">
                @csrf
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="temuanRak">Rak <span class="text-danger">*</span></label>
                    <select name="location_id" id="temuanRak" class="form-select form-select-sm" required>
                        <option value="">Pilih rak…</option>
                        @foreach($rakPilihan as $rak)
                            <option value="{{ $rak->id }}" @selected(old('location_id') == $rak->id)>{{ $rak->code }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Master produk ribuan baris; dropdown penuh berarti operator
                     di depan rak menggulir ribuan pilihan lewat HP. --}}
                <div class="col-12 col-md-4 cari-produk position-relative">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="temuanProduk">Produk <span class="text-danger">*</span></label>
                    <input type="text" id="temuanProduk" class="form-control form-control-sm cari-teks"
                           placeholder="ketik SKU atau nama…" autocomplete="off" required>
                    <input type="hidden" name="product_id" class="cari-nilai" value="{{ old('product_id') }}">
                    <div class="list-group position-absolute w-100 shadow-sm cari-saran d-none" style="z-index:20"></div>
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="temuanBatch">Batch <span class="text-danger">*</span></label>
                    <input type="text" name="batch_no" id="temuanBatch" maxlength="50" required
                           value="{{ old('batch_no') }}" class="form-control form-control-sm font-monospace">
                </div>

                {{-- TANGGAL PRODUKSI TIDAK DIKETIK LAGI: nomor batch sudah
                     memuat tahun dan bulannya (I1|26|08|0071). Selama ia
                     diketik terpisah, dua keterangan tentang palet yang sama
                     bisa saling bertentangan — dan yang salah justru yang
                     menentukan kedaluwarsa serta urutan FIFO.

                     Hasil bacaannya DIPERLIHATKAN, bukan diam-diam dipakai.
                     Isian manualnya hanya muncul untuk batch lama yang tidak
                     mengikuti pola itu; menolak barangnya sama sekali akan
                     membuat operator kembali mencatat di kertas. --}}
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="temuanTanggal">Tgl produksi</label>
                    <div id="temuanTanggalBaca" class="form-control form-control-sm bg-body-secondary d-none"
                         aria-live="polite"></div>
                    <input type="date" name="production_date" id="temuanTanggal"
                           value="{{ old('production_date') }}" max="{{ now()->toDateString() }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="temuanQty">Jumlah <span class="text-danger">*</span></label>
                    <input type="number" name="qty" id="temuanQty" min="1" required
                           value="{{ old('qty') }}" class="form-control form-control-sm">
                </div>

                <div class="col-12 col-md-7">
                    <label class="form-label small fw-semibold text-secondary mb-1" for="temuanCatatan">Catatan</label>
                    <input type="text" name="note" id="temuanCatatan" maxlength="500"
                           value="{{ old('note') }}" class="form-control form-control-sm"
                           placeholder="mis. palet terselip di belakang, label sobek">
                </div>

                <div class="col-12 col-md-3 d-flex align-items-end">
                    <button class="btn btn-sm btn-warning fw-bold w-100">
                        <i class="bi bi-check-lg me-1"></i> Catat Temuan
                    </button>
                </div>

                <div class="col-12">
                    <p class="small text-muted mb-0 mt-1">
                        <strong>Tanggal produksi dibaca dari nomor batch</strong> — pada
                        <span class="font-monospace">I1<strong>26</strong><strong>08</strong>0071</span>,
                        <strong>26</strong> adalah tahunnya dan <strong>08</strong> bulannya, jadi tanggalnya
                        1 Agustus 2026. Kedaluwarsa dan urutan FIFO dihitung dari situ. Kalau nomor batchnya
                        tidak berpola seperti itu, isian tanggalnya muncul untuk diisi dari label palet.
                        Stok belum bertambah sampai laporan sesi ini disahkan.
                    </p>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
@endif

@forelse($deret as $namaDeret => $perRak)
    <div class="card shadow-sm border-0 rounded-4 mb-3">
        <div class="card-body p-4">
            <h6 class="fw-bold text-dark mb-3">
                <i class="bi bi-bookshelf text-primary me-2"></i>Deret {{ $namaDeret }}
                <span class="badge bg-light text-dark border ms-1">{{ $perRak->count() }} rak</span>
            </h6>

            @foreach($perRak as $kodeRak => $baris)
                <div class="border rounded-3 mb-2">
                    <div class="px-3 py-2 bg-light-subtle border-bottom d-flex flex-wrap justify-content-between gap-2">
                        <span class="fw-semibold font-monospace">{{ $kodeRak }}</span>
                        <span class="small text-muted">{{ $baris->count() }} batch</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                {{-- SKU dan deskripsi BERDAMPINGAN, bukan
                                     bertumpuk. Ditumpuk, deskripsinya harus
                                     dicetak kecil agar muat dan barisnya jadi
                                     dua kali lebih tinggi — padahal layar ini
                                     dibaca sambil berdiri di depan rak,
                                     mencocokkan label. --}}
                                <tr class="small text-muted">
                                    <th style="width:170px">SKU</th>
                                    <th>Deskripsi</th>
                                    <th>Batch</th>
                                    <th class="text-end">Sistem</th>
                                    <th style="width:220px">Hitungan fisik</th>
                                    <th class="text-end">Selisih</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($baris as $item)
                                <tr id="baris-{{ $item->id }}">
                                    <td class="fw-semibold font-monospace small text-nowrap">{{ $item->product?->sku ?? '—' }}</td>
                                    <td class="small">{{ $item->product?->name }}</td>
                                    <td class="font-monospace small">
                                        {{ $item->batch_no ?? '—' }}
                                        @if($item->is_found)
                                            {{-- Baris temuan dibedakan terang-terangan. Angka
                                                 sistemnya nol BUKAN karena raknya kosong, tetapi
                                                 karena batchnya memang belum pernah ada di
                                                 sistem — dan itu dua hal yang sangat berbeda
                                                 bagi siapa pun yang membaca laporannya nanti. --}}
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning d-block mt-1"
                                                  title="Ditemukan di rak, tidak ada di sistem">Temuan</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format($item->qty_system) }}</td>
                                    <td>
                                        @if($sesi->sedangDihitung())
                                            {{-- Tetap FORMULIR sungguhan. Kalau
                                                 JavaScript-nya gagal dimuat, ia
                                                 disubmit biasa dan penghitungan
                                                 tetap jalan — hanya kembali ke
                                                 perilaku muat ulang. --}}
                                            <form method="POST" action="{{ route('wms.stocktake.count', $item) }}"
                                                  class="d-flex gap-1 js-hitung" data-baris="{{ $item->id }}"
                                                  data-sistem="{{ $item->qty_system }}">
                                                @csrf
                                                <input type="number" name="qty_physical" min="0" required
                                                       value="{{ $item->qty_physical }}"
                                                       class="form-control form-control-sm js-qty" style="max-width:90px">
                                                <input type="text" name="count_note" maxlength="500"
                                                       value="{{ $item->count_note }}"
                                                       class="form-control form-control-sm" placeholder="catatan">
                                                <button class="btn btn-sm btn-primary" title="Simpan hitungan">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        @else
                                            {{ $item->sudahDihitung() ? number_format($item->qty_physical) : '—' }}
                                        @endif
                                        <div class="text-muted js-meta" style="font-size:.7rem">
                                            @if($item->sudahDihitung())
                                                {{ $item->countedBy?->full_name ?? '—' }},
                                                {{ $item->counted_at?->format('d M H:i') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end js-selisih">
                                        {{-- Tiga keadaan BERBEDA. "Belum dihitung"
                                             bukan hal yang sama dengan "cocok", dan
                                             menampilkan keduanya sebagai 0 membuat
                                             rak yang terlewat terbaca sudah beres. --}}
                                        @if(! $item->sudahDihitung())
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis">Belum</span>
                                        @elseif($item->selisih === 0)
                                            <span class="badge bg-success-subtle text-success-emphasis">Cocok</span>
                                        @else
                                            <span class="badge bg-danger-subtle text-danger-emphasis">
                                                {{ $item->selisih > 0 ? '+' : '' }}{{ number_format($item->selisih) }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@empty
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
            @if($filter['rak'] !== '' || $filter['q'] !== '')
                {{-- Dibedakan dari sesi yang memang kosong. "Tidak ada baris"
                     pada layar yang sedang disaring terbaca seperti raknya
                     memang kosong, dan orang menutup pekerjaan yang belum
                     tersentuh. --}}
                Tidak ada baris yang cocok dengan penyaring ini.
                <a href="{{ route('wms.stocktake.show', $sesi) }}" class="d-block mt-2">Tampilkan semua</a>
            @else
                Tidak ada baris stok dalam cakupan sesi ini.
            @endif
        </div>
    </div>
@endforelse


@if($sesi->sedangDihitung())
@can(\App\Support\Permission::STOCKTAKE_COUNT)
@include('partials.pencarian-ketik')
<script>
document.addEventListener('DOMContentLoaded', function () {
    window.pasangPencarian(document.querySelector('.cari-produk'), {
        url: function (q) { return '{{ route('wms.stocktake.lookup.products') }}?q=' + encodeURIComponent(q); },
        tampilan: function (p) {
            return '<div class="fw-semibold small">' + p.teks + '</div>' +
                   '<div class="text-muted" style="font-size:.7rem">' + (p.ket || '') + '</div>';
        },
        label: function (p) { return p.teks; },
        kosong: 'SKU tidak terdaftar di Master Produk.',
    });

    /*
     * Membaca tanggal produksi dari nomor batch sambil diketik.
     *
     * Aturannya SAMA PERSIS dengan App\Support\Inventory\BatchProduksi di sisi
     * server, dan servernya tetap membaca ulang sendiri — yang di sini hanya
     * memperlihatkan hasilnya supaya operator bisa menangkap salah ketik
     * sebelum menekan simpan. Kalau JavaScript-nya gagal dimuat, isian
     * tanggalnya tetap terlihat dan formulirnya tetap jalan.
     */
    const bulanIndo = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    function bacaTanggalBatch(nilai) {
        const cocok = /^[A-Za-z]{1,3}\d(\d{2})(\d{2})\d{3,4}$/.exec(String(nilai).trim());

        if (! cocok) {
            return null;
        }

        const tahun = 2000 + Number(cocok[1]);
        const bulan = Number(cocok[2]);

        if (bulan < 1 || bulan > 12) {
            return null;
        }

        const tanggal = new Date(Date.UTC(tahun, bulan - 1, 1));
        const hariIni = new Date();

        if (tanggal > hariIni) {
            return null;
        }

        return {
            iso: tahun + '-' + String(bulan).padStart(2, '0') + '-01',
            label: '1 ' + bulanIndo[bulan - 1] + ' ' + tahun,
        };
    }

    const isianBatch = document.getElementById('temuanBatch');
    const isianTanggal = document.getElementById('temuanTanggal');
    const bacaan = document.getElementById('temuanTanggalBaca');

    function segarkanTanggal() {
        const hasil = bacaTanggalBatch(isianBatch.value);

        if (hasil === null) {
            bacaan.classList.add('d-none');
            isianTanggal.classList.remove('d-none');
            isianTanggal.required = true;

            return;
        }

        bacaan.textContent = hasil.label;
        bacaan.classList.remove('d-none');
        isianTanggal.classList.add('d-none');
        isianTanggal.required = false;
        isianTanggal.value = hasil.iso;
    }

    isianBatch.addEventListener('input', segarkanTanggal);
    segarkanTanggal();
});
</script>
@endcan

<script>
/*
 * Menyimpan hitungan TANPA memuat ulang halaman.
 *
 * MENGAPA INI PENTING, bukan sekadar kenyamanan: satu sesi stocktake bisa berisi
 * ribuan baris. Dengan submit biasa, orang yang sudah menghitung sampai baris
 * terakhir dilempar kembali ke puncak halaman setiap kali satu centang
 * ditekan — dan harus menggulir turun lagi mencari tempatnya semula. Pada
 * pekerjaan yang memang panjang, itu bukan gangguan kecil; itu yang membuat
 * orang berhenti memakai fiturnya.
 */
document.addEventListener('DOMContentLoaded', function () {
    const lolos = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);

    const angka = (n) => Number(n).toLocaleString('id-ID');

    function gambarSelisih(sel, selisih) {
        if (selisih === 0) {
            sel.innerHTML = '<span class="badge bg-success-subtle text-success-emphasis">Cocok</span>';

            return;
        }

        sel.innerHTML = '<span class="badge bg-danger-subtle text-danger-emphasis">'
            + (selisih > 0 ? '+' : '') + angka(selisih) + '</span>';
    }

    /** Menyorot sebentar baris yang baru tersimpan, lalu memudar sendiri. */
    function tandaiTersimpan(baris) {
        baris.classList.add('table-success');
        setTimeout(() => baris.classList.remove('table-success'), 1200);
    }

    function tampilkanGalat(baris, pesan) {
        // Ditempel DI BARISNYA, bukan sebagai peringatan di puncak halaman
        // yang justru tidak terlihat oleh orang yang sedang berada di baris
        // ke-800. Penolakan yang tidak terbaca sama saja dengan hilang.
        const sel = baris.querySelector('.js-meta');

        sel.innerHTML = '<span class="text-danger fw-semibold">'
            + '<i class="bi bi-exclamation-triangle me-1"></i>' + lolos(pesan) + '</span>';
        baris.classList.add('table-danger');
        setTimeout(() => baris.classList.remove('table-danger'), 3000);
    }

    function perbaruiRingkasan(r) {
        document.getElementById('kartuDihitung').textContent = angka(r.dihitung) + ' / ' + angka(r.baris);
        document.getElementById('kartuCocok').textContent = angka(r.cocok);
        document.getElementById('kartuSelisih').textContent = angka(r.selisih);
        document.getElementById('kartuBelum').textContent = angka(r.belum);
    }

    /** Memindahkan fokus ke isian berikutnya yang belum terisi. */
    function lompatKeBerikutnya(formSekarang) {
        const semua = Array.from(document.querySelectorAll('.js-hitung'));
        const mulai = semua.indexOf(formSekarang) + 1;

        for (let i = mulai; i < semua.length; i++) {
            const isian = semua[i].querySelector('.js-qty');

            if (isian.value === '') {
                isian.focus();
                isian.scrollIntoView({ block: 'center', behavior: 'smooth' });

                return;
            }
        }
    }

    document.querySelectorAll('.js-hitung').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const baris = document.getElementById('baris-' + form.dataset.baris);
            const tombol = form.querySelector('button');

            tombol.disabled = true;

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then((r) => r.json().then((data) => ({ ok: r.ok, data: data })))
                .then((hasil) => {
                    if (! hasil.ok) {
                        // 422 dari validasi membawa bentuk yang berbeda dari
                        // 422 aturan stocktake; keduanya sama-sama harus terbaca.
                        const pesan = hasil.data.pesan
                            || (hasil.data.errors && Object.values(hasil.data.errors)[0][0])
                            || 'Hitungan gagal disimpan.';

                        tampilkanGalat(baris, pesan);

                        return;
                    }

                    gambarSelisih(baris.querySelector('.js-selisih'), hasil.data.selisih);
                    baris.querySelector('.js-meta').innerHTML =
                        lolos(hasil.data.oleh ?? '—') + ', ' + lolos(hasil.data.waktu ?? '');
                    perbaruiRingkasan(hasil.data.ringkasan);
                    tandaiTersimpan(baris);
                    lompatKeBerikutnya(form);
                })
                .catch(() => {
                    tampilkanGalat(baris, 'Gagal menghubungi server. Hitungan ini BELUM tersimpan.');
                })
                .finally(() => {
                    tombol.disabled = false;
                });
        });
    });
});
</script>
@endif
@endsection
