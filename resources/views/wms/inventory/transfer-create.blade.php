@extends('layouts.wms')

@section('title', 'Buat Transfer Antar Gudang')
@section('page_title', 'Buat Transfer Antar Gudang')

@section('content')
{{-- PRD F-INV-05, langkah kirim.

     Yang dipilih di sini adalah BATCH, bukan produk. Satu SKU bisa punya
     beberapa batch dengan umur berbeda, dan yang berangkat harus jelas yang
     mana — batch dan tanggal produksinya ikut pindah apa adanya ke gudang
     tujuan supaya FIFO di sana tidak putus.

     Daftarnya diurutkan dari yang paling dekat kedaluwarsa, karena itulah
     yang paling sering perlu dipindahkan atau ditarik kembali. --}}
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <ul class="mb-0 ps-3">
        @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
    </ul>
</div>
@endif

@if(! $gudangAsal)
    {{-- Hanya terjadi untuk akun lintas gudang (Super Admin): ia tidak
         terikat satu gudang, jadi "kirim dari mana" belum terjawab. --}}
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-1">Pilih gudang asal</h5>
            <p class="text-muted small">Akun Anda tidak terikat satu gudang, jadi gudang pengirim perlu dinyatakan lebih dulu.</p>
            <form method="GET" class="row g-2" style="max-width: 480px">
                <div class="col-8">
                    <select name="from" class="form-select" required>
                        <option value="">— Pilih gudang —</option>
                        @foreach($semuaGudang as $g)
                            <option value="{{ $g->id }}">{{ $g->display_label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 d-grid">
                    <button class="btn btn-primary">Lanjut</button>
                </div>
            </form>
        </div>
    </div>
@else
<form method="POST" action="{{ route('wms.transfers.store') }}" id="formTransfer">
    @csrf
    <input type="hidden" name="from_warehouse_id" value="{{ $gudangAsal->id }}">

    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Gudang Asal</label>
                    <div class="form-control bg-light d-flex align-items-center gap-2" style="cursor: default;">
                        <i class="bi bi-building text-muted"></i>
                        <span class="fw-semibold">{{ $gudangAsal->name }}</span>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Gudang Tujuan <span class="text-danger">*</span></label>
                    <select name="to_warehouse_id" class="form-select" required>
                        <option value="">— Pilih gudang tujuan —</option>
                        @foreach($tujuan as $g)
                            <option value="{{ $g->id }}" @selected(old('to_warehouse_id') == $g->id)>{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-semibold">Catatan</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" class="form-control"
                           maxlength="1000" placeholder="Nomor truk, ekspedisi, dll (opsional)">
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-box-seam text-primary me-2"></i> Pilih Batch yang Dikirim
                </h5>
                <small class="text-muted">Urut dari yang paling dekat kedaluwarsa. Stok kedaluwarsa tidak muncul di sini.</small>
            </div>
            <span class="badge bg-primary-subtle text-primary-emphasis fs-6" id="hitungTerpilih">0 batch</span>
        </div>

        <div class="card-body px-4 pt-3">
            <div class="mb-3" style="max-width: 420px">
                <input type="text" id="cariBatch" class="form-control" placeholder="Saring SKU, batch, atau rak...">
            </div>

            <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0" id="tabelBatch">
                    <thead class="table-light" style="position: sticky; top: 0; z-index: 2;">
                        <tr>
                            <th style="width:2.5rem"></th>
                            <th>SKU / Produk</th>
                            <th>Batch</th>
                            <th>Rak</th>
                            <th class="text-end">Tersedia</th>
                            <th style="width:9rem" class="text-end">Qty Kirim</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($batch as $i => $s)
                        <tr data-cari="{{ strtolower($s->product?->sku.' '.$s->product?->name.' '.$s->batch_no.' '.$s->location?->code) }}">
                            <td>
                                <input type="checkbox" class="form-check-input pilih-batch" data-baris="{{ $i }}">
                            </td>
                            <td>
                                <div class="font-monospace small text-muted">{{ $s->product?->sku }}</div>
                                <div>{{ $s->product?->name }}</div>
                                @if($s->status === \App\Models\InventoryStock::STATUS_DDP)
                                    <span class="badge bg-danger-subtle text-danger-emphasis">DDP — {{ \App\Models\InventoryStock::DDP_REASON_LABELS[$s->ddp_reason] ?? 'karantina' }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-monospace">{{ $s->batch_no }}</div>
                                <div class="small text-muted">Exp {{ $s->expiry_date?->format('d M Y') }}</div>
                            </td>
                            <td class="font-monospace">{{ $s->location?->code }}</td>
                            <td class="text-end fw-semibold">{{ number_format($s->qty_available) }}</td>
                            <td class="text-end">
                                {{-- name diisi JavaScript saat dicentang. Input
                                     tanpa name tidak ikut terkirim, jadi baris
                                     yang tidak dipilih tidak perlu disaring
                                     lagi di server. --}}
                                <input type="number" class="form-control form-control-sm text-end qty-kirim"
                                       min="1" max="{{ $s->qty_available }}" value="{{ $s->qty_available }}" disabled>
                                <input type="hidden" class="id-stok" value="{{ $s->id }}">
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                Tidak ada stok yang bisa dikirim dari gudang ini.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-footer bg-white border-top px-4 py-3 d-flex justify-content-end gap-2">
            <a href="{{ route('wms.transfers.index') }}" class="btn btn-outline-secondary rounded-3">Batal</a>
            <button type="submit" class="btn btn-primary rounded-3" id="tombolKirim" disabled>
                <i class="bi bi-truck me-1"></i> Kirim Stok
            </button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabel = document.getElementById('tabelBatch');
    const tombol = document.getElementById('tombolKirim');
    const hitung = document.getElementById('hitungTerpilih');
    const cari = document.getElementById('cariBatch');

    function perbarui() {
        let n = 0;

        tabel.querySelectorAll('tbody tr').forEach(function (baris) {
            const centang = baris.querySelector('.pilih-batch');
            if (!centang) return;

            const qty = baris.querySelector('.qty-kirim');
            const idStok = baris.querySelector('.id-stok');

            qty.disabled = !centang.checked;

            if (centang.checked) {
                // Nomor baris dinomori ULANG tiap perubahan. Kalau nomornya
                // mengikuti urutan baris di tabel, array item[] di server
                // jadi bolong begitu ada baris tengah yang dilepas.
                qty.name = 'item[' + n + '][qty]';
                idStok.name = 'item[' + n + '][stock_id]';
                n++;
            } else {
                qty.removeAttribute('name');
                idStok.removeAttribute('name');
            }
        });

        hitung.textContent = n + ' batch';
        tombol.disabled = n === 0;
    }

    tabel.addEventListener('change', function (e) {
        if (e.target.classList.contains('pilih-batch')) perbarui();
    });

    cari.addEventListener('input', function () {
        const kata = cari.value.trim().toLowerCase();

        tabel.querySelectorAll('tbody tr[data-cari]').forEach(function (baris) {
            // Baris yang SEDANG DICENTANG tidak pernah disembunyikan: kalau
            // hilang dari layar, pengirim mengira pilihannya batal padahal
            // tetap ikut terkirim.
            const terpilih = baris.querySelector('.pilih-batch')?.checked;
            baris.hidden = !terpilih && kata !== '' && !baris.dataset.cari.includes(kata);
        });
    });

    perbarui();
});
</script>
@endif
@endsection
