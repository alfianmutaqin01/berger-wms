@extends('layouts.wms')

@section('title', 'Pratinjau Input Produksi')
@section('page_title', 'Pratinjau Input Produksi')

@section('content')
<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-clipboard-check text-success me-2"></i> Pratinjau Input Produksi
        </h5>
        <p class="text-muted small mt-1 mb-0">
            Berkas: <span class="font-monospace">{{ $originalName }}</span> —
            <strong>belum ada data yang tersimpan.</strong> Periksa dulu, lalu tekan Submit.
        </p>
    </div>

    <div class="card-body p-4">
        <!-- Identitas dokumen -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="border rounded-3 p-3">
                    <div class="text-muted small mb-1">No. Dokumen</div>
                    <div class="fw-bold font-monospace text-dark">{{ $documentNumber }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-3 p-3">
                    <div class="text-muted small mb-1">Tanggal Produksi</div>
                    <div class="fw-bold text-dark">{{ $productionDate->translatedFormat('d F Y') }}</div>
                    @if(! $productionDate->isToday())
                        {{-- Tanggal mundur DIKATAKAN, bukan dibiarkan lewat begitu
                             saja. Kedaluwarsa tiap batch dihitung dari tanggal ini,
                             dan salah pilih tanggal tidak akan pernah kelihatan lagi
                             setelah dokumennya tersimpan. --}}
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning mt-1">
                            <i class="bi bi-clock-history me-1"></i>{{ $productionDate->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-3 p-3">
                    <div class="text-muted small mb-1">Gudang Tujuan</div>
                    <div class="fw-bold text-dark">{{ $warehouse?->display_label ?? '—' }}</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-3 p-3">
                    <div class="text-muted small mb-1">Dibuat oleh</div>
                    <div class="fw-bold text-dark">{{ auth()->user()?->full_name }}</div>
                </div>
            </div>
        </div>

        <!-- Ringkasan -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="border rounded-3 p-3">
                    <div class="text-muted small mb-1">Baris Produksi</div>
                    <div class="fs-4 fw-bold text-dark">{{ $summary['total'] }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border border-success rounded-3 p-3 bg-success-subtle">
                    <div class="text-success-emphasis small mb-1">Siap Disimpan</div>
                    <div class="fs-4 fw-bold text-success">{{ $summary['siap'] }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border border-primary rounded-3 p-3 bg-primary-subtle">
                    <div class="text-primary-emphasis small mb-1">Total Palet</div>
                    <div class="fs-4 fw-bold text-primary">{{ $summary['palet'] }}</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="border {{ $summary['gagal'] > 0 ? 'border-danger bg-danger-subtle' : '' }} rounded-3 p-3">
                    <div class="small mb-1 {{ $summary['gagal'] > 0 ? 'text-danger-emphasis' : 'text-muted' }}">Dilewati</div>
                    <div class="fs-4 fw-bold {{ $summary['gagal'] > 0 ? 'text-danger' : 'text-muted' }}">{{ $summary['gagal'] }}</div>
                </div>
            </div>
        </div>

        @if($summary['gagal'] > 0)
            <div class="alert alert-danger border-0 small">
                <i class="bi bi-x-circle-fill me-1"></i>
                {{ $summary['gagal'] }} baris <strong>akan dilewati</strong> dan tidak tersimpan.
                Baris lainnya tetap diproses. Perbaiki penyebabnya lalu unggah ulang bila perlu.
            </div>
        @endif

        {{-- DUPLIKAT DIKATAKAN DI SINI, bukan setelah tersimpan.
             IN-260910-001 dan -002 pernah tersimpan berurutan dengan RMO dan
             batch yang sama persis, karena berkasnya diunggah dua kali dan
             layar ini tidak berkata apa-apa. Paletnya ikut naik rak dan stok
             bertambah dua kali untuk barang yang hanya dibuat sekali. --}}
        @if($summary['terkunci'] > 0)
            <div class="alert alert-danger border-0 small">
                <i class="bi bi-lock-fill me-1"></i>
                <strong>{{ $summary['terkunci'] }} baris tidak akan disimpan.</strong>
                RMO + batch-nya sudah pernah masuk, dan paletnya sudah disentuh gudang —
                sudah naik rak atau sudah diverifikasi. Barangnya sudah berdiri di rak dan
                angkanya sudah dihitung, jadi menimpanya akan membuat catatan sistem berbeda
                dari isi gudang. Kalau ada yang keliru, perbaikannya lewat Koreksi Stok.
            </div>
        @endif

        @if($summary['bisa_ditimpa'] > 0)
            <div class="alert alert-warning border-0 small">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>{{ $summary['bisa_ditimpa'] }} baris sudah pernah masuk</strong>
                lewat dokumen yang paletnya belum disentuh gudang.
                Tanpa dicentang di bawah, baris-baris itu <strong>dilewati</strong> — dokumen lamanya tetap utuh.
            </div>
        @endif

        <div class="table-responsive border rounded-3" style="max-height: 520px;">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th class="small text-nowrap">NO. PRODUKSI</th>
                        <th class="small text-nowrap">SKU</th>
                        <th class="small" style="min-width: 220px;">DESKRIPSI</th>
                        <th class="small text-nowrap">BATCH</th>
                        <th class="small text-end text-nowrap">QTY</th>
                        <th class="small text-center text-nowrap">MAKS/PALET</th>
                        <th class="small" style="min-width: 200px;">PEMBAGIAN PALET</th>
                        <th class="small text-center" style="width: 110px;">STATUS</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="{{ $row['status'] === 'gagal' ? 'table-danger' : '' }}">
                            <td class="font-monospace small text-muted text-nowrap">{{ $row['production_order_no'] ?? '—' }}</td>
                            <td class="font-monospace small fw-bold text-dark text-nowrap">{{ $row['sku'] }}</td>
                            <td class="small">{{ $row['description'] }}</td>
                            <td class="font-monospace small text-nowrap">{{ $row['batch_no'] ?? '—' }}</td>
                            <td class="text-end fw-semibold">{{ number_format($row['qty']) }}</td>
                            <td class="text-center small text-muted">{{ $row['capacity'] ? number_format($row['capacity']) : '—' }}</td>
                            <td>
                                @if($row['pallets'])
                                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary me-1">
                                        {{ count($row['pallets']) }} palet
                                    </span>
                                    <span class="small text-muted font-monospace">{{ implode(' + ', $row['pallets']) }}</span>
                                @else
                                    <span class="small text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($row['status'] !== 'siap')
                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger"
                                          title="{{ $row['message'] }}">Dilewati</span>
                                @elseif(($row['duplikat']['keadaan'] ?? null) === 'terkunci')
                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger">Terkunci</span>
                                @elseif(($row['duplikat']['keadaan'] ?? null) === 'bisa_ditimpa')
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Duplikat</span>
                                @else
                                    <span class="badge bg-success-subtle text-success-emphasis border border-success">Siap</span>
                                @endif
                            </td>
                        </tr>
                        @if($row['status'] === 'gagal' && $row['message'])
                            <tr class="table-danger">
                                <td colspan="8" class="small text-danger-emphasis pt-0">
                                    <i class="bi bi-exclamation-triangle me-1"></i>{{ $row['message'] }}
                                </td>
                            </tr>
                        @elseif($row['duplikat'])
                            {{-- Nomor dokumen lamanya disebut, bukan cuma "sudah ada".
                                 Tanpa nomornya, yang membaca tidak punya cara memeriksa
                                 sendiri apakah yang lama memang benar-benar sama. --}}
                            <tr class="{{ $row['duplikat']['keadaan'] === 'terkunci' ? 'table-danger' : 'table-warning' }}">
                                <td colspan="8" class="small pt-0">
                                    <i class="bi bi-files me-1"></i>
                                    RMO + batch ini sudah ada di dokumen
                                    <strong class="font-monospace">{{ $row['duplikat']['dokumen'] }}</strong>
                                    ({{ $row['duplikat']['status'] }}) — {{ $row['duplikat']['palet'] }} palet.
                                    @if($row['duplikat']['keadaan'] === 'terkunci')
                                        <strong>{{ $row['duplikat']['tersentuh'] }} palet sudah disentuh gudang, jadi baris ini tidak bisa ditimpa.</strong>
                                    @else
                                        Belum ada palet yang disentuh gudang, jadi baris ini boleh ditimpa.
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-footer bg-light border-top-0 rounded-bottom-4 py-3 px-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <form action="{{ route('wms.inbound.store') }}" method="POST" id="storeForm">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="extension" value="{{ $extension }}">
                    <input type="hidden" name="warehouse_id" value="{{ $warehouse?->id }}">
                    <input type="hidden" name="production_date" value="{{ $productionDate->toDateString() }}">
                    <label class="form-label small fw-semibold text-secondary">Catatan (opsional)</label>
                    <input type="text" name="notes" class="form-control" maxlength="500" placeholder="Catatan untuk dokumen ini...">

                    @if($summary['bisa_ditimpa'] > 0)
                        {{-- Tidak dicentang secara bawaan, dan itu disengaja. Yang
                             mengunggah ulang karena mengira unggahan pertama gagal
                             tidak sedang meminta apa pun ditimpa. --}}
                        <div class="form-check mt-3 p-3 border border-warning rounded-3 bg-warning-subtle">
                            <input class="form-check-input" type="checkbox" name="timpa" value="1" id="timpaDuplikat">
                            <label class="form-check-label small" for="timpaDuplikat">
                                <strong>Timpa data yang sudah ada</strong>
                                ({{ $summary['bisa_ditimpa'] }} baris)
                                <span class="d-block text-muted">
                                    Palet lama pada baris itu dibuang dan digantikan yang baru; baris yang belum pernah
                                    masuk tetap ditambahkan. Dokumen lama yang kehilangan seluruh paletnya ikut ditutup.
                                    Baris bertanda <strong>Terkunci</strong> tetap dilewati.
                                </span>
                            </label>
                        </div>
                    @endif
                </form>
            </div>
            <div class="col-md-4 text-md-end">
                <form action="{{ route('wms.inbound.cancel') }}" method="POST" class="d-inline">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="extension" value="{{ $extension }}">
                    <button type="submit" class="btn btn-outline-secondary px-4">
                        <i class="bi bi-x-lg me-1"></i> Batal
                    </button>
                </form>
                <button type="submit" form="storeForm" class="btn btn-primary px-4 fw-bold shadow-sm"
                        @disabled($summary['siap'] === 0)>
                    <i class="bi bi-save me-1"></i> Submit ({{ $summary['palet'] }} palet)
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
