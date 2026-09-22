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
