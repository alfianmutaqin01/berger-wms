<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-layers text-primary me-2"></i> Ketersediaan Stok Gudang</h5>
        <p class="text-muted small mt-1 mb-0">
            Satu baris = satu SKU. Klik barisnya untuk melihat rincian batch, rak, dan sisa umur simpan.
            Batch sengaja tidak dilebur agar urutan FIFO tetap terlacak.
        </p>
    </div>

    <div class="card-body p-4">
        <form method="GET" action="{{ url('/wms/inventory') }}" class="row g-2 mb-4 align-items-stretch">
            <div class="col-12 col-md-3">
                <div class="input-group h-100">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control bg-white border-start-0" placeholder="SKU, produk, batch, no. produksi...">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="warehouse_id" class="form-select h-100">
                    <option value="">Semua Gudang</option>
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($filters['warehouse_id'] == $w->id)>{{ $w->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="category_id" class="form-select h-100">
                    <option value="">Semua Kategori</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected($filters['category_id'] == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select h-100">
                    <option value="">Semua Status</option>
                    @foreach($statuses as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['status'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="production_date" value="{{ $filters['production_date'] }}" class="form-control h-100" title="Tanggal produksi">
            </div>
            <div class="col-12 col-md-1 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1" title="Terapkan filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ url('/wms/inventory') }}" class="btn btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="expiring" value="1" id="fExpiring"
                           @checked($filters['expiring']) onchange="this.form.submit()">
                    <label class="form-check-label small text-danger fw-semibold" for="fExpiring">
                        Hanya tampilkan yang hampir kedaluwarsa (≤ 90 hari)
                    </label>
                </div>
            </div>
        </form>

        @php
            // Saat filter status dipasang, salah satu blok memang sengaja
            // dikosongkan. Dibedakan dari "benar-benar tidak ada stok" supaya
            // blok kosong tidak terbaca sebagai data yang gagal dimuat.
            $statusDisaring = (bool) $filters['status'];
        @endphp

        @forelse($barisSku as $baris)
            @php
                $p = $baris['product'];
                $idAcc = 'sku'.$p->id;
            @endphp
            <div class="border rounded-4 mb-3 overflow-hidden {{ $baris['kritis'] ? 'border-danger border-2' : '' }}">
                <!-- Baris tertutup: hanya SKU dan angka ringkas -->
                <button class="btn w-100 text-start d-flex flex-wrap align-items-center gap-2 gap-md-3 p-3 bg-white collapsed"
                        type="button" data-bs-toggle="collapse" data-bs-target="#{{ $idAcc }}"
                        aria-expanded="false" aria-controls="{{ $idAcc }}">
                    <i class="bi bi-chevron-right chevron-sku text-muted flex-shrink-0"></i>
                    <span class="badge bg-light text-dark border font-monospace">{{ $p->sku }}</span>
                    <span class="fw-semibold text-dark flex-grow-1">{{ $p->name }}</span>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary">{{ $p->uom }}</span>
                    <span class="small text-nowrap">
                        <span class="text-muted">Good Stock:</span>
                        <strong class="text-success">{{ number_format($baris['total_good']) }}</strong>
                    </span>
                    @if($baris['per_gudang']->count() > 1)
                        {{-- Angka di atas MENJUMLAHKAN GUDANG YANG BERBEDA.
                             Tanpa rincian ini ia pernah terbaca sebagai selisih
                             stocktake — 234 di layar melawan 55 di laporan
                             Karawang, padahal 180 di antaranya sudah lama
                             dipindah ke Pekanbaru dan stocktake-nya benar. --}}
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning text-wrap"
                              title="Angka Good Stock di atas adalah gabungan seluruh gudang">
                            <i class="bi bi-diagram-3 me-1"></i>{{ $baris['per_gudang']->count() }} gudang:
                            @foreach($baris['per_gudang'] as $kodeGudang => $qty)
                                <span class="font-monospace">{{ $kodeGudang }}</span> {{ number_format($qty) }}@if(! $loop->last) &middot; @endif
                            @endforeach
                        </span>
                    @endif
                    @if($baris['total_karantina'] > 0)
                    <span class="text-muted">·</span>
                    <span class="small text-nowrap">
                        <span class="text-muted">Karantina:</span>
                        <strong class="text-warning-emphasis">{{ number_format($baris['total_karantina']) }}</strong>
                    </span>
                    @endif
                    <span class="text-muted">·</span>
                    <span class="small text-nowrap">
                        <span class="text-muted">DDP Stock:</span>
                        <strong class="{{ $baris['total_ddp'] > 0 ? 'text-danger' : 'text-muted' }}">{{ number_format($baris['total_ddp']) }}</strong>
                    </span>
                    @if($baris['kritis'])
                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i>Segera Exp</span>
                    @endif
                </button>

                <div class="collapse" id="{{ $idAcc }}">
                    <!-- ============ BLOK GOOD STOCK ============ -->
                    <div class="bg-success-subtle px-3 pt-3 pb-1 border-top">
                        <h6 class="fw-bold text-success-emphasis mb-2 small">
                            <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i>GOOD STOCK (Layak Jual)
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle bg-white rounded-3 mb-3">
                                <thead>
                                    <tr>
                                        <th class="text-secondary small fw-semibold">BATCH</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">TGL PROD</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">EXP DATE</th>
                                        <th class="text-secondary small fw-semibold text-nowrap" style="min-width: 130px;">SISA UMUR SIMPAN</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">LOKASI</th>
                                        <th class="text-secondary small fw-semibold text-end">TERSEDIA</th>
                                        <th class="text-secondary small fw-semibold text-end">DI-BOOK</th>
                                        @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER, \App\Support\Permission::INVENTORY_QUARANTINE])
                                        <th class="text-secondary small fw-semibold text-center">AKSI</th>
                                        @endcanany
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($baris['good'] as $stock)
                                        @php
                                            $warnaUmur = match($stock->shelf_life_urgency) {
                                                'expired' => 'text-danger fw-bold',
                                                'critical' => 'text-danger fw-semibold',
                                                'warning' => 'text-warning-emphasis fw-semibold',
                                                default => 'text-muted',
                                            };
                                        @endphp
                                        <tr>
                                            <td>
                                                <small class="font-monospace">{{ $stock->batch_no }}</small>
                                                @if($stock->has_quality_issue)
                                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger d-block mt-1" style="font-size: 0.65rem;"
                                                          title="Penanda informasi — batch ini tetap ikut FIFO. Pakai Karantina/DDP untuk menahannya.">
                                                        Quality Issue
                                                    </span>
                                                @endif
                                                @if($stock->prioritize_out)
                                                    <span class="badge bg-success-subtle text-success-emphasis border border-success d-block mt-1" style="font-size: 0.65rem;"
                                                          title="Dialokasikan lebih dulu, mendahului batch yang lebih tua. Alasan: {{ $stock->prioritize_reason }}">
                                                        ↑ Dahulukan Keluar
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="small text-muted text-nowrap">{{ $stock->production_date->translatedFormat('d M y') }}</td>
                                            <td class="small text-nowrap">{{ $stock->expiry_date->translatedFormat('d M y') }}</td>
                                            <td class="text-nowrap small {{ $warnaUmur }}">
                                                {{ $stock->shelf_life_label }}
                                                @if($stock->shelf_life_urgency === 'critical')
                                                    <span class="badge bg-warning text-dark ms-1">⚠ Segera Exp</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary font-monospace">{{ $stock->location?->code ?? '—' }}</span>
                                                <small class="d-block text-muted" style="font-size: 0.7rem;">{{ $stock->warehouse?->code }}</small>
                                            </td>
                                            <td class="text-end fw-bold text-nowrap">
                                                {{ number_format($stock->qty_available) }}
                                                <small class="text-muted fw-normal">{{ $p->uom }}</small>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                @if($stock->qty_allocated > 0)
                                                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary">{{ number_format($stock->qty_allocated) }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER, \App\Support\Permission::INVENTORY_QUARANTINE])
                                            <td class="text-center text-nowrap">
                                                @include('wms.inventory._aksi-batch', ['stock' => $stock, 'sku' => $p->sku])
                                            </td>
                                            @endcanany
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center text-muted small py-3">
                                                {{ $statusDisaring ? 'Disembunyikan oleh filter status.' : 'Tidak ada Good Stock untuk SKU ini.' }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ============ BLOK KARANTINA ============ -->
                    {{-- Selalu dirender meski kosong, pola yang sama dengan blok
                         DDP di bawahnya: ketiadaan batch yang sedang ditahan
                         harus terbaca sebagai informasi, bukan data yang belum
                         dimuat. BUKAN DDP — batch di sini biasanya masih layak
                         jual, cuma menunggu jangka waktunya lewat. --}}
                    <div class="bg-warning-subtle px-3 pt-3 pb-1 border-top">
                        <h6 class="fw-bold text-warning-emphasis mb-2 small">
                            <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i>KARANTINA (Menunggu, Masih Layak Jual)
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle bg-white rounded-3 mb-3">
                                <thead>
                                    <tr>
                                        <th class="text-secondary small fw-semibold">BATCH</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">TGL PROD</th>
                                        <th class="text-secondary small fw-semibold" style="min-width: 170px;">KARANTINA</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">LOKASI</th>
                                        <th class="text-secondary small fw-semibold text-end">QTY</th>
                                        @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER, \App\Support\Permission::INVENTORY_QUARANTINE])
                                        <th class="text-secondary small fw-semibold text-center">AKSI</th>
                                        @endcanany
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($baris['karantina'] as $stock)
                                        @php($sisaHari = $stock->quarantine_days_left)
                                        <tr>
                                            <td>
                                                <small class="font-monospace">{{ $stock->batch_no }}</small>
                                                @if($stock->has_quality_issue)
                                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger d-block mt-1" style="font-size: 0.65rem;"
                                                          title="Penanda informasi — batch ini tetap ikut FIFO. Pakai Karantina/DDP untuk menahannya.">
                                                        Quality Issue
                                                    </span>
                                                @endif
                                                @if($stock->prioritize_out)
                                                    <span class="badge bg-success-subtle text-success-emphasis border border-success d-block mt-1" style="font-size: 0.65rem;"
                                                          title="Dialokasikan lebih dulu, mendahului batch yang lebih tua. Alasan: {{ $stock->prioritize_reason }}">
                                                        ↑ Dahulukan Keluar
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="small text-muted text-nowrap">{{ $stock->production_date->translatedFormat('d M y') }}</td>
                                            <td class="small text-nowrap">
                                                @if($sisaHari !== null && $sisaHari <= 0)
                                                    <span class="text-success fw-semibold">Jangka waktu terlewati</span>
                                                    <small class="d-block text-muted">Akan dilepas sweep berikutnya.</small>
                                                @else
                                                    <span class="text-warning-emphasis fw-semibold">Sisa {{ $sisaHari }} hari</span>
                                                    <small class="d-block text-muted">
                                                        Habis {{ $stock->quarantine_until?->translatedFormat('d M Y') }}
                                                        ({{ $stock->quarantine_days }} hari)
                                                    </small>
                                                @endif
                                                @if($stock->quarantine_note)
                                                    <small class="d-block text-muted fst-italic">{{ $stock->quarantine_note }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-subtle text-primary-emphasis border border-primary font-monospace">{{ $stock->location?->code ?? '—' }}</span>
                                                <small class="d-block text-muted" style="font-size: 0.7rem;">{{ $stock->warehouse?->code }}</small>
                                            </td>
                                            <td class="text-end fw-bold text-nowrap">
                                                {{ number_format($stock->qty_available) }}
                                                <small class="text-muted fw-normal">{{ $p->uom }}</small>
                                            </td>
                                            @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER, \App\Support\Permission::INVENTORY_QUARANTINE])
                                            <td class="text-center text-nowrap">
                                                @include('wms.inventory._aksi-batch', ['stock' => $stock, 'sku' => $p->sku])
                                            </td>
                                            @endcanany
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted small py-3">
                                                {{ $statusDisaring ? 'Disembunyikan oleh filter status.' : 'Tidak ada batch yang sedang dikarantina untuk SKU ini.' }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ============ BLOK STOK DDP ============ -->
                    {{-- Selalu dirender meski kosong (docs/4 §4.3.9): ketiadaan
                         stok rusak harus terbaca sebagai informasi. --}}
                    <div class="bg-danger-subtle px-3 pt-3 pb-1 border-top">
                        <h6 class="fw-bold text-danger-emphasis mb-2 small">
                            <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i>STOK DDP (Rusak / Karantina / Expired)
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle bg-white rounded-3 mb-3">
                                <thead>
                                    <tr>
                                        <th class="text-secondary small fw-semibold">BATCH</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">TGL PROD</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">EXP DATE</th>
                                        <th class="text-secondary small fw-semibold" style="min-width: 150px;">KETERANGAN</th>
                                        <th class="text-secondary small fw-semibold text-nowrap">LOKASI</th>
                                        <th class="text-secondary small fw-semibold text-end">QTY</th>
                                        @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER, \App\Support\Permission::INVENTORY_QUARANTINE])
                                        <th class="text-secondary small fw-semibold text-center">AKSI</th>
                                        @endcanany
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($baris['ddp'] as $stock)
                                        <tr>
                                            <td>
                                                <small class="font-monospace">{{ $stock->batch_no }}</small>
                                                @if($stock->has_quality_issue)
                                                    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger d-block mt-1" style="font-size: 0.65rem;"
                                                          title="Penanda informasi — batch ini tetap ikut FIFO. Pakai Karantina/DDP untuk menahannya.">
                                                        Quality Issue
                                                    </span>
                                                @endif
                                                @if($stock->prioritize_out)
                                                    <span class="badge bg-success-subtle text-success-emphasis border border-success d-block mt-1" style="font-size: 0.65rem;"
                                                          title="Dialokasikan lebih dulu, mendahului batch yang lebih tua. Alasan: {{ $stock->prioritize_reason }}">
                                                        ↑ Dahulukan Keluar
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="small text-muted text-nowrap">{{ $stock->production_date->translatedFormat('d M y') }}</td>
                                            <td class="small text-nowrap">{{ $stock->expiry_date->translatedFormat('d M y') }}</td>
                                            <td class="small text-nowrap">
                                                @if($stock->status === \App\Models\InventoryStock::STATUS_EXPIRED)
                                                    <span class="text-danger fw-semibold">🔴 {{ $stock->status_label }}</span>
                                                @else
                                                    <span class="text-warning-emphasis fw-semibold">🟠 {{ $stock->ddp_reason_label ?? $stock->status_label }}</span>
                                                @endif
                                                <small class="d-block text-muted">{{ $stock->shelf_life_label }}</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary font-monospace">{{ $stock->location?->code ?? '—' }}</span>
                                                <small class="d-block text-muted" style="font-size: 0.7rem;">{{ $stock->warehouse?->code }}</small>
                                            </td>
                                            <td class="text-end fw-bold text-nowrap">
                                                {{ number_format($stock->qty_available) }}
                                                <small class="text-muted fw-normal">{{ $p->uom }}</small>
                                            </td>
                                            @canany([\App\Support\Permission::INVENTORY_ADJUST, \App\Support\Permission::INVENTORY_TRANSFER, \App\Support\Permission::INVENTORY_QUARANTINE])
                                            <td class="text-center text-nowrap">
                                                @include('wms.inventory._aksi-batch', ['stock' => $stock, 'sku' => $p->sku])
                                            </td>
                                            @endcanany
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center text-muted small py-3">
                                                {{ $statusDisaring ? 'Disembunyikan oleh filter status.' : 'Tidak ada stok DDP untuk SKU ini.' }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                Belum ada stok yang cocok dengan filter ini.
                <small class="d-block mt-2">Stok muncul setelah palet inbound diverifikasi Logistik.</small>
            </div>
        @endforelse

        @if($halaman->hasPages())
            <div class="mt-4">{{ $halaman->links() }}</div>
        @endif
    </div>
</div>
