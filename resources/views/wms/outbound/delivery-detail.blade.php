@extends('layouts.wms')

@section('title', 'Surat Jalan '.$note->document_no)
@section('page_title', 'Surat Jalan '.$note->document_no)

@section('content')
{{-- Layar keputusan Logistik sebelum barang berangkat.

     YANG DIBANDINGKAN: qty di dokumen resmi BC vs qty yang benar-benar
     diturunkan operator dari rak. Dokumen BC yang menang (keputusan pemilik
     produk), tetapi selisihnya harus TERLIHAT sebelum tombol ditekan — bukan
     dilaporkan sesudahnya, karena yang berpindah adalah barang fisik. --}}

<a href="{{ route('wms.delivery.index') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke daftar Surat Jalan
</a>

{{-- PENANDA "SUDAH DIKIRIM" — pertanyaan pemilik produk: di bagian mana
     halaman ini menyatakan pesanan sudah berangkat?

     Jawabannya harus terbaca dalam sekali lihat, bukan disimpulkan dari
     badge kecil di sudut. Tiga langkah dengan waktunya masing-masing:
     disalin dari BC -> berangkat -> sampai. Yang sudah lewat berwarna, yang
     belum abu-abu, sehingga posisi pengiriman terbaca tanpa membaca satu
     kalimat pun. --}}
@php($sudahBerangkat = $note->shipped_at !== null)
@php($sudahSampai = $note->delivered_at !== null)

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="row g-3 text-center">
            <div class="col-4">
                <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center
                            bg-success text-white" style="width:44px;height:44px">
                    <i class="bi bi-download fs-5"></i>
                </div>
                <div class="small fw-semibold">Disalin dari BC</div>
                <div class="small text-muted">{{ $note->imported_at?->format('d M Y H:i') ?? '—' }}</div>
            </div>

            <div class="col-4">
                <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center
                            {{ $sudahBerangkat ? 'bg-primary text-white' : 'bg-light text-muted border' }}"
                     style="width:44px;height:44px">
                    <i class="bi bi-truck fs-5"></i>
                </div>
                <div class="small fw-semibold {{ $sudahBerangkat ? '' : 'text-muted' }}">
                    {{ $sudahBerangkat ? 'Barang sudah dikirim' : 'Belum dikirim' }}
                </div>
                <div class="small text-muted">
                    {{ $note->shipped_at?->format('d M Y H:i') ?? 'menunggu dinyatakan berangkat' }}
                </div>
                @if($sudahBerangkat && $note->shippedBy)
                    <div class="small text-muted">oleh {{ $note->shippedBy->full_name }}</div>
                @endif
            </div>

            <div class="col-4">
                <div class="rounded-circle mx-auto mb-2 d-flex align-items-center justify-content-center
                            {{ $sudahSampai ? 'bg-success text-white' : 'bg-light text-muted border' }}"
                     style="width:44px;height:44px">
                    <i class="bi bi-check-lg fs-5"></i>
                </div>
                <div class="small fw-semibold {{ $sudahSampai ? '' : 'text-muted' }}">
                    {{ $sudahSampai ? 'Sampai tujuan' : 'Belum dikonfirmasi supir' }}
                </div>
                <div class="small text-muted">{{ $note->delivered_at?->format('d M Y H:i') ?? '—' }}</div>
            </div>
        </div>

        @if($note->salesOrder)
        <div class="text-center small text-muted mt-3 pt-3 border-top">
            Status pesanan
            <span class="font-monospace">{{ $note->salesOrder->order_number }}</span>:
            <span class="badge bg-{{ $note->salesOrder->status_color }}-subtle text-{{ $note->salesOrder->status_color }}-emphasis">
                {{ $note->salesOrder->status_label }}
            </span>
        </div>
        @endif
    </div>
</div>

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
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    @foreach($errors->all() as $pesan)<div>{{ $pesan }}</div>@endforeach
</div>
@endif

<div class="row g-4">
    <div class="col-12 col-xl-7">
        {{-- ---------------------------------------------- Perbandingan qty --}}
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="bi bi-clipboard-check text-primary me-2"></i> Dokumen BC vs Hasil Picking
                        </h5>
                        <small class="text-muted">
                            Qty yang berlaku adalah <strong>qty dokumen BC</strong>.
                        </small>
                    </div>
                    <span class="badge bg-{{ $note->status_color }}-subtle text-{{ $note->status_color }}-emphasis">
                        {{ $note->status_label }}
                    </span>
                </div>
            </div>

            <div class="card-body px-4 pt-3">
                <dl class="row small mb-3">
                    <dt class="col-5 col-sm-4 text-muted fw-normal">No. SO (BC)</dt>
                    <dd class="col-7 col-sm-8 font-monospace">{{ $note->bc_so_number }}</dd>

                    <dt class="col-5 col-sm-4 text-muted fw-normal">Pesanan</dt>
                    <dd class="col-7 col-sm-8">
                        @if($note->salesOrder)
                            <span class="font-monospace">{{ $note->salesOrder->order_number }}</span>
                        @else
                            <span class="badge bg-warning-subtle text-warning-emphasis">Belum berpasangan</span>
                        @endif
                    </dd>

                    <dt class="col-5 col-sm-4 text-muted fw-normal">Customer</dt>
                    <dd class="col-7 col-sm-8">{{ $note->customer?->name ?? $note->customer_code ?? '—' }}</dd>

                    <dt class="col-5 col-sm-4 text-muted fw-normal">Tanggal kirim</dt>
                    <dd class="col-7 col-sm-8">{{ $note->shipment_date?->format('d M Y') ?? '—' }}</dd>
                </dl>

                @if($note->sales_order_id === null)
                <div class="alert alert-warning border-0 rounded-3 small">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Surat Jalan ini belum menemukan pesanannya di sistem ini, jadi belum bisa dinyatakan berangkat.
                    Periksa nomor SO-nya — kalau seharusnya ada, berarti nomor yang diketik saat menerima pesanan
                    berbeda dari yang tercatat di BC.
                </div>

                {{-- PINTU UTAMA UNTUK SALAH KETIK NOMOR SO.

                     Nomornya TIDAK diketik ulang di sini: yang benar sudah
                     tertulis di dokumen BC, dan sistem yang menyalinnya ke
                     pesanan. Meminta orang mengetik ulang berarti meminta jari
                     yang tadi salah untuk tidak salah lagi. --}}
                @if($note->status === \App\Models\DeliveryNote::STATUS_IMPORTED)
                <form method="POST" action="{{ route('wms.delivery.pair', $note) }}" class="border rounded-3 p-3">
                    @csrf
                    <div class="fw-semibold mb-1">Pasangkan ke pesanan yang benar</div>
                    <p class="small text-muted">
                        Nomor SO pesanan yang dipilih akan <strong>disamakan dengan dokumen BC</strong>
                        ({{ $note->bc_so_number }}), dan perubahannya dicatat.
                    </p>

                    @if($kandidat->isEmpty())
                        <div class="small text-muted">
                            Tidak ada pesanan yang cocok. Yang ditampilkan hanya pesanan
                            <strong>pelanggan yang sama</strong> yang sudah dipicking dan belum punya Surat Jalan.
                            Kalau pesanannya belum sampai tahap itu, selesaikan dulu picking-nya.
                        </div>
                    @else
                    <div class="row g-2">
                        <div class="col-12 col-md-8">
                            <select name="sales_order_id" class="form-select rounded-3" required>
                                <option value="">— pilih pesanan —</option>
                                @foreach($kandidat as $calon)
                                <option value="{{ $calon->id }}">
                                    {{ $calon->order_number }} · SO {{ $calon->bc_so_number ?? '—' }} ·
                                    {{ $calon->customer?->name }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <button class="btn btn-warning rounded-3">
                                <i class="bi bi-link-45deg me-1"></i> Pasangkan
                            </button>
                        </div>
                    </div>
                    @endif
                </form>
                @endif
                @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>SKU</th>
                                <th class="text-end">Dokumen BC</th>
                                <th class="text-end">Diambil dari rak</th>
                                <th class="text-end">Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($perbandingan as $baris)
                            @php($mustahil = $baris['selisih'] < 0)
                            <tr class="{{ $mustahil ? 'table-danger' : ($baris['selisih'] > 0 ? 'table-warning' : '') }}">
                                <td>
                                    <div class="fw-semibold font-monospace small">{{ $baris['sku'] }}</div>
                                    <small class="text-muted">{{ $baris['nama'] }}</small>
                                </td>
                                <td class="text-end fw-bold">{{ $baris['qty_sj'] }}</td>
                                <td class="text-end">{{ $baris['qty_picking'] }}</td>
                                <td class="text-end">
                                    @if($baris['selisih'] === 0)
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    @elseif($mustahil)
                                        <span class="fw-semibold text-danger">kurang {{ abs($baris['selisih']) }}</span>
                                    @else
                                        <span class="fw-semibold text-warning-emphasis">lebih {{ $baris['selisih'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center py-4 text-muted">Dokumen ini tidak memuat baris barang.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @php($adaBedaSku = $bedaSku['di_sj_saja'] !== [])
                {{-- SKU berbeda menyingkirkan diagnosis selisih qty: kedua
                     barisnya memang muncul sebagai "kurang" dan "lebih", tapi
                     menamainya begitu di sini akan mengarahkan orang mengejar
                     selisih stok yang tidak pernah ada. --}}
                @php($adaKelebihan = ! $adaBedaSku && collect($perbandingan)->contains(fn ($b) => $b['selisih'] > 0))
                @php($adaKekurangan = ! $adaBedaSku && collect($perbandingan)->contains(fn ($b) => $b['selisih'] < 0))

                @if($adaBedaSku)
                <div class="alert alert-danger border-0 rounded-3 small mt-3 mb-0">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-exclamation-octagon-fill me-2"></i>Barang di Surat Jalan berbeda, bukan sekadar beda jumlah
                    </div>
                    <p class="mb-2">
                        SKU berikut ada di Surat Jalan tetapi <strong>tidak pernah dipicking</strong> untuk pesanan ini:
                        @foreach($bedaSku['di_sj_saja'] as $b)
                            <span class="font-monospace">{{ $b['sku'] }}</span> ({{ $b['qty'] }}){{ ! $loop->last ? ',' : '' }}
                        @endforeach
                    </p>
                    @if($bedaSku['di_picking_saja'] !== [])
                    <p class="mb-2">
                        Sebaliknya, yang diambil dari rak justru
                        @foreach($bedaSku['di_picking_saja'] as $b)
                            <span class="font-monospace">{{ $b['sku'] }}</span> ({{ $b['qty'] }}){{ ! $loop->last ? ',' : '' }}
                        @endforeach
                        — dan itu tidak ada di dokumen.
                    </p>
                    @endif
                    <p class="mb-0">
                        Sistem <strong>tidak bisa memutuskan sendiri</strong> siapa yang keliru: bisa SKU di BC yang salah,
                        bisa barang yang diambil dari rak yang salah. Keduanya menuntut tindakan yang berlawanan, dan
                        keduanya harus diputuskan <strong>sebelum kendaraan berangkat</strong>. Karena itu pengiriman ditahan.
                    </p>
                </div>
                @endif

                @if($adaKekurangan)
                <div class="alert alert-warning border-0 rounded-3 small mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Dokumen menyebut <strong>lebih banyak</strong> daripada yang tercatat dipicking.
                    Dokumen BC yang berlaku, jadi selisihnya tetap dinyatakan berangkat dan
                    <strong>dikeluarkan dari stok</strong> — artinya isi rak sebenarnya lebih sedikit daripada
                    angka di sistem. Selisih ini akan tercatat di Riwayat Mutasi untuk ditelusuri saat opname.
                </div>
                @endif

                @if($adaKelebihan)
                <div class="alert alert-warning border-0 rounded-3 small mt-3 mb-0">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    Ada barang yang sudah turun dari rak tetapi <strong>tidak tercantum</strong> di Surat Jalan.
                    Saat dinyatakan berangkat, kelebihannya <strong>dikembalikan ke raknya masing-masing</strong> —
                    pastikan barangnya benar-benar tidak ikut naik ke kendaraan.
                </div>
                @endif
                @endif
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        {{-- ------------------------------------------------------ Pengiriman --}}
        @php($tertahanBedaSku = $bedaSku['di_sj_saja'] !== [] && $note->substitution_confirmed_at === null)

        {{-- PINTU KONFIRMASI, menggantikan formulir supir selama SKU-nya
             belum diputuskan. Sengaja MENGGANTIKAN, bukan menemani: selama
             formulir berangkat masih terlihat, orang akan mengisinya dulu
             lalu bertanya belakangan. --}}
        @if($note->status === \App\Models\DeliveryNote::STATUS_IMPORTED && $note->sales_order_id !== null && $tertahanBedaSku)
        <div class="card shadow-sm border-0 rounded-4 border-danger">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-danger mb-0">
                    <i class="bi bi-sign-stop-fill me-2"></i> Pengiriman Ditahan
                </h5>
                <small class="text-muted">Barang di Surat Jalan berbeda dari yang dipicking.</small>
            </div>
            <div class="card-body px-4 pt-3">
                <p class="small text-muted">
                    Ada dua jalan keluar, dan keduanya butuh keputusan orang:
                </p>
                <ol class="small text-muted ps-3">
                    <li class="mb-2">
                        <strong>Surat Jalan yang salah SKU</strong> — betulkan di sistem BC, lalu impor ulang
                        berkasnya. Tidak ada yang perlu ditekan di sini.
                    </li>
                    <li>
                        <strong>Barang di Surat Jalan memang yang naik</strong> (mis. pelanggan setuju ganti ukuran)
                        — nyatakan di bawah ini. Barang yang semula dipicking dikembalikan ke rak, barang di Surat
                        Jalan yang dikeluarkan, dan baris pesanan yang digantikan ditutup.
                    </li>
                </ol>

                <form method="POST" action="{{ route('wms.delivery.substitution', $note) }}"
                      onsubmit="return confirm('Nyatakan barang di Surat Jalan memang yang naik kendaraan?');">
                    @csrf
                    <label class="form-label small fw-semibold">
                        Alasan penggantian <span class="text-danger">*</span>
                    </label>
                    <textarea name="substitution_reason" rows="3" required minlength="10" maxlength="1000"
                              class="form-control mb-3"
                              placeholder="mis. pelanggan setuju diganti ukuran 20Kg karena 5Kg kosong; sudah dikonfirmasi Sales">{{ old('substitution_reason') }}</textarea>

                    <button class="btn btn-danger rounded-3 w-100">
                        <i class="bi bi-arrow-left-right me-1"></i> Konfirmasi Barang Beda SKU
                    </button>
                </form>
            </div>
        </div>
        @endif

        @if($note->status === \App\Models\DeliveryNote::STATUS_IMPORTED && $note->sales_order_id !== null && ! $tertahanBedaSku)
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-truck text-primary me-2"></i> Data Pengiriman</h5>
                <small class="text-muted">Tautan konfirmasi dikirim ke nomor ini.</small>
            </div>

            @if($note->substitution_confirmed_at)
            <div class="alert alert-warning border-0 rounded-3 small mx-4 mt-3 mb-0">
                <i class="bi bi-arrow-left-right me-2"></i>
                <strong>Penggantian barang dikonfirmasi</strong>
                {{ $note->substitution_confirmed_at->format('d M Y H:i') }}
                @if($note->substitutionConfirmedBy) oleh {{ $note->substitutionConfirmedBy->full_name }} @endif —
                {{ $note->substitution_reason }}
            </div>
            @endif

            <form method="POST" action="{{ route('wms.delivery.ship', $note) }}" class="card-body px-4 pt-3"
                  onsubmit="return confirm('Nyatakan barang berangkat? Stok dan status pesanan akan berubah.');">
                @csrf

                <label class="form-label small fw-semibold">Nama supir <span class="text-danger">*</span></label>
                <input type="text" name="driver_name" value="{{ old('driver_name') }}"
                       class="form-control mb-3" maxlength="100" required list="daftarSupir">

                <label class="form-label small fw-semibold">Nomor WhatsApp supir <span class="text-danger">*</span></label>
                <div class="input-group mb-1">
                    <span class="input-group-text bg-white"><i class="bi bi-whatsapp text-success"></i></span>
                    <input type="text" name="driver_phone" id="nomorSupir" value="{{ old('driver_phone') }}"
                           class="form-control" maxlength="30" required placeholder="081234567890"
                           list="daftarNomor" autocomplete="off">
                </div>
                {{-- Nomor ditampilkan kembali dalam bentuk yang akan
                     BENAR-BENAR dipakai mengirim. Salah ketik pada nomor
                     gagalnya diam: pesan "terkirim" ke nomor orang lain, dan
                     yang menemukan masalahnya adalah Logistik keesokan
                     harinya saat menanyakan kenapa belum dikonfirmasi. --}}
                <div class="form-text mb-3">
                    Akan dikirim ke: <strong id="nomorTerbaca" class="font-monospace">—</strong>
                </div>

                <label class="form-label small fw-semibold">Plat nomor kendaraan <span class="text-danger">*</span></label>
                <input type="text" name="vehicle_plate" value="{{ old('vehicle_plate') }}"
                       class="form-control mb-3 text-uppercase" maxlength="20" required placeholder="B 1234 XYZ">

                {{-- Bukan master data supir: supir berganti tiap hari dan
                     sebagian besar dari perusahaan jasa lain. Daftar ini
                     tumbuh sendiri dari pengiriman yang sudah terjadi. --}}
                <datalist id="daftarNomor">
                    @foreach($nomorTerakhir as $supir)
                        <option value="{{ $supir['nomor'] }}">{{ $supir['nama'] }} · {{ $supir['plat'] }}</option>
                    @endforeach
                </datalist>
                <datalist id="daftarSupir">
                    @foreach($nomorTerakhir as $supir)
                        <option value="{{ $supir['nama'] }}"></option>
                    @endforeach
                </datalist>

                <div class="d-grid">
                    <button class="btn btn-success btn-lg rounded-3">
                        <i class="bi bi-send me-1"></i> Nyatakan Berangkat
                    </button>
                </div>
            </form>
        </div>
        @else
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-truck text-primary me-2"></i> Pengiriman</h5>
            </div>
            <div class="card-body px-4 pt-3">
                <dl class="row small mb-3">
                    <dt class="col-5 text-muted fw-normal">Supir</dt>
                    <dd class="col-7">{{ $note->driver_name ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Nomor WhatsApp</dt>
                    <dd class="col-7 font-monospace">{{ $note->driver_phone ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Kendaraan</dt>
                    <dd class="col-7">{{ $note->vehicle_plate ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Berangkat</dt>
                    <dd class="col-7">{{ $note->shipped_at?->format('d M Y H:i') ?? '—' }}</dd>
                    @if($note->delivered_at)
                    <dt class="col-5 text-muted fw-normal">Sampai</dt>
                    <dd class="col-7">
                        {{ $note->delivered_at->format('d M Y H:i') }}
                        @if($note->received_by_name)
                            <div class="text-muted">Diterima {{ $note->received_by_name }}</div>
                        @endif
                    </dd>
                    @endif
                </dl>

                @if($note->epod_token)
                {{-- STATUS PESAN TERPISAH DARI STATUS BARANG. Truk tidak
                     menunggu WhatsApp; tetapi kegagalannya harus terlihat,
                     karena supir yang tidak menerima tautan tidak akan pernah
                     mengonfirmasi apa pun. --}}
                @php($gagal = $note->notify_status === \App\Models\DeliveryNote::NOTIFY_FAILED)
                @php($manual = $note->notify_status === \App\Models\DeliveryNote::NOTIFY_MANUAL)

                <div class="alert alert-{{ $gagal ? 'danger' : ($manual ? 'warning' : 'success') }} border-0 rounded-3 small">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-whatsapp me-1"></i> {{ $note->notify_label }}
                    </div>
                    @if($note->notify_error)
                        <div class="mb-2">{{ $note->notify_error }}</div>
                    @endif
                    @if($manual)
                        <div class="mb-2">
                            Sistem belum tersambung ke penyedia WhatsApp, jadi pesannya dikirim dari WhatsApp Anda sendiri.
                        </div>
                    @endif

                    <div class="d-flex flex-wrap gap-2 mt-2">
                        @if($note->driver_phone)
                        <a class="btn btn-sm btn-success rounded-3"
                           href="https://wa.me/{{ $note->driver_phone }}?text={{ rawurlencode($note->pesanUntukSupir()) }}"
                           target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp me-1"></i> Buka WhatsApp
                        </a>
                        @endif

                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-3" id="salinTautan"
                                data-tautan="{{ $note->epodUrl() }}">
                            <i class="bi bi-clipboard me-1"></i> Salin tautan
                        </button>

                        @if($gagal)
                        <form method="POST" action="{{ route('wms.delivery.resend', $note) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger rounded-3">
                                <i class="bi bi-arrow-clockwise me-1"></i> Kirim ulang
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const nomor = document.getElementById('nomorSupir');
    const terbaca = document.getElementById('nomorTerbaca');

    // Cerminan aturan PhoneNumber::forWhatsApp() di sisi layar. Sengaja
    // hanya untuk DILIHAT — yang menentukan tetap server, dan bentuk yang
    // tampil di sini harus sama supaya tidak ada kejutan setelah menekan.
    function bentukKirim(mentah) {
        const angka = (mentah || '').replace(/\D/g, '');
        if (angka === '') { return '—'; }
        if (angka.startsWith('0')) { return '62' + angka.replace(/^0+/, ''); }
        if (angka.startsWith('62')) { return angka; }
        return '62' + angka;
    }

    if (nomor && terbaca) {
        const perbarui = () => { terbaca.textContent = bentukKirim(nomor.value); };
        nomor.addEventListener('input', perbarui);
        perbarui();
    }

    const salin = document.getElementById('salinTautan');

    if (salin) {
        salin.addEventListener('click', function () {
            const tautan = salin.dataset.tautan;

            // Jalan mundur ke execCommand: API clipboard hanya bekerja di
            // HTTPS/localhost dan gagal DIAM-DIAM di jaringan kantor lewat
            // http:// — persis masalah yang sudah ditemui di layar
            // penerimaan pesanan.
            const selesai = () => {
                salin.innerHTML = '<i class="bi bi-check-lg me-1"></i> Tersalin';
                setTimeout(() => {
                    salin.innerHTML = '<i class="bi bi-clipboard me-1"></i> Salin tautan';
                }, 2000);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(tautan).then(selesai).catch(() => cadangan(tautan, selesai));
            } else {
                cadangan(tautan, selesai);
            }
        });
    }

    function cadangan(teks, selesai) {
        const kotak = document.createElement('textarea');
        kotak.value = teks;
        kotak.style.position = 'fixed';
        kotak.style.opacity = '0';
        document.body.appendChild(kotak);
        kotak.select();
        try { document.execCommand('copy'); selesai(); } catch (e) { window.prompt('Salin tautan ini:', teks); }
        document.body.removeChild(kotak);
    }
});
</script>
@endsection
