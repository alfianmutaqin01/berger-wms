@extends('layouts.wms')

@section('title', 'Terima Transfer '.$transfer->transfer_number)
@section('page_title', 'Terima Transfer '.$transfer->transfer_number)

@section('content')
{{-- PRD F-INV-05, langkah terima.

     DUA HAL DIISI SEKALIGUS di layar ini: qty yang benar-benar sampai, dan
     rak tempat barangnya diletakkan. Keputusan pemilik produk — satu layar,
     satu kali kerja, dan stoknya langsung siap dijual begitu disimpan.

     KODE RAK DI SINI ADALAH KODE RAK GUDANG INI, bukan gudang asal.
     Penomoran rak tiap gudang berbeda, dan itulah kekeliruan yang paling
     mudah terjadi di layar ini. --}}
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="small text-muted">Nomor Transfer</div>
                <div class="fw-bold font-monospace">{{ $transfer->transfer_number }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Dari Gudang</div>
                <div class="fw-semibold">{{ $transfer->fromWarehouse?->name }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Dikirim</div>
                <div class="fw-semibold">{{ $transfer->shipped_at?->format('d M Y H:i') }}</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="small text-muted">Catatan Pengirim</div>
                <div>{{ $transfer->notes ?: '—' }}</div>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('wms.transfers.receive', $transfer) }}">
    @csrf

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
            <h5 class="fw-bold text-dark mb-0">
                <i class="bi bi-box-arrow-in-down text-success me-2"></i> Rincian Kiriman
            </h5>
            <small class="text-muted">
                Isi qty yang <strong>benar-benar sampai</strong> dan rak di gudang {{ $transfer->toWarehouse?->name }}.
                Kalau ada yang kurang, alasannya wajib diisi.
            </small>
        </div>

        <div class="card-body px-4 pt-3">
            <div class="table-responsive">
                <table class="table align-middle mb-0" id="tabelTerima">
                    <thead class="table-light">
                        <tr>
                            <th>SKU / Produk</th>
                            <th>Batch</th>
                            <th class="text-end">Dikirim</th>
                            <th style="width:8rem" class="text-end">Diterima</th>
                            <th style="width:11rem">Rak Tujuan</th>
                            <th style="width:16rem">Alasan bila kurang</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($transfer->details as $d)
                        <tr data-dikirim="{{ $d->qty_shipped }}">
                            <td>
                                <div class="font-monospace small text-muted">{{ $d->product?->sku }}</div>
                                <div>{{ $d->product?->name }}</div>
                                @if($d->status === \App\Models\InventoryStock::STATUS_DDP)
                                    <span class="badge bg-danger-subtle text-danger-emphasis">DDP — tetap DDP setelah pindah</span>
                                @endif
                            </td>
                            <td>
                                <div class="font-monospace">{{ $d->batch_no }}</div>
                                {{-- Tanggal produksi ikut apa adanya dari gudang
                                     asal; umur barang tidak lahir kembali karena
                                     berpindah gudang. --}}
                                <div class="small text-muted">
                                    Prod {{ $d->production_date?->format('d M Y') }} &middot;
                                    Exp {{ $d->expiry_date?->format('d M Y') }}
                                </div>
                            </td>
                            <td class="text-end fw-semibold">{{ number_format($d->qty_shipped) }} {{ $d->product?->uom }}</td>
                            <td class="text-end">
                                <input type="number" name="baris[{{ $d->id }}][qty]" class="form-control form-control-sm text-end qty-terima"
                                       min="0" max="{{ $d->qty_shipped }}" value="{{ old('baris.'.$d->id.'.qty', $d->qty_shipped) }}" required>
                            </td>
                            <td>
                                <input type="text" name="baris[{{ $d->id }}][location_code]" class="form-control form-control-sm font-monospace"
                                       list="daftarRak" maxlength="20" placeholder="mis. A-01-01"
                                       value="{{ old('baris.'.$d->id.'.location_code') }}">
                            </td>
                            <td>
                                <input type="text" name="baris[{{ $d->id }}][reason]" class="form-control form-control-sm alasan"
                                       maxlength="500" placeholder="Wajib bila kurang"
                                       value="{{ old('baris.'.$d->id.'.reason') }}">
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <datalist id="daftarRak">
                @foreach($rak as $r)
                    <option value="{{ $r->code }}">{{ $r->zone }}</option>
                @endforeach
            </datalist>
        </div>

        <div class="card-footer bg-white border-top px-4 py-3 d-flex justify-content-between align-items-center">
            <div class="small text-muted" id="ringkasSelisih"></div>
            <div class="d-flex gap-2">
                <a href="{{ route('wms.transfers.show', $transfer) }}" class="btn btn-outline-secondary rounded-3">Batal</a>
                <button type="submit" class="btn btn-success rounded-3">
                    <i class="bi bi-check2-circle me-1"></i> Terima Kiriman
                </button>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabel = document.getElementById('tabelTerima');
    const ringkas = document.getElementById('ringkasSelisih');

    function perbarui() {
        let kurang = 0;

        tabel.querySelectorAll('tbody tr').forEach(function (baris) {
            const dikirim = parseInt(baris.dataset.dikirim, 10);
            const diterima = parseInt(baris.querySelector('.qty-terima').value || '0', 10);
            const alasan = baris.querySelector('.alasan');
            const selisih = dikirim - diterima;

            // Kolom alasan ditandai merah saat ada selisih. Server tetap
            // menolaknya kalau kosong — ini hanya supaya ketahuan lebih
            // dulu, bukan setelah tombol simpan ditekan.
            alasan.classList.toggle('is-invalid', selisih > 0 && alasan.value.trim() === '');

            if (selisih > 0) kurang += selisih;
        });

        ringkas.innerHTML = kurang > 0
            ? '<span class="text-danger fw-semibold">' + kurang + ' unit tidak sampai.</span> Alasannya wajib diisi.'
            : 'Seluruh kiriman sampai lengkap.';
    }

    tabel.addEventListener('input', perbarui);
    perbarui();
});
</script>
@endsection
