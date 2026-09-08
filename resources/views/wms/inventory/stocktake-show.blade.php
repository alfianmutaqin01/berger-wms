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
                    <button type="button" class="btn btn-success fw-bold rounded-3"
                            data-bs-toggle="modal" data-bs-target="#modalSahkan">
                        <i class="bi bi-printer me-1"></i> Sahkan &amp; Cetak Laporan
                    </button>
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
                                <tr class="small text-muted">
                                    <th>Produk</th>
                                    <th>Batch</th>
                                    <th class="text-end">Sistem</th>
                                    <th style="width:220px">Hitungan fisik</th>
                                    <th class="text-end">Selisih</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($baris as $item)
                                <tr id="baris-{{ $item->id }}">
                                    <td>
                                        <div class="fw-semibold font-monospace small">{{ $item->product?->sku ?? '—' }}</div>
                                        <div class="text-muted" style="font-size:.72rem">{{ $item->product?->name }}</div>
                                    </td>
                                    <td class="font-monospace small">{{ $item->batch_no ?? '—' }}</td>
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
            Tidak ada baris stok dalam cakupan sesi ini.
        </div>
    </div>
@endforelse

@can(\App\Support\Permission::STOCKTAKE_MANAGE)
<div class="modal fade" id="modalSahkan" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.stocktake.finalize', $sesi) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Sahkan Laporan Stocktake</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Seluruh selisih akan <strong>diterapkan ke stok</strong> dan tercatat di ledger sebagai
                    koreksi stocktake. Sesudah ini hitungannya tidak bisa diubah lagi.
                </p>
                @if($ringkasan['belum'] > 0)
                    <div class="alert alert-warning border-0 rounded-3 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>{{ number_format($ringkasan['belum']) }} baris belum dihitung.</strong>
                        Baris itu <strong>tidak akan disentuh</strong> — stoknya tetap seperti sekarang, dan
                        laporannya akan menyebutkan bahwa cakupan stocktake ini belum penuh.
                    </div>
                @endif
                <div class="border rounded-3 p-3 small">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Baris cocok</span>
                        <span class="fw-semibold">{{ number_format($ringkasan['cocok']) }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Baris berselisih</span>
                        <span class="fw-semibold text-danger">{{ number_format($ringkasan['selisih']) }}</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-success rounded-3 fw-bold">
                    <i class="bi bi-printer me-1"></i> Sahkan &amp; Cetak
                </button>
            </div>
        </form>
    </div>
</div>
@endcan

@if($sesi->sedangDihitung())
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
