@extends('layouts.wms')

@section('title', 'Kartu Stok')

@section('content')
@include('wms.partials.tab-audit')

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-journal-text me-2"></i>Kartu Stok</h4>
        <p class="text-muted small mb-0">
            Buku besar mutasi barang: setiap pertambahan dan pengurangan, beserta dokumen penyebabnya.
            Tidak dapat diubah maupun dihapus — koreksi dilakukan dengan menambah baris lawan.
        </p>
    </div>
    <span class="badge bg-dark-subtle text-dark-emphasis border">{{ number_format($stats['baris']) }} mutasi</span>
</div>

{{-- Dua angka besar, bukan empat: yang dicari saat merekonsiliasi adalah
     apakah yang masuk dan yang keluar sepadan dengan yang diperkirakan.
     Alokasi dan batal-alokasi tidak menggerakkan qty tersedia, jadi
     menjumlahkannya di sini justru mengaburkan keduanya. --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Total bertambah</div>
                <div class="fs-4 fw-bold text-success" style="font-variant-numeric:tabular-nums">
                    +{{ number_format($stats['masuk']) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Total berkurang</div>
                <div class="fs-4 fw-bold text-danger" style="font-variant-numeric:tabular-nums">
                    {{ number_format($stats['keluar']) }}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Cari</label>
                <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm"
                       placeholder="SKU, nama produk, batch, atau nomor dokumen">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Jenis mutasi</label>
                <select name="tipe" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($tipeOptions as $kode => $label)
                        <option value="{{ $kode }}" @selected($filters['tipe'] === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($gudangOptions->count() > 1)
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Gudang</label>
                    {{-- KODE PENUH di layar audit, termasuk akhiran jenis
                         barangnya. Buku besar ini memuat pengiriman ke
                         pelanggan — yang selalu finish good — bersama mutasi
                         MRF yang bisa datang dari barang DDP. Memendekkannya
                         menghapus justru keterangan yang membedakan keduanya. --}}
                    <select name="warehouse_id" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        @foreach($gudangOptions as $g)
                            <option value="{{ $g->id }}" @selected((string) $filters['warehouse_id'] === (string) $g->id)>
                                {{ $g->code }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Rentang tanggal</label>
                <div class="input-group input-group-sm">
                    <input type="date" name="dari" value="{{ $filters['dari'] }}" class="form-control">
                    <input type="date" name="sampai" value="{{ $filters['sampai'] }}" class="form-control">
                </div>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button class="btn btn-sm btn-dark px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
                <a href="{{ route('wms.inventory.kartu-stok') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:130px">Waktu</th>
                    <th style="width:230px">Produk</th>
                    <th style="width:130px">Mutasi</th>
                    <th class="text-end" style="width:170px">Perubahan</th>
                    <th style="width:70px">Jenis</th>
                    <th style="width:150px">Nomor Transaksi</th>
                    <th style="width:150px">Untuk</th>
                    <th style="width:130px">Lokasi</th>
                    <th style="width:150px">Pelaku</th>
                </tr>
            </thead>
            <tbody>
            @forelse($halaman as $baris)
                @php($acuan = $dokumen[$baris->reference_type.':'.$baris->reference_id] ?? null)
                <tr>
                    <td class="small text-nowrap">
                        {{ $baris->created_at?->translatedFormat('d M Y') }}
                        <div class="text-muted font-monospace">{{ $baris->created_at?->format('H:i:s') }}</div>
                    </td>
                    {{-- SKU dan nama berdampingan, sesuai layar MRF dan
                         stocktake: dua baris bertumpuk membuat tabel selebar
                         ini jadi dua kali lebih tinggi tanpa menambah apa pun. --}}
                    <td class="small">
                        <span class="font-monospace fw-semibold">{{ $baris->product?->sku ?? '—' }}</span>
                        <span class="text-muted">— {{ $baris->product?->name ?? '—' }}</span>
                        @if($baris->batch_no)
                            <div class="text-muted" style="font-size:.72rem">
                                Batch <span class="font-monospace">{{ $baris->batch_no }}</span>
                            </div>
                        @endif
                    </td>
                    <td class="small">
                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                            {{ $baris->type_label }}
                        </span>
                    </td>
                    {{-- SEBELUM → SESUDAH ikut ditampilkan, bukan hanya
                         selisihnya. Dari situlah baris yang hilang ketahuan:
                         angka "sesudah" satu baris harus sama dengan "sebelum"
                         baris berikutnya, dan lompatan di antaranya berarti ada
                         yang tidak tercatat. --}}
                    <td class="text-end small" style="font-variant-numeric:tabular-nums">
                        <div class="fw-bold {{ $baris->qty_change >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $baris->qty_change > 0 ? '+' : '' }}{{ number_format($baris->qty_change) }}
                            <span class="text-muted fw-normal">{{ $baris->product?->uom }}</span>
                        </div>
                        <div class="text-muted font-monospace" style="font-size:.72rem">
                            {{ number_format($baris->qty_before) }} → {{ number_format($baris->qty_after) }}
                        </div>
                    </td>
                    <td class="small">
                        @if($acuan)
                            <span class="badge bg-dark-subtle text-dark-emphasis border font-monospace">{{ $acuan['kode'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="small font-monospace text-break">{{ $acuan['nomor'] ?? '—' }}</td>
                    <td class="small text-break">{{ $acuan['pihak'] ?? '—' }}</td>
                    <td class="small">
                        <span class="font-monospace">{{ $baris->warehouse?->code ?? '—' }}</span>
                        <div class="text-muted font-monospace" style="font-size:.72rem">
                            {{ $baris->location?->code ?? '—' }}
                        </div>
                    </td>
                    <td class="small">
                        {{ $baris->user?->full_name ?? 'Sistem' }}
                        @if($baris->notes)
                            <div class="text-muted text-break" style="font-size:.72rem">{{ $baris->notes }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted py-5">
                        Belum ada mutasi yang cocok dengan penyaring ini.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($halaman->hasPages())
        <div class="card-footer bg-white border-0 py-3">{{ $halaman->links() }}</div>
    @endif
</div>

<p class="text-center text-muted small mt-3 mb-0">
    <i class="bi bi-shield-check me-1"></i>
    Buku besar ini tidak pernah diubah maupun dihapus. Nomor transaksi yang sama bisa ditelusuri di Log Aktivitas
    untuk melihat siapa yang mengerjakannya.
</p>
@endsection
