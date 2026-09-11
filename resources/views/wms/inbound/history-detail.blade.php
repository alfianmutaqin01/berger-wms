@extends('layouts.wms')

@section('title', 'Detail Riwayat Produksi')
@section('page_title', 'Detail Riwayat Produksi')

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4" id="printableArea">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-file-earmark-text text-primary me-2"></i>
                        Dokumen: <span class="font-monospace">{{ $header->document_number }}</span>
                    </h5>
                    <p class="text-muted small mt-1 mb-0">Rincian palet hasil produksi pada dokumen ini.</p>
                </div>
                <button class="btn btn-danger fw-bold shadow-sm d-print-none" onclick="window.print()">
                    <i class="bi bi-file-earmark-pdf me-1"></i> Cetak PDF
                </button>
            </div>

            <div class="card-body p-4">

                <!-- Identitas dokumen -->
                <div class="row mb-4 bg-light rounded-3 p-3 mx-0">
                    <div class="col-md-3 col-6 mb-3 mb-md-0">
                        <small class="text-muted d-block fw-semibold">TANGGAL PRODUKSI</small>
                        <span class="fw-bold text-dark">{{ $header->production_date->translatedFormat('d F Y') }}</span>
                    </div>
                    <div class="col-md-3 col-6 mb-3 mb-md-0">
                        <small class="text-muted d-block fw-semibold">GUDANG TUJUAN</small>
                        <span class="fw-bold text-dark">{{ $header->warehouse?->display_label ?? '—' }}</span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block fw-semibold">DIBUAT OLEH</small>
                        <span class="fw-bold text-dark">{{ $header->creator?->full_name ?? '—' }}</span>
                    </div>
                    <div class="col-md-3 col-6">
                        <small class="text-muted d-block fw-semibold">STATUS</small>
                        @php
                            $warna = match($header->status) {
                                \App\Models\InboundHeader::STATUS_VERIFIED => 'success',
                                \App\Models\InboundHeader::STATUS_PUTAWAY_PENDING => 'warning',
                                default => 'info',
                            };
                            $ikon = match($header->status) {
                                \App\Models\InboundHeader::STATUS_VERIFIED => 'bi-check-circle',
                                \App\Models\InboundHeader::STATUS_PUTAWAY_PENDING => 'bi-hourglass-split',
                                default => 'bi-shield-check',
                            };
                        @endphp
                        <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis border border-{{ $warna }}">
                            <i class="bi {{ $ikon }} me-1"></i>{{ $header->status_label }}
                        </span>
                    </div>
                </div>

                @if($header->notes)
                    <div class="alert alert-light border small mb-4">
                        <i class="bi bi-sticky text-primary me-1"></i><strong>Catatan:</strong> {{ $header->notes }}
                    </div>
                @endif

                <!-- Ringkasan -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Total Palet</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['palet']) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Total Qty</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['qty']) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Jenis Produk</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['produk']) }}</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="border rounded-3 p-3">
                            <div class="text-muted small mb-1">Jumlah Batch</div>
                            <div class="fs-4 fw-bold text-dark">{{ number_format($totals['batch']) }}</div>
                        </div>
                    </div>
                </div>

                {{-- PANEL SELISIH.
                     Sebelum panel ini ada, Tim Produksi tidak punya cara tahu
                     bahwa angka yang mereka tulis meleset: selisihnya hanya
                     beredar antara Operator dan Logistik, sementara yang bisa
                     memperbaiki sumbernya justru Produksi. Dipisah dari tabel
                     besar di bawah karena pada dokumen berisi puluhan palet,
                     "cari sendiri baris yang qty-nya beda" adalah cara paling
                     andal membuat selisih terlewat. --}}
                @if($berselisih->isNotEmpty())
                    <div class="alert alert-warning border-0 rounded-3 mb-4 d-print-none">
                        <h6 class="fw-bold mb-2">
                            <i class="bi bi-exclamation-diamond-fill me-1"></i>
                            {{ $berselisih->count() }} palet dihitung berbeda oleh Operator
                        </h6>
                        <p class="small mb-3">
                            Angka di bawah ini adalah hasil hitung fisik di gudang. <strong>Stok memakai angka fisik ini</strong>,
                            bukan angka dokumen — jadi isi rak sudah benar. Yang belum benar adalah dokumen produksinya.
                        </p>

                        <form action="{{ route('wms.inbound.history.adjust', $header->document_number) }}" method="POST">
                            @csrf
                            <div class="table-responsive bg-white rounded-3 border mb-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            @if($bolehSesuaikan)<th style="width: 40px;"></th>@endif
                                            <th class="small text-nowrap">BATCH</th>
                                            <th class="small text-nowrap">PALET</th>
                                            <th class="small">PRODUK</th>
                                            <th class="small text-end text-nowrap">DITULIS PRODUKSI</th>
                                            <th class="small text-end text-nowrap">HITUNG FISIK</th>
                                            <th class="small text-end text-nowrap">SELISIH</th>
                                            <th class="small text-nowrap">KETERANGAN</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($berselisih as $d)
                                            <tr>
                                                @if($bolehSesuaikan)
                                                    <td>
                                                        {{-- Palet yang sudah disesuaikan tidak lagi bisa dipilih:
                                                             menyesuaikan dua kali akan menimpa angka semula dengan
                                                             angka yang sudah disesuaikan, dan jejak aslinya hilang. --}}
                                                        <input class="form-check-input" type="checkbox" name="pallets[]"
                                                               value="{{ $d->id }}"
                                                               @checked(! $d->sudah_disesuaikan)
                                                               @disabled($d->sudah_disesuaikan)>
                                                    </td>
                                                @endif
                                                <td class="font-monospace small text-nowrap">{{ $d->batch_no }}</td>
                                                <td class="small text-nowrap">#{{ $d->pallet_no }}</td>
                                                <td class="small">{{ $d->product?->sku }} — {{ $d->product?->name }}</td>
                                                <td class="text-end fw-semibold">{{ number_format($d->qty_sistem_asli) }}</td>
                                                <td class="text-end fw-semibold text-primary">{{ number_format($d->qty_actual) }}</td>
                                                <td class="text-end fw-bold {{ $d->qty_variance > 0 ? 'text-success' : 'text-danger' }}">
                                                    {{ $d->qty_variance > 0 ? '+' : '' }}{{ number_format($d->qty_variance) }}
                                                </td>
                                                <td class="small">
                                                    @if($d->sudah_disesuaikan)
                                                        <span class="badge bg-success-subtle text-success-emphasis border border-success">Sudah disesuaikan</span>
                                                        <span class="d-block text-muted">
                                                            {{ $d->qtyAdjustedBy?->full_name ?? '—' }},
                                                            {{ $d->qty_adjusted_at?->translatedFormat('d M Y H:i') }}
                                                            — {{ $d->qty_adjust_reason }}
                                                        </span>
                                                    @else
                                                        <span class="text-muted">Belum ditanggapi</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if($bolehSesuaikan)
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-semibold" for="alasanSesuaikan">
                                            Alasan penyesuaian <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="alasanSesuaikan" name="reason"
                                               minlength="10" maxlength="500" required
                                               placeholder="mis. salah ketik qty di berkas produksi, atau satu palet pecah saat dipindah">
                                    </div>
                                    <div class="col-md-4 text-md-end">
                                        <button type="submit" class="btn btn-warning fw-bold w-100 w-md-auto">
                                            <i class="bi bi-check2-square me-1"></i> Sesuaikan &amp; Simpan
                                        </button>
                                    </div>
                                </div>
                                <p class="small text-muted mt-2 mb-0">
                                    Angka dokumen akan mengikuti hitungan fisik. <strong>Angka semula tetap tercatat</strong>
                                    dan selisihnya <strong>tetap terlihat oleh Logistik</strong> saat verifikasi —
                                    penyesuaian ini menambah keterangan, bukan menghapus temuan.
                                </p>
                            @elseif($header->status === \App\Models\InboundHeader::STATUS_VERIFIED)
                                <p class="small mb-0">
                                    <i class="bi bi-lock-fill me-1"></i>
                                    Dokumen sudah diverifikasi Logistik dan stoknya sudah aktif, jadi angkanya tidak bisa
                                    diubah lagi dari sini. Perbaikan setelah tahap ini dilakukan lewat Koreksi Stok.
                                </p>
                            @endif
                        </form>
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 border">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-nowrap">NO. PRODUKSI</th>
                                <th class="text-secondary small fw-semibold text-nowrap">SKU</th>
                                <th class="text-secondary small fw-semibold" style="min-width: 220px;">DESKRIPSI PRODUK</th>
                                <th class="text-secondary small fw-semibold text-nowrap">BATCH</th>
                                <th class="text-secondary small fw-semibold text-center text-nowrap">PALET</th>
                                <th class="text-secondary small fw-semibold text-end text-nowrap">QTY / MAKS</th>
                                <th class="text-secondary small fw-semibold text-nowrap">LOKASI RAK</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $nomorProduksiSebelumnya = null; @endphp
                            @foreach($details as $detail)
                                @php
                                    // Nomor produksi & SKU hanya ditulis pada palet
                                    // pertama dari kelompoknya, agar deretan palet
                                    // yang berasal dari satu baris produksi terbaca
                                    // sebagai satu kesatuan.
                                    $awalKelompok = $detail->production_order_no !== $nomorProduksiSebelumnya;
                                    $nomorProduksiSebelumnya = $detail->production_order_no;
                                    $kapasitas = $detail->product?->kapasitasPalet();
                                    $penuh = $kapasitas && $detail->pallet_qty === $kapasitas;
                                @endphp
                                <tr class="{{ $awalKelompok && ! $loop->first ? 'border-top border-2' : '' }}">
                                    <td class="font-monospace small text-muted text-nowrap">
                                        {{ $awalKelompok ? ($detail->production_order_no ?? '—') : '' }}
                                    </td>
                                    <td class="text-nowrap">
                                        @if($awalKelompok)
                                            <span class="badge bg-light text-dark border font-monospace">{{ $detail->product?->sku ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $awalKelompok ? ($detail->product?->name ?? '—') : '' }}</td>
                                    <td class="font-monospace small text-nowrap">{{ $awalKelompok ? $detail->batch_no : '' }}</td>
                                    <td class="text-center fw-bold">#{{ $detail->pallet_no }}</td>
                                    <td class="text-end text-nowrap">
                                        {{-- Palet penuh vs palet sisa dibedakan agar
                                             terlihat mana yang belum terisi penuh. --}}
                                        <span class="badge border px-2 py-1 {{ $penuh ? 'bg-primary-subtle text-primary border-primary' : 'bg-warning-subtle text-warning-emphasis border-warning' }}">
                                            {{ number_format($detail->pallet_qty) }} / {{ number_format($kapasitas ?? 0) }}
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        @if($detail->location)
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success font-monospace">{{ $detail->location->code }}</span>
                                        @else
                                            <span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Belum ditempatkan</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 pt-3 border-top d-flex justify-content-between d-print-none">
                    <a href="{{ route('wms.inbound.history') }}" class="btn btn-outline-secondary px-4">
                        <i class="bi bi-arrow-left me-1"></i> Kembali ke Riwayat
                    </a>
                </div>

            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #printableArea, #printableArea * {
        visibility: visible !important;
    }
    #printableArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        border: none !important;
        box-shadow: none !important;
    }
    .d-print-none, header, .sidebar, .btn {
        display: none !important;
    }
    .badge {
        border: 1px solid #000 !important;
        color: #000 !important;
        background: transparent !important;
    }
}
</style>
@endsection
