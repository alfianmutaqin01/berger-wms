@extends('layouts.wms')

@section('title', 'Terima Pesanan '.$order->order_number)
@section('page_title', 'Terima Pesanan')

@push('styles')
<style>
    /*
        Kisi mirip lembar Excel.

        Alasannya bukan gaya-gayaan: Logistik menyalin daftar ini ke dokumen
        Excel lain, jadi bentuknya sengaja dibuat rapat, bergaris penuh, dan
        rata kanan untuk angka — sama seperti sheet asalnya. Angka ditulis
        TANPA pemisah ribuan supaya hasil salinan langsung terbaca Excel
        sebagai angka, bukan teks.
    */
    .kisi { border-collapse: collapse; width: 100%; font-size: 0.875rem; }
    .kisi th, .kisi td { border: 1px solid #cbd5e1; padding: 0.35rem 0.5rem; }
    .kisi thead th { background: #f1f5f9; font-weight: 600; white-space: nowrap; }
    .kisi td.angka, .kisi th.angka { text-align: right; font-variant-numeric: tabular-nums; }
    .kisi input { border: 0; width: 100%; text-align: right; background: transparent; padding: 0; }
    .kisi input:focus { outline: 2px solid #2563eb; outline-offset: -2px; background: #fff; }
    .kisi tr.kurang td { background: #fff7ed; }
    .zona-tempel { border: 2px dashed #94a3b8; border-radius: 0.75rem; background: #f8fafc; }
</style>
@endpush

@section('content')
@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Pesanan belum bisa diterima:</strong>
    <ul class="mb-0 mt-2">
        @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
    </ul>
</div>
@endif

<a href="{{ route('wms.approval.index') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke antrean
</a>

{{-- Apa yang DULU salah, dibawa ke layar penilaian. Tanpa ini, pengajuan
     kedua dinilai tanpa yang menilainya tahu ia sedang menilai sebuah
     koreksi — dan alasan penolakan pertama tersimpan di tempat yang tidak
     dilihat siapa pun saat keputusan diambil. --}}
@if($order->rejections->isNotEmpty())
<div class="alert alert-warning border-0 shadow-sm rounded-3">
    <strong class="d-block mb-2">
        <i class="bi bi-arrow-repeat me-2"></i>
        Ini pengajuan ke-{{ $order->rejections->count() + 1 }} — pesanan ini pernah ditolak
        {{ $order->rejections->count() }}&times;
    </strong>
    @foreach($order->rejections as $tolak)
        <div class="small {{ ! $loop->last ? 'border-bottom pb-2 mb-2' : '' }}">
            <span class="fw-semibold">Pengajuan ke-{{ $tolak->attempt_no }}:</span>
            {{ $tolak->reason }}
            <span class="text-muted">
                ({{ $tolak->rejected_at?->translatedFormat('d M Y, H:i') }},
                {{ $tolak->rejectedBy?->full_name ?? '—' }})
            </span>
        </div>
    @endforeach
</div>
@endif

<div class="row g-3">
    {{-- ------------------------------------------------ Identitas pesanan --}}
    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6 class="text-muted fw-normal mb-1">
                    {{ $order->isDocumentBased() ? 'No. PO Customer' : 'No. PO' }}
                </h6>
                <h4 class="fw-bold font-monospace mb-1">
                    {{ $order->isDocumentBased() ? ($order->customer_po_number ?? '—') : $order->order_number }}
                </h4>
                @if($order->isDocumentBased())
                    {{-- Nomor internal tetap ada dan tetap ditampilkan: itulah
                         yang dipakai seluruh sistem, nomor customer hanya rujukan. --}}
                    <div class="small text-muted mb-3">No. internal: <span class="font-monospace">{{ $order->order_number }}</span></div>
                @else
                    <div class="mb-3"></div>
                @endif

                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted fw-normal">Customer</dt>
                    <dd class="col-7 fw-semibold">
                        {{ $order->customer?->name ?? '—' }}
                        {{-- F-BILL-03: informasi, bukan pemblokir. Tombol Terima tetap bisa ditekan. --}}
                        <div class="mt-1">@include('partials.penanda-piutang', ['penanda' => $piutang, 'lengkap' => true])</div>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Kode Customer</dt>
                    <dd class="col-7 font-monospace">{{ $order->customer?->code ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Alamat</dt>
                    <dd class="col-7">{{ $order->customer?->full_address ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Telepon</dt>
                    <dd class="col-7 font-monospace">{{ $order->customer?->phone_label ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Sales</dt>
                    <dd class="col-7">
                        {{ $order->user?->full_name ?? '—' }}
                        @if($order->dibuatkanOrangLain())
                            {{-- Ditandai DI SEBELAH nama Sales, bukan di kotak
                                 terpisah di bawah: yang membaca baris ini sedang
                                 menyimpulkan "ini pesanan si A", dan kesimpulan
                                 itu harus dikoreksi di detik yang sama. --}}
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1">
                                <i class="bi bi-person-badge me-1"></i>dibuatkan
                            </span>
                        @endif
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Gudang</dt>
                    <dd class="col-7">{{ $order->warehouse?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Pembayaran</dt>
                    <dd class="col-7">{{ $order->paymentTerm?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Disubmit</dt>
                    <dd class="col-7">{{ $order->submitted_at?->format('d M Y H:i') ?? '—' }}</dd>
                </dl>

                {{-- JALUR INTERNAL DIKATAKAN LENGKAP DI SINI.

                     Pemilik produk memutuskan pembuat pesanan boleh menyetujui
                     pesanannya sendiri, jadi tidak ada mata kedua di rantai
                     ini. Yang tersisa sebagai kontrol adalah orang yang sedang
                     membaca layar ini — dan ia hanya bisa menjalankan perannya
                     kalau tahu pesanan ini tidak datang dari Sales-nya. --}}
                @if($order->dibuatkanOrangLain())
                    <div class="alert alert-warning border-0 rounded-3 mt-3 mb-0 small">
                        <div class="fw-semibold mb-1">
                            <i class="bi bi-person-badge me-1"></i>
                            Dibuat {{ $order->placedBy?->full_name ?? 'pengguna internal' }},
                            bukan oleh {{ $order->user?->full_name ?? 'Sales' }}
                        </div>
                        <div class="text-muted">Alasan: {{ $order->placed_reason }}</div>
                    </div>
                @endif

                @if(filled($order->notes))
                    <div class="alert alert-light border mt-3 mb-0 small">
                        <i class="bi bi-chat-left-text me-1"></i> {{ $order->notes }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------- Kisi item --}}
    <div class="col-12 col-xl-8">
        <form method="POST" action="{{ route('wms.approval.accept', $order) }}" id="formTerima">
            @csrf

            @if($order->isDocumentBased())
                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body">
                        <h6 class="fw-bold mb-2"><i class="bi bi-paperclip text-primary me-1"></i> Dokumen dari Sales</h6>
                        <p class="small text-muted mb-3">
                            Unduh berkasnya, masukkan rinciannya ke sistem BC, lalu salin hasilnya
                            (kolom <strong>SKU</strong> dan <strong>Qty</strong>) dan tempel ke kisi di bawah.
                        </p>
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <a href="{{ route('wms.approval.document', $order) }}" class="btn btn-primary rounded-3">
                                <i class="bi bi-download me-1"></i> Unduh Dokumen
                            </a>
                            <div class="small text-muted">
                                <div class="fw-semibold text-dark">{{ $order->document_name ?? '—' }}</div>
                                {{ $order->document_size ? number_format($order->document_size / 1024, 0).' KB' : '' }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4 mb-3">
                    <div class="card-body">
                        <div class="fw-bold mb-1">
                            <i class="bi bi-clipboard-plus text-primary me-1"></i> Tempel dari sistem BC
                        </div>
                        <p class="small text-muted mb-3">
                            Salin kolom <strong>SKU</strong> dari Excel lalu tempel di kiri, kemudian
                            salin kolom <strong>Qty</strong> dan tempel di kanan. Urutan barisnya harus
                            sama. Kalau Anda menyalin dua kolom sekaligus, tempel saja di kotak SKU —
                            sistem memisahkannya sendiri.
                        </p>

                        <div class="row g-3">
                            <div class="col-12 col-md-7">
                                <label for="tempelSku" class="form-label small fw-semibold mb-1">Kolom SKU</label>
                                <textarea id="tempelSku" rows="6" spellcheck="false"
                                          class="form-control zona-tempel font-monospace small"
                                          placeholder="ID1-F00113202225&#10;ID1-F0011B128320"></textarea>
                                <small class="text-muted" id="hitungSku">0 baris</small>
                            </div>
                            <div class="col-12 col-md-5">
                                <label for="tempelQty" class="form-label small fw-semibold mb-1">Kolom Qty</label>
                                <textarea id="tempelQty" rows="6" spellcheck="false"
                                          class="form-control zona-tempel font-monospace small text-end"
                                          placeholder="100&#10;50"></textarea>
                                <small class="text-muted" id="hitungQty">0 baris</small>
                            </div>
                        </div>

                        {{-- Peringatan jumlah baris adalah penjaga terpenting di sini.
                             Menempel dua kolom terpisah bisa meleset satu baris (mis.
                             kolom SKU ikut terbawa judulnya), dan akibatnya BUKAN galat
                             melainkan qty yang menempel diam-diam ke SKU yang salah. --}}
                        <div id="selisihBaris" class="alert alert-danger py-2 mt-3 mb-0 small d-none">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            Jumlah barisnya tidak sama — <span id="rincianSelisih"></span>.
                            Kalau diproses, qty bisa menempel ke SKU yang salah. Periksa dulu, mis. baris judul yang ikut tersalin.
                        </div>

                        <div class="d-flex justify-content-end align-items-center mt-3 gap-2">
                            <button type="button" id="btnKosongkan" class="btn btn-sm btn-link text-muted text-decoration-none">
                                Kosongkan
                            </button>
                            <button type="button" id="btnProsesTempel" class="btn btn-sm btn-outline-primary rounded-3">
                                <i class="bi bi-arrow-down-circle me-1"></i> Proses Tempelan
                            </button>
                        </div>

                        <div id="hasilTempel" class="mt-2"></div>
                    </div>
                </div>
            @endif

            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-table text-primary me-2"></i> Rincian Pesanan</h5>
                        <small class="text-muted">Kolom <strong>Setuju</strong> bisa diubah. Kolom lain hanya bacaan.</small>
                    </div>
                    <button type="button" id="btnSalin" class="btn btn-sm btn-outline-secondary rounded-3">
                        <i class="bi bi-clipboard me-1"></i> Salin ke Excel
                    </button>
                </div>

                <div class="card-body px-4 pt-3">
                    <div class="table-responsive">
                        <table class="kisi" id="kisi">
                            <thead>
                                <tr>
                                    <th style="width:2.5rem">#</th>
                                    <th>SKU</th>
                                    <th>Deskripsi</th>
                                    <th style="width:4rem">UOM</th>
                                    <th class="angka" style="width:5.5rem">Pesan</th>
                                    <th class="angka" style="width:5.5rem">Stok</th>
                                    <th class="angka" style="width:6rem">Setuju</th>
                                    <th style="width:9rem">Status</th>
                                    {{-- Kolom tombol hapus HANYA untuk metode dokumen. Pada metode
                                         rincian barisnya berasal dari Sales dan tidak boleh dibuang,
                                         jadi kolomnya tidak digambar sama sekali - bukan digambar
                                         kosong yang menyisakan sel menggantung di ujung kanan. --}}
                                    @if($order->isDocumentBased())
                                        <th style="width:2.5rem"></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody id="isiKisi"></tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="4" class="text-end">Total</td>
                                    <td class="angka" id="totalPesan">0</td>
                                    <td class="angka">—</td>
                                    <td class="angka" id="totalSetuju">0</td>
                                    <td colspan="{{ $order->isDocumentBased() ? 2 : 1 }}"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div id="kisiKosong" class="text-center py-5 text-muted d-none">
                        <i class="bi bi-table display-6 d-block mb-2 opacity-50"></i>
                        Belum ada rincian item. Tempelkan daftar dari sistem BC di atas.
                    </div>

                    <div id="peringatanStok" class="alert alert-warning border-0 rounded-3 mt-3 d-none">
                        <i class="bi bi-exclamation-circle-fill me-2"></i>
                        <strong><span id="jumlahKurang">0</span> unit menunggu stok.</strong>
                        Pesanan tetap bisa diterima, tetapi porsi itu belum bisa dipicking sampai stoknya
                        ditambahkan. Jelaskan alasannya di catatan penerimaan.
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------- Keputusan akhir --}}
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-5">
                            <label for="bcSo" class="form-label fw-semibold">
                                Nomor SO (sistem BC) <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="bc_so_number" id="bcSo" required maxlength="50"
                                   value="{{ old('bc_so_number') }}"
                                   class="form-control font-monospace @error('bc_so_number') is-invalid @enderror"
                                   placeholder="SO-2026-00123">
                            @error('bc_so_number')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="text-muted" id="petunjukSo">
                                Nomor yang terulang biasanya berarti pesanan ini belum masuk BC.
                            </small>

                            {{-- Diisi oleh pemeriksaan nomor SO sambil diketik.
                                 Tanpa ini, satu-satunya cara tahu nomornya
                                 bentrok adalah menekan Terima lalu ditolak —
                                 dan pada pesanan bermetode dokumen itu berarti
                                 seluruh tempelan dari BC harus diulang. --}}
                            <div id="kotakSo" class="mt-2 d-none"></div>

                            {{-- Terkirim HANYA bila Logistik mencentang
                                 penggabungan. Keduanya diisi JavaScript dari
                                 hasil pemeriksaan, bukan diketik manusia. --}}
                            <input type="hidden" name="gabung_invoice" id="gabungInvoice" value="0">
                            <input type="hidden" name="merge_with_order_id" id="mergeWithOrderId" value="">
                        </div>
                        <div class="col-12 col-md-7">
                            <label for="catatan" class="form-label fw-semibold">Catatan penerimaan</label>
                            <textarea name="approval_note" id="catatan" rows="2" maxlength="1000"
                                      class="form-control @error('approval_note') is-invalid @enderror"
                                      placeholder="mis. 10 unit sudah di gudang tapi belum di-putaway">{{ old('approval_note') }}</textarea>
                            @error('approval_note')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="d-flex justify-content-between gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-danger rounded-3"
                                data-bs-toggle="modal" data-bs-target="#modalTolak">
                            <i class="bi bi-x-circle me-1"></i> Tolak Pesanan
                        </button>
                        <button type="submit" class="btn btn-success rounded-3 px-4" id="btnTerima">
                            <i class="bi bi-check-circle me-1"></i> Terima Pesanan
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('modals')
<div class="modal fade" id="modalTolak" tabindex="-1" aria-labelledby="judulTolak" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.approval.reject', $order) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold" id="judulTolak">Tolak Pesanan {{ $order->order_number }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Alasan ini dibaca Sales di layar pesanannya, jadi tulis yang jelas.
                    Nomor SO tidak diperlukan — pesanan yang ditolak memang tidak masuk sistem BC.
                </p>
                <label for="alasanTolak" class="form-label fw-semibold">
                    Alasan penolakan <span class="text-danger">*</span>
                </label>
                <textarea name="rejection_reason" id="alasanTolak" rows="3" required minlength="10" maxlength="1000"
                          class="form-control" placeholder="mis. Customer masih menunggak dan diminta pelunasan lebih dulu."></textarea>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger rounded-3">
                    <i class="bi bi-x-circle me-1"></i> Tolak Pesanan
                </button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
@include('wms.outbound._approval-detail-skrip')
@endpush
