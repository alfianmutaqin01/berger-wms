@extends('layouts.wms')

@section('title', 'Stok Opname')
@section('page_title', 'Stok Opname')

@section('content')
{{-- Permintaan pemilik produk: mencocokkan angka sistem dengan barang yang
     benar-benar ada di rak, sebulan atau tiga bulan sekali.

     YANG PALING PENTING DIKATAKAN DI LAYAR INI: membuka sesi dan menghitung
     TIDAK mengubah stok apa pun. Yang mengubah stok hanya pengesahan
     laporannya. Tanpa kalimat itu, orang ragu menekan tombol apa pun karena
     mengira setiap ketikan langsung menggeser angka gudang. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@if($berjalan)
    <div class="card border-0 shadow-sm rounded-4 mb-4 border-start border-warning border-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h5 class="fw-bold text-dark mb-1">
                    <i class="bi bi-hourglass-split text-warning me-2"></i>
                    Sesi {{ $berjalan->reference }} sedang berjalan
                </h5>
                <div class="text-muted small">
                    {{ $berjalan->warehouse?->name }} &middot; {{ $berjalan->scope_label }} &middot;
                    dibuka {{ $berjalan->opened_at?->translatedFormat('d M Y, H:i') }}
                </div>
            </div>
            <a href="{{ route('wms.stocktake.show', $berjalan) }}" class="btn btn-warning fw-bold rounded-3">
                <i class="bi bi-list-check me-1"></i> Lanjutkan Menghitung
            </a>
        </div>
    </div>
@else
    @can(\App\Support\Permission::STOCKTAKE_MANAGE)
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-clipboard-check text-primary me-2"></i> Buka Sesi Opname</h5>
            <small class="text-muted">
                Angka sistem dibekukan saat sesi dibuka. Stok tidak berubah sampai laporannya disahkan.
            </small>
        </div>
        <div class="card-body px-4 pt-3">
            <form method="POST" action="{{ route('wms.stocktake.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold">Gudang</label>
                    <select name="warehouse_id" class="form-select" required>
                        @foreach($warehouses as $w)
                            <option value="{{ $w->id }}" @selected($warehouse?->id === $w->id)>{{ $w->display_label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Cakupan</label>
                    <select name="scope_type" id="cakupan" class="form-select" required>
                        @foreach(\App\Models\StockTake::SCOPE_LABELS as $slug => $label)
                            <option value="{{ $slug }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold">Zona / Deret</label>
                    {{-- Satu kolom, dua daftar. Yang tidak dipakai disembunyikan
                         DAN dinonaktifkan, supaya isian zona tidak ikut terkirim
                         saat cakupannya deret. --}}
                    <select name="scope_value" id="pilihZona" class="form-select" disabled>
                        @foreach($zones as $z)
                            <option value="{{ $z }}">{{ $z }}</option>
                        @endforeach
                    </select>
                    <select name="scope_value" id="pilihDeret" class="form-select d-none" disabled>
                        @foreach($racks as $r)
                            <option value="{{ $r }}">{{ $r }}</option>
                        @endforeach
                    </select>
                    <div id="cakupanPenuh" class="form-text">Seluruh rak di gudang itu.</div>
                </div>
                <div class="col-12 col-md-3 d-grid">
                    <button class="btn btn-primary fw-bold rounded-3">
                        <i class="bi bi-play-circle me-1"></i> Buka Sesi
                    </button>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold mt-2">Catatan (opsional)</label>
                    <input type="text" name="note" class="form-control" maxlength="1000"
                           placeholder="mis. opname triwulan III">
                </div>
            </form>
        </div>
    </div>
    @endcan
@endif

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-clock-history text-primary me-2"></i> Riwayat Opname</h5>
        <small class="text-muted">Terbaru di atas.</small>
    </div>
    <div class="card-body px-4 pt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Gudang</th>
                        <th>Cakupan</th>
                        <th>Kemajuan</th>
                        <th>Status</th>
                        <th>Dibuka</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($sesi as $s)
                    <tr>
                        <td class="fw-semibold font-monospace">{{ $s->reference }}</td>
                        <td>{{ $s->warehouse?->name ?? '—' }}</td>
                        <td class="small">{{ $s->scope_label }}</td>
                        <td>
                            @php($persen = $s->items_count > 0 ? round($s->items_dihitung_count / $s->items_count * 100) : 0)
                            <div class="progress" style="height:6px;width:120px">
                                <div class="progress-bar bg-{{ $persen === 100 ? 'success' : 'warning' }}"
                                     style="width: {{ $persen }}%"></div>
                            </div>
                            <small class="text-muted">
                                {{ number_format($s->items_dihitung_count) }} / {{ number_format($s->items_count) }} baris
                            </small>
                        </td>
                        <td>
                            @php($warna = match($s->status) {
                                \App\Models\StockTake::STATUS_FINALIZED => 'success',
                                \App\Models\StockTake::STATUS_CANCELLED => 'secondary',
                                default => 'warning',
                            })
                            <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis">{{ $s->status_label }}</span>
                            @if($s->sudahDisahkan())
                                <div class="small text-muted mt-1">
                                    {{ $s->finalized_at?->translatedFormat('d M Y, H:i') }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div>{{ $s->opened_at?->translatedFormat('d M Y') }}</div>
                            <small class="text-muted">{{ $s->openedBy?->full_name ?? '—' }}</small>
                        </td>
                        <td class="text-end">
                            @if($s->sedangDihitung())
                                <a href="{{ route('wms.stocktake.show', $s) }}" class="btn btn-sm btn-outline-primary rounded-3">
                                    <i class="bi bi-list-check me-1"></i> Hitung
                                </a>
                            @elseif($s->sudahDisahkan())
                                <a href="{{ route('wms.stocktake.report', $s) }}" class="btn btn-sm btn-outline-secondary rounded-3">
                                    <i class="bi bi-file-earmark-text me-1"></i> Laporan
                                </a>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-clipboard-check display-6 d-block mb-2 opacity-50"></i>
                            Belum pernah ada stok opname.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $sesi->links() }}</div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cakupan = document.getElementById('cakupan');
    if (! cakupan) {
        return;
    }

    const zona = document.getElementById('pilihZona');
    const deret = document.getElementById('pilihDeret');
    const penuh = document.getElementById('cakupanPenuh');

    function sesuaikan() {
        const v = cakupan.value;

        zona.classList.toggle('d-none', v !== 'zone');
        zona.disabled = v !== 'zone';
        deret.classList.toggle('d-none', v !== 'rack');
        deret.disabled = v !== 'rack';
        penuh.classList.toggle('d-none', v !== 'warehouse');
    }

    cakupan.addEventListener('change', sesuaikan);
    sesuaikan();
});
</script>
@endsection
