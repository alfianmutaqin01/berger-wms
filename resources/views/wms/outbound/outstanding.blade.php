@extends('layouts.wms')

@section('title', 'Riwayat Outstanding')
@section('page_title', 'Riwayat Outstanding')

@section('content')
{{-- SATU BARIS = SATU NOMOR SO. Rincian SKU-nya ada di dalam, tinggal diklik.

     Dulu tiap SKU yang kurang berdiri sebagai barisnya sendiri di daftar ini,
     sehingga satu pesanan berisi dua belas SKU memenuhi seluruh halaman dan
     pesanan lain yang juga terutang terdorong ke halaman berikutnya. Yang
     dibawa orang ke layar ini adalah "PO mana yang masih terutang".

     ANGKA DI BARIS PESANAN DIBACA HIDUP dari baris pesanannya; angka di dalam
     rincian peristiwa adalah cuplikan saat kejadiannya dan memang tidak ikut
     berubah. Baris hijau berarti kewajibannya sudah tertutup — riwayatnya
     tetap berdiri, karena riwayat yang menghilang begitu masalahnya beres
     tidak bisa dipakai menjawab "dulu kurang berapa". --}}

<div class="row g-3 mb-3">
    @php($kartu = [
        ['Baris masih kurang', $stats['baris_berjalan'], 'warning', 'list-ul'],
        ['Total unit kurang', $stats['qty_berjalan'], 'danger', 'box-seam'],
        ['Pesanan terdampak', $stats['pesanan_berjalan'], 'primary', 'receipt'],
    ])
    @foreach($kartu as [$judul, $nilai, $warna, $ikon])
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis d-flex align-items-center justify-content-center"
                         style="width:44px;height:44px">
                        <i class="bi bi-{{ $ikon }} fs-5"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-dark lh-1">{{ number_format($nilai) }}</div>
                        <small class="text-muted">{{ $judul }}</small>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0">
            <i class="bi bi-exclamation-diamond text-warning me-2"></i> Kekurangan yang Pernah Terjadi
        </h5>
        <small class="text-muted">
            Satu baris satu nomor SO — klik <strong>Detail</strong> untuk melihat SKU mana yang kurang.
            Terbaru di atas; baris hijau berarti kekurangannya sudah tertutup.
        </small>
    </div>

    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-lg-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                           placeholder="Cari nomor PO, nomor SO, customer, atau SKU...">
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <select name="keadaan" class="form-select">
                    <option value="">Semua keadaan</option>
                    <option value="berjalan" @selected($filters['keadaan'] === 'berjalan')>Masih kurang</option>
                    <option value="selesai" @selected($filters['keadaan'] === 'selesai')>Sudah terpenuhi</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <select name="sebab" class="form-select">
                    <option value="">Semua sebab</option>
                    @foreach(\App\Models\SalesOrderOutstanding::CAUSE_LABELS as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['sebab'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if($warehouses->count() > 1)
                <div class="col-6 col-lg-2">
                    <select name="warehouse" class="form-select">
                        <option value="">Semua gudang</option>
                        @foreach($warehouses as $w)
                            <option value="{{ $w->id }}" @selected($filters['warehouse'] === $w->id)>{{ $w->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-6 col-lg-2 d-grid">
                <button class="btn btn-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:44px"></th>
                        <th>No. PO / SO</th>
                        <th>Customer</th>
                        <th class="text-end">Total pesan</th>
                        <th class="text-end">Total kirim</th>
                        <th class="text-end">Masih kurang</th>
                        <th>Keadaan</th>
                        <th>Terakhir</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($kelompok as $k)
                    {{-- SENGAJA BENTUK BERKURUNG, bukan blok pembuka-penutup.
                         Berkas ini sudah memakai bentuk berkurung di awal
                         halaman, dan Blade memasangkan pembuka pertama dengan
                         penutup pertama yang ia temukan — termasuk yang cuma
                         tertulis di dalam komentar. Satu blok di sini membuat
                         seluruh tabel di antaranya tertelan jadi satu blok PHP
                         yang tidak sah, dan galatnya menunjuk ke baris yang
                         sama sekali tidak bersalah. --}}
                    @php($order = $k['order'])
                    @php($tertutup = $k['sisa'] === 0)
                    @php($panel = 'rincian-'.$order->id)
                    <tr class="{{ $tertutup ? 'table-success' : '' }}">
                        <td>
                            {{-- Tombol buka/tutup berdiri sendiri, tidak menempel
                                 di baris: baris yang seluruhnya bisa diklik akan
                                 ikut terbuka setiap kali orang menyeleksi nomor
                                 SO untuk disalin. --}}
                            <button class="btn btn-sm btn-outline-secondary rounded-3 border-0" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#{{ $panel }}"
                                    aria-expanded="false" aria-controls="{{ $panel }}"
                                    title="Lihat SKU yang kurang">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </td>
                        <td>
                            <span class="fw-semibold font-monospace">{{ $order->order_number }}</span>
                            @if($order->customer_po_number)
                                <div class="small text-muted">PO customer: {{ $order->customer_po_number }}</div>
                            @endif
                            @if($order->bc_so_number)
                                <div class="small text-muted font-monospace">SO {{ $order->bc_so_number }}</div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $order->customer?->name ?? '—' }}</div>
                            <small class="text-muted">{{ $order->warehouse?->name }}</small>
                        </td>
                        <td class="text-end">{{ number_format($k['dipesan']) }}</td>
                        <td class="text-end">{{ number_format($k['terkirim']) }}</td>
                        <td class="text-end fw-bold {{ $k['sisa'] > 0 ? 'text-danger' : 'text-success' }}">
                            {{ number_format($k['sisa']) }}
                        </td>
                        <td>
                            @if($k['sisa'] > 0)
                                <span class="badge bg-danger-subtle text-danger-emphasis">
                                    {{ $k['sku_kurang'] }} SKU masih kurang
                                </span>
                                <div class="small text-muted mt-1">{{ $order->status_label }}</div>
                            @else
                                <span class="badge bg-success-subtle text-success-emphasis">
                                    <i class="bi bi-check-circle me-1"></i>Sudah terpenuhi
                                </span>
                            @endif
                        </td>
                        <td>
                            <div>{{ $k['terakhir']?->format('d M Y') ?? '—' }}</div>
                            <small class="text-muted">{{ $k['peristiwa'] }} peristiwa</small>
                        </td>
                        <td class="text-end">
                            {{-- KIRIM OUTSTANDING. Hanya muncul pada pesanan yang
                                 putaran pengirimannya sudah selesai DAN masih punya
                                 kekurangan. Satu tombol per pesanan, bukan per SKU:
                                 yang dibuka adalah putaran untuk SELURUH kekurangan
                                 pesanan itu — memisahkannya per SKU berarti satu
                                 pesanan bisa punya tiga putaran berjalan sekaligus,
                                 dan tiap putaran menuntut Surat Jalannya sendiri. --}}
                            @if(isset($bolehKirimUlang[$order->id]))
                                <button type="button" class="btn btn-sm btn-primary rounded-3"
                                        data-bs-toggle="modal" data-bs-target="#modalKirimUlang"
                                        data-order="{{ $order->id }}"
                                        data-nomor="{{ $order->order_number }}"
                                        data-customer="{{ $order->customer?->name }}"
                                        data-kurang="{{ $k['sisa'] }}">
                                    <i class="bi bi-truck me-1"></i> Kirim Outstanding
                                </button>
                            @elseif($k['sisa'] > 0)
                                {{-- Masih kurang, tapi belum boleh dibuka. Alasannya
                                     dikatakan, bukan dibiarkan jadi strip kosong: yang
                                     membaca layar ini justru sedang menunggu barangnya,
                                     dan "tombolnya tidak ada" tanpa keterangan terbaca
                                     sebagai sistem yang rusak. --}}
                                <span class="text-muted small d-inline-block lh-sm" style="max-width:160px"
                                      title="Pesanan ini masih berstatus {{ $order->status_label }} — putaran pengirimannya belum berangkat. Tombol Kirim Outstanding muncul setelah barangnya benar-benar jalan, supaya stok yang sama tidak dicadangkan dua kali untuk kekurangan yang sama.">
                                    <i class="bi bi-hourglass-split me-1"></i>Menunggu putaran ini berangkat
                                </span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>

                    {{-- RINCIAN PER SKU. Satu baris per SKU, dengan peristiwa
                         kekurangannya di bawahnya: sebuah SKU bisa kurang dua kali
                         — sekali saat disetujui sebagian, sekali lagi saat Surat
                         Jalan berangkat kurang — dan keduanya peristiwa yang
                         berbeda, bukan satu angka yang diperbarui. --}}
                    <tr class="collapse" id="{{ $panel }}">
                        <td colspan="9" class="bg-body-tertiary px-4 py-3">
                            <div class="fw-semibold small text-muted mb-2">
                                <i class="bi bi-list-ul me-1"></i>Rincian SKU — {{ $order->order_number }}
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0 bg-white rounded-3 overflow-hidden">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Produk</th>
                                            <th class="text-end">Pesan</th>
                                            <th class="text-end">Disetujui</th>
                                            <th class="text-end">Terkirim</th>
                                            <th class="text-end">Masih kurang</th>
                                            <th>Riwayat kekurangan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($k['sku'] as $s)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold font-monospace small">{{ $s['produk']?->sku ?? '—' }}</div>
                                                <small class="text-muted">{{ $s['produk']?->name }}</small>
                                            </td>
                                            <td class="text-end">{{ number_format($s['dipesan']) }}</td>
                                            <td class="text-end">{{ number_format($s['disetujui']) }}</td>
                                            <td class="text-end">{{ number_format($s['terkirim']) }}</td>
                                            <td class="text-end">
                                                {{-- Tiga keadaan yang BERBEDA, sengaja tidak
                                                     diringkas jadi angka saja: barisnya dicabut
                                                     dari pesanan bukan hal yang sama dengan
                                                     kewajiban yang sudah dipenuhi. --}}
                                                @if($s['sisa'] === null)
                                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Baris dicabut</span>
                                                @elseif($s['sisa'] > 0)
                                                    <span class="badge bg-danger-subtle text-danger-emphasis">
                                                        Kurang {{ number_format($s['sisa']) }} {{ $s['produk']?->uom }}
                                                    </span>
                                                @else
                                                    <span class="badge bg-success-subtle text-success-emphasis">
                                                        <i class="bi bi-check-circle me-1"></i>Terpenuhi
                                                    </span>
                                                @endif
                                            </td>
                                            <td>
                                                @foreach($s['peristiwa'] as $p)
                                                    <div class="small {{ ! $loop->last ? 'mb-2 pb-2 border-bottom' : '' }}">
                                                        <span class="badge bg-{{ $p->cause === \App\Models\SalesOrderOutstanding::CAUSE_APPROVAL ? 'warning' : 'info' }}-subtle
                                                                     text-{{ $p->cause === \App\Models\SalesOrderOutstanding::CAUSE_APPROVAL ? 'warning' : 'info' }}-emphasis">
                                                            {{ $p->cause_label }}
                                                        </span>
                                                        <span class="text-danger fw-semibold ms-1">kurang {{ number_format($p->qty_outstanding) }}</span>
                                                        <span class="text-muted">
                                                            dari {{ number_format($p->qty_ordered) }}
                                                            &middot; {{ $p->created_at?->format('d M Y H:i') }}
                                                            &middot; {{ $p->recordedBy?->full_name ?? 'Sistem' }}
                                                        </span>
                                                        @if($p->note)
                                                            <div class="text-muted">{{ $p->note }}</div>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-check2-circle display-6 d-block mb-2 opacity-50"></i>
                            Belum ada kekurangan yang tercatat.
                            <div class="small">Setiap pesanan yang disetujui atau dikirim kurang dari yang diminta akan muncul di sini.</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $halaman->links() }}</div>
    </div>
</div>

{{-- KIRIM OUTSTANDING. Satu modal dipakai bersama seluruh baris; identitas
     pesanannya ditempelkan lewat data-* saat tombolnya ditekan. Membuat satu
     modal per baris berarti dua puluh salinan formulir yang sama di satu
     halaman. --}}
<div class="modal fade" id="modalKirimUlang" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formKirimUlang" class="modal-content border-0 rounded-4">
            @csrf
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-truck text-primary me-2"></i>Kirim Outstanding
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="bg-light rounded-3 p-3 mb-3 small">
                    <div><strong id="kuNomor" class="font-monospace"></strong></div>
                    <div class="text-muted" id="kuCustomer"></div>
                    <div class="text-danger fw-semibold mt-1" id="kuKurang"></div>
                </div>

                <div class="alert alert-info border-0 rounded-3 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Nomor SO tetap sama.</strong> Pesanan ini dibuka kembali untuk putaran
                    pengiriman berikutnya, masuk lagi ke <strong>Daftar Picking</strong>, lalu berangkat
                    dengan <strong>Surat Jalan baru</strong>. Tidak ada pesanan baru yang dibuat —
                    kewajibannya memang satu, bukan dua.
                </div>

                <div class="alert alert-warning border-0 rounded-3 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Stok dicadangkan <strong>sebanyak yang ada sekarang</strong>. Kalau pengiriman ini
                    pun masih kurang, sisanya <strong>masuk lagi ke daftar outstanding</strong> dan bisa
                    dikirim outstanding sekali lagi.
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-semibold">Catatan (opsional)</label>
                    <textarea name="note" class="form-control" rows="2" maxlength="1000"
                              placeholder="Contoh: sisa 20 pail menyusul setelah produksi batch B12 masuk."></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary fw-bold">Buka Pengiriman Ulang</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('modalKirimUlang');
        if (! modal) return;

        const form = document.getElementById('formKirimUlang');

        modal.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;

            // Rutenya dirakit dari template supaya nomor pesanan tidak perlu
            // ditulis dua kali di Blade maupun di sini.
            form.action = '{{ route('wms.outstanding.reship', ['order' => '__ID__']) }}'
                .replace('__ID__', b.dataset.order);

            modal.querySelector('#kuNomor').textContent = b.dataset.nomor || '—';
            modal.querySelector('#kuCustomer').textContent = b.dataset.customer || '—';
            modal.querySelector('#kuKurang').textContent = b.dataset.kurang
                ? 'Masih kurang ' + b.dataset.kurang + ' unit'
                : '';
        });

        // Panah pada tombol buka/tutup mengikuti keadaan panelnya. Tanpa ini
        // panah tetap menunjuk ke bawah pada rincian yang sudah terbuka, dan
        // orang menekannya lagi mengira belum terbuka.
        document.querySelectorAll('tr.collapse').forEach(function (panel) {
            const tombol = document.querySelector('[data-bs-target="#' + panel.id + '"]');
            if (! tombol) return;

            const ikon = tombol.querySelector('i');

            panel.addEventListener('show.bs.collapse', function () {
                ikon.classList.replace('bi-chevron-down', 'bi-chevron-up');
            });
            panel.addEventListener('hide.bs.collapse', function () {
                ikon.classList.replace('bi-chevron-up', 'bi-chevron-down');
            });
        });
    });
</script>
@endpush
