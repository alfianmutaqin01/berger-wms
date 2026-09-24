@extends('layouts.wms')

@section('title', 'Pemindahan Stok DDP')
@section('page_title', 'Pemindahan Stok DDP ke Rak DDP')

@section('content')
{{-- Daftar di halaman ini TIDAK disimpan di mana pun: isinya stok DDP yang
     lokasinya masih rak barang bagus. Barisnya hilang sendiri begitu barangnya
     benar-benar berada di rak DDP, jadi tidak ada yang perlu ditandai selesai
     dan tidak ada tugas yang bisa lupa dibuat. --}}

@foreach(['success' => 'check-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : 'success' }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $errors->first() }}
</div>
@endif

<!-- Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-danger border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Menunggu dipindah</h6>
                <h3 class="mb-0 fw-bold text-danger">{{ $stats['baris'] }}</h3>
                <small class="text-muted">baris stok</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Total unit</h6>
                <h3 class="mb-0 fw-bold text-dark">{{ number_format($stats['unit']) }}</h3>
                <small class="text-muted">masih di rak FG</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Sudah diserahkan</h6>
                <h3 class="mb-0 fw-bold text-warning">{{ $stats['siap'] }}</h3>
                <small class="text-muted">menunggu operator</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-2">Deret rak DDP</h6>
                <h3 class="mb-0 fw-bold text-dark font-monospace">{{ $deretDdp->isEmpty() ? '—' : $deretDdp->implode(', ') }}</h3>
                <small class="text-muted">{{ $rakDdp->count() }} sel</small>
            </div>
        </div>
    </div>
</div>

@if($rakDdp->isEmpty())
    {{-- Tanpa rak DDP, seluruh halaman ini tidak bisa menyelesaikan apa pun:
         tidak ada tujuan yang boleh dipilih. Disebut di paling atas, lengkap
         dengan jalan keluarnya. --}}
    <div class="alert alert-warning border-0 shadow-sm rounded-4 d-flex align-items-start gap-3">
        <i class="bi bi-signpost-2-fill fs-4"></i>
        <div>
            <div class="fw-bold">Belum ada deret rak yang ditandai sebagai rak DDP.</div>
            <div class="small">
                Selama belum ada, barang DDP tidak punya tujuan yang boleh dipilih dan tetap berdiri di rak barang bagus.
                @can(\App\Support\Permission::INVENTORY_DDP_ASSIGN)
                    Tandai deretnya di <a href="{{ route('wms.locations.map') }}" class="fw-semibold">Denah Gudang</a> — cukup sekali untuk seluruh deret.
                @else
                    Mintakan ke Logistik untuk menandainya di Denah Gudang.
                @endcan
            </div>
        </div>
    </div>
@endif

@can(\App\Support\Permission::INVENTORY_DDP_ASSIGN)
<!-- Yang belum diserahkan ke operator -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3 p-md-4">
        <form method="POST" action="{{ route('wms.ddp.serahkan') }}">
            @csrf
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom">
                <div>
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-inboxes text-danger me-2"></i>Perlu diperiksa Logistik</h6>
                    <span class="text-muted small">Pilih baris yang benar-benar harus turun ke rak DDP, lalu serahkan ke operator.</span>
                </div>
                <button type="submit" class="btn btn-primary btn-sm rounded-3 px-3 fw-semibold text-nowrap"
                        @disabled($belumDiserahkan->isEmpty())>
                    <i class="bi bi-send me-1"></i> Serahkan ke operator
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px"><input type="checkbox" class="form-check-input" id="pilihSemua"></th>
                            <th class="text-secondary small fw-semibold">SKU</th>
                            <th class="text-secondary small fw-semibold">BATCH</th>
                            <th class="text-secondary small fw-semibold text-end">QTY</th>
                            <th class="text-secondary small fw-semibold">RAK SEKARANG</th>
                            <th class="text-secondary small fw-semibold">SEBAB DDP</th>
                            <th class="text-secondary small fw-semibold">KEDALUWARSA</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($belumDiserahkan as $baris)
                        <tr>
                            <td><input type="checkbox" class="form-check-input js-pilih" name="stock_ids[]" value="{{ $baris->id }}"></td>
                            <td>
                                {{-- SKU dan deskripsi berdampingan, bukan bertumpuk. --}}
                                <span class="fw-semibold font-monospace">{{ $baris->product?->sku ?? '—' }}</span>
                                <small class="text-muted">— {{ $baris->product?->name }}</small>
                            </td>
                            <td class="font-monospace small">{{ $baris->batch_no ?? '—' }}</td>
                            <td class="text-end fw-bold">{{ number_format($baris->qty_available) }} <span class="small text-muted fw-normal">{{ $baris->product?->uom }}</span></td>
                            <td><span class="badge bg-light text-dark border font-monospace">{{ $baris->location?->code ?? '—' }}</span></td>
                            <td><span class="badge bg-danger-subtle text-danger-emphasis border border-danger">{{ $baris->ddp_reason_label ?? 'DDP' }}</span></td>
                            <td class="small text-muted">{{ $baris->expiry_date?->format('d/m/Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-check2-circle display-6 d-block mb-2 opacity-25 text-success"></i>
                                Tidak ada stok DDP yang menunggu diperiksa.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>
@endcan

<!-- Daftar kerja operator -->
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-3 p-md-4">
        <div class="mb-3 pb-2 border-bottom">
            <h6 class="fw-bold text-dark mb-0"><i class="bi bi-box-arrow-down text-warning me-2"></i>Daftar kerja operator</h6>
            <span class="text-muted small">
                Sudah diperiksa Logistik. Turunkan barangnya, lalu catat rak DDP tempat barang itu diletakkan.
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-secondary small fw-semibold">SKU</th>
                        <th class="text-secondary small fw-semibold">BATCH</th>
                        <th class="text-secondary small fw-semibold text-end">QTY</th>
                        <th class="text-secondary small fw-semibold">RAK SEKARANG</th>
                        <th class="text-secondary small fw-semibold">SEBAB DDP</th>
                        <th class="text-secondary small fw-semibold">DISERAHKAN</th>
                        @if($bolehPindah)<th class="text-end text-secondary small fw-semibold text-nowrap">AKSI</th>@endif
                    </tr>
                </thead>
                <tbody>
                @forelse($daftarKerja as $baris)
                    @php
                        // Disiapkan di sini, bukan ditulis langsung di dalam
                        // atribut onclick: array yang terpotong antar baris
                        // membuat Blade salah membaca kurungnya, dan galatnya
                        // muncul sebagai ParseError di berkas view terkompilasi
                        // yang tidak menunjuk baris ini sama sekali.
                        $payload = [
                            'id' => $baris->id,
                            'sku' => $baris->product?->sku,
                            'nama' => $baris->product?->name,
                            'batch' => $baris->batch_no,
                            'qty' => $baris->qty_available,
                            'rak' => $baris->location?->code,
                        ];
                    @endphp
                    <tr>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $baris->product?->sku ?? '—' }}</span>
                            <small class="text-muted">— {{ $baris->product?->name }}</small>
                        </td>
                        <td class="font-monospace small">{{ $baris->batch_no ?? '—' }}</td>
                        <td class="text-end fw-bold">{{ number_format($baris->qty_available) }} <span class="small text-muted fw-normal">{{ $baris->product?->uom }}</span></td>
                        <td><span class="badge bg-light text-dark border font-monospace">{{ $baris->location?->code ?? '—' }}</span></td>
                        <td><span class="badge bg-danger-subtle text-danger-emphasis border border-danger">{{ $baris->ddp_reason_label ?? 'DDP' }}</span></td>
                        <td class="small text-muted">
                            {{ $baris->ddp_assigned_at?->format('d/m/Y H:i') }}
                            <div style="font-size:.72rem">{{ $baris->ddpAssignedBy?->full_name }}</div>
                        </td>
                        @if($bolehPindah)
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-warning rounded-pill px-3 fw-semibold d-inline-flex align-items-center text-nowrap"
                                    data-bs-toggle="modal" data-bs-target="#modalPindahDdp"
                                    onclick='bukaPindahDdp(@json($payload))'
                                    @disabled($rakDdp->isEmpty())>
                                <i class="bi bi-box-arrow-down me-1"></i> Pindahkan
                            </button>
                        </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $bolehPindah ? 7 : 6 }}" class="text-center py-5 text-muted">
                            <i class="bi bi-cup-hot display-6 d-block mb-2 opacity-25"></i>
                            Belum ada pemindahan yang diserahkan Logistik.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@if($bolehPindah)
@push('modals')
<div class="modal fade" id="modalPindahDdp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.ddp.pindahkan') }}">
            @csrf
            <input type="hidden" name="stock_id" id="ddpStockId">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark">
                        <i class="bi bi-box-arrow-down text-warning me-2"></i>Pindahkan ke Rak DDP
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="bg-light rounded-3 p-3 mb-3 small">
                        <div><span class="fw-semibold font-monospace" id="ddpSku"></span> <span class="text-muted" id="ddpNama"></span></div>
                        <div class="text-muted">Batch <span class="font-monospace" id="ddpBatch"></span> &middot; sekarang di rak <span class="font-monospace" id="ddpRak"></span></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Rak DDP tujuan *</label>
                        <select name="location_id" class="form-select" required>
                            <option value="">— Pilih rak DDP —</option>
                            @foreach($rakDdp as $rak)
                                <option value="{{ $rak->id }}">{{ $rak->code }}</option>
                            @endforeach
                        </select>
                        {{-- Hanya rak dari deret bertanda DDP yang ada di sini,
                             dan pembatasan yang sama diulang di server: dropdown
                             benar tetap bisa dilewati. --}}
                        <div class="form-text">Hanya deret yang ditandai Logistik sebagai rak DDP yang muncul di sini.</div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label small fw-semibold text-secondary">Qty dipindahkan *</label>
                        <input type="number" name="qty" id="ddpQty" class="form-control" min="1" required>
                        <div class="form-text">
                            Isi apa adanya kalau tidak seluruhnya terangkut sekali jalan; sisanya tetap muncul di daftar ini.
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top-0 rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning px-4 fw-bold">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endpush
@endif

@push('scripts')
<script>
    @if($bolehPindah)
    function bukaPindahDdp(data) {
        document.getElementById('ddpStockId').value = data.id;
        document.getElementById('ddpSku').textContent = data.sku ?? '—';
        document.getElementById('ddpNama').textContent = data.nama ? '— ' + data.nama : '';
        document.getElementById('ddpBatch').textContent = data.batch ?? '—';
        document.getElementById('ddpRak').textContent = data.rak ?? '—';

        const qty = document.getElementById('ddpQty');
        qty.value = data.qty;
        qty.max = data.qty;
    }
    @endif

    @if($bolehSerah)
    document.addEventListener('DOMContentLoaded', function () {
        const semua = document.getElementById('pilihSemua');

        if (semua) {
            semua.addEventListener('change', function () {
                document.querySelectorAll('.js-pilih').forEach(function (kotak) {
                    kotak.checked = semua.checked;
                });
            });
        }
    });
    @endif
</script>
@endpush
