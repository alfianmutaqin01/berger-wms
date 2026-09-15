@extends('layouts.wms')

@section('title', 'Booking Produk')
@section('page_title', 'Booking Produk')

@section('content')
{{-- Keputusan pemilik produk: menahan jatah untuk satu customer SEBELUM
     pesanannya resmi masuk.

     YANG PALING PENTING DIKATAKAN DI LAYAR INI: unit yang dibooking langsung
     hilang dari angka yang boleh dijanjikan ke pelanggan lain. Tanpa kalimat
     itu, orang mengira booking cuma catatan pengingat — dan tetap menjual
     barang yang sudah ada pemiliknya. --}}

@foreach(['success' => 'check-circle-fill', 'warning' => 'exclamation-circle-fill', 'error' => 'exclamation-triangle-fill'] as $jenis => $ikon)
    @if(session($jenis))
    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : $jenis }} alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
        <i class="bi bi-{{ $ikon }} me-2"></i>{{ session($jenis) }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
    </div>
    @endif
@endforeach

@if($errors->any())
<div class="alert alert-danger border-0 shadow-sm rounded-3">
    <strong><i class="bi bi-exclamation-triangle-fill me-2"></i>Ada isian yang perlu diperbaiki:</strong>
    <ul class="mb-0 mt-2 small">
        @foreach($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach
    </ul>
</div>
@endif

<div class="row g-3 mb-4">
    @php($kartu = [
        ['Booking berlaku', number_format($stats['berlaku']), 'primary', 'bookmark-check'],
        ['Unit tertahan di rak', number_format($stats['tertahan']), 'success', 'lock'],
        ['Unit menunggu produksi', number_format($stats['menunggu']), 'warning', 'hourglass-split'],
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
                        <div class="fs-4 fw-bold text-dark lh-1">{{ $nilai }}</div>
                        <small class="text-muted">{{ $judul }}</small>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-bookmark-plus text-primary me-2"></i> Buat Booking</h5>
        <small class="text-muted">
            Jatah langsung ditahan sebanyak stok yang ada. Sisanya menunggu produksi dan
            diambilkan otomatis begitu barang baru diverifikasi.
        </small>
    </div>
    <div class="card-body px-4 pt-3">
        <form method="POST" action="{{ route('wms.booking.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold">Gudang</label>
                <select name="warehouse_id" id="bookGudang" class="form-select" required>
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($warehouse?->id === $w->id)>{{ $w->display_label }}</option>
                    @endforeach
                </select>
            </div>
            {{-- KETIK LALU PILIH, bukan dropdown.

                 Dropdown lamanya memuat seluruh master: 1.840 customer dan
                 1.734 produk. Customer yang kebetulan ada di tengah harus
                 dicari dengan menggulir ratusan nama — dan seluruh daftar itu
                 ikut terkirim ke HP/komputer tiap kali halaman dibuka,
                 padahal yang dipakai satu. --}}
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold">Customer</label>
                <div class="position-relative" id="cariCustomer">
                    <input type="text" class="form-control cari-teks" autocomplete="off"
                           placeholder="Ketik nama atau kode customer..." value="{{ $pilihanLama['customer'] }}">
                    <input type="hidden" name="customer_id" class="cari-nilai" value="{{ old('customer_id') }}">
                    <div class="list-group position-absolute w-100 shadow cari-saran d-none"
                         style="z-index:1050; max-height:260px; overflow-y:auto;"></div>
                </div>
                <div class="form-text">Minimal 2 huruf.</div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold">Produk</label>
                <div class="position-relative" id="cariProduk">
                    <input type="text" class="form-control cari-teks" autocomplete="off"
                           placeholder="Ketik SKU atau nama produk..." value="{{ $pilihanLama['produk'] }}">
                    <input type="hidden" name="product_id" class="cari-nilai" value="{{ old('product_id') }}">
                    <div class="list-group position-absolute w-100 shadow cari-saran d-none"
                         style="z-index:1050; max-height:260px; overflow-y:auto;"></div>
                </div>
                {{-- Sisa stok bebas ditampilkan SEBELUM tombol ditekan. Kalau
                     tidak, orang baru tahu jatahnya cuma sebagian setelah
                     booking terlanjur dibuat. --}}
                <div class="form-text" id="bookTersedia">Pilih produk untuk melihat stok bebasnya.</div>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small fw-semibold">Qty</label>
                <input type="number" name="qty" min="1" value="{{ old('qty') }}" class="form-control" required>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold">Dibutuhkan</label>
                <input type="date" name="needed_by" value="{{ old('needed_by') }}" class="form-control">
            </div>
            <div class="col-12 col-md-9">
                <label class="form-label small fw-semibold">Catatan (opsional)</label>
                <input type="text" name="note" value="{{ old('note') }}" class="form-control" maxlength="1000"
                       placeholder="mis. permintaan lisan Pak Andi, kirim bersama pengiriman rutin">
            </div>
            <div class="col-12 col-md-3 d-grid">
                <button class="btn btn-primary fw-bold rounded-3">
                    <i class="bi bi-bookmark-plus me-1"></i> Tahan Jatah
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-list-check text-primary me-2"></i> Daftar Booking</h5>
        <small class="text-muted">Terbaru di atas.</small>
    </div>
    <div class="card-body px-4 pt-3">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-12 col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control border-start-0"
                           placeholder="Cari nomor booking, customer, atau SKU...">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select">
                    <option value="">Semua status</option>
                    @foreach($statuses as $slug => $label)
                        <option value="{{ $slug }}" @selected($filters['status'] === $slug)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 d-grid">
                <button class="btn btn-primary rounded-3"><i class="bi bi-funnel me-1"></i> Saring</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Customer</th>
                        <th>Produk</th>
                        <th class="text-end">Dibooking</th>
                        <th class="text-end">Tertahan</th>
                        <th class="text-end">Menunggu</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($bookings as $b)
                    <tr class="{{ $b->terlambat() ? 'table-warning' : '' }}">
                        <td>
                            <span class="fw-semibold font-monospace">{{ $b->reference }}</span>
                            <div class="small text-muted">
                                {{ $b->created_at?->translatedFormat('d M Y') }} &middot;
                                {{ $b->createdBy?->full_name ?? '—' }}
                            </div>
                            @if($b->needed_by)
                                <div class="small {{ $b->terlambat() ? 'text-danger fw-semibold' : 'text-muted' }}">
                                    Dibutuhkan {{ $b->needed_by->translatedFormat('d M Y') }}
                                    @if($b->terlambat()) (lewat) @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $b->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">{{ $b->customer?->code }}</small>
                        </td>
                        <td>
                            <div class="fw-semibold font-monospace small">{{ $b->product?->sku ?? '—' }}</div>
                            <small class="text-muted">{{ $b->product?->name }}</small>
                            @if($b->allocations->isNotEmpty())
                                {{-- Batch mana persisnya yang ditahan. Ini yang
                                     dipakai orang gudang untuk memastikan
                                     barangnya benar-benar berdiri di sana. --}}
                                <div class="small text-muted mt-1">
                                    @foreach($b->allocations as $a)
                                        <span class="badge bg-light text-dark border font-monospace">
                                            {{ $a->stock?->batch_no ?? '—' }}
                                            @ {{ $a->stock?->location?->code ?? '—' }} &middot; {{ $a->qty }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="text-end fw-semibold">{{ number_format($b->qty_booked) }}</td>
                        <td class="text-end text-success fw-semibold">{{ number_format($b->qty_reserved) }}</td>
                        <td class="text-end {{ $b->qty_waiting > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">
                            {{ number_format($b->qty_waiting) }}
                        </td>
                        <td>
                            @php($warna = match($b->status) {
                                \App\Models\StockBooking::STATUS_CLOSED => 'success',
                                \App\Models\StockBooking::STATUS_CANCELLED => 'secondary',
                                default => 'primary',
                            })
                            <span class="badge bg-{{ $warna }}-subtle text-{{ $warna }}-emphasis">{{ $b->status_label }}</span>
                            @if($b->qty_used > 0)
                                <div class="small text-muted mt-1">{{ number_format($b->qty_used) }} sudah dipakai pesanan</div>
                            @endif
                            @if($b->status === \App\Models\StockBooking::STATUS_CANCELLED && $b->cancel_reason)
                                <div class="small text-muted">{{ $b->cancel_reason }}</div>
                            @endif
                        </td>
                        <td class="text-end">
                            @if($b->masihBerlaku())
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-3 tombol-batal"
                                        data-bs-toggle="modal" data-bs-target="#modalBatalBooking"
                                        data-aksi="{{ route('wms.booking.cancel', $b) }}"
                                        data-nomor="{{ $b->reference }}"
                                        data-customer="{{ $b->customer?->name }}"
                                        data-tertahan="{{ $b->qty_reserved }}">
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
                            <i class="bi bi-bookmark display-6 d-block mb-2 opacity-50"></i>
                            Belum ada booking.
                            <div class="small">Pakai formulir di atas untuk menahan jatah customer sebelum pesanannya masuk.</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $bookings->links() }}</div>
    </div>
</div>

<div class="modal fade" id="modalBatalBooking" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formBatalBooking" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Batalkan Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning border-0 rounded-3 small">
                    <div class="fw-semibold" id="batalNomor"></div>
                    <div id="batalCustomer"></div>
                    <div id="batalTertahan"></div>
                </div>
                <p class="text-muted small">
                    Jatah yang sedang ditahan akan <strong>kembali menjadi stok bebas</strong> dan bisa
                    dipesan pelanggan lain. Tindakan ini tidak bisa dibatalkan.
                </p>
                <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                <textarea name="cancel_reason" class="form-control" rows="3" minlength="5" maxlength="1000" required
                          placeholder="mis. customer membatalkan permintaannya"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-danger rounded-3">Batalkan Booking</button>
            </div>
        </form>
    </div>
</div>

{{-- Kolom ketik-lalu-pilih. Berkas yang sama dipakai formulir Buat Pesanan
     milik Sales — penundaan ketikan dan penanda permintaan terakhirnya
     harus diperbaiki di satu tempat, bukan dua. --}}
@include('partials.pencarian-ketik')

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formBatalBooking');

    document.querySelectorAll('.tombol-batal').forEach(function (t) {
        t.addEventListener('click', function () {
            form.action = t.dataset.aksi;
            document.getElementById('batalNomor').textContent = 'Booking ' + t.dataset.nomor;
            document.getElementById('batalCustomer').textContent = t.dataset.customer || '';
            document.getElementById('batalTertahan').textContent =
                t.dataset.tertahan + ' unit yang ditahan akan dilepas kembali.';
        });
    });

    // Sisa stok bebas ditampilkan sambil memilih produk.
    const gudang = document.getElementById('bookGudang');
    const kotak = document.getElementById('bookTersedia');
    const wadahProduk = document.getElementById('cariProduk');
    const produk = wadahProduk.querySelector('.cari-nilai');

    /* ------------------------------------------- Kolom ketik-lalu-pilih */

    pasangPencarian(document.getElementById('cariCustomer'), {
        url: (q) => '{{ route('wms.booking.lookup.customers') }}?q=' + encodeURIComponent(q),
        tampilan: (c) => '<span class="badge bg-light text-dark border font-monospace me-1">'
            + escapeHtml(c.code) + '</span>' + escapeHtml(c.name),
        label: (c) => c.code + ' — ' + c.name,
        kosong: 'Tidak ada customer yang cocok.',
    });

    pasangPencarian(wadahProduk, {
        url: (q) => '{{ route('wms.booking.lookup.products') }}?q=' + encodeURIComponent(q)
            + '&warehouse_id=' + encodeURIComponent(gudang.value),
        tampilan: function (p) {
            // Stok bebasnya ikut di daftar saran. Tanpa itu, memilih produk
            // yang stoknya nol baru ketahuan setelah dipilih — dan orang
            // mencoba satu per satu.
            const sisa = Number(p.tersedia || 0);
            const warna = sisa > 0 ? 'text-success' : 'text-warning';

            return '<span class="fw-semibold small d-block text-truncate">' + escapeHtml(p.name) + '</span>'
                + '<small class="text-muted font-monospace">' + escapeHtml(p.sku) + '</small>'
                + '<small class="' + warna + ' ms-2">bebas ' + sisa.toLocaleString('id-ID')
                + ' ' + escapeHtml(p.uom || '') + '</small>';
        },
        label: (p) => p.sku + ' — ' + p.name,
        kosong: 'Tidak ada produk yang cocok.',
        // Memilih produk langsung memicu pemeriksaan stok di bawah kolom.
        setelahPilih: () => periksa(),
    });

    // Nama produk dan customer datang dari master data yang diketik orang.
    // Menyisipkannya sebagai HTML mentah membuat satu nama produk yang
    // mengandung tanda kurung siku bisa merusak — atau menyetir — halaman
    // ini bagi setiap orang yang membukanya.
    function escapeHtml(teks) {
        const d = document.createElement('div');
        d.textContent = teks == null ? '' : String(teks);

        return d.innerHTML;
    }

    function periksa() {
        if (! produk.value) {
            kotak.textContent = 'Pilih produk untuk melihat stok bebasnya.';
            kotak.className = 'form-text';

            return;
        }

        kotak.textContent = 'Memeriksa…';
        kotak.className = 'form-text';

        const url = '{{ route('wms.booking.availability') }}?product_id=' + encodeURIComponent(produk.value)
            + '&warehouse_id=' + encodeURIComponent(gudang.value);

        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : Promise.reject())
            .then((d) => {
                // "Bebas" — bukan "ada". Angka ini sudah dikurangi yang
                // dibooking dan yang teralokasi pesanan, dan menyebutnya
                // "stok" saja akan membuat orang mengira sisanya lebih banyak.
                let pesan = 'Stok bebas sekarang: ' + Number(d.tersedia).toLocaleString('id-ID')
                    + '. Lebih dari itu boleh dibooking — sisanya menunggu produksi.';

                // "Nol di gudang ini" dan "tidak ada di mana pun" adalah dua
                // keadaan yang sangat berbeda. Tanpa kalimat ini, orang yang
                // baru saja memasukkan stoknya ke gudang lain akan menyimpulkan
                // sistemnya tidak membaca stok itu.
                const lain = d.gudang_lain || [];
                if (lain.length > 0) {
                    pesan += ' Produk ini ada di gudang lain — '
                        + lain.map((g) => g.gudang + ' ' + Number(g.qty).toLocaleString('id-ID')).join(', ')
                        + ' — tetapi stok gudang lain TIDAK bisa dipakai booking ini. Pindahkan lewat Transfer Antar Gudang dulu.';
                }

                kotak.textContent = pesan;
                kotak.className = d.tersedia > 0
                    ? 'form-text text-success'
                    : (lain.length > 0 ? 'form-text text-danger' : 'form-text text-warning');
            })
            .catch(() => {
                kotak.textContent = 'Stok bebas gagal diperiksa. Booking tetap bisa dibuat.';
                kotak.className = 'form-text text-muted';
            });
    }

    // Hanya gudang. Kolom produk sekarang input tersembunyi yang diisi
    // skrip, dan mengisi .value dari skrip TIDAK memicu event 'change' —
    // pemeriksaannya dipanggil langsung lewat setelahPilih di atas.
    gudang.addEventListener('change', periksa);

    // Formulir yang ditolak validasi kembali membawa id pilihannya; label
    // yang terlihat diisi ulang server (lihat $pilihanLama di controller),
    // jadi tinggal stoknya yang perlu diperiksa lagi.
    if (produk.value) periksa();
});
</script>
@endsection
