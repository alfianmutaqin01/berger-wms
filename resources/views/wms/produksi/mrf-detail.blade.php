@extends('layouts.wms')

@section('title', 'MRF '.$mrf->mrf_number)
@section('page_title', 'Permintaan Material '.$mrf->mrf_number)

@section('content')
{{-- RINCIAN SATU PERMINTAAN — dibaca keempat peran yang terlibat.

     Susunannya mengikuti PERJALANANNYA, bukan mengikuti tabel: siapa meminta,
     siapa menyetujui, batch mana yang dipilih, apa yang benar-benar ditemukan
     di rak, dan berapa yang akhirnya diterima Produksi. Orang yang membuka
     halaman ini biasanya sedang mencari tahu "sekarang nyangkut di mana", dan
     urutan itulah yang menjawabnya paling cepat. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
@endforeach

<div class="row g-3">
    <div class="col-12 col-lg-8">
        {{-- Keadaan sekarang, di paling atas dan dalam kalimat penuh. Lencana
             status saja menjawab "apa", bukan "lalu siapa yang harus berbuat
             apa" — dan yang kedua itulah yang dicari orang. --}}
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <span class="badge {{ $mrf->status_badge }} fs-6">{{ $mrf->status_label }}</span>
                        <span class="ms-2 text-muted small">dibuat {{ $mrf->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Total diminta</div>
                        <span class="fs-4 fw-bold">{{ number_format($mrf->total_diminta) }}</span>
                        @if($mrf->status === \App\Models\MaterialRequisition::STATUS_RECEIVED)
                            <span class="text-muted small">· diterima {{ number_format($mrf->total_diterima) }}</span>
                        @endif
                    </div>
                </div>

                @switch($mrf->status)
                    @case(\App\Models\MaterialRequisition::STATUS_PENDING_APPROVAL)
                        <p class="mb-0">
                            Menunggu <strong>{{ $mrf->approver_name }}</strong> menekan Setuju pada tautan WhatsApp.
                            Logistik belum bisa memprosesnya sebelum itu.
                        </p>
                        @break
                    @case(\App\Models\MaterialRequisition::STATUS_PENDING_LOGISTICS)
                        <p class="mb-0">
                            Sudah disetujui atasan. Sekarang menunggu <strong>Logistik</strong> memilih batch mana yang diambilkan.
                        </p>
                        @break
                    @case(\App\Models\MaterialRequisition::STATUS_PENDING_PICKING)
                        <p class="mb-0">
                            Batch sudah dipilih dan <strong>dicadangkan</strong> — barangnya masih di rak tetapi tidak bisa dijual
                            ke pelanggan mana pun. Menunggu operator mengerjakan daftar
                            <span class="font-monospace">{{ $mrf->pickingList?->list_number ?? '—' }}</span>.
                        </p>
                        @break
                    @case(\App\Models\MaterialRequisition::STATUS_READY_FOR_PICKUP)
                        <div class="alert alert-primary border-0 rounded-3 mb-0">
                            <i class="bi bi-box-seam me-2"></i>
                            Barangnya sudah turun dari rak dan menunggu di
                            <strong class="font-monospace">{{ $mrf->handoverLocation?->code ?? '—' }}</strong>.
                            @if(filled($mrf->handover_note))
                                <div class="small mt-1">Catatan operator: {{ $mrf->handover_note }}</div>
                            @endif
                            <div class="small mt-1">
                                Sejak saat ini barang itu sudah <strong>keluar dari stok gudang</strong>. Tekan Diterima
                                supaya ia tercatat di buku Produksi dan sisanya ikut terpantau.
                            </div>
                        </div>
                        @break
                    @case(\App\Models\MaterialRequisition::STATUS_REJECTED_APPROVAL)
                        <div class="alert alert-danger border-0 rounded-3 mb-0">
                            Ditolak <strong>{{ $mrf->approver_name }}</strong>
                            pada {{ $mrf->approver_rejected_at?->format('d/m/Y H:i') }}.
                            <div class="mt-1">Alasan: {{ $mrf->approver_rejection_reason }}</div>
                            @include('wms.produksi.partials.tombol-perbaiki-mrf', ['mrf' => $mrf])
                        </div>
                        @break
                    @case(\App\Models\MaterialRequisition::STATUS_REJECTED_LOGISTICS)
                        <div class="alert alert-danger border-0 rounded-3 mb-0">
                            Ditolak Logistik ({{ $mrf->logisticsRejectedBy?->full_name ?? '—' }})
                            pada {{ $mrf->logistics_rejected_at?->format('d/m/Y H:i') }}.
                            <div class="mt-1">Alasan: {{ $mrf->logistics_rejection_reason }}</div>
                            @include('wms.produksi.partials.tombol-perbaiki-mrf', ['mrf' => $mrf])
                        </div>
                        @break
                    @case(\App\Models\MaterialRequisition::STATUS_CANCELLED)
                        <div class="alert alert-secondary border-0 rounded-3 mb-0">
                            Dibatalkan {{ $mrf->cancelledBy?->full_name ?? '—' }}
                            pada {{ $mrf->cancelled_at?->format('d/m/Y H:i') }}.
                            <div class="mt-1">Alasan: {{ $mrf->cancellation_reason }}</div>
                        </div>
                        @break
                    @default
                        <p class="mb-0">
                            Diterima Produksi pada {{ $mrf->received_at?->format('d/m/Y H:i') }}
                            oleh {{ $mrf->receivedBy?->full_name ?? '—' }}.
                            Pemakaiannya dicatat di <a href="{{ route('wms.material-produksi.index') }}">MRF Picked</a>.
                        </p>
                @endswitch
            </div>
        </div>

        {{-- APA YANG DIMINTA vs APA YANG BENAR-BENAR TERJADI, berdampingan.
             Tiga angka itu sengaja tidak pernah dilebur: selisih di antaranya
             adalah pertanyaan yang pantas ditanyakan seseorang. --}}
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Barang yang Diminta</h6>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>SKU</th>
                                <th class="text-end">Diminta</th>
                                <th class="text-end">Dijanjikan</th>
                                <th>Batch yang dipilih Logistik</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($mrf->items as $item)
                            <tr>
                                <td>
                                    <span class="font-monospace fw-semibold">{{ $item->product?->sku }}</span>
                                    <div class="small text-muted">{{ $item->product?->name }}</div>
                                    @if(filled($item->note))
                                        <div class="small text-primary">
                                            <i class="bi bi-chat-left-quote me-1"></i>{{ $item->note }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end fw-semibold">{{ number_format($item->qty_requested) }}</td>
                                <td class="text-end">
                                    {{ number_format($item->qty_dialokasikan) }}
                                    @if($item->qty_tidak_terpenuhi > 0 && $mrf->allocations->isNotEmpty())
                                        <div class="small text-warning-emphasis">
                                            kurang {{ number_format($item->qty_tidak_terpenuhi) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @php($alokasi = $mrf->allocations->where('material_requisition_item_id', $item->id))
                                    @if($alokasi->isEmpty())
                                        <span class="text-muted small">Belum dipilih</span>
                                    @else
                                        @foreach($alokasi as $a)
                                        <div class="small d-flex flex-wrap gap-2 align-items-center mb-1">
                                            <span class="font-monospace">{{ $a->batch_no ?? '—' }}</span>
                                            <span class="text-muted">rak {{ $a->pickingItem?->location?->code ?? '—' }}</span>
                                            <span class="badge bg-light text-dark border">janji {{ $a->qty_allocated }}</span>
                                            @if($a->qty_picked !== null)
                                                <span class="badge {{ $a->qty_kurang > 0 ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis' }}">
                                                    diambil {{ $a->qty_picked }}
                                                </span>
                                            @endif
                                            @if($a->status === \App\Models\InventoryStock::STATUS_DDP)
                                                <span class="badge bg-danger-subtle text-danger-emphasis">DDP</span>
                                            @endif
                                        </div>
                                        @if(filled($a->discrepancy_reason))
                                        <div class="small text-muted fst-italic mb-1">
                                            Selisihnya: {{ $a->discrepancy_reason }}
                                        </div>
                                        @endif
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if($mrf->holdings->isNotEmpty())
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Pemakaian di Produksi</h6>

                @foreach($mrf->holdings as $holding)
                <div class="border rounded-3 p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="font-monospace fw-semibold">{{ $holding->product?->sku }}</span>
                            <span class="text-muted small">batch {{ $holding->batch_no ?? '—' }}</span>
                            <div class="small text-muted">
                                di {{ $holding->production_area }} · diterima {{ $holding->received_at->format('d/m/Y') }}
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="fs-5 fw-bold {{ $holding->qty_sisa > 0 ? 'text-primary' : 'text-success' }}">
                                {{ number_format($holding->qty_sisa) }}
                            </span>
                            <span class="text-muted small">sisa dari {{ number_format($holding->qty_received) }}</span>
                        </div>
                    </div>

                    @if($holding->consumptions->isNotEmpty())
                    <ul class="list-unstyled small mt-2 mb-0">
                        @foreach($holding->consumptions as $pakai)
                        <li class="d-flex gap-2 text-muted">
                            <i class="bi bi-dot"></i>
                            <span>
                                {{ $pakai->consumed_at->format('d/m/Y') }} — dipakai
                                <strong>{{ number_format($pakai->qty) }}</strong>
                                oleh {{ $pakai->consumedBy?->full_name ?? '—' }}
                                @if(filled($pakai->note)) · {{ $pakai->note }} @endif
                            </span>
                        </li>
                        @endforeach
                    </ul>
                    @else
                    <div class="small text-muted mt-2">Belum ada pemakaian yang dicatat.</div>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Keterangan</h6>

                <dl class="row small mb-0">
                    <dt class="col-5 text-muted fw-normal">Pemohon</dt>
                    <dd class="col-7">{{ $mrf->requestedBy?->full_name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Departemen</dt>
                    <dd class="col-7">{{ $mrf->department_name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Gudang</dt>
                    <dd class="col-7">{{ $mrf->warehouse?->code }} — {{ $mrf->warehouse?->name }}</dd>

                    <dt class="col-5 text-muted fw-normal">Jenis</dt>
                    <dd class="col-7">{{ $mrf->jenis_label }}</dd>

                    <dt class="col-5 text-muted fw-normal">Keperluan</dt>
                    <dd class="col-7">{{ $mrf->purpose }}</dd>

                    <dt class="col-5 text-muted fw-normal">Atasan</dt>
                    <dd class="col-7">
                        {{ $mrf->approver_name }}
                        <div class="font-monospace text-muted">{{ $mrf->approver_phone }}</div>
                    </dd>

                    @if($mrf->pickingList)
                    <dt class="col-5 text-muted fw-normal">Daftar picking</dt>
                    <dd class="col-7">
                        <a href="{{ route('wms.picking.show', $mrf->pickingList) }}" class="font-monospace">
                            {{ $mrf->pickingList->list_number }}
                        </a>
                        <div class="text-muted">{{ $mrf->pickingList->status_label }}</div>
                    </dd>
                    @endif
                </dl>
            </div>
        </div>

        @if($mrf->status === \App\Models\MaterialRequisition::STATUS_PENDING_APPROVAL)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body">
                <h6 class="fw-bold mb-2"><i class="bi bi-whatsapp text-success me-2"></i>Tautan Persetujuan</h6>

                {{-- TOMBOL KIRIM MANUAL. Mode bawaan sistem memang tidak
                     mengirim sendiri — nomor atasan bukan nomor yang pernah
                     bercakap dengan nomor perusahaan, dan gateway tidak resmi
                     paling cepat diblokir justru untuk pola seperti itu.
                     Jadi sistem menyiapkan pesannya, orang yang menekan kirim. --}}
                <p class="small text-muted">
                    Status pesan:
                    <strong>{{ \App\Models\MaterialRequisition::NOTIFY_SENT === $mrf->notify_status ? 'Terkirim otomatis' : 'Menunggu dikirim' }}</strong>
                    @if(filled($mrf->notify_error))
                        <span class="d-block text-danger">{{ $mrf->notify_error }}</span>
                    @endif
                </p>

                <div class="d-grid gap-2">
                    <a class="btn btn-success rounded-3" target="_blank" rel="noopener"
                       href="https://wa.me/{{ $mrf->approver_phone }}?text={{ rawurlencode($mrf->pesanUntukApprover()) }}">
                        <i class="bi bi-whatsapp me-1"></i> Kirim lewat WhatsApp
                    </a>

                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-3" id="salinTautan"
                            data-tautan="{{ $mrf->approvalUrl() }}">
                        <i class="bi bi-clipboard me-1"></i> Salin tautannya saja
                    </button>

                    @can(\App\Support\Permission::MRF_CREATE)
                    <form method="POST" action="{{ route('wms.mrf.resend', $mrf) }}">
                        @csrf
                        <button class="btn btn-link btn-sm text-decoration-none w-100">Coba kirim otomatis lagi</button>
                    </form>
                    @endcan
                </div>
            </div>
        </div>
        @endif

        @if($bolehMemutus)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body d-grid gap-2">
                <a href="{{ route('wms.mrf.approve.form', $mrf) }}" class="btn btn-primary rounded-3">
                    <i class="bi bi-check2-circle me-1"></i> Proses: Pilih Batch
                </a>
                <button type="button" class="btn btn-outline-danger rounded-3" data-bs-toggle="modal" data-bs-target="#modalTolakLogistik">
                    <i class="bi bi-x-circle me-1"></i> Tolak Permintaan
                </button>
            </div>
        </div>
        @endif

        @if($bolehMenerima)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <form method="POST" action="{{ route('wms.mrf.receive', $mrf) }}" class="card-body">
                @csrf
                <h6 class="fw-bold mb-2">Terima Material</h6>

                <label class="form-label small">Ditaruh di mana di area Produksi?</label>
                <input type="text" name="production_area" class="form-control rounded-3 mb-2" maxlength="60"
                       value="Transit Produksi" placeholder="Mis. I-01-01 atau Transit Produksi">
                <div class="form-text mb-3">
                    Ditulis apa adanya — nama area, nomor rak produksi, atau patokan yang dipakai sehari-hari.
                    Ini yang akan dibaca orang yang mencarinya minggu depan.
                </div>

                <button class="btn btn-success w-100 rounded-3">
                    <i class="bi bi-box-arrow-in-down me-1"></i> Diterima Semua
                </button>

                <p class="small text-muted mt-2 mb-0">
                    Setelah ini barangnya tercatat di buku Produksi. Pemakaiannya dicatat sedikit demi sedikit,
                    jadi sisa yang belum dikerjakan tetap terbaca sampai habis.
                </p>
            </form>
        </div>
        @endif

        @if($bolehMembatalkan)
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body">
                <button type="button" class="btn btn-outline-secondary w-100 rounded-3"
                        data-bs-toggle="modal" data-bs-target="#modalBatal">
                    Batalkan Permintaan
                </button>
                <p class="small text-muted mt-2 mb-0">
                    Masih bisa dibatalkan selama barangnya belum turun dari rak. Batch yang sempat dicadangkan
                    akan kembali bisa dipakai pesanan lain.
                </p>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection

@push('modals')
@if($bolehMemutus)
<div class="modal fade" id="modalTolakLogistik" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.mrf.reject', $mrf) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title">Tolak {{ $mrf->mrf_number }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Alasan penolakan <span class="text-danger">*</span></label>
                <textarea name="reason" rows="3" class="form-control rounded-3" required minlength="5" maxlength="500"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-link text-decoration-none" data-bs-dismiss="modal">Batal</button>
                <button class="btn btn-danger rounded-3">Tolak</button>
            </div>
        </form>
    </div>
</div>
@endif

@if($bolehMembatalkan)
<div class="modal fade" id="modalBatal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.mrf.cancel', $mrf) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title">Batalkan {{ $mrf->mrf_number }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Alasan pembatalan <span class="text-danger">*</span></label>
                <textarea name="reason" rows="3" class="form-control rounded-3" required minlength="5" maxlength="500"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-link text-decoration-none" data-bs-dismiss="modal">Tutup</button>
                <button class="btn btn-danger rounded-3">Batalkan Permintaan</button>
            </div>
        </form>
    </div>
</div>
@endif
@endpush

@push('scripts')
<script>
(function () {
    const tombol = document.getElementById('salinTautan');
    if (!tombol) return;

    tombol.addEventListener('click', function () {
        const tautan = tombol.dataset.tautan;

        // Penyalinan lewat clipboard API hanya bekerja di halaman aman
        // (HTTPS atau localhost). Di jaringan lokal berbasis HTTP ia akan
        // ditolak diam-diam — karena itu ada jalan mundurnya.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(tautan).then(function () {
                tombol.innerHTML = '<i class="bi bi-check2 me-1"></i> Tersalin';
            });
            return;
        }

        window.prompt('Salin tautan ini lalu kirimkan:', tautan);
    });
})();
</script>
@endpush
