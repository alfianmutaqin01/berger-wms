@extends('layouts.wms')

@section('title', 'Master Lokasi Rak')
@section('page_title', 'Master Lokasi Rak')

@section('content')
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

<!-- Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Total Bin</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ number_format($stats['total']) }}</h3>
            </div>
        </div>
    </div>
    @foreach($zones as $zone)
        @php
            $warna = match($zone) {
                \App\Models\Location::ZONE_FAST => 'success',
                \App\Models\Location::ZONE_SLOW => 'secondary',
                default => 'info',
            };
        @endphp
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-{{ $warna }} border-4">
                <div class="card-body">
                    <h6 class="text-muted fw-normal mb-2">{{ $zone }}</h6>
                    <h3 class="mb-0 fw-bold text-{{ $warna }}">{{ number_format($stats['per_zone'][$zone] ?? 0) }}</h3>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-grid-3x3-gap text-primary me-2"></i> Master Lokasi Rak</h5>
            <p class="text-muted small mt-1 mb-0">
                Kode berpola <span class="font-monospace">[Rak]-[Level]-[Sel]</span> — contoh
                <span class="font-monospace">B-01-01</span> berarti Rak B, Level 1, Sel 1.
            </p>
        </div>
        <div>
            <a href="{{ route('wms.locations.map') }}" class="btn btn-outline-secondary fw-bold shadow-sm me-2">
                <i class="bi bi-map me-1"></i> Denah Gudang
            </a>
            <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#locationModal" onclick="openLocationModal('add')">
                <i class="bi bi-plus-circle me-1"></i> Tambah Lokasi
            </button>
        </div>
    </div>

    <div class="card-body p-4">
        <!-- Filter: submit via GET agar hasil filter bisa di-bookmark & di-share -->
        <form method="GET" action="{{ route('wms.locations.index') }}" class="row g-2 mb-4 align-items-stretch">
            <div class="col-12 col-md-3">
                <div class="input-group h-100">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-white border-start-0" placeholder="Cari kode bin...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select h-100">
                    <option value="">Semua Gudang</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($filters['warehouse_id'] == $warehouse->id)>{{ $warehouse->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="rack" class="form-select h-100">
                    <option value="">Semua Rak</option>
                    @foreach($racks as $rack)
                        <option value="{{ $rack }}" @selected($filters['rack'] === $rack)>Rak {{ $rack }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-md-1">
                <select name="level" class="form-select h-100">
                    <option value="">Level</option>
                    @for($i = 1; $i <= \App\Models\Location::MAX_LEVEL; $i++)
                        <option value="{{ $i }}" @selected($filters['level'] == $i)>{{ $i }}</option>
                    @endfor
                </select>
            </div>
            <div class="col-8 col-md-2">
                <select name="zone" class="form-select h-100">
                    <option value="">Semua Zona</option>
                    @foreach($zones as $zone)
                        <option value="{{ $zone }}" @selected($filters['zone'] === $zone)>{{ $zone }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <a href="{{ route('wms.locations.index') }}" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-secondary small fw-semibold text-center text-nowrap" style="width: 60px;">NO</th>
                        <th class="text-secondary small fw-semibold text-nowrap">KODE BIN</th>
                        <th class="text-secondary small fw-semibold text-nowrap">GUDANG</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">RAK</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">LEVEL</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">SEL</th>
                        <th class="text-secondary small fw-semibold text-nowrap">ZONA</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">STATUS</th>
                        <th class="text-secondary small fw-semibold text-center pe-3 text-nowrap">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($locations as $location)
                        @php
                            // Payload untuk mengisi modal edit. Disiapkan di sini
                            // (bukan inline di atribut onclick) agar Blade tidak
                            // salah membaca array yang terpotong antar baris.
                            $payload = [
                                'id' => $location->id,
                                'warehouse_id' => $location->warehouse_id,
                                'code' => $location->code,
                                'zone' => $location->zone,
                                'is_active' => $location->is_active,
                            ];
                        @endphp
                        <tr class="{{ $location->is_active ? '' : 'opacity-50' }}">
                            <td class="text-center text-muted">{{ $loop->iteration + ($locations->currentPage() - 1) * $locations->perPage() }}</td>
                            <td class="font-monospace fw-bold text-dark">{{ $location->code }}</td>
                            <td class="small text-muted text-nowrap">{{ $location->warehouse?->code ?? '—' }}</td>
                            <td class="text-center"><span class="badge bg-light text-dark border">{{ $location->rack }}</span></td>
                            <td class="text-center">{{ $location->level }}</td>
                            <td class="text-center">{{ $location->cell }}</td>
                            <td class="text-nowrap">
                                @if($location->zone)
                                    @php
                                        $warna = match($location->zone) {
                                            \App\Models\Location::ZONE_FAST => 'success',
                                            \App\Models\Location::ZONE_SLOW => 'secondary',
                                            default => 'info',
                                        };
                                    @endphp
                                    <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis border border-{{ $warna }}">{{ $location->zone }}</span>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($location->is_active)
                                    <span class="badge bg-success-subtle text-success-emphasis border border-success">Aktif</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary">Non-aktif</span>
                                @endif
                            </td>
                            <td class="text-center pe-3 text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary" title="Sunting"
                                        data-bs-toggle="modal" data-bs-target="#locationModal"
                                        onclick='openLocationModal("edit", @json($payload))'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form action="{{ route('wms.locations.status', $location) }}" method="POST" class="d-inline js-toggle-status">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm {{ $location->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                            data-code="{{ $location->code }}" data-action="{{ $location->is_active ? 'menonaktifkan' : 'mengaktifkan' }}"
                                            title="{{ $location->is_active ? 'Nonaktifkan' : 'Aktifkan' }}">
                                        <i class="bi {{ $location->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                Belum ada lokasi yang cocok dengan filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($locations->hasPages())
            <div class="mt-4">{{ $locations->links() }}</div>
        @endif
    </div>
</div>
@endsection

@push('modals')
<div class="modal fade" id="locationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="locationForm" method="POST" action="{{ route('wms.locations.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-grid-3x3-gap text-primary me-2"></i> <span id="modalTitle">Tambah Lokasi</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Gudang *</label>
                        <select name="warehouse_id" id="inpWarehouse" class="form-select" required>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->display_label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Kode Bin *</label>
                        <input type="text" name="code" id="inpCode" class="form-control font-monospace text-uppercase" placeholder="B-01-01" required>
                        <div class="form-text">
                            Format <span class="font-monospace">[Rak]-[Level]-[Sel]</span>.
                            Rak, level, dan sel dibaca otomatis dari kode ini.
                        </div>
                        <div id="codePreview" class="small mt-2"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Zona</label>
                        <select name="zone" id="inpZone" class="form-select">
                            <option value="">— Tanpa zona —</option>
                            @foreach($zones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Menentukan strategi put-away: barang cepat laku ditempatkan di zona terdekat jalur keluar.</div>
                    </div>

                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" id="inpActive" value="1" checked>
                        <label class="form-check-label" for="inpActive">Lokasi aktif</label>
                        <div class="form-text">Bin non-aktif tidak akan dipilih proses put-away.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm"><i class="bi bi-save me-1"></i> Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
<script>
    const LOCATION_STORE_URL = @json(route('wms.locations.store'));
    const MAX_LEVEL = @json(\App\Models\Location::MAX_LEVEL);

    function openLocationModal(mode, data = null) {
        const form = document.getElementById('locationForm');
        const method = document.getElementById('formMethod');

        if (mode === 'add') {
            form.reset();
            form.action = LOCATION_STORE_URL;
            method.value = 'POST';
            document.getElementById('modalTitle').textContent = 'Tambah Lokasi';
            document.getElementById('inpActive').checked = true;
            document.getElementById('codePreview').innerHTML = '';
            return;
        }

        form.action = LOCATION_STORE_URL + '/' + data.id;
        method.value = 'PUT';
        document.getElementById('modalTitle').textContent = 'Sunting Lokasi';

        document.getElementById('inpWarehouse').value = data.warehouse_id ?? '';
        document.getElementById('inpCode').value = data.code ?? '';
        document.getElementById('inpZone').value = data.zone ?? '';
        document.getElementById('inpActive').checked = !!data.is_active;
        previewCode();
    }

    // Menampilkan hasil pembacaan kode agar salah ketik langsung terlihat
    // sebelum disimpan. Server tetap penegak utamanya.
    function previewCode() {
        const el = document.getElementById('codePreview');
        const code = document.getElementById('inpCode').value.trim().toUpperCase();
        const m = code.match(/^([A-Z]{1,2})-(\d{1,2})-(\d{1,3})$/);

        if (!code) {
            el.innerHTML = '';
            return;
        }

        if (!m) {
            el.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Format belum sesuai — contoh: B-01-01</span>';
            return;
        }

        const level = parseInt(m[2], 10);

        if (level < 1 || level > MAX_LEVEL) {
            el.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Level harus 1–' + MAX_LEVEL + '</span>';
            return;
        }

        el.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Rak <strong>' + m[1] +
            '</strong> · Level <strong>' + level + '</strong> · Sel <strong>' + parseInt(m[3], 10) + '</strong></span>';
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('inpCode').addEventListener('input', previewCode);

        // Konfirmasi sebelum mengubah status, agar tidak terjadi karena salah klik.
        document.querySelectorAll('.js-toggle-status').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                const btn = form.querySelector('button[type="submit"]');
                if (form.dataset.confirmed === 'yes') {
                    return;
                }
                e.preventDefault();

                Swal.fire({
                    title: 'Ubah status lokasi?',
                    text: 'Anda akan ' + btn.dataset.action + ' bin ' + btn.dataset.code + '.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, lanjutkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#1B4F8A',
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.dataset.confirmed = 'yes';
                        form.submit();
                    }
                });
            });
        });

        // Buka kembali modal bila validasi server gagal, agar isian tidak hilang percuma.
        @if($errors->any())
            new bootstrap.Modal(document.getElementById('locationModal')).show();
        @endif
    });
</script>
@endpush
