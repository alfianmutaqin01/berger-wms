@can(\App\Support\Permission::INVENTORY_ADJUST)
{{--
    Tambah stok yang belum pernah tercatat sistem.

    Berbeda dari Koreksi Stok: yang ini MEMBUAT baris baru, bukan mengubah
    baris yang sudah ada. Ada karena sistem dipasang di gudang yang sudah
    berjalan — banyak barang fisiknya di rak tapi belum punya baris untuk
    dikoreksi. Batch, tanggal produksi, dan lokasi tetap wajib: ketiganya
    tumpuan FIFO, sweep kedaluwarsa, dan Stok DDP.
--}}
<div class="modal fade" id="modalTambahStok" tabindex="-1" aria-labelledby="judulTambahStok" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="{{ route('wms.inventory.store') }}" class="modal-content border-0 rounded-4">
            @csrf
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="judulTambahStok">
                    <i class="bi bi-plus-circle text-primary me-2"></i>Tambah Stok
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Untuk barang yang sudah ada di rak tetapi belum pernah tercatat sistem.
                    Batch yang sama di rak dan tanggal produksi yang sama akan
                    <strong>digabung</strong> ke baris yang sudah ada, bukan dibuat kembar.
                </p>

                <div class="row g-3">
                    {{-- GUDANG DIPILIH, TIDAK DISIMPULKAN DARI KODE RAK. Kode rak
                         tidak unik antar gudang — "A-01-02" ada di Karawang maupun
                         Pekanbaru — sehingga menyimpulkannya bisa menaruh stok di
                         gudang yang sama sekali tidak dimaksud tanpa pesan galat.

                         Yang gudangnya tunggal (Manager, Logistik) tidak diberi
                         pilihan sama sekali: pilihan yang cuma punya satu jawaban
                         hanya menambah langkah, dan wewenangnya tetap ditegakkan
                         di belakang oleh WarehouseScope. --}}
                    @if($warehouses->count() > 1)
                        <div class="col-12">
                            <label for="tsGudang" class="form-label fw-semibold">
                                Gudang Tujuan <span class="text-danger">*</span>
                            </label>
                            <select name="warehouse_id" id="tsGudang" required class="form-select">
                                <option value="">— pilih gudang —</option>
                                @foreach($warehouses as $w)
                                    <option value="{{ $w->id }}" @selected(old('warehouse_id') == $w->id)>
                                        {{ $w->code }} — {{ $w->name }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="text-muted">
                                Wajib dipilih. Kode rak yang sama bisa ada di lebih dari satu gudang,
                                jadi raknya dicari di dalam gudang ini saja.
                            </small>
                        </div>
                    @else
                        <input type="hidden" name="warehouse_id" value="{{ $warehouses->first()?->id }}">
                        <div class="col-12">
                            <div class="alert alert-light border rounded-3 small mb-0">
                                <i class="bi bi-building me-1"></i>
                                Masuk ke gudang <strong>{{ $warehouses->first()?->code }} —
                                {{ $warehouses->first()?->name }}</strong>, wilayah kerja Anda.
                            </div>
                        </div>
                    @endif

                    <div class="col-12 col-md-6">
                        <label for="tsSku" class="form-label fw-semibold">SKU <span class="text-danger">*</span></label>
                        <input type="text" name="sku" id="tsSku" required maxlength="50"
                               class="form-control font-monospace" placeholder="ID1-F00113202225"
                               value="{{ old('sku') }}">
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="tsLokasi" class="form-label fw-semibold">Kode Lokasi <span class="text-danger">*</span></label>
                        <input type="text" name="location_code" id="tsLokasi" required maxlength="20"
                               class="form-control font-monospace" placeholder="A-01-02"
                               value="{{ old('location_code') }}">
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="tsBatch" class="form-label fw-semibold">Nomor Batch <span class="text-danger">*</span></label>
                        <input type="text" name="batch_no" id="tsBatch" required maxlength="50"
                               class="form-control font-monospace" placeholder="BT-2026-001"
                               value="{{ old('batch_no') }}">
                        <small class="text-muted">Tercetak di kaleng/pail.</small>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="tsTanggal" class="form-label fw-semibold">Tanggal Produksi <span class="text-danger">*</span></label>
                        <input type="date" name="production_date" id="tsTanggal" required
                               max="{{ now()->toDateString() }}" class="form-control"
                               value="{{ old('production_date') }}">
                        <small class="text-muted">Tanggal kedaluwarsa dihitung dari sini.</small>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="tsQty" class="form-label fw-semibold">Qty <span class="text-danger">*</span></label>
                        <input type="number" name="qty" id="tsQty" required min="1" max="1000000" step="1"
                               class="form-control" value="{{ old('qty') }}">
                    </div>
                    <div class="col-12">
                        <label for="tsAlasan" class="form-label fw-semibold">Alasan <span class="text-danger">*</span></label>
                        <textarea name="reason" id="tsAlasan" rows="2" required minlength="5" maxlength="500"
                                  class="form-control"
                                  placeholder="mis. Stocktake 1 Sep, barang sudah di rak sejak sebelum sistem dipakai">{{ old('reason') }}</textarea>
                        <small class="text-muted">Tercatat di ledger sebagai koreksi, berikut nama Anda.</small>
                    </div>
                </div>

                <div class="alert alert-info border-0 rounded-3 small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Kalau ada pesanan yang sedang menunggu SKU ini, stoknya akan langsung
                    dialokasikan ke pesanan tersebut dan Anda diberi tahu ke mana perginya.
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary rounded-3">
                    <i class="bi bi-plus-lg me-1"></i> Tambah Stok
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Impor Stok Awal: pengisian sekali jalan untuk gudang yang sudah berjalan. --}}
<div class="modal fade" id="modalImporStok" tabindex="-1" aria-labelledby="judulImporStok" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="{{ route('wms.inventory.import.preview') }}"
              enctype="multipart/form-data" class="modal-content border-0 rounded-4">
            @csrf
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="judulImporStok">
                    <i class="bi bi-upload text-primary me-2"></i>Impor Stok Awal
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Mengisi stok gudang yang sudah berjalan ke sistem. Berkas .xlsx / .xls,
                    maksimal 10 MB. Baris pertama harus berisi judul kolom.
                </p>

                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0 small font-monospace">
                        <thead class="table-light">
                            <tr><th>SKU</th><th>Batch</th><th>Tanggal Produksi</th><th>Qty</th><th>Lokasi</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>ID1-F00113202225</td><td>BT-2026-001</td><td>2026-03-15</td><td>120</td><td>A-01-02</td></tr>
                            <tr><td>ID1-F0011B128320</td><td>BT-2026-004</td><td>2026-04-02</td><td>40</td><td>B-01-01</td></tr>
                        </tbody>
                    </table>
                </div>

                <ul class="small text-muted ps-3 mb-3">
                    <li>Semua kolom <strong>wajib</strong> terisi. Baris tanpa batch atau tanggal
                        produksi ditolak — tanpa keduanya FIFO dan kedaluwarsa tidak bisa dihitung.</li>
                    <li>Gudang diambil dari kode lokasi, jadi tidak perlu kolom tersendiri.</li>
                    <li>Baris yang sudah ada <strong>disamakan</strong> dengan isi berkas, bukan
                        ditambahkan — mengimpor berkas yang sama dua kali tidak melipatgandakan stok.</li>
                    <li>SKU atau lokasi yang tidak dikenal dilaporkan per baris, bukan menghentikan impor.</li>
                </ul>

                <label for="berkasStok" class="form-label fw-semibold">Berkas Excel <span class="text-danger">*</span></label>
                <input type="file" name="file" id="berkasStok" required accept=".xlsx,.xls" class="form-control">
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary rounded-3">
                    <i class="bi bi-eye me-1"></i> Pratinjau
                </button>
            </div>
        </form>
    </div>
</div>
@endcan

@can(\App\Support\Permission::INVENTORY_ADJUST)
<!-- Koreksi stok -->
<div class="modal fade" id="modalAdjust" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ url('/wms/inventory/adjust') }}" class="modal-content border-0 rounded-4">
            @csrf
            <input type="hidden" name="stock_id" id="adjStockId">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-warning me-2"></i>Koreksi Stok</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded-3 p-3 mb-3 small">
                    <div><strong id="adjSku" class="font-monospace"></strong></div>
                    <div class="text-muted">Batch <span id="adjBatch" class="font-monospace"></span></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Qty Tersedia Saat Ini</label>
                    <input type="text" id="adjQtyOld" class="form-control bg-light" readonly>
                    <small class="text-muted" id="adjAllocHint"></small>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Qty Baru <span class="text-danger">*</span></label>
                    <input type="number" name="qty_new" id="adjQtyNew" class="form-control" min="0" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Tandai sebagai DDP (opsional)</label>
                    <select name="ddp_reason" class="form-select">
                        <option value="">Tetap Good Stock</option>
                        @foreach(\App\Models\InventoryStock::DDP_REASON_LABELS as $slug => $label)
                            <option value="{{ $slug }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Stok DDP tidak pernah ikut alokasi FIFO.</small>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Alasan Koreksi <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="2" minlength="5" maxlength="500" required
                              placeholder="Contoh: hasil stocktake 31 Agu 2026, selisih 2 pail rusak saat penurunan."></textarea>
                    <small class="text-muted">Wajib diisi — tercatat permanen di ledger stok.</small>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning fw-bold">Simpan Koreksi</button>
            </div>
        </form>
    </div>
</div>
@endcan

@can(\App\Support\Permission::INVENTORY_TRANSFER)
<!-- Pindah rak -->
<div class="modal fade" id="modalTransfer" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ url('/wms/inventory/transfer') }}" class="modal-content border-0 rounded-4">
            @csrf
            <input type="hidden" name="stock_id" id="trfStockId">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right text-primary me-2"></i>Pindah Rak</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded-3 p-3 mb-3 small">
                    <div><strong id="trfSku" class="font-monospace"></strong></div>
                    <div class="text-muted">Batch <span id="trfBatch" class="font-monospace"></span> · dari rak <strong id="trfLoc" class="font-monospace"></strong></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Qty Dipindahkan <span class="text-danger">*</span></label>
                    <input type="number" name="qty" id="trfQty" class="form-control" min="1" required>
                    <small class="text-muted" id="trfQtyHint"></small>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Rak Tujuan <span class="text-danger">*</span></label>
                    <input type="text" name="to_location_code" class="form-control text-uppercase font-monospace" placeholder="mis. B-01-05" required>
                    <small class="text-muted">Harus rak aktif di gudang yang sama.</small>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Alasan Pemindahan <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="2" minlength="5" maxlength="500" required
                              placeholder="Contoh: konsolidasi batch agar rak B-01-01 bisa dikosongkan."></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary fw-bold">Pindahkan</button>
            </div>
        </form>
    </div>
</div>
@endcan

@can(\App\Support\Permission::INVENTORY_QUARANTINE)
{{--
    Karantina — permintaan pemilik produk (bukan PRD).

    BUKAN penandaan DDP: batch ini biasanya MASIH LAYAK JUAL, cuma ditahan
    menunggu hasil pemeriksaan QC selesai dinyatakan. Lama harinya bebas
    diisi (mis. 30 atau 90 hari) supaya sistem tinggal menghitung tanggal
    lepasnya sendiri — Logistik tidak perlu mengingat tanggal kalender.
--}}
<div class="modal fade" id="modalKarantina" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.inventory.quarantine') }}" class="modal-content border-0 rounded-4">
            @csrf
            <input type="hidden" name="stock_id" id="krtStockId">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-hourglass-split text-warning me-2"></i>Karantina Batch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded-3 p-3 mb-3 small">
                    <div><strong id="krtSku" class="font-monospace"></strong></div>
                    <div class="text-muted">Batch <span id="krtBatch" class="font-monospace"></span></div>
                </div>

                <div class="alert alert-warning border-0 rounded-3 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Berlaku untuk <strong>seluruh baris stok</strong> batch ini di gudang yang sama,
                    bukan cuma baris yang dipilih — satu batch, satu keputusan karantina.
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold">Lama Karantina (hari) <span class="text-danger">*</span></label>
                    <input type="number" name="days" id="krtDays" class="form-control" min="1" max="365" step="1" required
                           placeholder="mis. 30">
                    <small class="text-muted">
                        Otomatis kembali jadi Good Stock setelah jangka waktu ini lewat — tidak perlu tindakan manual.
                    </small>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Catatan (opsional)</label>
                    <textarea name="note" class="form-control" rows="2" maxlength="500"
                              placeholder="Contoh: menunggu hasil uji QC batch produksi 18 Sep 2026."></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning fw-bold">Karantina</button>
            </div>
        </form>
    </div>
</div>

{{-- Dahulukan Keluar — kebalikan karantina. Alasannya WAJIB: ini satu-satunya
     penanda yang melanggar FIFO, dan orang akan bertanya kenapa batch baru
     keluar duluan sementara yang lama menua di rak. --}}
<div class="modal fade" id="modalPrioritas" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.inventory.prioritize') }}" class="modal-content border-0 rounded-4">
            @csrf
            <input type="hidden" name="stock_id" id="prtStockId">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-up text-success me-2"></i>Dahulukan Keluar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded-3 p-3 mb-3 small">
                    <div><strong id="prtSku" class="font-monospace"></strong></div>
                    <div class="text-muted">Batch <span id="prtBatch" class="font-monospace"></span></div>
                </div>

                <div class="alert alert-success border-0 rounded-3 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Batch ini akan <strong>dialokasikan lebih dulu</strong> walau ada batch yang lebih tua —
                    berlaku untuk pesanan, booking, maupun pengeluaran saat kirim. Penanda lepas sendiri
                    begitu batchnya habis.
                </div>

                <div class="alert alert-warning border-0 rounded-3 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Batch yang lebih tua akan <strong>menunggu lebih lama</strong> dan bisa mendekati
                    kedaluwarsa. Lepas penandanya begitu tidak diperlukan lagi.
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="2" maxlength="500" required
                              placeholder="Contoh: batch B05 diminta customer PT Aneka, harus dikosongkan lebih dulu."></textarea>
                    <small class="text-muted">
                        Ikut tercatat di ledger stok dan terbaca di layar ini — supaya nanti masih ada yang
                        bisa menjawab kenapa FIFO dilewati.
                    </small>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success fw-bold">Dahulukan Batch Ini</button>
            </div>
        </form>
    </div>
</div>
@endcan
