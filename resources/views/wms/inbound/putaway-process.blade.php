@extends('layouts.wms')

@section('title', 'Proses Put-away')
@section('page_title', 'Proses Put-away')

@section('content')
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Ada isian yang perlu diperbaiki:</strong>
    <ul class="mb-0 mt-2 small">
        @foreach($errors->all() as $pesan)
            <li>{{ $pesan }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <a href="{{ route('wms.inbound.putaway') }}" class="text-muted text-decoration-none me-2"><i class="bi bi-arrow-left"></i></a>
                        Put-away: <span class="text-primary font-monospace">{{ $header->document_number }}</span>
                    </h5>
                    <p class="text-muted small mt-1 ms-4 mb-0">Tentukan lokasi rak untuk masing-masing palet di bawah ini.</p>
                </div>
                <div class="text-end">
                    <span class="d-block text-muted small">Gudang: <strong class="text-dark">{{ $header->warehouse?->display_label ?? '—' }}</strong></span>
                    <span class="d-block text-muted small">Tgl Produksi: <strong class="text-dark">{{ $header->production_date->translatedFormat('d M Y') }}</strong></span>
                    <span class="d-block text-muted small">Kemajuan: <strong class="text-dark" id="progressLabel">{{ $totals['ditempatkan'] }} / {{ $totals['palet'] }} palet</strong></span>
                </div>
            </div>

            <div class="card-body p-4">

                <div class="alert alert-info border-0 bg-info-subtle text-info-emphasis d-flex align-items-start rounded-3 p-3 mb-4" role="alert">
                    <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                    <div class="small">
                        Palet yang belum sempat ditempatkan boleh dikosongkan — yang sudah terisi tetap tersimpan dan dokumen ini
                        tetap ada di daftar put-away sampai seluruh paletnya punya lokasi.
                        <strong>Qty Aktual</strong> boleh dikoreksi sesuai hitungan fisik; SKU dan batch tidak dapat diubah di sini.
                    </div>
                </div>

                <form method="POST" action="{{ route('wms.inbound.putaway.store', $header->document_number) }}" id="putawayForm">
                    @csrf

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="putawayTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-secondary small fw-semibold text-center text-nowrap">PALET</th>
                                    <th class="text-secondary small fw-semibold" style="min-width: 230px;">SKU / DESKRIPSI</th>
                                    <th class="text-secondary small fw-semibold text-nowrap">BATCH</th>
                                    <th class="text-secondary small fw-semibold text-center text-nowrap">QTY SISTEM</th>
                                    <th class="text-secondary small fw-semibold text-center" style="width: 130px;">QTY AKTUAL</th>
                                    <th class="text-secondary small fw-semibold" style="min-width: 260px;">LOKASI RAK</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $nomorProduksiSebelumnya = null; @endphp
                                @foreach($details as $detail)
                                    @php
                                        $awalKelompok = $detail->production_order_no !== $nomorProduksiSebelumnya;
                                        $nomorProduksiSebelumnya = $detail->production_order_no;
                                        $kodeLama = old("pallets.{$detail->id}.location_code", $detail->location?->code);
                                        $qtyLama = old("pallets.{$detail->id}.qty_actual", $detail->qty_actual ?? $detail->pallet_qty);
                                    @endphp
                                    <tr class="{{ $awalKelompok && ! $loop->first ? 'border-top border-2' : '' }}">
                                        <td class="text-center text-nowrap">
                                            <span class="fw-bold">#{{ $detail->pallet_no }}</span>
                                            @if($detail->location_id)
                                                <i class="bi bi-check-circle-fill text-success ms-1" title="Sudah ditempatkan"></i>
                                            @endif
                                            <small class="d-block text-muted font-monospace" style="font-size: 0.7rem;">
                                                {{ $awalKelompok ? $detail->production_order_no : '' }}
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace mb-1">{{ $detail->product?->sku ?? '—' }}</span><br>
                                            <small class="text-muted">{{ $detail->product?->name ?? '—' }}</small>
                                        </td>
                                        <td><small class="font-monospace text-muted">{{ $detail->batch_no }}</small></td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border px-2 py-1 qty-sistem"
                                                  data-qty="{{ $detail->pallet_qty }}" title="Dari dokumen produksi">
                                                {{ number_format($detail->pallet_qty) }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <input type="number"
                                                   name="pallets[{{ $detail->id }}][qty_actual]"
                                                   value="{{ $qtyLama }}" min="0"
                                                   class="form-control form-control-sm text-center fw-bold qty-aktual @error("pallets.{$detail->id}.qty_actual") is-invalid @enderror">
                                        </td>
                                        <td>
                                            <input type="text"
                                                   name="pallets[{{ $detail->id }}][location_code]"
                                                   value="{{ $kodeLama }}"
                                                   list="daftarLokasi"
                                                   placeholder="Ketik / pindai kode rak, mis. B-01-01"
                                                   autocomplete="off"
                                                   class="form-control text-uppercase font-monospace lokasi-input @error("pallets.{$detail->id}.location_code") is-invalid @enderror">
                                            <small class="text-muted lokasi-info d-block mt-1" style="min-height: 1rem;"></small>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{--
                        Satu datalist dipakai bersama seluruh baris. Menyalinnya
                        per baris akan menggandakan ribuan bin sebanyak jumlah
                        palet dan membuat halaman berat tanpa manfaat.
                    --}}
                    <datalist id="daftarLokasi">
                        @foreach($locations as $lokasi)
                            <option value="{{ $lokasi->code }}">{{ $lokasi->zone }}</option>
                        @endforeach
                    </datalist>

                    <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                        <a href="{{ route('wms.inbound.putaway') }}" class="btn btn-outline-secondary px-4">Batal</a>
                        <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm">
                            <i class="bi bi-check-circle me-1"></i> Simpan Put-away
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // SATU BIN = SATU PALET. Bin yang terisi sudah disingkirkan dari daftar
    // rekomendasi ({{ $locations->count() }} bin kosong ditawarkan di sini);
    // isian ini dipakai untuk menjelaskan KENAPA suatu kode ditolak bila
    // Operator tetap mengetiknya manual.
    const ISI_BIN = @json((object) $occupancy);

    document.addEventListener('DOMContentLoaded', function () {
        const tabel = document.getElementById('putawayTable');

        function perbaruiKemajuan() {
            const isian = tabel.querySelectorAll('.lokasi-input');
            let terisi = 0;
            isian.forEach(i => { if (i.value.trim() !== '') terisi++; });
            const label = document.getElementById('progressLabel');
            label.textContent = terisi + ' / ' + isian.length + ' palet';
        }

        // Delegasi: satu listener untuk seluruh baris, berapa pun jumlah palet.
        tabel.addEventListener('input', function (e) {
            if (e.target.classList.contains('lokasi-input')) {
                const kode = e.target.value.trim().toUpperCase();
                const info = e.target.closest('td').querySelector('.lokasi-info');
                const jumlah = ISI_BIN[kode];

                if (kode === '') {
                    info.textContent = '';
                } else if (jumlah !== undefined) {
                    info.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>Rak ini sudah terisi ' + jumlah + ' pcs — tidak tersedia.';
                    info.className = 'text-danger lokasi-info d-block mt-1 small';
                } else {
                    info.innerHTML = '<i class="bi bi-check-circle me-1"></i>Kosong.';
                    info.className = 'text-success lokasi-info d-block mt-1 small';
                }

                perbaruiKemajuan();
            }

            if (e.target.classList.contains('qty-aktual')) {
                const baris = e.target.closest('tr');
                const sistem = parseInt(baris.querySelector('.qty-sistem').dataset.qty, 10);
                const aktual = parseInt(e.target.value, 10);
                // Selisih ditandai saat diketik agar salah ketik ketahuan di
                // tempat, bukan baru muncul sebagai temuan saat verifikasi.
                e.target.classList.toggle('border-danger', !isNaN(aktual) && aktual !== sistem);
                e.target.classList.toggle('text-danger', !isNaN(aktual) && aktual !== sistem);
            }
        });

        document.getElementById('putawayForm').addEventListener('submit', function (e) {
            const baris = Array.from(tabel.querySelectorAll('tbody tr'));
            const terisi = baris.filter(r => r.querySelector('.lokasi-input').value.trim() !== '');

            if (terisi.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Belum ada lokasi',
                    text: 'Isi minimal satu lokasi rak sebelum menyimpan.',
                    confirmButtonText: 'Mengerti'
                });
                return;
            }

            const selisih = terisi.filter(r => {
                const sistem = parseInt(r.querySelector('.qty-sistem').dataset.qty, 10);
                const aktual = parseInt(r.querySelector('.qty-aktual').value, 10);
                return !isNaN(aktual) && aktual !== sistem;
            });

            if (this.dataset.dikonfirmasi === '1') {
                return;
            }

            e.preventDefault();

            let pesan = terisi.length + ' dari ' + baris.length + ' palet akan disimpan.';
            if (selisih.length > 0) {
                pesan += ' ' + selisih.length + ' palet punya selisih antara qty sistem dan qty fisik — selisih ini akan diperiksa saat verifikasi Logistik.';
            }
            if (terisi.length < baris.length) {
                pesan += ' Sisanya bisa dilanjutkan nanti.';
            }

            Swal.fire({
                icon: selisih.length > 0 ? 'warning' : 'question',
                title: 'Simpan put-away?',
                text: pesan,
                showCancelButton: true,
                cancelButtonText: 'Periksa Lagi',
                confirmButtonText: 'Ya, Simpan',
                confirmButtonColor: '#198754'
            }).then(hasil => {
                if (hasil.isConfirmed) {
                    this.dataset.dikonfirmasi = '1';
                    this.submit();
                }
            });
        });

        // Menandai bin yang sudah terisi pada nilai yang dimuat dari server.
        tabel.querySelectorAll('.lokasi-input').forEach(i => {
            i.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
</script>
@endpush
