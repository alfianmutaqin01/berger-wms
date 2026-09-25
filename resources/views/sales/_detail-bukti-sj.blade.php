        <!-- ============ Bukti Surat Jalan (F-OUT-05) ============ -->
        @php
            // Cerminan ProofOfDelivery::BOLEH_UNGGAH. Keduanya WAJIB berubah
            // bersamaan: yang di sini hanya menyembunyikan formulir, yang
            // menolak unggahannya ada di sana.
            $bolehUnggah = $order->status === \App\Models\SalesOrder::STATUS_PROOF_UPLOADED
                && $order->cancelled_at === null;

            // Dibedakan dari "belum berangkat": yang ini sudah jalan dan
            // tinggal menunggu satu ketukan supir.
            $masihDiJalan = $order->status === \App\Models\SalesOrder::STATUS_SHIPPING;

            $sudahSelesai = in_array($order->status, [
                \App\Models\SalesOrder::STATUS_COMPLETED,
                \App\Models\SalesOrder::STATUS_COMPLETED_BILLING,
            ], true);
        @endphp

        {{-- Yang masih di jalan tetap menampilkan kartunya, berisi keterangan
             kenapa tombolnya belum ada. Menyembunyikannya sama sekali membuat
             Sales mengira fiturnya hilang lalu menelepon Logistik. --}}
        @if($bolehUnggah || $masihDiJalan || $bukti->isNotEmpty())
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
                @elseif($masihDiJalan)
                    <div class="alert alert-info border-0 small mb-0 py-2">
                        <i class="bi bi-truck me-1"></i>
                        Barang masih dalam perjalanan. Unggahan bukti terbuka setelah supir menekan
                        <strong>&ldquo;sampai di tujuan&rdquo;</strong> &mdash; Anda akan dikabari lewat lonceng
                        dan WhatsApp saat itu terjadi.
                    </div>
                @elseif(! $bolehUnggah)
                    <div class="text-muted small">Bukti bisa diunggah setelah barang dinyatakan sampai.</div>
                @elseif($sisaKuotaBukti < 1)
                    <div class="text-muted small">
                        Sudah ada {{ \App\Models\DeliveryProof::maksFoto() }} foto yang berlaku. Menunggu diperiksa Logistik.
                    </div>
                @else
                {{-- KAMERA DIBUKA DI DALAM HALAMAN, seperti halaman ePOD supir.

                     Sebelumnya di sini hanya ada `<input capture>`, dan itu
                     dua kali keliru. Pertama, `capture` DIABAIKAN peramban
                     desktop — yang terbuka pemilih berkas, bukan kamera, dan
                     itulah yang terlihat sebagai "kameranya tidak jalan".
                     Kedua, dan ini yang lebih merugikan: satu input `capture`
                     hanya sanggup memegang SATU foto. Setiap jepretan baru
                     menimpa yang sebelumnya, sehingga Surat Jalan dua halaman
                     tidak pernah bisa dikirim utuh.

                     Sekarang fotonya DIKUMPULKAN di sisi peramban: berapa pun
                     kali tombol jepret ditekan, hasilnya bertambah, bukan
                     menggantikan. --}}
                <form method="POST" action="{{ route('sales.proofs.store', $order) }}"
                      enctype="multipart/form-data" id="formBukti">
                    @csrf

                    <div class="border rounded-4 overflow-hidden mb-2 bg-dark position-relative"
                         id="kotakKamera" style="aspect-ratio: 4/3;">
                        <video id="kamera" class="w-100 h-100" style="object-fit: cover;"
                               autoplay playsinline muted></video>
                        <canvas id="kanvas" class="d-none"></canvas>

                        <div id="kameraMati"
                             class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column
                                    align-items-center justify-content-center text-white-50 p-3 text-center">
                            <i class="bi bi-camera fs-1 mb-2"></i>
                            <small>Menyiapkan kamera…</small>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-sm-flex mb-2">
                        <button type="button" class="btn btn-primary rounded-3 flex-fill" id="tombolJepret" disabled>
                            <i class="bi bi-camera-fill me-1"></i> Ambil Foto
                        </button>
                        <button type="button" class="btn btn-outline-primary rounded-3 flex-fill" id="tombolGaleri">
                            <i class="bi bi-images me-1"></i> Pilih dari Galeri
                        </button>
                    </div>

                    {{-- JALUR CADANGAN, alasannya sama dengan di halaman ePOD:
                         kamera dalam halaman hanya hidup di koneksi aman dan
                         setelah izinnya diberikan. Satu penolakan izin tidak
                         boleh membuat bukti yang sudah ada di tangan Sales
                         tidak bisa dikirim sama sekali. --}}
                    <div class="d-none" id="kotakCadangan">
                        <div class="alert alert-warning border-0 rounded-3 small py-2">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            <span id="alasanCadangan">Kamera tidak bisa dibuka di peramban ini.</span>
                            Gunakan aplikasi kamera HP Anda.
                        </div>
                        <button type="button" class="btn btn-primary rounded-3 w-100 mb-2" id="tombolKameraHp">
                            <i class="bi bi-camera-fill me-1"></i> Buka Kamera HP
                        </button>
                    </div>

                    <input type="file" id="buktiKameraHp" accept="image/jpeg,image/png"
                           capture="environment" class="d-none">
                    <input type="file" id="buktiGaleri" accept="image/jpeg,image/png" multiple class="d-none">

                    {{-- SATU-SATUNYA medan yang benar-benar terkirim. Ketiga
                         jalur di atas hanya pengumpul; isinya disalin ke sini
                         lewat DataTransfer supaya server tidak pernah harus
                         menebak medan mana yang dimaksud. --}}
                    <input type="file" name="photos[]" id="buktiTerkumpul" multiple class="d-none">

                    <div class="row g-2" id="daftarFoto"></div>
                    <div id="buktiPesan" class="small text-muted mt-2"></div>

                    <button type="submit" id="buktiKirim" class="btn btn-success rounded-3 w-100 mt-2" disabled>
                        <i class="bi bi-upload me-1"></i> Kirim Bukti
                    </button>

                    <div class="form-text mt-2">
                        JPG atau PNG, maksimal 5 MB per foto. Sisa kuota: {{ $sisaKuotaBukti }} foto.
                    </div>
                </form>

                <script>
                (function () {
                    var SISA = {{ $sisaKuotaBukti }};
                    var MAKS_BYTE = {{ \App\Models\DeliveryProof::MAKS_UKURAN_KB }} * 1024;

                    var video    = document.getElementById('kamera');
                    var kanvas   = document.getElementById('kanvas');
                    var kotakKam = document.getElementById('kotakKamera');
                    var kamMati  = document.getElementById('kameraMati');
                    var jepret   = document.getElementById('tombolJepret');
                    var galeri   = document.getElementById('tombolGaleri');
                    var cadangan = document.getElementById('kotakCadangan');
                    var alasan   = document.getElementById('alasanCadangan');
                    var kameraHp = document.getElementById('buktiKameraHp');
                    var tblKamHp = document.getElementById('tombolKameraHp');
                    var pilihan  = document.getElementById('buktiGaleri');
                    var terkumpul = document.getElementById('buktiTerkumpul');
                    var daftar   = document.getElementById('daftarFoto');
                    var pesan    = document.getElementById('buktiPesan');
                    var kirim    = document.getElementById('buktiKirim');

                    var kumpulan = [];
                    var aliran = null;

                    function keCadangan(teks) {
                        if (teks) { alasan.textContent = teks; }
                        kotakKam.classList.add('d-none');
                        jepret.classList.add('d-none');
                        cadangan.classList.remove('d-none');
                    }

                    function beriTahu(teks, salah) {
                        pesan.textContent = teks || '';
                        pesan.className = 'small mt-2 ' + (salah ? 'text-danger' : 'text-muted');
                    }

                    /** Menyalin kumpulan ke input yang dikirim, lalu menggambar ulang petiknya. */
                    function segarkan() {
                        var dt = new DataTransfer();
                        kumpulan.forEach(function (f) { dt.items.add(f.file); });
                        terkumpul.files = dt.files;

                        daftar.innerHTML = '';
                        kumpulan.forEach(function (f, i) {
                            var kolom = document.createElement('div');
                            kolom.className = 'col-4';
                            kolom.innerHTML =
                                '<div class="border rounded-3 overflow-hidden position-relative">' +
                                    '<img src="' + f.url + '" alt="" class="w-100" style="height:110px;object-fit:cover">' +
                                    '<button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 py-0 px-1" ' +
                                            'data-hapus="' + i + '" aria-label="Hapus foto">&times;</button>' +
                                '</div>';
                            daftar.appendChild(kolom);
                        });

                        kirim.disabled = kumpulan.length === 0;

                        // Tombol jepret dimatikan saat kuota habis, bukan
                        // dibiarkan lalu ditolak server setelah unggahan
                        // panjang di sinyal seluler.
                        var penuh = kumpulan.length >= SISA;
                        jepret.disabled = penuh || !aliran;
                        galeri.disabled = penuh;
                        tblKamHp.disabled = penuh;

                        if (penuh) {
                            beriTahu('Kuota ' + SISA + ' foto sudah terpakai.');
                        } else if (kumpulan.length > 0) {
                            beriTahu(kumpulan.length + ' foto siap dikirim, sisa kuota ' + (SISA - kumpulan.length) + '.');
                        } else {
                            beriTahu('');
                        }
                    }

                    function tambah(berkas) {
                        var ditolak = 0;

                        for (var i = 0; i < berkas.length; i++) {
                            if (kumpulan.length >= SISA) {
                                beriTahu('Sisa kuota hanya ' + SISA + ' foto; sebagian tidak ikut ditambahkan.', true);
                                break;
                            }
                            if (berkas[i].size > MAKS_BYTE) { ditolak++; continue; }

                            kumpulan.push({ file: berkas[i], url: URL.createObjectURL(berkas[i]) });
                        }

                        segarkan();

                        if (ditolak > 0) {
                            beriTahu(ditolak + ' foto dilewati karena ukurannya lebih dari 5 MB.', true);
                        }
                    }

                    daftar.addEventListener('click', function (e) {
                        var tombol = e.target.closest('[data-hapus]');
                        if (!tombol) { return; }

                        var i = parseInt(tombol.getAttribute('data-hapus'), 10);
                        // Alamat objeknya dilepas: di HP, puluhan pratinjau
                        // yang tidak pernah dilepas membuat tab-nya dimatikan
                        // sistem tepat saat tombol kirim ditekan.
                        URL.revokeObjectURL(kumpulan[i].url);
                        kumpulan.splice(i, 1);
                        segarkan();
                    });

                    galeri.addEventListener('click', function () { pilihan.click(); });
                    tblKamHp.addEventListener('click', function () { kameraHp.click(); });

                    [pilihan, kameraHp].forEach(function (input) {
                        input.addEventListener('change', function () {
                            tambah(input.files);
                            // Dikosongkan supaya foto yang sama bisa dipilih
                            // lagi, dan supaya kamera HP bisa dibuka berkali-
                            // kali untuk halaman Surat Jalan berikutnya.
                            input.value = '';
                        });
                    });

                    jepret.addEventListener('click', function () {
                        if (!video.videoWidth) { return; }

                        // Dikecilkan ke maksimal 2000px — bukan 1600 seperti
                        // foto supir. Yang difoto di sini DOKUMEN: ada kolom
                        // qty berangka kecil dan tanda tangan. Di bawah 2000px
                        // angkanya mulai pecah, dan Logistik menolak foto yang
                        // sebenarnya benar.
                        var skala = Math.min(1, 2000 / video.videoWidth);
                        kanvas.width  = Math.round(video.videoWidth * skala);
                        kanvas.height = Math.round(video.videoHeight * skala);
                        kanvas.getContext('2d').drawImage(video, 0, 0, kanvas.width, kanvas.height);

                        kanvas.toBlob(function (blob) {
                            if (!blob) { keCadangan('Foto gagal diambil dari kamera.'); return; }

                            var nama = 'bukti-sj-' + (kumpulan.length + 1) + '.jpg';
                            tambah([new File([blob], nama, { type: 'image/jpeg' })]);
                        }, 'image/jpeg', 0.85);
                    });

                    function mulaiKamera() {
                        if (!window.DataTransfer || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                            keCadangan(location.protocol === 'https:' || location.hostname === 'localhost'
                                ? 'Peramban ini tidak mendukung kamera di dalam halaman.'
                                : 'Kamera hanya bisa dibuka lewat koneksi aman (https).');
                            return;
                        }

                        navigator.mediaDevices.getUserMedia({
                            // Diminta setinggi mungkin lalu dikecilkan sendiri:
                            // aliran bawaan sering hanya 640px, terlalu kasar
                            // untuk membaca angka di Surat Jalan.
                            video: { facingMode: { ideal: 'environment' }, width: { ideal: 2560 } },
                            audio: false
                        }).then(function (s) {
                            aliran = s;
                            video.srcObject = s;
                            kamMati.classList.add('d-none');
                            segarkan();
                        }).catch(function () {
                            keCadangan('Izin kamera belum diberikan.');
                        });
                    }

                    // Kamera dilepas saat halaman ditinggalkan; lampu kamera
                    // yang tetap menyala setelah Sales pindah halaman membuat
                    // orang mencurigai aplikasinya.
                    window.addEventListener('pagehide', function () {
                        if (aliran) { aliran.getTracks().forEach(function (t) { t.stop(); }); }
                    });

                    segarkan();
                    mulaiKamera();
                })();
                </script>
                @endif
            </div>
        </div>
        @endif
