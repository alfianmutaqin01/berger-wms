@extends('layouts.wms')

@section('title', 'Daftar Picking '.$list->list_number)
@section('page_title', 'Daftar Picking '.$list->list_number)

@section('content')
{{-- Layar kerja operator, dan sekaligus layar periksa bagi Logistik.

     URUTAN BARIS DITENTUKAN KODE RAK (F-OUT-03 #3), bukan urutan pesanan.
     Operator berjalan sekali dari rak depan ke belakang; mengurutkannya per
     pesanan berarti ia bolak-balik ke rak yang sama sebanyak jumlah pesanan
     — dan itu justru yang mau dihindari dengan menggabungkan pesanan. --}}

<a href="{{ route('wms.picking.queue') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke daftar tugas
</a>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    @foreach($errors->all() as $pesan)<div>{{ $pesan }}</div>@endforeach
</div>
@endif

{{-- ------------------------------------------------------------ Ringkasan --}}
<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <span class="badge bg-{{ $list->status_color }}-subtle text-{{ $list->status_color }}-emphasis mb-2">
                    {{ $list->status_label }}
                </span>
                <div class="small text-muted">
                    {{ $list->warehouse?->name }} ·
                    disusun {{ $list->createdBy?->full_name ?? '—' }}, {{ $list->created_at?->format('d M Y H:i') }}
                    @if($list->claimed_by)
                        <div><i class="bi bi-person-badge me-1"></i>Dikerjakan {{ $list->claimedBy?->full_name }}</div>
                    @endif
                </div>
                @if($list->notes)
                    <div class="alert alert-light border rounded-3 small mt-2 mb-0">
                        <i class="bi bi-sticky me-1"></i>{{ $list->notes }}
                    </div>
                @endif
            </div>

            <div class="text-end">
                <div class="h4 fw-bold mb-0">{{ $ringkas['selesai'] }} / {{ $ringkas['total'] }}</div>
                <div class="small text-muted">baris ditandai</div>
                @if($ringkas['kurang'] > 0)
                    <span class="badge bg-warning-subtle text-warning-emphasis mt-1">
                        {{ $ringkas['kurang'] }} baris kurang
                    </span>
                @endif
            </div>
        </div>

        @php($persen = $ringkas['total'] > 0 ? round($ringkas['selesai'] / $ringkas['total'] * 100) : 0)
        <div class="progress mt-3" style="height:8px">
            <div class="progress-bar bg-success" style="width: {{ $persen }}%"></div>
        </div>

        {{-- Pesanan yang ikut dalam daftar ini. Operator perlu tahu barang
             ini untuk siapa saat memisahkannya di loading dock. --}}
        <div class="d-flex flex-wrap gap-2 mt-3">
            @foreach($list->orders as $order)
                <span class="badge bg-light text-dark border">
                    <span class="font-monospace">{{ $order->order_number }}</span>
                    · {{ $order->customer?->name ?? '—' }}
                </span>
            @endforeach
        </div>
    </div>
</div>

{{-- --------------------------------------------------------- Baris ambil --}}
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt text-primary me-2"></i> Urutan Pengambilan</h5>
            <small class="text-muted">Berurutan menurut kode rak — jalan sekali, dari depan ke belakang.</small>
        </div>

        @if($bolehDikerjakan)
        <form method="POST" action="{{ route('wms.picking.complete', $list) }}"
              onsubmit="return confirm('Selesaikan daftar ini? Stok di rak akan berkurang dan pesanannya berpindah ke Siap Kirim.');">
            @csrf
            <button class="btn btn-success btn-lg rounded-3 px-4"
                    @disabled($ringkas['selesai'] < $ringkas['total'])>
                <i class="bi bi-box-seam me-1"></i> Siap Loading
            </button>
        </form>
        @endif
    </div>

    <div class="card-body px-4 pt-3">
        @if($bolehDikerjakan && $ringkas['selesai'] < $ringkas['total'])
        <div class="alert alert-info border-0 rounded-3 small">
            <i class="bi bi-info-circle-fill me-2"></i>
            Tombol <strong>Siap Loading</strong> aktif setelah seluruh baris ditandai. Baris yang terlewat
            berarti barang yang tidak ikut naik ke kendaraan tanpa ada yang tahu.
        </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:120px">Rak</th>
                        <th>Produk</th>
                        <th>Batch</th>
                        <th>Untuk</th>
                        <th class="text-end">Qty</th>
                        <th class="text-center">Status</th>
                        @if($bolehDikerjakan)<th class="text-end" style="width:220px">Aksi</th>@endif
                    </tr>
                </thead>
                <tbody>
                @foreach($baris as $item)
                    <tr class="{{ $item->status === \App\Models\PickingListItem::STATUS_PICKED ? 'table-success' : ($item->status === \App\Models\PickingListItem::STATUS_SHORT ? 'table-warning' : '') }}">
                        <td>
                            <span class="fw-bold fs-5 font-monospace">{{ $item->location?->code ?? '—' }}</span>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $item->product?->sku }}</div>
                            <small class="text-muted">{{ $item->product?->name }}</small>
                        </td>
                        <td>
                            <span class="font-monospace">{{ $item->batch_no ?? '—' }}</span>
                            <div class="small text-muted">{{ $item->production_date?->format('d M Y') }}</div>
                        </td>
                        <td>
                            <div class="small">{{ $item->salesOrder?->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $item->salesOrder?->order_number }}</small>
                        </td>
                        <td class="text-end">
                            <span class="fw-bold fs-5">{{ $item->qty_to_pick }}</span>
                            <div class="small text-muted">{{ $item->product?->uom }}</div>
                            @if($item->status === \App\Models\PickingListItem::STATUS_SHORT)
                                <div class="small text-warning-emphasis fw-semibold">
                                    ditemukan {{ $item->qty_picked }}
                                </div>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($item->status === \App\Models\PickingListItem::STATUS_PICKED)
                                <i class="bi bi-check-circle-fill text-success fs-4"></i>
                            @elseif($item->status === \App\Models\PickingListItem::STATUS_SHORT)
                                <i class="bi bi-exclamation-triangle-fill text-warning fs-4"></i>
                                <div class="small text-muted">{{ $item->discrepancy_reason }}</div>
                            @else
                                <i class="bi bi-circle text-muted fs-4"></i>
                            @endif
                        </td>

                        @if($bolehDikerjakan)
                        <td class="text-end">
                            @if($item->sudahDitandai())
                                <form method="POST" action="{{ route('wms.picking.item.reset', [$list, $item]) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary rounded-3">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Batal tanda
                                    </button>
                                </form>
                            @else
                                {{-- Jalur cepat: satu ketuk. Isian qty sengaja
                                     TIDAK ada di sini — kalau tiap baris minta
                                     angka, operator mengetik angka yang sama
                                     ratusan kali sehari dan berhenti membacanya. --}}
                                <form method="POST" action="{{ route('wms.picking.item.pick', [$list, $item]) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-success rounded-3 px-3">
                                        <i class="bi bi-check-lg me-1"></i> Ambil
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-warning rounded-3 tombol-selisih"
                                        data-bs-toggle="modal" data-bs-target="#modalSelisih"
                                        data-aksi="{{ route('wms.picking.item.short', [$list, $item]) }}"
                                        data-sku="{{ $item->product?->sku }}"
                                        data-rak="{{ $item->location?->code }}"
                                        data-qty="{{ $item->qty_to_pick }}">
                                    Kurang
                                </button>
                            @endif
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($bolehDikerjakan)
{{-- Pintu keadaan khusus. Sengaja di balik satu ketukan tambahan supaya
     jalur normal tetap satu ketuk, tetapi tetap ADA — tanpanya, operator yang
     menemukan rak kurang hanya bisa menandai barang yang tidak ia ambil, atau
     berhenti dan menahan pengiriman. --}}
<div class="modal fade" id="modalSelisih" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formSelisih" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Barang di Rak Kurang</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning border-0 rounded-3 small">
                    <div class="fw-semibold" id="selisihSku"></div>
                    <div id="selisihRak"></div>
                </div>

                <label class="form-label small fw-semibold">
                    Berapa yang benar-benar ada di rak? <span class="text-danger">*</span>
                </label>
                <input type="number" name="qty_picked" id="selisihQty" class="form-control form-control-lg mb-1"
                       min="0" required>
                <div class="form-text mb-3">
                    Tertulis di daftar: <strong id="selisihTertulis"></strong>. Isi 0 kalau raknya kosong sama sekali.
                </div>

                <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                <textarea name="discrepancy_reason" class="form-control" rows="3" minlength="10" maxlength="1000" required
                          placeholder="Minimal 10 karakter, mis. rak hanya berisi 8 kaleng, sisanya tidak ditemukan"></textarea>

                <p class="text-muted small mt-3 mb-0">
                    Selisihnya akan dicatat sebagai <strong>koreksi stok</strong> saat Siap Loading ditekan,
                    lengkap dengan alasan ini — bukan sebagai barang yang keluar ke customer.
                </p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning rounded-3">Catat Selisih</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formSelisih');
    const qty = document.getElementById('selisihQty');

    document.querySelectorAll('.tombol-selisih').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            form.action = tombol.dataset.aksi;
            document.getElementById('selisihSku').textContent = tombol.dataset.sku || '';
            document.getElementById('selisihRak').textContent = 'Rak ' + (tombol.dataset.rak || '—');
            document.getElementById('selisihTertulis').textContent = tombol.dataset.qty;

            // Batas atas mengikuti baris yang ditekan: mengambil LEBIH banyak
            // daripada yang dicadangkan berarti mengambil jatah pesanan lain
            // dari batch yang sama.
            qty.max = Number(tombol.dataset.qty) - 1;
            qty.value = '';
        });
    });
});
</script>
@endif
@endsection
