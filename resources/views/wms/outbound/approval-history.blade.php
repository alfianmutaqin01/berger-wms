@extends('layouts.wms')

@section('title', 'Riwayat Penerimaan Pesanan')
@section('page_title', 'Riwayat Penerimaan Pesanan')

@section('content')
{{-- Permintaan pemilik produk: seluruh penerimaan, penolakan, DAN pembatalan
     tercatat di satu halaman, supaya keputusan Logistik bisa ditelusuri
     belakangan.

     Pembatalan ditambahkan setelah temuan lapangan: pesanan yang sudah
     diterima masih bisa batal (customer membatalkan, atau BC tidak
     menyetujui), dan tanpa jalan keluar di sini nomor SO-nya terkunci
     selamanya sehingga pesanan berikutnya ditolak dengan alasan yang keliru. --}}
<a href="{{ route('wms.approval.index') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke antrean
</a>

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-clock-history text-primary me-2"></i> Riwayat Keputusan
        </h5>
        <small class="text-muted">Terbaru di atas.</small>
    </div>

    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-7">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                           placeholder="Cari nomor PO, nomor SO, atau customer...">
                </div>
            </div>
            <div class="col-8 col-md-3">
                <select name="hasil" class="form-select">
                    <option value="">Semua keputusan</option>
                    <option value="diterima" @selected($filters['hasil'] === 'diterima')>Diterima</option>
                    <option value="ditolak" @selected($filters['hasil'] === 'ditolak')>Ditolak</option>
                    <option value="dibatalkan" @selected($filters['hasil'] === 'dibatalkan')>Dibatalkan</option>
                </select>
            </div>
            <div class="col-4 col-md-2 d-grid">
                <button class="btn btn-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PO</th>
                        <th>No. SO (BC)</th>
                        <th>Customer</th>
                        <th>Gudang</th>
                        <th>Keputusan</th>
                        <th>Oleh</th>
                        <th>Waktu</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($orders as $order)
                    @php
                        // Urutannya menentukan. Pesanan yang dibatalkan MEMANG
                        // pernah diterima, jadi pembatalan diperiksa lebih dulu
                        // — kalau tidak, hasil akhirnya terbaca "Diterima"
                        // padahal sudah tidak berlaku.
                        $dibatalkan = $order->cancelled_at !== null;
                        $ditolak = ! $dibatalkan && $order->rejected_at !== null;

                        // Boleh dibatalkan selama barangnya belum berangkat.
                        // Sesudah itu urusannya Retur, bukan pembatalan.
                        $bolehDibatalkan = in_array($order->status, [
                            \App\Models\SalesOrder::STATUS_APPROVED,
                            \App\Models\SalesOrder::STATUS_PICKING,
                            \App\Models\SalesOrder::STATUS_READY_TO_SHIP,
                        ], true);
                    @endphp
                    <tr>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $order->order_number }}</span>
                            @if($order->customer_po_number)
                                <div class="small text-muted">PO customer: {{ $order->customer_po_number }}</div>
                            @endif
                            @if($order->so_merged_into_id)
                                <div class="small">
                                    <span class="badge bg-info-subtle text-info-emphasis">Gabung invoice</span>
                                </div>
                            @endif
                        </td>
                        <td class="font-monospace">{{ $order->bc_so_number ?? '—' }}</td>
                        <td>
                            <div class="fw-semibold">{{ $order->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $order->customer?->code }}</small>
                        </td>
                        <td>{{ $order->warehouse?->name ?? '—' }}</td>
                        <td>
                            @if($dibatalkan)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Dibatalkan</span>
                                <div class="small text-muted mt-1">
                                    {{ \App\Models\SalesOrderCancellation::SOURCE_LABELS[$order->cancellation_source] ?? '' }}
                                </div>
                                <div class="small text-muted">{{ $order->cancellation_reason }}</div>
                                <div class="small text-muted fst-italic">Kembali ke antrean.</div>
                            @elseif($ditolak)
                                <span class="badge bg-danger-subtle text-danger-emphasis">Ditolak</span>
                                @if($order->rejection_reason)
                                    <div class="small text-muted mt-1">{{ $order->rejection_reason }}</div>
                                @endif
                            @else
                                <span class="badge bg-success-subtle text-success-emphasis">Diterima</span>
                                <div class="small text-muted mt-1">{{ $order->details_count }} item</div>
                                @if($order->approval_note)
                                    <div class="small text-muted">{{ $order->approval_note }}</div>
                                @endif
                            @endif
                        </td>
                        <td>
                            @if($dibatalkan)
                                {{ $order->cancelledBy?->full_name ?? '—' }}
                            @elseif($ditolak)
                                {{ $order->rejectedBy?->full_name ?? '—' }}
                            @else
                                {{ $order->approvedBy?->full_name ?? '—' }}
                            @endif
                        </td>
                        <td>
                            @php($waktu = $dibatalkan ? $order->cancelled_at : ($ditolak ? $order->rejected_at : $order->approved_at))
                            <div>{{ $waktu?->format('d M Y') ?? '—' }}</div>
                            <small class="text-muted">{{ $waktu?->format('H:i') }}</small>
                        </td>
                        <td class="text-end">
                            {{-- PINTU KECIL untuk salah ketik nomor SO. Hanya
                                 selama pesanan belum berangkat: sesudah itu
                                 koreksinya lewat tombol Pasangkan di Surat
                                 Jalan, supaya nomornya disalin dari dokumen BC
                                 dan bukan diketik ulang. --}}
                            @if($bolehDibatalkan && ! $dibatalkan && ! $ditolak && $order->so_merged_into_id === null)
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 mb-1 tombol-koreksi-so"
                                        data-bs-toggle="modal" data-bs-target="#modalKoreksiSo"
                                        data-aksi="{{ route('wms.approval.so-number', $order) }}"
                                        data-nomor="{{ $order->order_number }}"
                                        data-so="{{ $order->bc_so_number }}">
                                    <i class="bi bi-pencil me-1"></i> No. SO
                                </button>
                            @endif
                            @if($bolehDibatalkan)
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-3 tombol-batal"
                                        data-bs-toggle="modal" data-bs-target="#modalBatal"
                                        data-aksi="{{ route('wms.approval.cancel', $order) }}"
                                        data-nomor="{{ $order->order_number }}"
                                        data-so="{{ $order->bc_so_number }}"
                                        data-customer="{{ $order->customer?->name }}">
                                    <i class="bi bi-x-circle me-1"></i> Batalkan
                                </button>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-clock-history display-6 d-block mb-2 opacity-50"></i>
                            Belum ada pesanan yang diterima, ditolak, maupun dibatalkan.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $orders->links() }}</div>
    </div>
</div>

{{-- Satu modal dipakai bersama seluruh baris; isinya diisi dari data-* baris
     yang tombolnya ditekan. Satu modal per baris berarti puluhan salinan
     markup yang sama di satu halaman. --}}
<div class="modal fade" id="modalBatal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formBatal" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Batalkan Pesanan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning border-0 rounded-3 small">
                    <div class="fw-semibold mb-1" id="batalNomor"></div>
                    <div id="batalCustomer" class="mb-2"></div>
                    <div id="batalSo"></div>
                </div>

                <p class="text-muted small">
                    Stok yang sudah dicadangkan akan <strong>dilepas kembali</strong>, dan pesanan ini
                    <strong>kembali ke antrean</strong> — bisa diterima lagi dengan nomor SO baru bila
                    sudah diperbaiki, atau ditolak bila memang final.
                </p>

                <label class="form-label small fw-semibold">Sumber pembatalan <span class="text-danger">*</span></label>
                <select name="cancellation_source" class="form-select mb-3" required>
                    <option value="">— Pilih sumber —</option>
                    @foreach(\App\Models\SalesOrderCancellation::SOURCE_LABELS as $slug => $label)
                        <option value="{{ $slug }}">{{ $label }}</option>
                    @endforeach
                </select>

                <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                <textarea name="cancellation_reason" class="form-control" rows="3" minlength="10" maxlength="1000" required
                          placeholder="Minimal 10 karakter, mis. BC menolak karena limit kredit customer terlampaui"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-danger rounded-3">Batalkan Pesanan</button>
            </div>
        </form>
    </div>
</div>

{{-- Koreksi nomor SO yang salah ketik (Fase 6 tahap 5). --}}
<div class="modal fade" id="modalKoreksiSo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formKoreksiSo" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Koreksi Nomor SO</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-secondary border-0 rounded-3 small">
                    <div class="fw-semibold" id="koreksiNomor"></div>
                    <div id="koreksiSoLama"></div>
                </div>

                <p class="text-muted small">
                    Dipakai bila nomor SO salah ketik dan <strong>Surat Jalan-nya belum terbit</strong>.
                    Kalau Surat Jalan sudah masuk dari BC, jangan mengetik ulang di sini — buka Surat Jalan
                    itu dan tekan <strong>Pasangkan</strong>, supaya nomornya disalin langsung dari dokumennya.
                </p>

                <label class="form-label small fw-semibold">Nomor SO yang benar <span class="text-danger">*</span></label>
                <input type="text" name="bc_so_number" class="form-control font-monospace mb-3"
                       maxlength="50" required placeholder="mis. SO260903">

                <label class="form-label small fw-semibold">Alasan koreksi</label>
                <textarea name="reason" class="form-control" rows="2" maxlength="1000"
                          placeholder="Opsional, mis. salah ketik satu digit saat menerima pesanan"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-primary rounded-3">Simpan Nomor Baru</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const formKoreksi = document.getElementById('formKoreksiSo');

    document.querySelectorAll('.tombol-koreksi-so').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            formKoreksi.action = tombol.dataset.aksi;
            document.getElementById('koreksiNomor').textContent = 'Pesanan ' + tombol.dataset.nomor;
            document.getElementById('koreksiSoLama').textContent = tombol.dataset.so
                ? 'Nomor SO sekarang: ' + tombol.dataset.so
                : 'Pesanan ini belum punya nomor SO.';
            formKoreksi.querySelector('[name="bc_so_number"]').value = tombol.dataset.so || '';
        });
    });

    const form = document.getElementById('formBatal');

    document.querySelectorAll('.tombol-batal').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            form.action = tombol.dataset.aksi;
            document.getElementById('batalNomor').textContent = 'Pesanan ' + tombol.dataset.nomor;
            document.getElementById('batalCustomer').textContent = tombol.dataset.customer || '';
            document.getElementById('batalSo').textContent = tombol.dataset.so
                ? 'Nomor SO ' + tombol.dataset.so + ' akan kembali bisa dipakai pesanan lain.'
                : 'Pesanan ini belum punya nomor SO.';
        });
    });
});
</script>
@endsection
