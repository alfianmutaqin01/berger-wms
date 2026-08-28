@extends('layouts.wms')

@section('title', 'Master Produk')
@section('page_title', 'Master Data Produk')

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
                <h6 class="text-muted fw-normal mb-2">Total SKU</h6>
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
        {{-- Kapasitas palet wajib terisi agar pemecahan palet otomatis (PRD §7.1)
             bisa berjalan. Produk yang belum terisi ditonjolkan di sini. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Palet Belum Diisi</h6>
                <h3 class="mb-0 fw-bold text-warning">{{ $stats['no_pallet'] }}</h3>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i> Master Data Produk</h5>
                    <p class="text-muted small mt-1 mb-0">Daftar referensi seluruh SKU produk tanpa memuat informasi jumlah stok.</p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-secondary fw-bold shadow-sm me-2"
                            data-bs-toggle="modal" data-bs-target="#importExcelModal">
                        <i class="bi bi-upload me-1"></i> Import Excel
                    </button>
                    <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openProductModal('add')">
                        <i class="bi bi-plus-circle me-1"></i> Tambah Produk
                    </button>
                </div>
            </div>
            <div class="card-body p-4">

                <!-- Filter: submit via GET agar hasil filter bisa di-bookmark & di-share -->
                <form method="GET" action="{{ route('wms.products.index') }}" class="row g-2 mb-4 align-items-stretch">
                    <div class="col-12 col-md-4">
                        <div class="input-group h-100">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-white border-start-0" placeholder="Cari SKU, Description, Shade...">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="category_id" class="form-select h-100">
                            <option value="">Semua Type</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected($filters['category_id'] == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="uom" class="form-select h-100">
                            <option value="">Semua UoM</option>
                            @foreach($uoms as $uom)
                                <option value="{{ $uom }}" @selected($filters['uom'] === $uom)>{{ $uom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="status" class="form-select h-100">
                            <option value="">Semua Status</option>
                            <option value="active" @selected($filters['status'] === 'active')>Aktif</option>
                            <option value="inactive" @selected($filters['status'] === 'inactive')>Non-aktif</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                        <a href="{{ route('wms.products.index') }}" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        {{-- Urutan kolom: identitas -> klasifikasi -> ukuran -> operasional.
                             Kolom "KEMASAN" (ukuran wadah) sengaja dipisah dari "VOL. ISI"
                             (unit_volume) karena keduanya memang berbeda: pail 20 L bisa
                             berisi 19.4 L, dan aturan palet memakai ukuran wadahnya. --}}
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary small fw-semibold text-center" style="width: 46px;">NO</th>
                                <th class="text-secondary small fw-semibold">SAPSKU</th>
                                <th class="text-secondary small fw-semibold" style="min-width: 240px;">DESCRIPTION</th>
                                <th class="text-secondary small fw-semibold text-center">PROD<br>CODE</th>
                                <th class="text-secondary small fw-semibold text-center">SHADE<br>CODE</th>
                                <th class="text-secondary small fw-semibold text-center">PACK<br>CODE</th>
                                <th class="text-secondary small fw-semibold">PRODUCT TYPE</th>
                                <th class="text-secondary small fw-semibold text-center">UoM</th>
                                <th class="text-secondary small fw-semibold text-end">KEMASAN</th>
                                <th class="text-secondary small fw-semibold text-end">VOL. ISI</th>
                                <th class="text-secondary small fw-semibold text-end">NET (KG)</th>
                                <th class="text-secondary small fw-semibold text-end">GROSS (KG)</th>
                                <th class="text-secondary small fw-semibold text-center">MAKS<br>PALET</th>
                                <th class="text-secondary small fw-semibold text-center">MASA<br>SIMPAN</th>
                                <th class="text-secondary small fw-semibold text-center">STATUS</th>
                                <th class="text-secondary small fw-semibold text-center pe-3">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $product)
                                @php
                                    // Payload untuk mengisi modal edit. Disiapkan di sini
                                    // (bukan inline di atribut onclick) agar Blade tidak
                                    // salah membaca array yang terpotong antar baris.
                                    $payload = [
                                        'id' => $product->id,
                                        'sku' => $product->sku,
                                        'name' => $product->name,
                                        'product_code' => $product->product_code,
                                        'shade_code' => $product->shade_code,
                                        'pack_code' => $product->pack_code,
                                        'category_id' => $product->category_id,
                                        'uom' => $product->uom,
                                        'pack_size' => $product->pack_size,
                                        'pack_unit' => $product->pack_unit,
                                        'unit_volume' => $product->unit_volume,
                                        'net_weight' => $product->net_weight,
                                        'gross_weight' => $product->gross_weight,
                                        'max_qty_per_pallet' => $product->max_qty_per_pallet,
                                        'shelf_life_months' => $product->shelf_life_months,
                                        'stock_threshold_low' => $product->stock_threshold_low,
                                        'is_active' => $product->is_active,
                                    ];
                                    $dimmed = $product->is_active ? '' : 'opacity-50';
                                @endphp
                                <tr class="{{ $dimmed }}">
                                    <td class="text-center text-muted">{{ $loop->iteration + ($products->currentPage() - 1) * $products->perPage() }}</td>
                                    <td class="font-monospace fw-bold text-dark text-nowrap">{{ $product->sku }}</td>
                                    <td class="text-dark">{{ $product->name }}</td>
                                    <td class="text-center font-monospace small text-muted">{{ $product->product_code }}</td>
                                    <td class="text-center font-monospace small text-muted">{{ $product->shade_code }}</td>
                                    <td class="text-center font-monospace small text-muted">{{ $product->pack_code }}</td>
                                    <td class="text-nowrap">
                                        @if($product->category)
                                            <span class="badge bg-secondary text-white">{{ $product->category->name }}</span>
                                        @else
                                            {{-- Pada ekspor ERP nilainya "Tidak ditemukan" — dibiarkan kosong
                                                 agar masalahnya terlihat, bukan disamarkan jadi kategori palsu. --}}
                                            <span class="badge bg-light text-muted border" title="Product Type tidak ditemukan di ERP">Belum berkategori</span>
                                        @endif
                                    </td>
                                    <td class="text-center"><span class="badge bg-light text-dark border">{{ $product->uom }}</span></td>
                                    <td class="text-end font-monospace fw-semibold text-dark text-nowrap">{{ $product->pack_label }}</td>
                                    <td class="text-end font-monospace text-muted">{{ $product->unit_volume !== null ? rtrim(rtrim(number_format((float) $product->unit_volume, 3, '.', ''), '0'), '.') : '—' }}</td>
                                    <td class="text-end font-monospace text-muted">{{ $product->net_weight !== null ? number_format((float) $product->net_weight, 2) : '—' }}</td>
                                    <td class="text-end font-monospace text-muted">{{ $product->gross_weight !== null ? number_format((float) $product->gross_weight, 2) : '—' }}</td>
                                    <td class="text-center">
                                        @if($product->max_qty_per_pallet)
                                            <span class="fw-bold text-dark">{{ number_format($product->max_qty_per_pallet) }}</span>
                                        @else
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning text-nowrap"
                                                  title="Ukuran kemasan tidak ada di aturan palet gudang. Mohon isi manual.">
                                                <i class="bi bi-exclamation-triangle"></i> Belum diisi
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-center text-muted small text-nowrap">{{ $product->shelf_life_months }} bln</td>
                                    <td class="text-center">
                                        @if($product->is_active)
                                            <span class="badge bg-success-subtle text-success-emphasis border border-success">Aktif</span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary">Non-aktif</span>
                                        @endif
                                    </td>
                                    <td class="text-center pe-3 text-nowrap">
                                        <button class="btn btn-sm btn-outline-secondary" title="Sunting"
                                                data-bs-toggle="modal" data-bs-target="#productModal"
                                                onclick='openProductModal("edit", @json($payload))'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form action="{{ route('wms.products.status', $product) }}" method="POST" class="d-inline js-toggle-status">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm {{ $product->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                    data-sku="{{ $product->sku }}" data-action="{{ $product->is_active ? 'menonaktifkan' : 'mengaktifkan' }}"
                                                    title="{{ $product->is_active ? 'Nonaktifkan' : 'Aktifkan' }}">
                                                <i class="bi {{ $product->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="16" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                        Belum ada produk yang cocok dengan filter ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($products->hasPages())
                    <div class="mt-4">{{ $products->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('modals')
<!-- Modal Import Excel -->
<div class="modal fade" id="importExcelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="{{ route('wms.products.import.preview') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Import Produk dari Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Berkas Excel (.xlsx / .xls) *</label>
                        <input class="form-control" type="file" name="file" accept=".xlsx,.xls" required>
                        <div class="form-text">Ukuran maksimal 10 MB, hingga 5.000 baris.</div>
                    </div>

                    <div class="alert alert-light border small mb-0">
                        <strong>Kolom yang dibaca</strong> (baris pertama harus berisi judul kolom):
                        <div class="font-monospace mt-2" style="font-size: 0.78rem;">
                            No. · Description · Product Code · Shade Code · Pack Code ·<br>
                            Base Unit of Measure · Net Weight · Gross Weight · Unit Volume · Product Type
                        </div>
                        <hr class="my-2">
                        Kolom <strong>Inventory diabaikan</strong> — jumlah stok bukan data master.<br>
                        SKU yang sudah ada akan <strong>diperbarui</strong>, yang belum ada ditambahkan.
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm"><i class="bi bi-search me-1"></i> Pratinjau Data</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah / Sunting Produk -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form id="productForm" method="POST" action="{{ route('wms.products.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-box-seam text-primary me-2"></i> <span id="modalTitle">Tambah Master Produk</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">

                    <div class="row g-3 mb-3">
                        <div class="col-4">
                            <label class="form-label small fw-semibold text-secondary">Product Code *</label>
                            <input type="text" name="product_code" id="inpProductCode" class="form-control font-monospace" placeholder="0011" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold text-secondary">Shade Code *</label>
                            <input type="text" name="shade_code" id="inpShadeCode" class="form-control font-monospace" placeholder="3202" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-semibold text-secondary">Pack Code *</label>
                            <input type="text" name="pack_code" id="inpPackCode" class="form-control font-monospace" placeholder="225" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">SAPSKU *</label>
                        <input type="text" name="sku" id="inpSku" class="form-control font-monospace bg-light" placeholder="terbentuk otomatis" required>
                        <div class="form-text">Terbentuk otomatis dari ketiga kode di atas. Boleh disunting bila SKU dari ERP berbeda.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Description *</label>
                        <input type="text" name="name" id="inpName" class="form-control" placeholder="Royale Smart Clean White 2.5Ltr" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Product Type</label>
                            <select name="category_id" id="inpCategory" class="form-select">
                                <option value="">— Tanpa kategori —</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">UoM (Kemasan) *</label>
                            <input type="text" name="uom" id="inpUom" class="form-control" list="uomOptions" placeholder="TIN / PAI / KG / CAN" required>
                            <datalist id="uomOptions">
                                @foreach($uoms as $uom)<option value="{{ $uom }}">@endforeach
                                <option value="TIN"><option value="PAI"><option value="CAN"><option value="KG">
                            </datalist>
                        </div>
                    </div>

                    <hr class="my-4">
                    <p class="small fw-semibold text-secondary mb-3">
                        <i class="bi bi-pallet me-1"></i> Ukuran Kemasan &amp; Kapasitas Palet
                    </p>

                    <div class="alert alert-light border small mb-3">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        <strong>Ukuran Kemasan</strong> adalah ukuran wadahnya (pail 20 L), sedangkan
                        <strong>Volume Isi</strong> adalah isi sebenarnya menurut ERP — bisa lebih kecil,
                        misal 19,4 L, karena menyisakan ruang untuk pewarna. Kapasitas palet dihitung dari
                        <strong>ukuran wadah</strong>.
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Ukuran Kemasan *</label>
                            <input type="text" name="pack_size" id="inpPackSize" class="form-control" placeholder="20">
                            <div class="form-text">Kosongkan agar dibaca dari nama produk.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Satuan Ukuran</label>
                            <select name="pack_unit" id="inpPackUnit" class="form-select">
                                <option value="">— Otomatis —</option>
                                <option value="L">Liter (L)</option>
                                <option value="KG">Kilogram (KG)</option>
                            </select>
                            <div class="form-text">20 L = 27 pcs, 20 Kg = 36 pcs.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Volume Isi (L)</label>
                            <input type="text" name="unit_volume" id="inpVolume" class="form-control" placeholder="19.4">
                            <div class="form-text">Angka apa adanya dari ERP.</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Net Weight (Kg)</label>
                            <input type="text" name="net_weight" id="inpNet" class="form-control" placeholder="0">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Gross Weight (Kg)</label>
                            <input type="text" name="gross_weight" id="inpGross" class="form-control" placeholder="26.79">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Maks per Palet</label>
                            <input type="number" name="max_qty_per_pallet" id="inpPallet" class="form-control" placeholder="otomatis" min="1">
                            <div class="form-text">Kosongkan agar dihitung otomatis.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Masa Simpan (bulan) *</label>
                            <input type="number" name="shelf_life_months" id="inpShelf" class="form-control" value="30" min="1" max="120" required>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Batas Stok Menipis *</label>
                            <input type="number" name="stock_threshold_low" id="inpThreshold" class="form-control" value="50" min="0" required>
                            <div class="form-text">Dasar indikator ⚠ Terbatas pada Portal Sales.</div>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check mt-4">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" name="is_active" id="inpActive" value="1" checked>
                                <label class="form-check-label" for="inpActive">Produk aktif</label>
                            </div>
                        </div>
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
    const PRODUCT_STORE_URL = @json(route('wms.products.store'));
    const SKU_PREFIX = @json(\App\Models\Product::SKU_PREFIX);

    function buildSku() {
        const p = document.getElementById('inpProductCode').value.trim();
        const s = document.getElementById('inpShadeCode').value.trim();
        const k = document.getElementById('inpPackCode').value.trim();

        if (p && s && k) {
            document.getElementById('inpSku').value = (SKU_PREFIX + p + s + k).toUpperCase();
        }
    }

    function openProductModal(mode, data = null) {
        const form = document.getElementById('productForm');
        const method = document.getElementById('formMethod');

        if (mode === 'add') {
            form.reset();
            form.action = PRODUCT_STORE_URL;
            method.value = 'POST';
            document.getElementById('modalTitle').textContent = 'Tambah Master Produk';
            document.getElementById('inpShelf').value = 30;
            document.getElementById('inpThreshold').value = 50;
            document.getElementById('inpActive').checked = true;
            return;
        }

        form.action = PRODUCT_STORE_URL + '/' + data.id;
        method.value = 'PUT';
        document.getElementById('modalTitle').textContent = 'Sunting Produk';

        document.getElementById('inpProductCode').value = data.product_code ?? '';
        document.getElementById('inpShadeCode').value = data.shade_code ?? '';
        document.getElementById('inpPackCode').value = data.pack_code ?? '';
        document.getElementById('inpSku').value = data.sku ?? '';
        document.getElementById('inpName').value = data.name ?? '';
        document.getElementById('inpCategory').value = data.category_id ?? '';
        document.getElementById('inpUom').value = data.uom ?? '';
        document.getElementById('inpPackSize').value = data.pack_size ?? '';
        document.getElementById('inpPackUnit').value = data.pack_unit ?? '';
        document.getElementById('inpVolume').value = data.unit_volume ?? '';
        document.getElementById('inpNet').value = data.net_weight ?? '';
        document.getElementById('inpGross').value = data.gross_weight ?? '';
        document.getElementById('inpPallet').value = data.max_qty_per_pallet ?? '';
        document.getElementById('inpShelf').value = data.shelf_life_months ?? 30;
        document.getElementById('inpThreshold').value = data.stock_threshold_low ?? 50;
        document.getElementById('inpActive').checked = !!data.is_active;
    }

    document.addEventListener('DOMContentLoaded', function () {
        ['inpProductCode', 'inpShadeCode', 'inpPackCode'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', buildSku);
        });

        // Konfirmasi sebelum mengubah status, agar tidak terjadi karena salah klik.
        document.querySelectorAll('.js-toggle-status').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                const btn = form.querySelector('button[type="submit"]');
                if (form.dataset.confirmed === 'yes') {
                    return;
                }
                e.preventDefault();

                Swal.fire({
                    title: 'Ubah status produk?',
                    text: 'Anda akan ' + btn.dataset.action + ' produk ' + btn.dataset.sku + '.',
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
            new bootstrap.Modal(document.getElementById('productModal')).show();
        @endif
    });
</script>
@endpush
