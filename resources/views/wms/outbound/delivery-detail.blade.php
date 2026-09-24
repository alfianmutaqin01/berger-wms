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
        @include('wms.outbound._sj-perbandingan')
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
                <small class="text-muted">Tautan konfirmasi dikirim ke nomor yang diisi di bawah.</small>
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

                {{-- SUPIR BERGANTI DI JALAN — ditentukan Logistik, bukan
                     ditebak sistem dari alamat pelanggan.

                     Yang menentukan bukan jaraknya dan bukan pulaunya,
                     melainkan apakah supirnya berganti — dan itu ikut cara
                     armadanya dipesan, yang hanya Logistik tahu. Karawang ke
                     Lampung juga menyeberang laut, tetapi truknya naik feri
                     dan supir yang sama yang tiba di toko; di situ konfirmasi
                     supir justru yang benar. Tebakan otomatis akan keliru
                     persis pada kiriman yang paling mirip aturannya. --}}
                <div class="border rounded-3 p-3 mb-3 bg-light-subtle">
                    <div class="form-check mb-0">
                        <input type="checkbox" name="epod_to_customer" value="1" id="luarPulau"
                               class="form-check-input" @checked(old('epod_to_customer'))>
                        <label class="form-check-label fw-semibold" for="luarPulau">
                            Supir berganti di perjalanan (luar pulau / kontainer)
                        </label>
                        <div class="form-text mb-0">
                            Tautan konfirmasi dikirim ke <strong>pelanggan</strong>, bukan ke supir, dan baru
                            terkirim pada perkiraan tanggal sampai.
                            @if($note->customer?->territory_code)
                                <br>Territory pelanggan ini: <strong>{{ $note->customer->territory_code }}</strong>.
                            @endif
                        </div>
                    </div>
                </div>

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
                <div class="form-text mb-3" id="barisNomorSupir">
                    Akan dikirim ke: <strong id="nomorTerbaca" class="font-monospace">—</strong>
                </div>

                {{-- Tetap diminta pada kiriman luar pulau: barangnya tetap
                     diangkut seseorang keluar dari gudang ini, dan tanpa
                     catatan itu dua minggu kemudian tidak ada jawaban untuk
                     "tadi diambil siapa". --}}
                <div class="alert alert-secondary border-0 rounded-3 small py-2 d-none" id="catatanSupirPertama">
                    <i class="bi bi-info-circle me-1"></i>
                    Data supir di atas dicatat sebagai pengangkut ke pelabuhan. Tautan konfirmasi
                    <strong>tidak</strong> dikirim kepadanya.
                </div>

                <div id="isianDarat">
                    <label class="form-label small fw-semibold">Plat nomor kendaraan <span class="text-danger">*</span></label>
                    <input type="text" name="vehicle_plate" value="{{ old('vehicle_plate') }}"
                           class="form-control mb-3 text-uppercase" maxlength="20" required placeholder="B 1234 XYZ">
                </div>

                {{-- Pengganti plat, bukan tambahan: plat truk ke pelabuhan
                     tidak menjawab pertanyaan apa pun dua minggu kemudian.
                     Yang dicari saat barangnya dipertanyakan adalah lewat
                     ekspedisi mana dan kontainer nomor berapa. --}}
                <div id="isianLautan" class="d-none">
                    <label class="form-label small fw-semibold">Nomor kontainer <span class="text-danger">*</span></label>
                    <input type="text" name="container_no" value="{{ old('container_no') }}"
                           class="form-control mb-3 text-uppercase" maxlength="30" placeholder="ABCU1234567">

                    <label class="form-label small fw-semibold">Nama ekspedisi</label>
                    <input type="text" name="forwarder_name" value="{{ old('forwarder_name') }}"
                           class="form-control mb-3" maxlength="100" placeholder="mis. Meratus, SPIL">

                    <label class="form-label small fw-semibold">
                        Nomor WhatsApp penerima di toko <span class="text-danger">*</span>
                    </label>
                    <div class="input-group mb-1">
                        <span class="input-group-text bg-white"><i class="bi bi-whatsapp text-success"></i></span>
                        <input type="text" name="customer_phone" id="nomorPelanggan"
                               value="{{ old('customer_phone', $note->customer?->phone) }}"
                               class="form-control" maxlength="30" placeholder="081234567890" autocomplete="off">
                    </div>
                    {{-- Diisi awal dari master pelanggan, TETAPI bisa diubah:
                         toko penerima di seberang pulau sering bukan nomor
                         yang tercatat di kantor pusat pelanggan. --}}
                    <div class="form-text mb-3">
                        Akan dikirim ke: <strong id="nomorPelangganTerbaca" class="font-monospace">—</strong>
                        @if($note->customer?->phone)
                            <br>Terisi dari master pelanggan — ubah bila toko penerimanya memakai nomor lain.
                        @endif
                    </div>

                    <label class="form-label small fw-semibold">
                        Perkiraan tanggal sampai di toko <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="eta_date" value="{{ old('eta_date') }}"
                           min="{{ now()->toDateString() }}"
                           max="{{ now()->addYear()->toDateString() }}"
                           class="form-control mb-1">
                    <div class="form-text mb-3">
                        Tautan konfirmasi terbit pada tanggal ini, berlaku
                        {{ (int) config('wms.epod.berlaku_jam') }} jam. Masih bisa digeser dari halaman ini
                        selama tautannya belum terbit.
                    </div>
                </div>

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
        @include('wms.outbound._sj-status')
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

    const nomorPelanggan = document.getElementById('nomorPelanggan');
    const pelangganTerbaca = document.getElementById('nomorPelangganTerbaca');

    if (nomorPelanggan && pelangganTerbaca) {
        const perbarui = () => { pelangganTerbaca.textContent = bentukKirim(nomorPelanggan.value); };
        nomorPelanggan.addEventListener('input', perbarui);
        perbarui();
    }

    // Centang "supir berganti di perjalanan" MENGGANTI isian wajibnya, bukan
    // menambahinya. Atribut required ikut dipindahkan, bukan hanya
    // disembunyikan: kolom wajib yang tersembunyi membuat peramban menolak
    // mengirim formulir sambil menunjuk kolom yang tidak terlihat di layar,
    // dan yang menekan tombolnya tidak akan pernah menemukan apa salahnya.
    const luarPulau = document.getElementById('luarPulau');
    const isianDarat = document.getElementById('isianDarat');
    const isianLautan = document.getElementById('isianLautan');
    const catatanSupir = document.getElementById('catatanSupirPertama');
    const barisNomorSupir = document.getElementById('barisNomorSupir');

    if (luarPulau && isianDarat && isianLautan) {
        const wajibDarat = ['vehicle_plate'];
        const wajibLautan = ['container_no', 'customer_phone', 'eta_date'];
        const cari = (nama) => document.querySelector('[name="' + nama + '"]');

        const terapkan = () => {
            const aktif = luarPulau.checked;

            isianDarat.classList.toggle('d-none', aktif);
            isianLautan.classList.toggle('d-none', !aktif);
            catatanSupir.classList.toggle('d-none', !aktif);

            // Baris "akan dikirim ke" di bawah nomor supir menjadi keliru
            // begitu tautannya tidak lagi menuju supir.
            barisNomorSupir.classList.toggle('d-none', aktif);

            wajibDarat.forEach((nama) => {
                const isian = cari(nama);
                if (isian) { isian.required = !aktif; }
            });

            wajibLautan.forEach((nama) => {
                const isian = cari(nama);
                if (isian) { isian.required = aktif; }
            });
        };

        luarPulau.addEventListener('change', terapkan);
        terapkan();
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
