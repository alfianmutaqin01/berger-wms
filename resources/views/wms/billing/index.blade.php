@extends('layouts.wms')
@section('title', 'Billing & Piutang')
@section('page_title', 'Manajemen Penagihan & Piutang')

@push('styles')
<style>
    .stat-card { transition: transform 0.2s ease, box-shadow 0.2s ease; border: none !important; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05) !important; }
    .bg-gradient-danger { background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
    .bg-gradient-success { background: linear-gradient(135deg, #10b981 0%, #047857 100%); }
    .table-hover tbody tr:hover { background-color: #f8fafc; }
    .badge-soft-danger { background-color: #fee2e2; color: #991b1b; }
    .badge-soft-warning { background-color: #fef3c7; color: #92400e; }
    .badge-soft-success { background-color: #d1fae5; color: #065f46; }
    .badge-soft-secondary { background-color: #f1f5f9; color: #475569; }
    tr.baris-terkunci { opacity: .45; }
</style>
@endpush

@section('content')
{{-- BUKU PANTAU, BUKAN PEMBUKUAN. Tidak ada nominal di layar ini — keputusan
     pemilik produk: pembayaran tidak pernah melewati sistem, angkanya hidup
     di BC. Yang dijawab di sini hanya invoice mana yang belum dibayar dan
     kapan jatuh temponya. --}}
<div class="row mb-4">
    <div class="col-12">
        <p class="text-muted mb-0">Pantau invoice pesanan tempo yang belum dibayar dan catat pelunasannya. Jatuh tempo dihitung dari tanggal barang sampai di customer.</p>
    </div>
</div>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-4" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
    <div class="alert alert-danger border-0 shadow-sm rounded-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}
    </div>
@endif

<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="card stat-card rounded-4 shadow-sm bg-gradient-danger text-white h-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-3 opacity-25">
                <i class="bi bi-exclamation-octagon-fill" style="font-size: 5rem; margin-top: -1rem; margin-right: -1rem;"></i>
            </div>
            <div class="card-body p-4 position-relative z-1">
                <h6 class="fw-semibold opacity-75 mb-3 text-uppercase" style="font-size: 0.8rem;">Lewat Jatuh Tempo</h6>
                <h2 class="fw-bold mb-2">{{ number_format($ringkasan['lewat']['tagihan']) }} Invoice</h2>
                <small class="opacity-75">Terkait {{ number_format($ringkasan['lewat']['customer']) }} customer</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card rounded-4 shadow-sm bg-gradient-warning text-white h-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-3 opacity-25">
                <i class="bi bi-hourglass-split" style="font-size: 5rem; margin-top: -1rem; margin-right: -1rem;"></i>
            </div>
            <div class="card-body p-4 position-relative z-1">
                <h6 class="fw-semibold opacity-75 mb-3 text-uppercase" style="font-size: 0.8rem;">Belum Lunas</h6>
                <h2 class="fw-bold mb-2">{{ number_format($ringkasan['berjalan']['tagihan']) }} Invoice</h2>
                <small class="opacity-75">Terkait {{ number_format($ringkasan['berjalan']['customer']) }} customer</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card rounded-4 shadow-sm bg-gradient-success text-white h-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-3 opacity-25">
                <i class="bi bi-check-all" style="font-size: 5rem; margin-top: -1rem; margin-right: -1rem;"></i>
            </div>
            <div class="card-body p-4 position-relative z-1">
                <h6 class="fw-semibold opacity-75 mb-3 text-uppercase" style="font-size: 0.8rem;">Lunas Bulan Ini</h6>
                <h2 class="fw-bold mb-2">{{ number_format($ringkasan['lunas_bulan_ini']['tagihan']) }} Invoice</h2>
                <small class="opacity-75">Menurut tanggal bukti bayar diterima</small>
            </div>
        </div>
    </div>
</div>

@php
    $tabs = [
        \App\Http\Controllers\Wms\BillingController::TAB_SEGERA => ['Jatuh tempo ≤ '.\App\Models\CustomerBilling::HARI_SEGERA.' hari', 'warning', 'Tidak ada invoice yang jatuh tempo dalam seminggu ke depan.'],
        \App\Http\Controllers\Wms\BillingController::TAB_LEWAT => ['Lewat jatuh tempo', 'danger', 'Tidak ada invoice yang lewat jatuh tempo.'],
        \App\Http\Controllers\Wms\BillingController::TAB_BERJALAN => ['Semua belum lunas', 'secondary', 'Tidak ada invoice yang belum lunas.'],
        \App\Http\Controllers\Wms\BillingController::TAB_LUNAS => ['Lunas', 'success', 'Belum ada invoice yang dikonfirmasi lunas.'],
    ];
    $tabLunas = $tab === \App\Http\Controllers\Wms\BillingController::TAB_LUNAS;
    $bolehLunasi = ! $tabLunas && auth()->user()->can(\App\Support\Permission::BILLING_CONFIRM);
    $bolehBatal = $tabLunas && auth()->user()->can(\App\Support\Permission::BILLING_VOID);
@endphp

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom pt-4 pb-0 px-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h6 class="fw-bold text-dark mb-1"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Daftar Piutang per Invoice</h6>
                <small class="text-muted">Satu baris = satu nomor SO BC. Pesanan yang digabung ke invoice yang sama ikut di dalamnya.</small>
            </div>
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="search" name="search" value="{{ $search }}" class="form-control bg-light border-0" placeholder="Cari customer / No. SO / PO...">
                </div>
                <button class="btn btn-sm btn-primary rounded-3">Cari</button>
            </form>
        </div>

        <ul class="nav nav-tabs border-0 gap-1">
            @foreach($tabs as $kunci => [$judul, $warna, $kosong])
            <li class="nav-item">
                <a class="nav-link rounded-top-3 {{ $tab === $kunci ? 'active fw-semibold' : 'text-muted' }}"
                   href="{{ route('wms.billing.index', array_filter(['tab' => $kunci, 'search' => $search])) }}">
                    {{ $judul }}
                    @if($jumlah[$kunci] > 0)
                        <span class="badge bg-{{ $tab === $kunci ? $warna : 'secondary' }}-subtle text-{{ $tab === $kunci ? $warna : 'secondary' }}-emphasis ms-1">{{ $jumlah[$kunci] }}</span>
                    @endif
                </a>
            </li>
            @endforeach
        </ul>
    </div>

    <div class="card-body p-0">
        @if($bolehLunasi && $tagihan->isNotEmpty())
        {{-- Satu konfirmasi = satu customer. Begitu satu baris dicentang,
             baris customer lain dikunci — satu bukti transfer datang dari
             satu customer, dan server menolak campuran yang sama. --}}
        <div id="barPilihan" class="d-none align-items-center justify-content-between gap-3 px-4 py-2 bg-primary-subtle border-bottom">
            <span class="small"><strong id="jumlahDipilih">0</strong> invoice dipilih &middot; <span id="customerDipilih" class="fw-semibold"></span></span>
            <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#confirmModal">
                <i class="bi bi-check-circle me-1"></i> Konfirmasi Lunas
            </button>
        </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                <thead class="table-light text-muted text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <tr>
                        @if($bolehLunasi)<th class="ps-4 py-3" style="width: 1%"></th>@endif
                        <th class="{{ $bolehLunasi ? '' : 'ps-4' }} py-3">No. SO (BC) / Pesanan</th>
                        <th class="py-3">Customer</th>
                        <th class="py-3">Barang Sampai</th>
                        <th class="py-3">Termin &amp; Jatuh Tempo</th>
                        <th class="text-end py-3">Qty Terkirim</th>
                        <th class="py-3">Status</th>
                        @if($tabLunas)<th class="text-end pe-4 py-3">Pelunasan</th>@endif
                    </tr>
                </thead>
                <tbody>
                @forelse($tagihan as $t)
                    @php
                        $order = $t->salesOrder;
                        $sisa = $t->sisaHari();
                        $lewat = $t->hariLewat();
                    @endphp
                    <tr class="baris-tagihan" data-customer="{{ $t->customer_id }}">
                        @if($bolehLunasi)
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input pilih-tagihan" value="{{ $t->id }}"
                                   data-customer="{{ $t->customer_id }}" data-customer-name="{{ $t->customer?->name }}"
                                   aria-label="Pilih {{ $order?->bc_so_number ?? $order?->order_number }}">
                        </td>
                        @endif
                        <td class="{{ $bolehLunasi ? '' : 'ps-4' }}">
                            <span class="fw-bold text-dark d-block font-monospace">{{ $order?->bc_so_number ?? '—' }}</span>
                            <small class="text-muted font-monospace">{{ $order?->order_number }}</small>
                            @foreach($t->mergedOrders as $anak)
                                <small class="text-muted font-monospace d-block">+ {{ $anak->order_number }}</small>
                            @endforeach
                        </td>
                        <td>
                            <span class="fw-semibold text-dark d-block">{{ $t->customer?->name ?? '—' }}</span>
                            <small class="text-muted">
                                <span class="font-monospace">{{ $t->customer?->code }}</span>
                                &middot; Sales {{ $order?->user?->full_name ?? '—' }}
                                &middot; {{ $t->warehouse?->code }}
                            </small>
                        </td>
                        <td>{{ $t->delivered_on->translatedFormat('d M Y') }}</td>
                        <td>
                            <span class="d-block">{{ $t->paymentTerm?->name ?? $t->term_days.' hari' }}</span>
                            <span class="small {{ $lewat > 0 ? 'text-danger fw-bold' : 'text-muted' }}">
                                @if($lewat > 0)<i class="bi bi-exclamation-circle-fill me-1"></i>@endif{{ $t->due_date->translatedFormat('d M Y') }}
                            </span>
                        </td>
                        <td class="text-end fw-bold font-monospace">{{ number_format($qty[$t->id] ?? 0) }}</td>
                        <td>
                            @if($t->sudahLunas())
                                <span class="badge badge-soft-success px-3 py-2 rounded-pill">Lunas</span>
                            @elseif($lewat > 0)
                                <span class="badge badge-soft-danger px-3 py-2 rounded-pill">Lewat {{ $lewat }} hari</span>
                            @elseif($sisa === 0)
                                <span class="badge badge-soft-warning px-3 py-2 rounded-pill">Jatuh tempo hari ini</span>
                            @elseif($sisa <= \App\Models\CustomerBilling::HARI_SEGERA)
                                <span class="badge badge-soft-warning px-3 py-2 rounded-pill">{{ $sisa }} hari lagi</span>
                            @else
                                <span class="badge badge-soft-secondary px-3 py-2 rounded-pill">Berjalan ({{ $sisa }} hari)</span>
                            @endif
                        </td>
                        @if($tabLunas)
                        <td class="text-end pe-4 small">
                            @if($t->payment)
                                <div class="fw-semibold">{{ $t->payment->method_label }}@if($t->payment->reference) &middot; <span class="font-monospace">{{ $t->payment->reference }}</span>@endif</div>
                                <div class="text-muted">Diterima {{ $t->payment->paid_on->translatedFormat('d M Y') }} &middot; {{ $t->payment->confirmedBy?->full_name ?? '—' }}</div>
                                @if($t->payment->notes)
                                    <div class="text-muted fst-italic">{{ $t->payment->notes }}</div>
                                @endif
                                @if($bolehBatal)
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" data-bs-toggle="modal" data-bs-target="#voidModal"
                                            data-action="{{ route('wms.billing.void', $t->payment) }}"
                                            data-label="{{ $t->payment->method_label }} {{ $t->payment->reference }} — {{ $t->customer?->name }}">
                                        Batalkan konfirmasi
                                    </button>
                                @endif
                            @endif
                        </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                            {{ $tabs[$tab][2] }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3">{{ $tagihan->links() }}</div>
    </div>
</div>
@endsection

@push('modals')
@if($bolehLunasi)
<!-- Modal Konfirmasi Pelunasan (F-BILL-02) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-check-circle text-success me-2"></i> Konfirmasi Lunas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <form action="{{ route('wms.billing.pay') }}" method="POST" id="billingForm">
                @csrf
                <div id="idTerpilih"></div>
                <div class="modal-body p-4">
                    <div class="alert bg-light border text-dark mb-4 rounded-3">
                        <div class="row text-center">
                            <div class="col-7 border-end">
                                <small class="text-muted d-block mb-1">Customer</small>
                                <strong id="modalCust" class="text-primary">—</strong>
                            </div>
                            <div class="col-5">
                                <small class="text-muted d-block mb-1">Invoice</small>
                                <strong id="modalJumlah" class="font-monospace text-dark">0</strong>
                            </div>
                        </div>
                    </div>

                    {{-- PRD v1.1 §6.6: tidak ada blokir order akibat piutang, jadi
                         pelunasan hanya menghapus penanda ⚠ Menunggak. --}}
                    <p class="text-muted small mb-3">Tandai lunas setelah bukti pembayaran diterima. Penanda <strong>⚠ Menunggak</strong> pada customer hilang begitu seluruh invoice-nya yang lewat jatuh tempo lunas.</p>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Tanggal Bukti Bayar Diterima <span class="text-danger">*</span></label>
                        <input type="date" class="form-control bg-light" name="paid_on" required
                               value="{{ old('paid_on', $hariIni->toDateString()) }}" max="{{ $hariIni->toDateString() }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Metode Pelunasan <span class="text-danger">*</span></label>
                        <select class="form-select bg-light" name="method" id="metodeBayar" required>
                            @foreach(\App\Models\BillingPayment::METHOD_LABELS as $nilai => $label)
                                <option value="{{ $nilai }}" @selected(old('method') === $nilai)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark" for="referensiBayar">
                            <span id="labelReferensi">No. Referensi Transfer</span> <span class="text-danger d-none" id="wajibReferensi">*</span>
                        </label>
                        <input type="text" class="form-control bg-light font-monospace" name="reference" id="referensiBayar" maxlength="60" value="{{ old('reference') }}">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-dark">Catatan</label>
                        <textarea class="form-control bg-light" name="notes" rows="2" maxlength="1000" placeholder="Opsional">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top-0 rounded-bottom-4 px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-success px-4 rounded-pill fw-bold shadow-sm"><i class="bi bi-shield-check me-1"></i> Tandai Lunas</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@if($bolehBatal)
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-arrow-counterclockwise text-danger me-2"></i> Batalkan Konfirmasi Lunas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <form method="POST" id="voidForm">
                @csrf
                <div class="modal-body p-4">
                    <p class="small mb-2"><strong id="voidLabel"></strong></p>
                    <p class="text-muted small">Semua invoice dalam konfirmasi ini kembali <strong>belum lunas</strong>. Riwayat konfirmasinya tetap tersimpan.</p>
                    <label class="form-label small fw-semibold text-dark">Alasan <span class="text-danger">*</span></label>
                    <textarea class="form-control bg-light" name="reason" rows="3" minlength="10" maxlength="1000" required
                              placeholder="mis. Giro ditolak bank, atau salah centang invoice."></textarea>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4 px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-danger px-4 rounded-pill fw-bold">Batalkan Konfirmasi</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const pilihan = Array.from(document.querySelectorAll('.pilih-tagihan'));
    const bar = document.getElementById('barPilihan');

    function perbarui() {
        const dicentang = pilihan.filter(function (c) { return c.checked; });
        const customer = dicentang.length ? dicentang[0].dataset.customer : null;

        pilihan.forEach(function (c) {
            const kunci = customer !== null && c.dataset.customer !== customer;
            c.disabled = kunci;
            c.closest('tr').classList.toggle('baris-terkunci', kunci);
        });

        if (!bar) return;
        bar.classList.toggle('d-none', dicentang.length === 0);
        bar.classList.toggle('d-flex', dicentang.length > 0);
        document.getElementById('jumlahDipilih').textContent = dicentang.length;
        document.getElementById('customerDipilih').textContent = dicentang.length ? dicentang[0].dataset.customerName : '';
    }

    pilihan.forEach(function (c) { c.addEventListener('change', perbarui); });

    const confirmModal = document.getElementById('confirmModal');
    if (confirmModal) {
        confirmModal.addEventListener('show.bs.modal', function () {
            const dicentang = pilihan.filter(function (c) { return c.checked; });
            const wadah = document.getElementById('idTerpilih');

            wadah.innerHTML = '';
            dicentang.forEach(function (c) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'billing_ids[]';
                input.value = c.value;
                wadah.appendChild(input);
            });

            document.getElementById('modalCust').textContent = dicentang.length ? dicentang[0].dataset.customerName : '—';
            document.getElementById('modalJumlah').textContent = dicentang.length;
        });

        // Nomor wajib hanya untuk giro: giro bisa ditolak bank, dan nomornya
        // satu-satunya cara menemukan pelunasan mana yang harus dibatalkan.
        const metode = document.getElementById('metodeBayar');
        const referensi = document.getElementById('referensiBayar');

        function perbaruiMetode() {
            const giro = metode.value === 'giro';
            referensi.required = giro;
            document.getElementById('wajibReferensi').classList.toggle('d-none', !giro);
            document.getElementById('labelReferensi').textContent =
                giro ? 'No. Giro' : (metode.value === 'transfer' ? 'No. Referensi Transfer' : 'No. Kuitansi');
        }

        metode.addEventListener('change', perbaruiMetode);
        perbaruiMetode();

        document.getElementById('billingForm').addEventListener('submit', function () {
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
            btn.disabled = true;
        });
    }

    const voidModal = document.getElementById('voidModal');
    if (voidModal) {
        voidModal.addEventListener('show.bs.modal', function (event) {
            const tombol = event.relatedTarget;
            document.getElementById('voidForm').action = tombol.dataset.action;
            document.getElementById('voidLabel').textContent = tombol.dataset.label;
        });
    }
});
</script>
@endpush
