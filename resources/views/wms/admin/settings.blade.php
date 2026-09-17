@extends('layouts.wms')

@section('title', 'Pengaturan Sistem')
@section('page_title', 'Pengaturan Sistem')

@section('content')
<div class="row mb-3">
    <div class="col-12 col-lg-8">
        <h4 class="fw-bold text-dark mb-1">Pengaturan Sistem</h4>
        <p class="text-muted small mb-0">
            Aturan operasional yang berlaku untuk seluruh gudang. Perubahannya langsung berlaku
            dan tercatat di Log Aktivitas.
        </p>
    </div>
</div>

@foreach(['success', 'error'] as $jenis)
    @if(session($jenis))
        <div class="alert alert-{{ $jenis === 'error' ? 'danger' : 'success' }} border-0 rounded-4 small">
            {{ session($jenis) }}
        </div>
    @endif
@endforeach

@if($errors->any())
    <div class="alert alert-danger border-0 rounded-4 small">
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $galat)<li>{{ $galat }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('wms.admin.settings.update') }}">
    @csrf

    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3 p-md-4">
            @foreach($daftar as $key => $meta)
                <div class="row align-items-start g-3 {{ $loop->last ? '' : 'border-bottom pb-3 mb-3' }}">
                    <div class="col-12 col-md-7">
                        <label for="setelan-{{ $key }}" class="fw-semibold text-dark mb-1 d-block">
                            {{ $meta['label'] }}
                        </label>
                        <div class="text-muted small">{{ $meta['bantuan'] }}</div>

                        {{-- Peringatan ditaruh DI SINI, bukan di bawah tombol Simpan.
                             Yang perlu tahu bahwa menurunkan angka ini menghapus data
                             adalah orang yang sedang mengetik angkanya, bukan orang
                             yang sudah menekan Simpan. --}}
                        @if(! empty($meta['peringatan']))
                            <div class="alert alert-warning border-0 small py-2 mt-2 mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $meta['peringatan'] }}
                            </div>
                        @endif
                    </div>

                    <div class="col-12 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white small text-muted">{{ $meta['satuan'] }}</span>
                            <input type="number" class="form-control fw-bold text-center"
                                   id="setelan-{{ $key }}" name="{{ $key }}"
                                   value="{{ old($key, $nilai[$key]) }}"
                                   min="{{ $meta['min'] }}" max="{{ $meta['max'] }}" required>
                        </div>
                        <div class="form-text">
                            Batas {{ $meta['min'] }}–{{ $meta['max'] }} &middot; bawaan {{ $meta['bawaan'] }}
                            @if($nilai[$key] !== $meta['bawaan'])
                                <span class="badge bg-info-subtle text-info-emphasis rounded-pill ms-1">diubah</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card-footer bg-white border-0 p-3 p-md-4 pt-0 d-flex flex-column flex-md-row gap-2 align-items-md-center">
            <button class="btn btn-primary rounded-3 px-4 fw-bold">
                <i class="bi bi-save me-1"></i> Simpan Pengaturan
            </button>
            @if($terakhir)
                <small class="text-muted">
                    Terakhir diubah {{ $terakhir->updated_at?->translatedFormat('d M Y, H:i') }}
                    @if($terakhir->updatedBy) oleh {{ $terakhir->updatedBy->full_name }}@endif.
                </small>
            @endif
        </div>
    </div>
</form>

{{-- Setelan yang bentuknya DAFTAR, bukan angka tunggal, punya halamannya
     sendiri — memaksanya masuk ke formulir di atas berarti satu formulir yang
     jumlah isiannya berubah tiap kali ada ukuran baru. --}}
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3 p-md-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h6 class="fw-bold text-dark mb-1">
                <i class="bi bi-stack text-primary me-2"></i>Kapasitas Palet
            </h6>
            <p class="text-muted small mb-0">
                Berapa banyak muat di satu palet, menurut ukuran kemasannya — mis. <strong>20 L PAIL = 36</strong>.
                Satu angka berlaku untuk seluruh produk seukuran, termasuk produk yang belum dibuat.
                Angka ini yang dipakai memecah hasil produksi jadi palet dan membaca isi rak.
            </p>
        </div>
        <a href="{{ route('wms.admin.pallet-capacity') }}" class="btn btn-outline-primary rounded-3 text-nowrap">
            Atur Kapasitas Palet <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
</div>

{{-- Dikatakan apa adanya, supaya tidak ada yang mencari tombol yang memang
     sengaja tidak dibuat. --}}
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-3 p-md-4">
        <h6 class="fw-bold text-dark mb-2">
            <i class="bi bi-info-circle text-primary me-2"></i>Yang sengaja TIDAK bisa diubah dari sini
        </h6>
        <ul class="text-muted small mb-0 ps-3">
            <li class="mb-1">
                <strong>Format &amp; nomor urut dokumen.</strong> Mengganti awalan di tengah jalan
                memecah riwayat menjadi dua bentuk yang tidak bisa dicari sekaligus, dan menggeser
                nomor mundur menghasilkan nomor kembar yang menghentikan pembuatan pesanan untuk
                semua orang. Keadaannya bisa dilihat di
                <a href="{{ route('wms.admin.sequence') }}">Penomoran Dokumen</a>.
            </li>
            <li class="mb-1">
                <strong>Hak akses per peran.</strong> Diatur di kode bersama halaman yang dijaganya,
                supaya menu dan halamannya tidak pernah berbeda pendapat.
            </li>
            <li>
                <strong>Batas percobaan login.</strong> Aturan keamanan, bukan aturan operasional
                gudang.
            </li>
        </ul>
    </div>
</div>

{{-- ------------------------------------------- Tautan permintaan material --}}
{{-- MENUMPANG HALAMAN INI, bukan menu sendiri. Daftarnya berisi dua-tiga
     baris dan diubah setahun sekali; menu tersendiri untuk itu hanya
     memperpanjang menu samping yang dibaca orang setiap hari.

     Yang diatur di sini: divisi mana yang boleh meminta material tanpa punya
     akun WMS, dan siapa atasan yang menyetujuinya. --}}
<div class="card border-0 shadow-sm rounded-4 mt-4">
    <div class="card-body p-3 p-md-4">
        <h6 class="fw-bold mb-1">
            <i class="bi bi-link-45deg me-2"></i>Tautan Permintaan Material per Divisi
        </h6>
        <p class="text-muted small">
            Untuk divisi yang <strong>tidak punya akun WMS</strong> — QC, R&amp;D, dan sejenisnya.
            Mereka mengisi permintaannya sendiri lewat tautan ini, lalu alurnya sama persis dengan MRF
            Produksi: disetujui atasan lewat WhatsApp, dipastikan Logistik, disiapkan Operator.
            Barangnya <strong>selesai saat diambil</strong> dan tercatat di Riwayat Pemakaian MRF.
        </p>

        <div class="alert alert-warning border-0 rounded-3 small">
            <i class="bi bi-key-fill me-2"></i>
            <strong>Perlakukan tautannya seperti kunci.</strong> Berikan sekali kepada kepala divisinya,
            jangan disebar di grup. Kalau terlanjur tersebar, tekan <em>Terbitkan ulang</em> — alamat
            lamanya mati seketika.
            <div class="mt-2 mb-0">
                Mengisi nama dan nomor atasan di bawah membuat atasannya <strong>terkunci</strong>:
                pengisi formulir tidak bisa menyebutkan atasan lain, apalagi dirinya sendiri.
                Dikosongkan berarti divisi itu menyebutkannya sendiri tiap kali, seperti Produksi.
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr class="small text-muted">
                        <th>Divisi</th>
                        <th>Gudang</th>
                        <th>Atasan penyetuju</th>
                        <th class="text-end">Dipakai</th>
                        <th>Tautan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($tautanMrf as $tautan)
                    <tr class="{{ $tautan->is_active ? '' : 'opacity-50' }}">
                        <td class="small fw-semibold">
                            {{ $tautan->department?->name ?? '—' }}
                            @unless($tautan->is_active)
                                <span class="badge bg-secondary ms-1">Nonaktif</span>
                            @endunless
                        </td>
                        <td class="small">{{ $tautan->warehouse?->kode_pendek ?? '—' }}</td>
                        <td class="small">
                            @if($tautan->atasanTerkunci())
                                {{ $tautan->approver_name }}
                                <span class="d-block text-muted font-monospace">{{ $tautan->approver_phone }}</span>
                            @else
                                <span class="text-muted">Diisi pemohon</span>
                            @endif
                        </td>
                        <td class="text-end small">{{ number_format($tautan->requisitions_count) }}&times;</td>
                        <td>
                            @if($tautan->is_active)
                                <input type="text" readonly class="form-control form-control-sm font-monospace"
                                       style="min-width:240px" value="{{ $tautan->url() }}"
                                       onclick="this.select()" aria-label="Alamat tautan">
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <form method="POST" action="{{ route('wms.admin.mrf-link.update', $tautan) }}"
                                  class="d-inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="aksi" value="{{ $tautan->is_active ? 'nonaktifkan' : 'aktifkan' }}">
                                <button class="btn btn-sm btn-outline-secondary rounded-3">
                                    {{ $tautan->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('wms.admin.mrf-link.update', $tautan) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Terbitkan ulang tautan {{ $tautan->department?->name }}? Alamat lamanya berhenti bekerja seketika dan harus dibagikan ulang.');">
                                @csrf @method('PUT')
                                <input type="hidden" name="aksi" value="terbitkan_ulang">
                                <button class="btn btn-sm btn-outline-warning rounded-3">Terbitkan ulang</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4 small">
                            Belum ada tautan divisi. Selama belum ada, permintaan material hanya bisa
                            diajukan lewat akun WMS.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('wms.admin.mrf-link.store') }}" class="row g-2 align-items-end border-top pt-3">
            @csrf
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-secondary mb-1" for="tautanDivisi">Divisi</label>
                <select name="department_id" id="tautanDivisi" class="form-select form-select-sm" required>
                    <option value="">Pilih divisi…</option>
                    @foreach($divisiOptions as $d)
                        <option value="{{ $d->id }}" @selected(old('department_id') == $d->id)>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1" for="tautanGudang">Gudang</label>
                <select name="warehouse_id" id="tautanGudang" class="form-select form-select-sm" required>
                    <option value="">Pilih gudang…</option>
                    @foreach($gudangOptions as $g)
                        <option value="{{ $g->id }}" @selected(old('warehouse_id') == $g->id)>{{ $g->kode_pendek }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-secondary mb-1" for="tautanAtasan">
                    Nama atasan <span class="text-muted fw-normal">(opsional)</span>
                </label>
                <input type="text" name="approver_name" id="tautanAtasan" maxlength="100"
                       value="{{ old('approver_name') }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1" for="tautanNomor">Nomor WA</label>
                <input type="text" name="approver_phone" id="tautanNomor" maxlength="25"
                       value="{{ old('approver_phone') }}" class="form-control form-control-sm font-monospace"
                       placeholder="081234567890">
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-sm btn-primary rounded-3">
                    <i class="bi bi-plus-lg me-1"></i> Terbitkan
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
