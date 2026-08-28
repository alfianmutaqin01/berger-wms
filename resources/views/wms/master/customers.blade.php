@extends('layouts.wms')

@section('title', 'Master Customers')
@section('page_title', 'Master Data Pelanggan')

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
                <h6 class="text-muted fw-normal mb-2">Total Pelanggan</h6>
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
        {{-- Ship-to Code adalah nomor pelanggan di ERP; yang belum punya
             ditonjolkan agar bisa dilengkapi. --}}
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Tanpa Ship-to Code</h6>
                <h3 class="mb-0 fw-bold text-warning">{{ $stats['no_ship_to'] }}</h3>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-people text-primary me-2"></i> Master Data Pelanggan</h5>
            {{-- PRD v1.1 §6.2 F-MASTER-06: tidak ada lagi antrean pengajuan dari
                 Sales. Pelanggan didaftarkan langsung dan langsung aktif. --}}
            <p class="text-muted small mt-1 mb-0">Pelanggan didaftarkan langsung oleh Manager/Super Admin — tidak melalui pengajuan Sales.</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-secondary fw-bold shadow-sm me-2"
                    data-bs-toggle="modal" data-bs-target="#importExcelModal">
                <i class="bi bi-upload me-1"></i> Import Excel
            </button>
            <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openCustomerModal('add')">
                <i class="bi bi-plus-circle me-1"></i> Tambah Pelanggan
            </button>
        </div>
    </div>

    <div class="card-body p-4">
        <!-- Filter: submit via GET agar hasil filter bisa di-bookmark & di-share -->
        <form method="GET" action="{{ route('wms.customers.index') }}" class="row g-2 mb-4 align-items-stretch">
            <div class="col-12 col-md-5">
                <div class="input-group h-100">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-white border-start-0" placeholder="Cari nama, kode, email, telepon...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="territory" class="form-select h-100">
                    <option value="">Semua Territory</option>
                    @foreach($territories as $territory)
                        <option value="{{ $territory }}" @selected($filters['territory'] === $territory)>{{ $territory }}</option>
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
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <a href="{{ route('wms.customers.index') }}" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                {{-- Urutan kolom mengikuti ekspor ERP: identitas -> kontak -> lokasi.
                     Address & Address 2 disimpan terpisah tapi ditampilkan sebagai
                     SATU kolom alamat, sesuai permintaan. --}}
                <thead class="table-light">
                    <tr>
                        <th class="text-secondary small fw-semibold text-center text-nowrap" style="width: 46px;">NO</th>
                        <th class="text-secondary small fw-semibold text-nowrap">KODE</th>
                        <th class="text-secondary small fw-semibold text-nowrap">SHIP-TO CODE</th>
                        <th class="text-secondary small fw-semibold text-nowrap" style="min-width: 220px;">NAMA PELANGGAN</th>
                        <th class="text-secondary small fw-semibold text-nowrap">NO. TELEPON</th>
                        <th class="text-secondary small fw-semibold text-nowrap">KONTAK</th>
                        <th class="text-secondary small fw-semibold text-nowrap">EMAIL</th>
                        <th class="text-secondary small fw-semibold text-nowrap" style="min-width: 300px;">ALAMAT</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">TERRITORY</th>
                        <th class="text-secondary small fw-semibold text-center text-nowrap">STATUS</th>
                        <th class="text-secondary small fw-semibold text-center pe-3 text-nowrap">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($customers as $customer)
                        @php
                            // Payload untuk mengisi modal edit. Disiapkan di sini
                            // (bukan inline di atribut onclick) agar Blade tidak
                            // salah membaca array yang terpotong antar baris.
                            $payload = [
                                'id' => $customer->id,
                                'code' => $customer->code,
                                'ship_to_code' => $customer->ship_to_code,
                                'name' => $customer->name,
                                'phone' => $customer->phone,
                                'contact_name' => $customer->contact_name,
                                'email' => $customer->email,
                                'address' => $customer->address,
                                'address_2' => $customer->address_2,
                                'territory_code' => $customer->territory_code,
                                'is_active' => $customer->is_active,
                            ];
                            $dimmed = $customer->is_active ? '' : 'opacity-50';
                        @endphp
                        <tr class="{{ $dimmed }}">
                            <td class="text-center text-muted align-top">{{ $loop->iteration + ($customers->currentPage() - 1) * $customers->perPage() }}</td>
                            <td class="font-monospace fw-bold text-dark text-nowrap align-top">{{ $customer->code }}</td>
                            <td class="font-monospace small text-nowrap align-top">
                                @if($customer->ship_to_code)
                                    <span class="text-muted">{{ $customer->ship_to_code }}</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning"
                                          title="Belum terdaftar di ERP">Belum ada</span>
                                @endif
                            </td>
                            <td class="text-dark fw-semibold align-top">{{ $customer->name }}</td>
                            <td class="font-monospace small text-nowrap align-top">{{ $customer->phone_label }}</td>
                            <td class="small text-nowrap align-top">{{ $customer->contact_name ?: '—' }}</td>
                            <td class="small align-top text-lowercase">{{ $customer->email ?: '—' }}</td>
                            <td class="small text-muted align-top">{{ $customer->full_address }}</td>
                            <td class="text-center">
                                @if($customer->territory_code)
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info">{{ $customer->territory_code }}</span>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($customer->is_active)
                                    <span class="badge bg-success-subtle text-success-emphasis border border-success">Aktif</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary">Non-aktif</span>
                                @endif
                            </td>
                            <td class="text-center pe-3 text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary" title="Sunting"
                                        data-bs-toggle="modal" data-bs-target="#customerModal"
                                        onclick='openCustomerModal("edit", @json($payload))'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form action="{{ route('wms.customers.status', $customer) }}" method="POST" class="d-inline js-toggle-status">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm {{ $customer->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                            data-name="{{ $customer->name }}" data-action="{{ $customer->is_active ? 'menonaktifkan' : 'mengaktifkan' }}"
                                            title="{{ $customer->is_active ? 'Nonaktifkan' : 'Aktifkan' }}">
                                        <i class="bi {{ $customer->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }}"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                Belum ada pelanggan yang cocok dengan filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($customers->hasPages())
            <div class="mt-4">{{ $customers->links() }}</div>
        @endif
    </div>
</div>
@endsection

@push('modals')
<!-- Modal Import Excel -->
<div class="modal fade" id="importExcelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="{{ route('wms.customers.import.preview') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Import Pelanggan dari Excel</h5>
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
                            No./id · Ship-to Code · Name · Phone No. · Contact ·<br>
                            Email · Address · Address 2 · Territory Code
                        </div>
                        <hr class="my-2">
                        Kode pelanggan yang sudah ada akan <strong>diperbarui</strong>, yang belum ada ditambahkan.
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

<!-- Modal Tambah / Sunting Pelanggan -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form id="customerForm" method="POST" action="{{ route('wms.customers.store') }}">
            @csrf
            <input type="hidden" name="_method" id="formMethod" value="POST">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-people text-primary me-2"></i> <span id="modalTitle">Tambah Pelanggan</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Kode Pelanggan *</label>
                            <input type="text" name="code" id="inpCode" class="form-control font-monospace" placeholder="IDI10101" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">Ship-to Code</label>
                            <input type="text" name="ship_to_code" id="inpShipTo" class="form-control font-monospace" placeholder="1061600017">
                            <div class="form-text">Nomor pelanggan di ERP. Kosongkan bila belum terdaftar.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Nama Pelanggan *</label>
                        <input type="text" name="name" id="inpName" class="form-control" placeholder="PT PANDU BIO POLIMER" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">No. Telepon</label>
                            <input type="text" name="phone" id="inpPhone" class="form-control font-monospace" placeholder="6289531435435">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Nama Kontak</label>
                            <input type="text" name="contact_name" id="inpContact" class="form-control" placeholder="Nama PIC">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Territory Code</label>
                            <input type="text" name="territory_code" id="inpTerritory" class="form-control" list="territoryOptions" placeholder="PROJECT">
                            <datalist id="territoryOptions">
                                @foreach($territories as $territory)<option value="{{ $territory }}">@endforeach
                            </datalist>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Email</label>
                        <input type="email" name="email" id="inpEmail" class="form-control" placeholder="marketing@perusahaan.com">
                    </div>

                    <hr class="my-4">
                    <p class="small fw-semibold text-secondary mb-1">
                        <i class="bi bi-geo-alt me-1"></i> Alamat
                    </p>
                    <p class="small text-muted mb-3">
                        Dipisah dua baris mengikuti ekspor ERP: baris pertama alamat jalan,
                        baris kedua kelurahan/kecamatan/kota. Di tabel keduanya tampil digabung.
                    </p>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Alamat (Jalan) *</label>
                        <textarea name="address" id="inpAddress" class="form-control" rows="2" placeholder="JL RAYA PONDOK GEDE NO. 17 A, RT 002 RW 002, DUKUH KRAMAT JATI" required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Alamat 2 (Kelurahan / Kota)</label>
                        <textarea name="address_2" id="inpAddress2" class="form-control" rows="2" placeholder="JAKARTA TIMUR, DKI JAKARTA"></textarea>
                    </div>

                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" id="inpActive" value="1" checked>
                        <label class="form-check-label" for="inpActive">Pelanggan aktif</label>
                        <div class="form-text">Hanya pelanggan aktif yang muncul di form Buat Pesanan Sales.</div>
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
    const CUSTOMER_STORE_URL = @json(route('wms.customers.store'));

    function openCustomerModal(mode, data = null) {
        const form = document.getElementById('customerForm');
        const method = document.getElementById('formMethod');

        if (mode === 'add') {
            form.reset();
            form.action = CUSTOMER_STORE_URL;
            method.value = 'POST';
            document.getElementById('modalTitle').textContent = 'Tambah Pelanggan';
            document.getElementById('inpActive').checked = true;
            return;
        }

        form.action = CUSTOMER_STORE_URL + '/' + data.id;
        method.value = 'PUT';
        document.getElementById('modalTitle').textContent = 'Sunting Pelanggan';

        document.getElementById('inpCode').value = data.code ?? '';
        document.getElementById('inpShipTo').value = data.ship_to_code ?? '';
        document.getElementById('inpName').value = data.name ?? '';
        document.getElementById('inpPhone').value = data.phone ?? '';
        document.getElementById('inpContact').value = data.contact_name ?? '';
        document.getElementById('inpEmail').value = data.email ?? '';
        document.getElementById('inpTerritory').value = data.territory_code ?? '';
        document.getElementById('inpAddress').value = data.address ?? '';
        document.getElementById('inpAddress2').value = data.address_2 ?? '';
        document.getElementById('inpActive').checked = !!data.is_active;
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Konfirmasi sebelum mengubah status, agar tidak terjadi karena salah klik.
        document.querySelectorAll('.js-toggle-status').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                const btn = form.querySelector('button[type="submit"]');
                if (form.dataset.confirmed === 'yes') {
                    return;
                }
                e.preventDefault();

                Swal.fire({
                    title: 'Ubah status pelanggan?',
                    text: 'Anda akan ' + btn.dataset.action + ' ' + btn.dataset.name + '.',
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
            new bootstrap.Modal(document.getElementById('customerModal')).show();
        @endif
    });
</script>
@endpush
