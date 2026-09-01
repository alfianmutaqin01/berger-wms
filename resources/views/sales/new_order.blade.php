@extends('layouts.soms')

@section('title', $order ? 'Ubah Draft Pesanan' : 'Buat Pesanan Baru')
@section('page_title', $order ? 'Ubah Draft Pesanan' : 'Buat Pesanan Baru')

@php
    // Nilai awal: isian lama (setelah validasi gagal), lalu draft yang
    // sedang diubah, baru nilai kosong. Urutan ini yang membuat isian Sales
    // tidak hilang saat ada satu kolom yang salah.
    $vSource = old('order_source', $order?->order_source ?? \App\Models\SalesOrder::SOURCE_MANUAL);
    $vWarehouse = old('warehouse_id', $order?->warehouse_id);
    $itemLama = old('items', $order
        ? $order->details->map(fn ($d) => ['product_id' => $d->product_id, 'qty' => $d->qty_ordered])->values()->all()
        : []);
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-10">

        @if(session('error'))
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
        </div>
        @endif

        @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Ada isian yang perlu diperbaiki:</strong>
            <ul class="mb-0 mt-2 small">
                @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
            </ul>
        </div>
        @endif

        {{-- Peringatan cutoff. Teks dan tombol WAJIB sejalan (docs/4 §3.3.2):
             yang dikunci hanya Submit, Simpan Draft tetap hidup. --}}
        @unless($cutoffOpen)
        <div class="alert alert-warning border-0 shadow-sm rounded-3 d-flex align-items-start">
            <i class="bi bi-clock-history fs-4 me-3"></i>
            <div>
                <strong class="d-block">Batas waktu pemesanan hari ini sudah lewat.</strong>
                <span class="small">Order ditutup pukul {{ $cutoffLabel }}. Pesanan masih bisa <strong>disimpan sebagai draft</strong> dan dikirim besok.</span>
            </div>
        </div>
        @endunless

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-cart-plus text-primary me-2"></i>
                    {{ $order ? 'Ubah Draft '.$order->order_number : 'Form Pesanan Baru' }}
                </h5>
                <p class="text-muted small mt-1 mb-0">Nomor PO dibuat otomatis oleh sistem saat pesanan disimpan.</p>
            </div>

            <form method="POST" enctype="multipart/form-data"
                  action="{{ $order ? url('/sales/orders/'.$order->id) : url('/sales/new-order') }}">
                @csrf
                @if($order) @method('PUT') @endif

                <div class="card-body p-4">
                    <!-- ============ Data pesanan ============ -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Customer <span class="text-danger">*</span></label>
                            {{-- Kolom pencarian, BUKAN dropdown berisi seluruh
                                 pelanggan: jumlahnya ribuan dan tidak mungkin
                                 digulir di layar HP. --}}
                            <div class="cari-wadah position-relative" id="cariCustomer">
                                <input type="text" class="form-control cari-teks" autocomplete="off"
                                       placeholder="Ketik nama atau kode customer..."
                                       value="{{ $customerTerpilih ? $customerTerpilih['code'].' — '.$customerTerpilih['name'] : '' }}">
                                <input type="hidden" name="customer_id" class="cari-nilai"
                                       value="{{ $customerTerpilih['id'] ?? '' }}" required>
                                <div class="cari-saran list-group position-absolute w-100 shadow d-none"
                                     style="z-index: 1050; max-height: 260px; overflow-y: auto;"></div>
                            </div>
                            <small class="text-muted">Ketik minimal 2 huruf untuk mencari.</small>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Gudang Tujuan <span class="text-danger">*</span></label>
                            <select name="warehouse_id" id="warehouseSelect" class="form-select" required>
                                <option value="">— Pilih gudang —</option>
                                @foreach($warehouses as $w)
                                    <option value="{{ $w->id }}" @selected($vWarehouse == $w->id)>{{ $w->code }} — {{ $w->name }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Indikator ketersediaan mengikuti gudang ini.</small>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Syarat Pembayaran <span class="text-danger">*</span></label>
                            <select name="payment_term_id" class="form-select" required>
                                <option value="">— Pilih syarat pembayaran —</option>
                                @foreach($paymentTerms as $t)
                                    <option value="{{ $t->id }}" @selected(old('payment_term_id', $order?->payment_term_id) == $t->id)>{{ $t->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- ============ Metode pemesanan ============ -->
                    <div class="border rounded-4 p-3 mb-4 bg-light">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="pakaiDokumen" name="order_source"
                                   value="{{ \App\Models\SalesOrder::SOURCE_DOCUMENT }}"
                                   @checked($vSource === \App\Models\SalesOrder::SOURCE_DOCUMENT)>
                            <label class="form-check-label fw-semibold" for="pakaiDokumen">
                                Pesanan sesuai dengan dokumen yang diupload.
                                <span class="text-muted fw-normal">(Abaikan input rincian item di bawah)</span>
                            </label>
                        </div>
                        {{-- Checkbox yang tidak dicentang tidak terkirim sama sekali,
                             jadi nilai 'manual' dititipkan di input tersembunyi ini. --}}
                        <input type="hidden" name="order_source" value="{{ \App\Models\SalesOrder::SOURCE_MANUAL }}" id="sourceManual">

                        <div id="blokDokumen" class="mt-3 {{ $vSource === \App\Models\SalesOrder::SOURCE_DOCUMENT ? '' : 'd-none' }}">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold">Nomor PO Customer <span class="text-danger">*</span></label>
                                    <input type="text" name="customer_po_number" class="form-control font-monospace"
                                           value="{{ old('customer_po_number', $order?->customer_po_number) }}"
                                           placeholder="Nomor PO dari customer">
                                    <small class="text-muted">Sistem tetap memberi nomor internal tersendiri.</small>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold">Dokumen PO <span class="text-danger">*</span></label>
                                    <input type="file" name="document" class="form-control"
                                           accept=".{{ implode(',.', $dokumenConfig['mimes']) }}">
                                    <small class="text-muted">
                                        {{ strtoupper(implode(', ', $dokumenConfig['mimes'])) }} · maks {{ round($dokumenConfig['max_kb'] / 1024) }} MB
                                    </small>
                                    @if($order?->document_name)
                                        <div class="mt-2 small">
                                            <i class="bi bi-paperclip me-1"></i>Terlampir:
                                            <a href="{{ url('/sales/orders/'.$order->id.'/document') }}">{{ $order->document_name }}</a>
                                            <span class="text-muted">— biarkan kosong bila tidak diganti.</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="alert alert-info border-0 small mt-3 mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Rincian item akan diisi tim Logistik berdasarkan dokumen ini saat pesanan diterima.
                            </div>
                        </div>
                    </div>

                    <!-- ============ Item pesanan ============ -->
                    <div id="blokItem" class="{{ $vSource === \App\Models\SalesOrder::SOURCE_DOCUMENT ? 'd-none' : '' }}">
                        <h6 class="fw-bold text-dark mb-1">Item Pesanan</h6>
                        <p class="text-muted small">Ketersediaan ditampilkan sebagai indikator, bukan angka.</p>

                        <div id="daftarItem"></div>

                        <button type="button" class="btn btn-outline-primary w-100 mt-2" id="tambahItem">
                            <i class="bi bi-plus-lg me-1"></i> Tambah Produk
                        </button>
                    </div>

                    <div class="mt-4">
                        <label class="form-label small fw-semibold">Catatan (opsional)</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="1000">{{ old('notes', $order?->notes) }}</textarea>
                    </div>
                </div>

                <div class="card-footer bg-white border-top-0 p-4 d-flex flex-column flex-md-row gap-2">
                    <button type="submit" name="action" value="draft" class="btn btn-outline-secondary flex-grow-1">
                        <i class="bi bi-save me-1"></i> Simpan Draft
                    </button>
                    <button type="submit" name="action" value="submit" class="btn btn-primary flex-grow-1 fw-bold"
                            @disabled(! $cutoffOpen)>
                        <i class="bi bi-send me-1"></i> Submit Order
                    </button>
                </div>
            </form>
        </div>

        <p class="text-center text-muted small mt-3">
            <i class="bi bi-clock me-1"></i>Order ditutup pukul {{ $cutoffLabel }}
        </p>
    </div>
</div>

{{-- Cetakan satu baris item. Disimpan di <template> agar tidak ikut terkirim
     sebagai isian kosong saat form disubmit. --}}
<template id="templateItem">
    <div class="card border rounded-3 mb-2 baris-item">
        <div class="card-body p-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold mb-1">Produk</label>
                    {{-- Sama seperti customer: dicari, bukan digulir. Produk
                         berjumlah ribuan; mengetik "APKO" harus memunculkan
                         APKO saja. --}}
                    <div class="cari-wadah position-relative cari-produk">
                        <input type="text" class="form-control form-control-sm cari-teks" autocomplete="off"
                               placeholder="Ketik SKU atau nama produk...">
                        <input type="hidden" class="cari-nilai pilih-produk" required>
                        <div class="cari-saran list-group position-absolute w-100 shadow d-none"
                             style="z-index: 1050; max-height: 260px; overflow-y: auto;"></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Qty</label>
                    <input type="number" class="form-control form-control-sm isi-qty" min="1" value="1" required>
                </div>
                <div class="col-6 col-md-3 d-flex align-items-center gap-2">
                    <span class="badge bg-secondary badge-indikator flex-grow-1 text-center">—</span>
                    <button type="button" class="btn btn-sm btn-outline-danger hapus-item" title="Hapus item">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
@endsection


@push('scripts')
@include('sales._pencarian')
@endpush
