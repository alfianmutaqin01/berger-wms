@extends('layouts.soms')

@section('title', 'Detail Pesanan')
@section('page_title', 'Detail Pesanan')

@php
    // Kemajuan garis stepper: berhenti tepat di TENGAH bulatan terakhir yang
    // sudah selesai. Tiap bulatan berada di tengah kolomnya sendiri, jadi
    // pusatnya ada di (indeks + 0,5) / jumlah tahap — memakai indeks saja
    // membuat garisnya berhenti di tepi kolom, bukan di bulatannya.
    //
    // Dihitung di sini, bukan di JavaScript, supaya garisnya sudah benar pada
    // cat pertama: di jaringan lambat, garis yang melompat setelah skrip
    // jalan terbaca sebagai halaman yang belum selesai dimuat.
    $jumlahTahap = count($timeline);
    $tahapSelesai = collect($timeline)->filter(fn ($t) => $t['selesai'])->count();
    $persen = $tahapSelesai > 0
        ? ((($tahapSelesai - 1) + 0.5) / $jumlahTahap) * 100
        : 0;
    $adaYangGagal = collect($timeline)->contains(fn ($t) => $t['gagal']);
@endphp

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-9">

        <a href="{{ url('/sales/my-orders') }}" class="btn btn-sm btn-link text-decoration-none ps-0 mb-2">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke Pesanan Saya
        </a>

        <!-- ============ Kepala pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <div style="min-width: 0;">
                        <div class="fw-bold font-monospace text-dark">{{ $order->order_number }}</div>
                        <div class="text-dark text-truncate">{{ $order->customer?->name }}</div>
                        <div class="text-muted small">{{ $order->customer?->code }}</div>
                    </div>
                    <span class="badge bg-{{ $order->status_color }} flex-shrink-0">{{ $order->status_label }}</span>
                </div>

                @if($order->isEditable())
                    {{-- Seluruh stepper masih abu-abu pada draft — perjalanan
                         pesanan baru dimulai saat dikirim. Tanpa keterangan
                         ini, layar penuh bulatan kosong terbaca sebagai
                         halaman yang gagal memuat data. --}}
                    <div class="alert alert-secondary border-0 small py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Draft belum dikirim. Perjalanan pesanan dimulai setelah dikirim ke Logistik.
                    </div>
                @endif

                <!-- ============ Stepper status ============ -->
                {{-- Mendatar dan muat satu layar. Garis digambar sebagai dua
                     batang bertumpuk: abu-abu penuh sebagai latar, lalu batang
                     berwarna selebar kemajuannya. --}}
                <div class="position-relative px-1 pt-2">
                    <div class="position-absolute w-100 rounded-pill"
                         style="height: 4px; background-color: #e9ecef; top: 22px; left: 0; z-index: 1;"></div>
                    <div class="position-absolute rounded-pill"
                         style="height: 4px; background-color: {{ $adaYangGagal ? '#dc3545' : '#198754' }};
                                top: 22px; left: 0; width: {{ $persen }}%; z-index: 2;
                                transition: width .3s ease;"></div>

                    <div class="d-flex justify-content-between position-relative" style="z-index: 3;">
                        @foreach($timeline as $tahap)
                            @php
                                [$warnaBulat, $warnaTeks, $ikon] = match (true) {
                                    $tahap['gagal'] => ['bg-danger text-white', 'text-danger', 'bi-x-lg'],
                                    $tahap['selesai'] => ['bg-success text-white', 'text-success', 'bi-check-lg'],
                                    $tahap['menunggu'] => ['bg-warning text-dark', 'text-warning-emphasis', 'bi-hourglass-split'],
                                    default => ['bg-light text-muted border', 'text-muted', $tahap['ikon']],
                                };
                            @endphp
                            {{-- Lebar dibagi rata dari jumlah tahap, bukan angka
                                 tetap: menambah tahap di controller langsung
                                 terpasang benar tanpa menyentuh berkas ini. --}}
                            <div class="text-center" style="width: {{ 100 / count($timeline) }}%;">
                                <div class="{{ $warnaBulat }} rounded-circle d-inline-flex align-items-center justify-content-center mb-1 shadow-sm"
                                     style="width: 32px; height: 32px; border: 4px solid #fff !important;">
                                    <i class="bi {{ $ikon }}" style="font-size: 0.8rem;"></i>
                                </div>
                                <div class="fw-semibold {{ $warnaTeks }}" style="font-size: 0.68rem; line-height: 1.1;">
                                    {{ $tahap['judul'] }}
                                </div>
                                <div class="text-muted" style="font-size: 0.6rem; line-height: 1.2;">
                                    @if($tahap['waktu'])
                                        {{ $tahap['waktu']->translatedFormat('d M') }}<br>{{ $tahap['waktu']->format('H:i') }}
                                    @elseif($tahap['menunggu'])
                                        <span class="text-warning-emphasis fw-semibold">Menunggu</span>
                                    @else
                                        —
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if($order->sla_hours)
                    <div class="alert alert-success border-0 small mb-0 mt-3 py-2">
                        <i class="bi bi-stopwatch me-1"></i>
                        Selesai dalam {{ number_format((float) $order->sla_hours, 1) }} jam.
                    </div>
                @endif

                {{-- Seluruh penolakan yang pernah terjadi, bukan hanya yang
                     terakhir. Pada pengajuan ketiga dan seterusnya, mengetahui
                     apa saja yang SUDAH diperbaiki sama pentingnya dengan
                     mengetahui apa yang salah sekarang.

                     Bertahan setelah pesanannya diterima: kolom penolakan di
                     pesanan dikosongkan saat diajukan ulang, blok ini dibaca
                     dari tabel riwayat yang tidak pernah dibersihkan. --}}
                @if($order->rejections->isNotEmpty())
                    <div class="alert alert-danger border-0 small mb-0 mt-3 py-2">
                        <div class="fw-semibold mb-2">
                            <i class="bi bi-x-octagon me-1"></i>
                            Pesanan ini pernah ditolak {{ $order->rejections->count() }}&times;
                        </div>
                        @foreach($order->rejections as $tolak)
                            <div class="{{ ! $loop->last ? 'border-bottom pb-2 mb-2' : '' }}">
                                <div class="fw-semibold">
                                    Pengajuan ke-{{ $tolak->attempt_no }} &middot;
                                    {{ $tolak->rejected_at?->translatedFormat('d M Y, H:i') }}
                                </div>
                                <div>{{ $tolak->reason }}</div>
                                <div class="text-muted">oleh {{ $tolak->rejectedBy?->full_name ?? '—' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($order->sedangDitolak())
                    <div class="alert alert-warning border-0 small mb-0 mt-3 py-2 d-flex flex-wrap align-items-center gap-2">
                        <span class="flex-grow-1">
                            Perbaiki item yang dimaksud, lalu ajukan ulang — tidak perlu membuat pesanan baru.
                        </span>
                        <a href="{{ url('/sales/orders/'.$order->id.'/edit') }}" class="btn btn-sm btn-warning fw-semibold">
                            <i class="bi bi-arrow-repeat me-1"></i>Perbaiki &amp; Ajukan Ulang
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <!-- ============ Bukti Surat Jalan (F-OUT-05) ============ -->
        @php
            $bolehUnggah = in_array($order->status, [
                \App\Models\SalesOrder::STATUS_SHIPPING,
                \App\Models\SalesOrder::STATUS_PROOF_UPLOADED,
            ], true) && $order->cancelled_at === null;

            $sudahSelesai = in_array($order->status, [
                \App\Models\SalesOrder::STATUS_COMPLETED,
                \App\Models\SalesOrder::STATUS_COMPLETED_BILLING,
            ], true);
        @endphp

        @if($bolehUnggah || $bukti->isNotEmpty())
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-camera text-primary me-2"></i>Bukti Surat Jalan</h6>
                <small class="text-muted">Foto Surat Jalan yang sudah ditandatangani pelanggan.</small>
            </div>

            <div class="card-body px-3 px-md-4">
                @foreach(['success', 'error'] as $jenis)
                    @if(session($jenis))
                    <div class="alert alert-{{ $jenis === 'error' ? 'danger' : 'success' }} border-0 small py-2">
                        {{ session($jenis) }}
                    </div>
                    @endif
                @endforeach

                @if($errors->any())
                <div class="alert alert-danger border-0 small py-2">{{ $errors->first() }}</div>
                @endif

                {{-- Alasan penolakan ditaruh DI ATAS tombol, bukan di bawah
                     daftar foto. Sales membaca ini sambil berdiri di depan
                     toko; kalau alasannya berada di bawah lipatan layar, ia
                     akan memotret ulang kesalahan yang sama. --}}
                @if($alasanDitolak)
                <div class="alert alert-warning border-0 small py-2">
                    <strong>Foto sebelumnya ditolak Logistik:</strong><br>{{ $alasanDitolak }}
                    <div class="mt-1">Silakan potret ulang sesuai catatan di atas.</div>
                </div>
                @endif

                @if($bukti->isNotEmpty())
                <div class="row g-2 mb-3">
                    @foreach($bukti as $foto)
                    <div class="col-4">
                        <a href="{{ route('sales.proofs.preview', $foto) }}" target="_blank" rel="noopener"
                           class="d-block border rounded-3 overflow-hidden position-relative">
                            <img src="{{ route('sales.proofs.preview', $foto) }}" alt="Bukti"
                                 class="w-100" style="height:110px;object-fit:cover">
                            <span class="badge bg-{{ $foto->status_color }} position-absolute top-0 start-0 m-1"
                                  style="font-size:.6rem">{{ $foto->status_label }}</span>
                        </a>
                    </div>
                    @endforeach
                </div>
                @endif

                @if($sudahSelesai)
                    <div class="alert alert-success border-0 small mb-0 py-2">
                        <i class="bi bi-check2-circle me-1"></i> Bukti sudah diverifikasi Logistik. Pesanan selesai.
                    </div>
                @elseif(! $bolehUnggah)
                    <div class="text-muted small">Bukti bisa diunggah setelah barang berangkat.</div>
                @elseif($sisaKuotaBukti < 1)
                    <div class="text-muted small">
                        Sudah ada {{ \App\Models\DeliveryProof::maksFoto() }} foto yang berlaku. Menunggu diperiksa Logistik.
                    </div>
                @else
                <form method="POST" action="{{ route('sales.proofs.store', $order) }}" enctype="multipart/form-data">
                    @csrf
                    {{-- DUA TOMBOL, SATU FORMULIR. `capture="environment"`
                         membuka kamera belakang langsung (F-OUT-05 #3);
                         tombol kedua tanpa atribut itu membuka galeri, untuk
                         foto yang tadi sudah terlanjur diambil pakai aplikasi
                         kamera biasa. Keduanya menulis ke input yang sama. --}}
                    <input type="file" name="photos[]" id="buktiKamera" accept="image/jpeg,image/png"
                           capture="environment" class="d-none">
                    <input type="file" name="photos[]" id="buktiGaleri" accept="image/jpeg,image/png"
                           multiple class="d-none">

                    <div class="d-grid gap-2 d-sm-flex">
                        <button type="button" class="btn btn-primary rounded-3 flex-fill"
                                onclick="document.getElementById('buktiKamera').click()">
                            <i class="bi bi-camera-fill me-1"></i> Buka Kamera
                        </button>
                        <button type="button" class="btn btn-outline-primary rounded-3 flex-fill"
                                onclick="document.getElementById('buktiGaleri').click()">
                            <i class="bi bi-images me-1"></i> Pilih dari Galeri
                        </button>
                    </div>

                    <div id="buktiTerpilih" class="small text-muted mt-2"></div>

                    <button type="submit" id="buktiKirim" class="btn btn-success rounded-3 w-100 mt-2 d-none">
                        <i class="bi bi-upload me-1"></i> Kirim Bukti
                    </button>

                    <div class="form-text mt-2">
                        JPG atau PNG, maksimal 5 MB per foto. Sisa kuota: {{ $sisaKuotaBukti }} foto.
                    </div>
                </form>

                <script>
                (function () {
                    var kamera = document.getElementById('buktiKamera');
                    var galeri = document.getElementById('buktiGaleri');
                    var kirim = document.getElementById('buktiKirim');
                    var label = document.getElementById('buktiTerpilih');
                    var sisa = {{ $sisaKuotaBukti }};

                    function perbarui(sumber) {
                        // Hanya satu sumber yang dikirim: kalau keduanya terisi,
                        // jumlah berkasnya bisa melebihi kuota dan unggahannya
                        // ditolak setelah menunggu lama di jaringan seluler.
                        var lain = sumber === kamera ? galeri : kamera;
                        lain.value = '';

                        var berkas = sumber.files;
                        if (!berkas || berkas.length === 0) {
                            kirim.classList.add('d-none');
                            label.textContent = '';
                            return;
                        }

                        if (berkas.length > sisa) {
                            label.textContent = 'Terlalu banyak: sisa kuota hanya ' + sisa + ' foto.';
                            label.className = 'small text-danger mt-2';
                            kirim.classList.add('d-none');
                            return;
                        }

                        var nama = [];
                        for (var i = 0; i < berkas.length; i++) { nama.push(berkas[i].name); }
                        label.textContent = berkas.length + ' foto dipilih: ' + nama.join(', ');
                        label.className = 'small text-muted mt-2';
                        kirim.classList.remove('d-none');
                    }

                    kamera.addEventListener('change', function () { perbarui(kamera); });
                    galeri.addEventListener('change', function () { perbarui(galeri); });
                })();
                </script>
                @endif
            </div>
        </div>
        @endif


        {{-- ============ PENOLAKAN CUSTOMER (Fase 7) ============

             DITARUH TEPAT DI BAWAH BUKTI SURAT JALAN, dan itu seluruh
             alasannya ada di sini: keduanya dikerjakan dalam SATU kunjungan.
             Sales berdiri di depan toko, memotret Surat Jalan, dan pada saat
             itu juga tahu barang mana yang tidak diterima. Memisahkannya ke
             halaman lain berarti ia harus mengingat lalu kembali lagi nanti —
             dan yang tidak dilaporkan hari itu biasanya tidak pernah
             dilaporkan sama sekali. --}}
        @if($laporanTolak)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-arrow-return-left text-danger me-2"></i>Penolakan Customer
                </h6>
                <small class="text-muted">{{ $laporanTolak->reference }}</small>
            </div>
            <div class="card-body px-3 px-md-4">
                <span class="badge bg-{{ $laporanTolak->status_color }}-subtle text-{{ $laporanTolak->status_color }}-emphasis rounded-pill mb-2">
                    {{ $laporanTolak->status_label }}
                </span>

                <ul class="list-unstyled small mb-2">
                    @foreach($laporanTolak->details as $b)
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span>{{ $b->product?->sku }}</span>
                            <span class="fw-semibold">
                                {{ $b->qty_rejected }}
                                @if($b->qty_approved !== null && $b->qty_approved !== $b->qty_rejected)
                                    <span class="text-muted">&rarr; disetujui {{ $b->qty_approved }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <p class="small text-muted mb-0">{{ $laporanTolak->reason }}</p>

                {{-- Alasan Logistik menolak laporan WAJIB terlihat di sini.
                     Tanpa itu, satu-satunya cara Sales tahu kenapa klaimnya
                     tidak diterima adalah menelepon. --}}
                @if($laporanTolak->status === \App\Models\SalesReturn::STATUS_REJECTED && $laporanTolak->approval_note)
                    <div class="alert alert-danger border-0 small mt-2 mb-0 py-2">
                        <strong>Laporan ditolak Logistik:</strong> {{ $laporanTolak->approval_note }}
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Foto Surat Jalan belum ada. Formulirnya BELUM DIBUKA, dan
             alasannya dikatakan — bukan dibiarkan jadi kartu yang hilang.
             Laporan penolakan adalah tagihan barang kembali ke gudang, dan
             Logistik yang menilainya tidak ikut ke toko: satu-satunya hal
             yang bisa ia periksa adalah Surat Jalan bertanda tangan. --}}
        @if($perluBuktiDulu && ! $laporanTolak)
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-body px-3 px-md-4 py-3">
                <div class="d-flex gap-2">
                    <i class="bi bi-arrow-return-left text-muted fs-5"></i>
                    <div class="small">
                        <div class="fw-semibold text-dark mb-1">Ada barang yang ditolak customer?</div>
                        <span class="text-muted">
                            Unggah dulu foto Surat Jalan yang sudah ditandatangani pelanggan di kartu
                            di atas. Logistik menilai laporan penolakan bersama foto itu, jadi
                            formulirnya baru terbuka setelah fotonya ada.
                        </span>
                    </div>
                </div>
            </div>
        </div>
        @endif

        @if($bolehLaporTolak && ! $laporanTolak)
        @php
            /*
             * Hanya barang yang BENAR-BENAR BERANGKAT yang bisa ditolak.
             * Daftar ini juga jadi satu-satunya sumber pilihan di formulir,
             * sehingga Sales tidak mungkin melaporkan produk yang tidak
             * pernah ada di Surat Jalan pesanan ini.
             */
            $bisaDitolak = $order->details
                ->filter(fn ($d) => (int) ($d->qty_shipped ?? 0) > 0)
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'sku' => (string) $d->product?->sku,
                    'nama' => (string) $d->product?->name,
                    'uom' => (string) $d->product?->uom,
                    'max' => (int) $d->qty_shipped,
                ])
                ->values();
        @endphp
        @if($bisaDitolak->isNotEmpty())
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-arrow-return-left text-danger me-2"></i>Penolakan Customer
                </h6>
                <small class="text-muted">Isi hanya kalau ada barang yang tidak diterima.</small>
            </div>
            <div class="card-body px-3 px-md-4">
                {{-- TERTUTUP DULU, dan itu bukan sekadar soal rapi.
                     Pesanan dua puluh item berarti dua puluh kolom angka
                     yang harus digulir setiap kali halaman ini dibuka,
                     padahal penolakan itu perkara yang jarang. Yang sering
                     terjadi harus pendek; yang jarang boleh butuh satu klik. --}}
                <button type="button" id="bukaTolak" class="btn btn-outline-danger rounded-3 w-100">
                    <i class="bi bi-exclamation-triangle me-1"></i> Ada barang yang ditolak
                </button>

                <form method="POST" action="/sales/report-return" id="formTolak" class="d-none">
                    @csrf
                    <input type="hidden" name="order_id" value="{{ $order->id }}">

                    {{-- Sales MENGETIK item yang ditolak, bukan memilih dari
                         seluruh isi pesanan. Yang ditolak biasanya satu-dua
                         baris; menampilkan semuanya membuat Sales mencari
                         di antara belasan baris yang tidak ia butuhkan. --}}
                    <div id="daftarTolak" class="mt-3"></div>

                    <button type="button" class="btn btn-outline-danger btn-sm rounded-3 w-100 mb-3" id="tambahTolak">
                        <i class="bi bi-plus-lg me-1"></i> Tambah Item
                    </button>

                    <label class="form-label small fw-semibold">
                        Kenapa ditolak? <span class="text-danger">*</span>
                    </label>
                    {{-- Wajib dan tidak boleh sepatah kata: Logistik yang
                         menilai klaim ini tidak ikut ke toko, dan "ditolak"
                         saja tidak memberinya apa pun untuk dinilai. --}}
                    <textarea name="reason" class="form-control mb-2" rows="3" minlength="10"
                              placeholder="Mis. warna tidak sesuai contoh, tutup penyok saat diturunkan."></textarea>

                    <div class="alert alert-warning border-0 small py-2 mb-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Barangnya akan ditagih kembali ke gudang. Logistik memeriksa laporan ini
                        bersama foto Surat Jalan Anda.
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light rounded-3" id="batalTolak">Batal</button>
                        <button class="btn btn-danger rounded-3 flex-grow-1">
                            <i class="bi bi-send me-1"></i> Laporkan Penolakan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Cetakan satu baris. Ditaruh di <template> supaya baris kosongnya
             tidak ikut terkirim sebagai isian saat form disubmit. --}}
        <template id="templateTolak">
            <div class="border rounded-3 p-2 mb-2 baris-tolak">
                <div class="d-flex gap-2 align-items-start">
                    <div class="flex-grow-1 position-relative" style="min-width:0">
                        <input type="text" class="form-control form-control-sm tolak-cari" autocomplete="off"
                               placeholder="Ketik SKU atau nama produk...">
                        <div class="list-group position-absolute w-100 shadow tolak-saran d-none"
                             style="z-index:1050; max-height:220px; overflow-y:auto;"></div>
                        <small class="text-muted tolak-info d-none d-block mt-1"></small>
                    </div>
                    <input type="number" class="form-control form-control-sm text-center tolak-qty flex-shrink-0"
                           style="width:74px" min="1" placeholder="Qty" inputmode="numeric" disabled>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0 tolak-hapus"
                            title="Hapus baris"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
        </template>
        @endif
        @endif

        {{-- DIBUATKAN ORANG LAIN — dan Sales harus tahu.

             Tanpa kotak ini, pesanan muncul di daftarnya tanpa penjelasan apa
             pun dan Sales menyimpulkan sendiri: entah ia lupa membuatnya,
             entah sistemnya kacau. Keduanya salah, dan keduanya membuatnya
             diam. Padahal sejak pembuat pesanan boleh menyetujui pesanannya
             sendiri, Sales inilah satu-satunya orang di luar rantai itu yang
             bisa menyadari kalau ada yang tidak beres. --}}
        @if($order->dibuatkanOrangLain())
            <div class="alert alert-warning border-0 rounded-4 d-flex gap-3 align-items-start mb-3">
                <i class="bi bi-person-badge fs-4 mt-1"></i>
                <div class="small">
                    <strong class="d-block mb-1">
                        Pesanan ini dibuat {{ $order->placedBy?->full_name ?? 'tim internal' }}, bukan oleh Anda.
                    </strong>
                    <div class="mb-1">Alasan: {{ $order->placed_reason }}</div>
                    Pesanannya tetap tercatat atas nama Anda — termasuk unggah foto Surat Jalan
                    bertanda tangan nanti. Kalau menurut Anda ada yang keliru, hubungi
                    {{ $order->placedBy?->full_name ?? 'pembuatnya' }} atau Manager Anda.
                </div>
            </div>
        @endif

        <!-- ============ Item pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4 mb-3">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Item Pesanan</h6>
            </div>

            @if($order->details->isEmpty())
                <div class="card-body text-center text-muted py-4">
                    @if($order->isDocumentBased())
                        <i class="bi bi-file-earmark-arrow-up fs-2 d-block mb-2 opacity-50"></i>
                        Rincian item diisi tim Logistik berdasarkan dokumen yang Anda unggah.
                    @else
                        <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                        Draft ini belum punya item pesanan.
                    @endif
                </div>
            @else
                {{-- Daftar, BUKAN tabel. Tabel enam kolom memaksa gulir
                     mendatar di layar HP dan angka qty-nya jatuh di luar
                     layar — justru angka itu yang paling dicari. --}}
                <ul class="list-group list-group-flush">
                    @foreach($order->details as $d)
                        @php
                            /*
                             * ANGKA BESAR DI KANAN = YANG BENAR-BENAR BERANGKAT.
                             * Sesudah barangnya jalan, itulah angka yang dicari
                             * Sales lebih dulu: berapa yang sungguh sampai ke
                             * pelanggan. Sebelum berangkat belum ada apa pun untuk
                             * dilaporkan, jadi yang ditampilkan qty pesanannya.
                             *
                             * Istilah "Disetujui" sengaja tidak lagi muncul. Sales
                             * tidak menagih dengan angka persetujuan, dan dua angka
                             * berdampingan yang sama-sama bukan "terkirim" justru
                             * membuat barisnya tidak terbaca.
                             */
                            $sudahJalan = $order->shipped_at !== null;
                            $angkaUtama = $sudahJalan ? (int) $d->qty_shipped : (int) $d->qty_ordered;
                            $outstanding = (int) $d->outstanding_qty;
                        @endphp
                        <li class="list-group-item px-3 px-md-4 py-3">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div style="min-width: 0;">
                                    <div class="fw-semibold text-dark">{{ $d->product?->name }}</div>
                                    <small class="font-monospace text-muted">{{ $d->product?->sku }}</small>
                                </div>
                                {{-- Diberi label, karena artinya BERUBAH begitu
                                     pesanan berangkat. Angka telanjang yang diam-diam
                                     berganti makna lebih buruk daripada tidak ada. --}}
                                <div class="text-center flex-shrink-0">
                                    <span class="badge bg-primary rounded-pill">
                                        {{ number_format($angkaUtama) }}
                                    </span>
                                    <small class="text-muted d-block" style="font-size:.7rem">
                                        {{ $sudahJalan ? 'terkirim' : 'dipesan' }}
                                    </small>
                                </div>
                            </div>

                            @if($outstanding > 0)
                                {{-- Outstanding, bukan "tidak terpenuhi". Kata itu
                                     terdengar seperti kasus yang sudah ditutup,
                                     padahal sisanya masih jadi utang ke pelanggan
                                     dan sewaktu-waktu dijadwalkan Logistik untuk
                                     Pengiriman Ulang. --}}
                                <div class="mt-2 small">
                                    <span class="text-danger fw-semibold">
                                        <i class="bi bi-hourglass-split me-1"></i>
                                        Outstanding {{ number_format($outstanding) }}
                                    </span>
                                    <span class="text-muted">
                                        dari {{ number_format($d->qty_ordered) }} dipesan.
                                    </span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <!-- ============ Rincian pesanan ============ -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-3 px-3 px-md-4">
                <h6 class="fw-bold mb-0"><i class="bi bi-info-circle text-primary me-2"></i>Rincian Pesanan</h6>
            </div>
            <div class="card-body p-3 p-md-4 pt-2">
                {{-- Pasangan label/nilai berjajar, bukan tabel: di layar sempit
                     tiap baris tetap terbaca tanpa gulir mendatar. --}}
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">Gudang Tujuan</span>
                    <span class="fw-semibold text-end">{{ $order->warehouse?->name }}</span>
                </div>
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">Pembayaran</span>
                    <span class="fw-semibold text-end">{{ $order->paymentTerm?->name }}</span>
                </div>
                @if($order->customer_po_number)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">No. PO Customer</span>
                    <span class="fw-semibold font-monospace text-end">{{ $order->customer_po_number }}</span>
                </div>
                @endif
                @if($order->bc_so_number)
                <div class="d-flex justify-content-between border-bottom py-2 small">
                    <span class="text-muted">No. SO (BC)</span>
                    <span class="fw-semibold font-monospace text-end">{{ $order->bc_so_number }}</span>
                </div>
                @endif
                <div class="d-flex justify-content-between {{ $order->notes ? 'border-bottom' : '' }} py-2 small">
                    <span class="text-muted">Dibuat</span>
                    <span class="fw-semibold text-end">{{ $order->created_at->translatedFormat('d M Y, H:i') }}</span>
                </div>
                @if($order->notes)
                <div class="py-2 small">
                    <div class="text-muted mb-1">Catatan</div>
                    <div>{{ $order->notes }}</div>
                </div>
                @endif

                @if($order->document_name)
                    <a href="{{ url('/sales/orders/'.$order->id.'/document') }}"
                       class="btn btn-sm btn-outline-secondary w-100 mt-3 text-truncate">
                        <i class="bi bi-paperclip me-1"></i>{{ $order->document_name }}
                    </a>
                @endif
            </div>
        </div>

        {{-- Aksi draft diletakkan di BAWAH, dalam jangkauan ibu jari saat
             HP dipegang satu tangan. --}}
        @if($order->isEditable())
            <div class="d-flex gap-2 mt-3">
                <a href="{{ url('/sales/orders/'.$order->id.'/edit') }}" class="btn btn-outline-secondary flex-grow-1">
                    <i class="bi bi-pencil me-1"></i>Ubah Draft
                </a>
                <form method="POST" action="{{ url('/sales/orders/'.$order->id.'/submit') }}" class="flex-grow-1">
                    @csrf
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="bi bi-send me-1"></i>Kirim
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection

@if($bolehLaporTolak && ! $laporanTolak && ($bisaDitolak ?? collect())->isNotEmpty())
@push('scripts')
<script>
/*
 * Formulir lapor penolakan.
 *
 * Sumber pilihannya adalah isi Surat Jalan pesanan INI, bukan katalog
 * produk. Jadi pencariannya cukup di sisi klien — datanya paling banyak
 * sejumlah baris pesanan, dan tidak ada gunanya menembak server untuk
 * daftar yang sudah ada di halaman.
 */
(function () {
    const ITEM = @json($bisaDitolak);

    const kartu = document.getElementById('formTolak');
    const buka = document.getElementById('bukaTolak');
    const batal = document.getElementById('batalTolak');
    const daftar = document.getElementById('daftarTolak');
    const tambah = document.getElementById('tambahTolak');
    const cetakan = document.getElementById('templateTolak');

    if (!kartu || !buka || !daftar || !tambah || !cetakan) return;

    // Item yang sudah dipakai baris lain. Satu produk tidak boleh dilaporkan
    // dua kali — dua baris untuk SKU yang sama akan saling menimpa di server
    // (kuncinya id baris pesanan), dan Sales tidak akan tahu mana yang menang.
    function terpakai() {
        return Array.from(daftar.querySelectorAll('.tolak-qty'))
            .map((q) => q.dataset.detailId)
            .filter(Boolean);
    }

    function tutupSemuaSaran() {
        daftar.querySelectorAll('.tolak-saran').forEach((s) => s.classList.add('d-none'));
    }

    function pasangBaris() {
        const baris = cetakan.content.firstElementChild.cloneNode(true);
        const cari = baris.querySelector('.tolak-cari');
        const saran = baris.querySelector('.tolak-saran');
        const qty = baris.querySelector('.tolak-qty');
        const info = baris.querySelector('.tolak-info');

        function pilih(item) {
            cari.value = item.sku + ' — ' + item.nama;
            qty.disabled = false;
            qty.max = item.max;
            qty.dataset.detailId = item.id;
            qty.name = 'qty[' + item.id + ']';
            info.textContent = 'Terkirim ' + item.max + ' ' + item.uom + ' — maksimal sebanyak itu yang bisa ditolak.';
            info.classList.remove('d-none');
            saran.classList.add('d-none');
            qty.focus();
        }

        function lupakanPilihan() {
            qty.disabled = true;
            qty.value = '';
            qty.removeAttribute('name');
            delete qty.dataset.detailId;
            info.classList.add('d-none');
        }

        cari.addEventListener('input', function () {
            lupakanPilihan();

            const kata = cari.value.trim().toLowerCase();
            const dipakai = terpakai();
            const cocok = ITEM.filter((i) =>
                !dipakai.includes(String(i.id))
                && (kata === '' || i.sku.toLowerCase().includes(kata) || i.nama.toLowerCase().includes(kata)));

            saran.innerHTML = '';

            if (cocok.length === 0) {
                saran.classList.add('d-none');
                return;
            }

            cocok.slice(0, 8).forEach((i) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'list-group-item list-group-item-action py-2';
                b.innerHTML = '<span class="fw-semibold small d-block text-truncate"></span>'
                    + '<small class="text-muted font-monospace"></small>';
                b.querySelector('.fw-semibold').textContent = i.nama;
                b.querySelector('small').textContent = i.sku + ' · terkirim ' + i.max + ' ' + i.uom;
                b.addEventListener('mousedown', (e) => { e.preventDefault(); pilih(i); });
                saran.appendChild(b);
            });

            saran.classList.remove('d-none');
        });

        // Klik kolomnya langsung menampilkan seluruh sisa item: pesanan kecil
        // tidak perlu diketik sama sekali.
        cari.addEventListener('focus', function () {
            cari.dispatchEvent(new Event('input'));
        });

        cari.addEventListener('blur', function () {
            setTimeout(() => saran.classList.add('d-none'), 120);
        });

        baris.querySelector('.tolak-hapus').addEventListener('click', function () {
            baris.remove();
            if (daftar.children.length === 0) pasangBaris();
        });

        daftar.appendChild(baris);
        return baris;
    }

    buka.addEventListener('click', function () {
        buka.classList.add('d-none');
        kartu.classList.remove('d-none');
        if (daftar.children.length === 0) pasangBaris();
        daftar.querySelector('.tolak-cari')?.focus();
    });

    batal?.addEventListener('click', function () {
        kartu.classList.add('d-none');
        buka.classList.remove('d-none');
        daftar.innerHTML = '';
        kartu.querySelector('textarea[name="reason"]').value = '';
    });

    tambah.addEventListener('click', function () {
        tutupSemuaSaran();
        pasangBaris().querySelector('.tolak-cari').focus();
    });

    // Menahan submit yang pasti ditolak server. Pesan "jumlah yang ditolak
    // wajib diisi" setelah halaman ter-reload jauh lebih membingungkan
    // daripada dikatakan di tempat.
    kartu.addEventListener('submit', function (e) {
        const terisi = Array.from(daftar.querySelectorAll('.tolak-qty'))
            .filter((q) => q.dataset.detailId && parseInt(q.value || '0', 10) > 0);

        if (terisi.length === 0) {
            e.preventDefault();
            alert('Pilih dulu barang yang ditolak dan isi jumlahnya.');
            return;
        }

        const lebih = terisi.find((q) => parseInt(q.value, 10) > parseInt(q.max, 10));

        if (lebih) {
            e.preventDefault();
            alert('Jumlah yang ditolak tidak boleh lebih banyak daripada yang dikirim.');
        }
    });
})();
</script>
@endpush
@endif
