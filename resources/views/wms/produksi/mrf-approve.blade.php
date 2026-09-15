@extends('layouts.wms')

@section('title', 'Setujui Permintaan Material')
@section('page_title', 'Pilih Batch untuk '.$mrf->mrf_number)

@section('content')
{{-- LAYAR LOGISTIK.

     Di sinilah permintaan Produksi ("200 pcs SKU X") diterjemahkan menjadi
     batch sungguhan di rak sungguhan. Produksi tidak melihat layar ini dan
     memang tidak perlu: yang tahu batch mana yang pantas diambil — yang
     paling dekat kedaluwarsa, atau justru yang DDP untuk direproses — adalah
     orang yang berdiri di depan raknya.

     BARANG DDP IKUT DITAMPILKAN, berbeda dari layar penjualan yang
     menyaringnya keluar. Jenis permintaan yang paling sering dipakai memang
     reproses barang DDP; menyaringnya membuat layar ini kosong persis pada
     perkara yang paling sering terjadi. --}}

@if(session('error'))
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
</div>
@endif

<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <small class="text-muted d-block">Pemohon</small>
                <span class="fw-semibold">{{ $mrf->requestedBy?->full_name ?? '—' }}</span>
                <div class="small text-muted">{{ $mrf->department_name ?? '—' }}</div>
            </div>
            <div class="col-md-3">
                <small class="text-muted d-block">Jenis</small>
                <span class="fw-semibold">{{ $mrf->jenis_label }}</span>
                <div class="small text-muted">Gudang {{ $mrf->warehouse?->code }}</div>
            </div>
            <div class="col-md-6">
                <small class="text-muted d-block">Keperluan</small>
                <span>{{ $mrf->purpose }}</span>
            </div>
        </div>

        <hr class="my-3">

        <div class="small text-muted">
            <i class="bi bi-check2-circle text-success me-1"></i>
            Sudah disetujui <strong>{{ $mrf->approver_name }}</strong>
            pada {{ $mrf->approved_at?->format('d/m/Y H:i') ?? '—' }}
            @if(filled($mrf->approval_note))
                — catatannya: <em>{{ $mrf->approval_note }}</em>
            @endif
        </div>
    </div>
</div>

<form method="POST" action="{{ route('wms.mrf.approve', $mrf) }}" id="formSetuju">
    @csrf

    @foreach($mrf->items as $item)
    @php($batch = $batchPerProduk[$item->id] ?? collect())
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h6 class="fw-bold mb-0 font-monospace">{{ $item->product?->sku }}</h6>
                    <div class="small text-muted">{{ $item->product?->name }}</div>
                    @if(filled($item->note))
                    <div class="small text-primary mt-1">
                        <i class="bi bi-chat-left-quote me-1"></i>Usulan Produksi: {{ $item->note }}
                    </div>
                    @endif
                </div>
                <div class="text-end">
                    <div class="small text-muted">Diminta</div>
                    <span class="fs-5 fw-bold">{{ number_format($item->qty_requested) }}</span>
                    <span class="text-muted small">{{ $item->product?->uom }}</span>
                </div>
            </div>

            @if($batch->isEmpty())
            <div class="alert alert-warning border-0 rounded-3 mb-0 small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Tidak ada satu batch pun dari SKU ini yang tersedia di gudang {{ $mrf->warehouse?->code }}.
                Kalau seluruh baris permintaan begini, tolak permintaannya dengan alasan itu — jangan
                disetujui kosong, karena Produksi akan menunggu barang yang tidak akan pernah datang.
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Rak</th>
                            <th>Batch</th>
                            <th>Produksi</th>
                            <th>Kedaluwarsa</th>
                            <th>Keadaan</th>
                            <th class="text-end">Tersedia</th>
                            <th style="width:130px" class="text-end">Ambil</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($batch as $stok)
                        <tr>
                            <td class="font-monospace">{{ $stok->location?->code ?? '—' }}</td>
                            <td class="font-monospace small">{{ $stok->batch_no ?? '—' }}</td>
                            <td class="small">{{ $stok->production_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="small">
                                {{ $stok->expiry_date?->format('d/m/Y') ?? '—' }}
                            </td>
                            <td>
                                @if($stok->status === \App\Models\InventoryStock::STATUS_DDP)
                                    <span class="badge bg-danger-subtle text-danger-emphasis">DDP</span>
                                    <span class="small text-muted d-block">{{ $stok->ddp_reason }}</span>
                                @else
                                    <span class="badge bg-success-subtle text-success-emphasis">Normal</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ number_format($stok->qty_available) }}</td>
                            <td>
                                <input type="hidden" name="baris[{{ $stok->id }}][item_id]" value="{{ $item->id }}">
                                <input type="hidden" name="baris[{{ $stok->id }}][stock_id]" value="{{ $stok->id }}">
                                <input type="number" name="baris[{{ $stok->id }}][qty]"
                                       class="form-control form-control-sm text-end rounded-3 qty-ambil"
                                       min="0" max="{{ $stok->qty_available }}"
                                       data-item="{{ $item->id }}" data-diminta="{{ $item->qty_requested }}"
                                       placeholder="0">
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="text-end small text-muted">Total dipilih untuk SKU ini</td>
                            <td class="text-end fw-bold" id="total-{{ $item->id }}">0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @endif
        </div>
    </div>
    @endforeach

    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary rounded-3">
            <i class="bi bi-check2-circle me-1"></i> Setujui &amp; Buat Daftar Picking
        </button>
        <button type="button" class="btn btn-outline-danger rounded-3" data-bs-toggle="modal" data-bs-target="#modalTolak">
            <i class="bi bi-x-circle me-1"></i> Tolak Permintaan
        </button>
        <a href="{{ route('wms.mrf.show', $mrf) }}" class="btn btn-link text-decoration-none">Kembali</a>
    </div>

    <p class="small text-muted mt-3 mb-0">
        Menekan Setujui <strong>mencadangkan</strong> batch yang dipilih — barangnya masih di rak, tetapi berhenti
        bisa dijual ke pelanggan mana pun — dan mengantrekan tugasnya ke operator. Barangnya baru benar-benar
        turun setelah operator menekan Siap Loading.
    </p>
</form>
@endsection

@push('modals')
<div class="modal fade" id="modalTolak" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.mrf.reject', $mrf) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title">Tolak {{ $mrf->mrf_number }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Alasan penolakan <span class="text-danger">*</span></label>
                <textarea name="reason" rows="3" class="form-control rounded-3" required minlength="5" maxlength="500"
                          placeholder="Mis. Batch yang diminta sudah dialokasikan ke PO260901007 yang berangkat besok."></textarea>
                <div class="form-text">
                    Produksi perlu tahu apakah ia harus menunggu atau mencari jalan lain. Penolakan tanpa
                    alasan hanya melahirkan permintaan kedua yang sama persis.
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-link text-decoration-none" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger rounded-3">Tolak Permintaan</button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
<script>
(function () {
    // Penjumlahan berjalan sambil mengetik. Tanpa ini, kelebihan baru
    // ketahuan setelah tombol ditekan dan servernya menolak — sesudah
    // Logistik mengisi belasan kolom.
    function hitung() {
        const total = {};

        document.querySelectorAll('.qty-ambil').forEach(function (kolom) {
            const item = kolom.dataset.item;
            total[item] = (total[item] || 0) + (parseInt(kolom.value, 10) || 0);
        });

        Object.keys(total).forEach(function (item) {
            const sel = document.getElementById('total-' + item);
            if (!sel) return;

            const diminta = parseInt(
                document.querySelector('.qty-ambil[data-item="' + item + '"]').dataset.diminta, 10
            );

            sel.textContent = total[item].toLocaleString('id-ID');
            sel.classList.toggle('text-danger', total[item] > diminta);
            sel.classList.toggle('text-success', total[item] > 0 && total[item] <= diminta);
        });
    }

    document.querySelectorAll('.qty-ambil').forEach(function (kolom) {
        kolom.addEventListener('input', hitung);
    });

    hitung();

    // Kolom kosong atau nol TIDAK ikut terkirim. Kalau ikut, servernya
    // menerima puluhan baris beralokasi nol untuk tiap batch yang kebetulan
    // terlihat di layar.
    document.getElementById('formSetuju').addEventListener('submit', function (e) {
        let ada = false;

        document.querySelectorAll('.qty-ambil').forEach(function (kolom) {
            const nilai = parseInt(kolom.value, 10) || 0;

            if (nilai < 1) {
                kolom.closest('td').querySelectorAll('input').forEach(function (i) { i.disabled = true; });
                return;
            }
            ada = true;
        });

        if (!ada) {
            e.preventDefault();
            document.querySelectorAll('.qty-ambil').forEach(function (kolom) {
                kolom.closest('td').querySelectorAll('input').forEach(function (i) { i.disabled = false; });
            });
            alert('Belum ada satu batch pun yang diisi qty-nya.');
        }
    });
})();
</script>
@endpush
