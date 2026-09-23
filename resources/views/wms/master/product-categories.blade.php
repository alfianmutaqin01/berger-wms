@extends('layouts.wms')

@section('title', 'Master Kategori Produk')
@section('page_title', 'Master Data Kategori Produk')

@section('content')
{{-- Kategorinya sudah dipakai sejak awal sebagai saringan di Master Produk
     dan Daftar Stok; yang tidak pernah ada adalah cara mengelolanya. Sebelum
     halaman ini, kategori baru menuntut impor berkas atau orang yang bisa
     menyentuh basis data. --}}

@foreach(['success' => 'check-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : 'success' }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

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
                <h6 class="text-muted fw-normal mb-2">Total Kategori</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ $stats['total'] }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Aktif</h6>
                <h3 class="mb-0 fw-bold text-success">{{ $stats['active'] }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-secondary border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Non-aktif</h6>
                <h3 class="mb-0 fw-bold text-secondary">{{ $stats['inactive'] }}</h3>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        {{-- Biasanya salah ketik yang ditinggalkan. Tidak ada layar lain yang
             akan menyebutnya, jadi disebut di sini. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Belum dipakai produk</h6>
                <h3 class="mb-0 fw-bold text-warning">{{ $stats['tanpa_produk'] }}</h3>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="fw-bold text-dark mb-0">Daftar Kategori</h5>
            <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#categoryModal"
                    onclick="openCategoryModal('add')">
                <i class="bi bi-plus-lg me-1"></i> Tambah Kategori
            </button>
        </div>

        <form method="GET" action="{{ route('wms.product-categories.index') }}" class="row g-2 mb-4 align-items-stretch">
            <div class="col-8 col-md-5">
                <input type="text" name="search" value="{{ $filters['search'] }}"
                       class="form-control rounded-3" placeholder="Cari nama atau keterangan kategori">
            </div>
            <div class="col-4 col-md-3">
                <select name="status" class="form-select rounded-3">
                    <option value="">Semua status</option>
                    <option value="active" @selected($filters['status'] === 'active')>Aktif</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Non-aktif</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button class="btn btn-outline-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-secondary small fw-semibold">NAMA</th>
                        <th class="text-secondary small fw-semibold">KETERANGAN</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">PRODUK</th>
                        <th class="text-secondary small fw-semibold text-center">STATUS</th>
                        <th class="text-secondary small fw-semibold text-center pe-3">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($categories as $category)
                    @php
                        // Payload untuk mengisi modal sunting. Disiapkan di sini,
                        // bukan inline di atribut onclick, mengikuti pola Master
                        // Pelanggan — Blade tidak salah membaca array yang
                        // terpotong antar baris.
                        $payload = [
                            'id' => $category->id,
                            'name' => $category->name,
                            'description' => $category->description,
                            'is_active' => $category->is_active,
                        ];
                    @endphp
                    <tr class="{{ $category->is_active ? '' : 'opacity-50' }}">
                        <td class="fw-semibold text-dark">{{ $category->name }}</td>
                        <td class="small text-muted">{{ $category->description ?: '—' }}</td>
                        <td class="text-center">
                            @if($category->products_count > 0)
                                <a href="{{ route('wms.products.index', ['category_id' => $category->id]) }}"
                                   class="badge bg-info-subtle text-info-emphasis border border-info text-decoration-none">
                                    {{ $category->products_count }} produk
                                </a>
                            @else
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">Belum dipakai</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($category->is_active)
                                <span class="badge bg-success-subtle text-success-emphasis border border-success">Aktif</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary">Non-aktif</span>
                            @endif
                        </td>
                        <td class="text-center pe-3 text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary" title="Sunting"
                                    data-bs-toggle="modal" data-bs-target="#categoryModal"
                                    onclick='openCategoryModal("edit", @json($payload))'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form action="{{ route('wms.product-categories.status', $category) }}" method="POST" class="d-inline js-toggle-status">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-sm {{ $category->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                        data-name="{{ $category->name }}"
                                        data-produk="{{ $category->products_count }}"
                                        data-action="{{ $category->is_active ? 'menonaktifkan' : 'mengaktifkan' }}"
                                        title="{{ $category->is_active ? 'Nonaktifkan' : 'Aktifkan' }}">
                                    <i class="bi {{ $category->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-tags display-6 d-block mb-2 opacity-50"></i>
                            Belum ada kategori produk.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $categories->links() }}</div>
    </div>
</div>
@endsection

@push('modals')
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="categoryForm" method="POST" action="{{ route('wms.product-categories.store') }}">
            @csrf
            <input type="hidden" name="_method" id="categoryFormMethod" value="POST">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark">
                        <i class="bi bi-tags text-primary me-2"></i>
                        <span id="categoryModalTitle">Tambah Kategori</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Kategori *</label>
                        <input type="text" name="name" id="inpCategoryName" class="form-control"
                               maxlength="100" placeholder="Cat Tembok" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Keterangan</label>
                        <textarea name="description" id="inpCategoryDescription" class="form-control" rows="2"
                                  maxlength="500" placeholder="Penjelasan singkat, boleh dikosongkan"></textarea>
                    </div>
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               id="inpCategoryActive" checked>
                        <label class="form-check-label small" for="inpCategoryActive">Aktif</label>
                        <div class="form-text">
                            Yang non-aktif tidak lagi muncul sebagai pilihan saat menambah produk baru.
                            Produk yang sudah memakainya tidak berubah.
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
<script>
    const CATEGORY_STORE_URL = @json(route('wms.product-categories.store'));

    function openCategoryModal(mode, data = null) {
        const form = document.getElementById('categoryForm');
        const method = document.getElementById('categoryFormMethod');

        if (mode === 'add') {
            form.reset();
            form.action = CATEGORY_STORE_URL;
            method.value = 'POST';
            document.getElementById('categoryModalTitle').textContent = 'Tambah Kategori';
            document.getElementById('inpCategoryActive').checked = true;
            return;
        }

        form.action = CATEGORY_STORE_URL + '/' + data.id;
        method.value = 'PUT';
        document.getElementById('categoryModalTitle').textContent = 'Sunting Kategori';

        document.getElementById('inpCategoryName').value = data.name ?? '';
        document.getElementById('inpCategoryDescription').value = data.description ?? '';
        document.getElementById('inpCategoryActive').checked = !!data.is_active;
    }

    // Nama kategori diketik orang dan disisipkan ke HTML dialog konfirmasi.
    // Tanpa ini, satu nama berisi tag menjalankan skrip di layar setiap orang
    // yang menekan tombol status kategori tersebut.
    function escapeHtml(teks) {
        const d = document.createElement('div');
        d.textContent = teks == null ? '' : String(teks);

        return d.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Konfirmasi sebelum mengubah status, agar tidak terjadi karena salah
        // klik. Jumlah produk yang memakainya ikut disebut: menonaktifkan
        // kategori yang dipakai 300 produk dan yang dipakai nol produk terasa
        // sama di layar, padahal akibatnya jauh berbeda.
        document.querySelectorAll('.js-toggle-status').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (form.dataset.confirmed === 'yes') {
                    return;
                }
                e.preventDefault();

                const btn = form.querySelector('button[type="submit"]');
                const produk = parseInt(btn.dataset.produk || '0', 10);
                const aksi = btn.dataset.action;

                Swal.fire({
                    title: 'Ubah status kategori?',
                    html: 'Anda akan <strong>' + aksi + '</strong> kategori <strong>' + escapeHtml(btn.dataset.name) + '</strong>.'
                        + (aksi === 'menonaktifkan' && produk > 0
                            ? '<br><br><span class="text-muted small">' + produk
                              + ' produk memakainya dan TIDAK akan berubah — kategori ini hanya tidak lagi muncul sebagai pilihan baru.</span>'
                            : ''),
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, lanjutkan',
                    cancelButtonText: 'Batal',
                }).then(function (hasil) {
                    if (hasil.isConfirmed) {
                        form.dataset.confirmed = 'yes';
                        form.submit();
                    }
                });
            });
        });
    });
</script>
@endpush
