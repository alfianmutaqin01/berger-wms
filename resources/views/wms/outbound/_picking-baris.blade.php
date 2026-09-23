{{-- --------------------------------------------------------- Baris ambil --}}
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt text-primary me-2"></i> Urutan Pengambilan</h5>
            <small class="text-muted">Berurutan menurut kode rak — jalan sekali, dari depan ke belakang.</small>
        </div>

        @if($bolehDikerjakan)
        <div class="d-flex align-items-center gap-3 flex-wrap">
            {{-- Dengan 100 baris, menyembunyikan yang sudah selesai membuat
                 sisanya mengerut ke arah operator. Tidak dinyalakan sendiri:
                 baris yang tiba-tiba hilang terbaca sebagai kesalahan. --}}
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="sembunyikanSelesai">
                <label class="form-check-label small text-muted" for="sembunyikanSelesai">
                    Sembunyikan yang sudah ditandai
                </label>
            </div>

            {{-- Pengiriman digeser ke besok, atau tugasnya dioper ke orang
                 lain. Tanpa pintu ini satu-satunya jalan adalah Logistik
                 membubarkan seluruh daftar lalu menyusunnya lagi dari nol. --}}
            <button type="button" class="btn btn-outline-secondary rounded-3"
                    data-bs-toggle="modal" data-bs-target="#modalLepasTugas">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Batal Ambil Tugas
            </button>

            @if($list->requisition)
                {{-- DAFTAR MRF: SERAH TERIMA, bukan Siap Loading.

                     Barang permintaan material tidak naik kendaraan mana pun
                     dan tidak menunggu Surat Jalan — ia berpindah tangan di
                     tempat. Divisinya disebut dengan namanya, bukan selalu
                     "Produksi": sejak Sales, QC dan R&D ikut meminta, kalimat
                     yang menyebut divisi yang salah menyesatkan operator.

                     Dua titik transit berdiri paling atas karena ke situlah
                     barangnya hampir selalu pergi; rak lain tetap bisa dipilih
                     kalau kenyataannya memang berbeda. --}}
                @php($divisiMrf = $list->requisition->nama_divisi)
                @php($tanyaSerah = $list->requisition->lewatTautan()
                    ? sprintf('Serahkan daftar ini? Stok di rak berkurang dan %s dikabari lewat WhatsApp bahwa barangnya bisa diambil.', $divisiMrf)
                    : sprintf('Serahkan daftar ini ke %s? Stok di rak berkurang dan barangnya LANGSUNG tercatat atas nama %s — tidak ada konfirmasi susulan.', $divisiMrf, $divisiMrf))
                <form method="POST" action="{{ route('wms.picking.complete', $list) }}" id="formSelesai"
                      class="d-flex flex-wrap gap-2 align-items-end"
                      onsubmit="return confirm(@json($tanyaSerah));">
                    @csrf
                    <div>
                        <label class="form-label small text-muted mb-1">Diserahkan ke</label>
                        @php($transit = $rakSerah->whereIn('zone', \App\Models\Location::ZONES_TRANSIT))
                        <select name="handover_location_id" class="form-select rounded-3" required style="min-width:200px">
                            <option value="">Pilih tempat…</option>
                            {{-- Dua kelompok, bukan satu daftar panjang: yang
                                 lazim dipilih tidak boleh tenggelam di antara
                                 ribuan kode rak. --}}
                            <optgroup label="Titik serah terima">
                                @foreach($transit as $rak)
                                    <option value="{{ $rak->id }}">{{ $rak->zone }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Rak gudang">
                                @foreach($rakSerah->whereNotIn('id', $transit->pluck('id')) as $rak)
                                    <option value="{{ $rak->id }}">{{ $rak->code }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                    </div>
                    <div class="flex-grow-1" style="min-width:180px">
                        <label class="form-label small text-muted mb-1">Catatan untuk {{ $divisiMrf }} (opsional)</label>
                        <input type="text" name="handover_note" class="form-control rounded-3" maxlength="500"
                               placeholder="Mis. 3 palet, ditumpuk di sisi kiri">
                    </div>
                    <button class="btn btn-success btn-lg rounded-3 px-4" id="tombolSiapLoading"
                            @disabled($ringkas['selesai'] < $ringkas['total'])>
                        <i class="bi bi-arrow-left-right me-1"></i> Serah Terima
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('wms.picking.complete', $list) }}" id="formSelesai"
                      onsubmit="return confirm('Selesaikan daftar ini? Stok di rak akan berkurang dan pesanannya berpindah ke Siap Kirim.');">
                    @csrf
                    <button class="btn btn-success btn-lg rounded-3 px-4" id="tombolSiapLoading"
                            @disabled($ringkas['selesai'] < $ringkas['total'])>
                        <i class="bi bi-box-seam me-1"></i> Siap Loading
                    </button>
                </form>
            @endif
        </div>
        @elseif($bolehMelepasTugas)
        {{-- Logistik/Manager: melepas tugas milik operator lain. Satu-satunya
             jalan saat operatornya sudah pulang dan daftarnya tertinggal
             terkunci atas namanya. --}}
        <button type="button" class="btn btn-outline-warning rounded-3"
                data-bs-toggle="modal" data-bs-target="#modalLepasTugas">
            <i class="bi bi-arrow-counterclockwise me-1"></i>
            Lepas Tugas {{ $list->claimedBy?->full_name }}
        </button>
        @endif
    </div>

    <div class="card-body px-4 pt-3">
        @if($bolehDikerjakan)
        <div class="alert alert-info border-0 rounded-3 small" id="catatanBelumLengkap"
             @if($ringkas['selesai'] >= $ringkas['total']) hidden @endif>
            <i class="bi bi-info-circle-fill me-2"></i>
            X            berarti barang yang tidak ikut naik ke kendaraan tanpa ada yang tahu.
        </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tabelPicking">
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
                    @php($ditandai = $item->sudahDitandai())
                    {{-- id dipakai dua hal: sasaran anchor pada jalan mundur
                         tanpa JavaScript, dan sasaran pembaruan satu baris. --}}
                    <tr id="baris-{{ $item->id }}" class="baris-ambil" data-status="{{ $item->status }}">
                        <td>
                            <span class="fw-bold fs-5 font-monospace">{{ $item->location?->code ?? '—' }}</span>
                        </td>
                        <td>
                            {{-- SKU dan deskripsi berdampingan, bukan bertumpuk. --}}
                            <span class="fw-semibold font-monospace">{{ $item->product?->sku }}</span>
                            <small class="text-muted">— {{ $item->product?->name }}</small>
                        </td>
                        <td>
                            <span class="font-monospace">{{ $item->batch_no ?? '—' }}</span>
                            <div class="small text-muted">{{ $item->production_date?->format('d M Y') }}</div>
                            @if($item->stock?->prioritize_out)
                                {{-- Kenapa batch ini, bukan yang lebih tua. Tanpa kalimat ini
                                     operator wajar mengira daftarnya salah dan mengambil sendiri
                                     batch yang lebih tua "supaya benar". --}}
                                <div class="badge bg-success-subtle text-success-emphasis border border-success mt-1 text-wrap text-start"
                                     style="font-size:.65rem; max-width: 180px;">
                                    ↑ Didahulukan — {{ $item->stock->prioritize_reason }}
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($item->stock_transfer_detail_id)
                                <div class="small">{{ $list->transfer?->toWarehouse?->name ?? 'Gudang tujuan' }}</div>
                                <small class="text-muted font-monospace">
                                    {{ $list->transfer?->transfer_number ?? '—' }}
                                </small>
                            @elseif($item->material_requisition_allocation_id)
                                <div class="small">{{ $list->requisition?->nama_divisi ?? '—' }} — {{ $list->requisition?->nama_pemohon ?? '—' }}</div>
                                <small class="text-muted font-monospace">
                                    {{ $list->requisition?->mrf_number ?? '—' }}
                                </small>
                            @else
                                <div class="small">{{ $item->salesOrder?->customer?->name ?? '—' }}</div>
                                <small class="text-muted font-monospace">
                                    {{ $item->salesOrder?->bc_so_number ?? $item->salesOrder?->order_number }}
                                </small>
                            @endif
                        </td>
                        <td class="text-end">
                            <span class="fw-bold fs-5">{{ $item->qty_to_pick }}</span>
                            <div class="small text-muted">{{ $item->product?->uom }}</div>
                            <div class="small text-warning-emphasis fw-semibold sel-ditemukan"
                                 @if($item->status !== \App\Models\PickingListItem::STATUS_SHORT) hidden @endif>
                                ditemukan <span class="nilai-ditemukan">{{ $item->qty_picked }}</span>
                            </div>
                        </td>

                        {{-- Ketiga bentuk status sudah ada di halaman sejak awal
                             dan hanya ditampilkan bergantian. Menyusun HTML dari
                             JavaScript berarti tampilan baris yang sama ditulis
                             di dua tempat, dan cepat atau lambat keduanya
                             berbeda. --}}
                        <td class="text-center sel-status">
                            <span class="status-pending" @if($ditandai) hidden @endif>
                                <i class="bi bi-circle text-muted fs-4"></i>
                            </span>
                            <span class="status-picked" @if($item->status !== \App\Models\PickingListItem::STATUS_PICKED) hidden @endif>
                                <i class="bi bi-check-circle-fill text-success fs-4"></i>
                            </span>
                            <span class="status-short" @if($item->status !== \App\Models\PickingListItem::STATUS_SHORT) hidden @endif>
                                <i class="bi bi-exclamation-triangle-fill text-warning fs-4"></i>
                                <div class="small text-muted nilai-alasan">{{ $item->discrepancy_reason }}</div>
                            </span>
                        </td>

                        @if($bolehDikerjakan)
                        <td class="text-end">
                            <div class="aksi-pending" @if($ditandai) hidden @endif>
                                {{-- Jalur cepat: satu ketuk. Isian qty sengaja
                                     TIDAK ada di sini — kalau tiap baris minta
                                     angka, operator mengetik angka yang sama
                                     ratusan kali sehari dan berhenti membacanya. --}}
                                <form method="POST" action="{{ route('wms.picking.item.pick', [$list, $item]) }}"
                                      class="d-inline aksi-picking" data-baris="{{ $item->id }}">
                                    @csrf
                                    <button class="btn btn-sm btn-success rounded-3 px-3">
                                        <i class="bi bi-check-lg me-1"></i> Ambil
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-warning rounded-3 tombol-selisih"
                                        data-bs-toggle="modal" data-bs-target="#modalSelisih"
                                        data-aksi="{{ route('wms.picking.item.short', [$list, $item]) }}"
                                        data-baris="{{ $item->id }}"
                                        data-sku="{{ $item->product?->sku }}"
                                        data-rak="{{ $item->location?->code }}"
                                        data-qty="{{ $item->qty_to_pick }}">
                                    Kurang
                                </button>
                            </div>

                            <div class="aksi-ditandai" @if(! $ditandai) hidden @endif>
                                <form method="POST" action="{{ route('wms.picking.item.reset', [$list, $item]) }}"
                                      class="d-inline aksi-picking" data-baris="{{ $item->id }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary rounded-3">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Batal tanda
                                    </button>
                                </form>
                            </div>
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
