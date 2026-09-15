@extends('layouts.wms')
@section('title', 'Penolakan '.$retur->reference)

{{--
    SATU HALAMAN, TIGA PEKERJAAN — dan tombol yang muncul menyesuaikan siapa
    yang membuka DAN sudah sampai mana dokumennya:

      reported             -> Logistik: setujui / tolak klaimnya
      putaway_pending      -> Operator: naikkan ke rak, pisahkan bagus & DDP
      verification_pending -> Logistik: verifikasi barangnya (stok bertambah)

    Kalimat "stok belum bertambah" diulang di beberapa tempat dengan sengaja.
    Tiga layar berbeda menampilkan angka yang sama, dan tanpa itu orang wajar
    mengira barangnya sudah bisa dijual begitu naik rak.
--}}

@section('content')
@php
    use App\Models\SalesReturn;
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a href="{{ route('wms.returns.index') }}" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke daftar
        </a>
        <h4 class="fw-bold text-dark mb-0 mt-1">{{ $retur->reference }}</h4>
        <p class="text-muted mb-0">
            {{ $retur->customer?->name }} &middot;
            {{ $retur->salesOrder?->order_number }}
            @if($retur->salesOrder?->bc_so_number) &middot; SO {{ $retur->salesOrder->bc_so_number }} @endif
        </p>
    </div>
    <span class="badge bg-{{ $retur->status_color }}-subtle text-{{ $retur->status_color }}-emphasis rounded-pill px-3 py-2">
        {{ $retur->status_label }}
    </span>
</div>

{{-- Alasan customer, apa adanya dari lapangan. Ditaruh paling atas karena
     inilah yang dinilai Logistik saat memutuskan setuju atau tidak. --}}
<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body">
        <h6 class="fw-bold mb-1"><i class="bi bi-chat-quote me-2 text-danger"></i>Alasan Customer Menolak</h6>
        <p class="mb-2">{{ $retur->reason }}</p>
        <small class="text-muted">
            Dilaporkan {{ $retur->reportedBy?->full_name ?? 'Sistem' }},
            {{ $retur->reported_at?->translatedFormat('d M Y H:i') }}
        </small>

        @if($retur->approval_note)
            <hr>
            <h6 class="fw-bold mb-1 small">Catatan Logistik</h6>
            <p class="mb-1">{{ $retur->approval_note }}</p>
            <small class="text-muted">
                {{ $retur->approvedBy?->full_name }},
                {{ $retur->approved_at?->translatedFormat('d M Y H:i') }}
            </small>
        @endif
    </div>
</div>

@if($retur->status === SalesReturn::STATUS_REJECTED)
    <div class="alert alert-danger rounded-4">
        <i class="bi bi-x-circle me-1"></i>
        Laporan ini ditolak. Tidak ada barang yang masuk kembali ke stok.
    </div>
@endif

{{-- ==================================== TAHAP 1 — LOGISTIK MENILAI KLAIM --}}
@if($bolehSetujui)
    <form method="POST" action="{{ route('wms.returns.approve', $retur) }}" class="card border-0 shadow-sm rounded-4 mb-3">
        @csrf
        <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h6 class="fw-bold mb-0">Setujui Klaim</h6>
            {{-- Dikatakan terang-terangan supaya tidak ada yang mengira
                 persetujuan ini sudah memeriksa barangnya. --}}
            <small class="text-muted">
                Barangnya belum dilihat siapa pun — yang dinilai di sini cocok tidaknya
                dengan Surat Jalan. Pemeriksaan barangnya menyusul setelah naik rak.
            </small>
        </div>
        <div class="card-body px-4">
            <div class="table-responsive">
                <table class="table align-middle mb-3">
                    <thead class="table-light text-muted small">
                        <tr>
                            <th>SKU</th>
                            <th>BATCH</th>
                            <th class="text-center">DILAPORKAN</th>
                            <th style="width:150px">DISETUJUI</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($retur->details as $d)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $d->product?->sku }}</div>
                                    <small class="text-muted">{{ $d->product?->name }}</small>
                                </td>
                                <td>
                                    <div>{{ $d->batch_no }}</div>
                                    <small class="text-muted">{{ $d->production_date?->format('d M y') }}</small>
                                </td>
                                <td class="text-center fw-bold">{{ $d->qty_rejected }}</td>
                                <td>
                                    <input type="number" name="qty[{{ $d->id }}]" class="form-control"
                                           value="{{ $d->qty_rejected }}" min="0" max="{{ $d->qty_rejected }}" required>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Catatan (opsional)</label>
                <textarea name="note" class="form-control" rows="2"
                          placeholder="Mis. dua unit tidak cocok dengan SJ, hanya 8 yang diakui."></textarea>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary rounded-pill px-4">
                    <i class="bi bi-check2-circle me-1"></i>Setujui
                </button>
                <button type="button" class="btn btn-outline-danger rounded-pill px-4"
                        data-bs-toggle="modal" data-bs-target="#modalTolak">
                    <i class="bi bi-x-circle me-1"></i>Tolak Laporan
                </button>
            </div>
        </div>
    </form>
@endif

{{-- ======================================= DAFTAR BARIS + AKSI OPERATOR --}}
<form method="POST" action="{{ route('wms.returns.verify', $retur) }}" class="card border-0 shadow-sm rounded-4">
    @csrf
    <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">Barang yang Ditolak</h6>
        @if($bolehVerifikasi)
            <small class="text-muted">Centang baris yang sudah Anda periksa di rak.</small>
        @endif
    </div>
    <div class="card-body px-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light text-muted small">
                    <tr>
                        @if($bolehVerifikasi)<th style="width:36px"></th>@endif
                        <th>SKU / BATCH</th>
                        <th class="text-center">LAPOR</th>
                        <th class="text-center">SETUJU</th>
                        <th class="text-center">DI RAK</th>
                        <th>KEADAAN</th>
                        @if($bolehNaikkan)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($retur->details as $d)
                        <tr>
                            @if($bolehVerifikasi)
                                <td>
                                    @if(! $d->is_verified && $d->sudahNaikRak())
                                        <input type="checkbox" name="baris[]" value="{{ $d->id }}"
                                               class="form-check-input" checked>
                                    @endif
                                </td>
                            @endif
                            <td>
                                <div class="fw-semibold">{{ $d->product?->sku }}</div>
                                <small class="text-muted">
                                    {{ $d->batch_no }} &middot; {{ $d->production_date?->format('d M y') }}
                                </small>
                            </td>
                            <td class="text-center">{{ $d->qty_rejected }}</td>
                            <td class="text-center">{{ $d->qty_approved ?? '—' }}</td>
                            <td class="text-center">
                                @if($d->sudahNaikRak())
                                    <span class="badge bg-success-subtle text-success-emphasis rounded-pill">
                                        {{ $d->qty_good }} bagus
                                    </span>
                                    @if($d->qty_ddp > 0)
                                        <span class="badge bg-danger-subtle text-danger-emphasis rounded-pill">
                                            {{ $d->qty_ddp }} DDP
                                        </span>
                                    @endif
                                    {{-- Selisih tidak pernah disembunyikan: inilah
                                         pertanyaan yang harus dijawab SEBELUM
                                         diverifikasi, bukan sesudahnya. --}}
                                    @if($d->selisih !== null && $d->selisih !== 0)
                                        <div class="small text-danger fw-semibold mt-1">
                                            Selisih {{ $d->selisih > 0 ? '+' : '' }}{{ $d->selisih }}
                                        </div>
                                    @endif
                                    <div class="small text-muted">
                                        {{ $d->location?->code }}
                                        @if($d->ddpLocation) / {{ $d->ddpLocation->code }} @endif
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($d->is_verified)
                                    <span class="badge bg-success rounded-pill">
                                        <i class="bi bi-check2 me-1"></i>Stok masuk
                                    </span>
                                @elseif($d->sudahNaikRak())
                                    <span class="badge bg-info-subtle text-info-emphasis rounded-pill">
                                        Menunggu verifikasi
                                    </span>
                                @elseif($d->qty_approved !== null)
                                    <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill">
                                        Belum naik rak
                                    </span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">
                                        Menunggu persetujuan
                                    </span>
                                @endif
                                @if($d->condition_note)
                                    <div class="small text-muted mt-1">{{ $d->condition_note }}</div>
                                @endif
                            </td>
                            @if($bolehNaikkan)
                                <td class="text-end">
                                    @if(! $d->is_verified && ($d->qty_approved ?? 0) > 0)
                                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3"
                                                data-bs-toggle="modal" data-bs-target="#modalNaikkan"
                                                data-detail="{{ $d->id }}"
                                                data-sku="{{ $d->product?->sku }}"
                                                data-batch="{{ $d->batch_no }}"
                                                data-disetujui="{{ $d->qty_approved }}"
                                                data-good="{{ $d->qty_good }}"
                                                data-ddp="{{ $d->qty_ddp }}">
                                            <i class="bi bi-box-arrow-in-down me-1"></i>
                                            {{ $d->sudahNaikRak() ? 'Ubah' : 'Naikkan' }}
                                        </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($bolehVerifikasi)
            <div class="alert alert-warning rounded-4 mt-3 mb-3 small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Setelah diverifikasi, stoknya <strong>langsung resmi masuk</strong> dan bisa dipesan
                customer. Periksa dulu barangnya di rak, terutama pemisahan bagus/DDP-nya.
            </div>
            <button class="btn btn-success rounded-pill px-4">
                <i class="bi bi-check2-all me-1"></i>Verifikasi &amp; Masukkan ke Stok
            </button>
        @endif
    </div>
</form>

{{-- ------------------------------------------------------------- MODAL --}}
@if($bolehSetujui)
    <div class="modal fade" id="modalTolak" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('wms.returns.reject', $retur) }}" class="modal-content rounded-4">
                @csrf
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Tolak Laporan Penolakan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        Tidak ada barang yang masuk kembali ke stok. Sales akan melihat alasan Anda
                        di detail pesanannya.
                    </p>
                    <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="3" required minlength="5"
                              placeholder="Mis. barang tidak pernah sampai gudang, atau klaim tidak cocok dengan SJ."></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Batal</button>
                    <button class="btn btn-danger rounded-pill px-4">Tolak Laporan</button>
                </div>
            </form>
        </div>
    </div>
@endif

@if($bolehNaikkan)
    <div class="modal fade" id="modalNaikkan" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" id="formNaikkan" class="modal-content rounded-4">
                @csrf
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold">Naikkan ke Rak</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        <span class="fw-bold" id="nkSku"></span>
                        <span class="text-muted" id="nkBatch"></span>
                    </p>
                    <p class="text-muted small">
                        Disetujui <strong id="nkDisetujui"></strong> unit. Pisahkan yang masih layak
                        jual dari yang tidak — <strong>boleh kurang</strong> kalau ada yang hilang di
                        perjalanan pulang, tapi tidak boleh lebih.
                    </p>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-success">Masih bagus</label>
                            <input type="number" name="qty_good" id="nkGood" class="form-control" min="0" value="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Rak</label>
                            <input type="text" name="location_good" class="form-control text-uppercase"
                                   placeholder="ZA-01-01">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-danger">DDP (tidak layak jual)</label>
                            <input type="number" name="qty_ddp" id="nkDdp" class="form-control" min="0" value="0" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Rak DDP</label>
                            <input type="text" name="location_ddp" class="form-control text-uppercase"
                                   placeholder="ZD-01-01">
                        </div>
                    </div>

                    <label class="form-label small fw-semibold">Catatan kondisi (opsional)</label>
                    <textarea name="note" class="form-control" rows="2"
                              placeholder="Mis. 2 pail penyok di bagian tutup."></textarea>

                    <div class="alert alert-info rounded-4 mt-3 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Mengisi ini <strong>belum menambah stok</strong>. Logistik memeriksa dulu
                        barangnya di rak.
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Batal</button>
                    <button class="btn btn-primary rounded-pill px-4">Simpan</button>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection

@push('scripts')
@if($bolehNaikkan)
<script>
    // Satu modal dipakai bersama seluruh baris: rute-nya ditulis ulang dari
    // pola ber-placeholder, bukan dari string yang dirakit di JavaScript.
    const polaNaikkan = @json(route('wms.returns.putaway', ['detail' => '__ID__']));

    document.getElementById('modalNaikkan')?.addEventListener('show.bs.modal', (ev) => {
        const t = ev.relatedTarget;
        if (!t) return;

        document.getElementById('formNaikkan').action =
            polaNaikkan.replace('__ID__', t.dataset.detail);

        document.getElementById('nkSku').textContent = t.dataset.sku || '';
        document.getElementById('nkBatch').textContent = ' · ' + (t.dataset.batch || '');
        document.getElementById('nkDisetujui').textContent = t.dataset.disetujui || '0';

        // Baris yang sudah pernah dinaikkan dibuka dengan angkanya sendiri,
        // bukan nol — mengubah satu angka tidak boleh berarti mengetik ulang
        // seluruhnya.
        document.getElementById('nkGood').value = t.dataset.good || 0;
        document.getElementById('nkDdp').value = t.dataset.ddp || 0;
    });
</script>
@endif
@endpush
