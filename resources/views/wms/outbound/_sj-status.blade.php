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
                    @if($note->epod_to_customer)
                    {{-- Pada kiriman luar pulau, plat truk ke pelabuhan tidak
                         menjawab pertanyaan apa pun berminggu-minggu kemudian.
                         Nomor kontainernya yang menjawab, jadi itu yang
                         ditaruh di tempat paling terbaca. --}}
                    <dt class="col-5 text-muted fw-normal">Kontainer</dt>
                    <dd class="col-7 font-monospace">{{ $note->container_no ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Ekspedisi</dt>
                    <dd class="col-7">{{ $note->forwarder_name ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Penerima di toko</dt>
                    <dd class="col-7 font-monospace">{{ $note->customer_phone ?? '—' }}</dd>
                    <dt class="col-5 text-muted fw-normal">Perkiraan sampai</dt>
                    <dd class="col-7">{{ $note->eta_date?->translatedFormat('d M Y') ?? '—' }}</dd>
                    @else
                    <dt class="col-5 text-muted fw-normal">Kendaraan</dt>
                    <dd class="col-7">{{ $note->vehicle_plate ?? '—' }}</dd>
                    @endif
                    <dt class="col-5 text-muted fw-normal">Berangkat</dt>
                    <dd class="col-7">{{ $note->shipped_at?->format('d M Y H:i') ?? '—' }}</dd>
                    @if($note->delivered_at)
                    <dt class="col-5 text-muted fw-normal">Sampai</dt>
                    <dd class="col-7">
                        {{ $note->delivered_at->format('d M Y H:i') }}
                        @if($note->received_by_name)
                            <div class="text-muted">Diterima {{ $note->received_by_name }}</div>
                        @endif
                        {{-- DIBEDAKAN DARI KESAKSIAN SUPIR. Yang menandai
                             manual tidak berdiri di tempat tujuan; menampilkan
                             keduanya sama membuat orang yang memeriksa
                             pengiriman bermasalah salah membaca sumbernya. --}}
                        @if($note->arrival_manual_by)
                            <div class="mt-1">
                                <span class="badge bg-warning-subtle text-warning-emphasis">Ditandai manual oleh Logistik</span>
                                <div class="text-muted small mt-1">{{ $note->arrival_manual_reason }}</div>
                            </div>
                        @endif
                    </dd>
                    @endif
                </dl>

                {{-- MENUNGGU TANGGAL, BUKAN GAGAL — dan harus terbaca begitu.

                     Inilah keadaan normal sebuah kontainer selama berminggu-
                     minggu di laut: sudah berangkat, tautannya sengaja belum
                     ada. Tanpa panel ini, layarnya cuma menampilkan kekosongan
                     yang sama persis dengan pengiriman yang pesannya gagal
                     terkirim — dan Logistik akan mengejar sesuatu yang tidak
                     perlu dikejar. --}}
                @if($note->menungguTautanPelanggan())
                <div class="alert alert-info border-0 rounded-3 small">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-hourglass-split me-1"></i>
                        Menunggu perkiraan tanggal sampai
                    </div>
                    <div class="mb-2">
                        Tautan konfirmasi belum diterbitkan. Akan dikirim ke pelanggan
                        (<span class="font-monospace">{{ $note->customer_phone }}</span>) pada
                        <strong>{{ $note->eta_date?->translatedFormat('d F Y') ?? '—' }}</strong>,
                        lalu berlaku {{ (int) config('wms.epod.berlaku_jam') }} jam.
                    </div>
                    {{-- Tanggalnya tebakan yang dibuat saat memesan kontainer,
                         dan kapal tertahan adalah kejadian biasa. Tautan yang
                         terbit sebelum barangnya ada di toko membuat pelanggan
                         berhenti membaca pesan berikutnya. --}}
                    <form method="POST" action="{{ route('wms.delivery.perkiraan-sampai', $note) }}"
                          class="row g-2 align-items-end">
                        @csrf
                        <div class="col-12 col-sm-5">
                            <label class="form-label small text-muted mb-1">Geser ke tanggal</label>
                            <input type="date" name="eta_date" required
                                   min="{{ now()->toDateString() }}"
                                   max="{{ now()->addYear()->toDateString() }}"
                                   value="{{ old('eta_date', $note->eta_date?->toDateString()) }}"
                                   class="form-control form-control-sm rounded-3">
                        </div>
                        <div class="col-12 col-sm-7">
                            <label class="form-label small text-muted mb-1">Alasan</label>
                            <input type="text" name="alasan" maxlength="200" required
                                   value="{{ old('alasan') }}"
                                   class="form-control form-control-sm rounded-3"
                                   placeholder="mis. kapal tertahan di Tanjung Priok">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-outline-primary rounded-3">
                                <i class="bi bi-calendar-event me-1"></i> Geser perkiraan sampai
                            </button>
                        </div>
                    </form>
                </div>
                @endif

                {{-- JALAN KELUAR, bukan jalan pintas: hanya muncul pada
                     pengiriman yang sudah berangkat tetapi belum dikonfirmasi
                     supir. Tanpa ini, supir yang kehilangan tautannya membuat
                     pesanan itu macet tanpa ada yang bisa menutupnya. --}}
                @if($note->status === \App\Models\DeliveryNote::STATUS_SHIPPED)
                <div class="border rounded-3 p-3 mt-3 bg-light-subtle">
                    <div class="fw-semibold small mb-1">
                        {{ $note->epod_to_customer ? 'Pelanggan belum konfirmasi?' : 'Supir tidak bisa konfirmasi?' }}
                    </div>
                    <p class="text-muted small mb-2">
                        @if($note->epod_to_customer)
                            Pakai ini kalau barangnya sudah Anda pastikan diterima tetapi pelanggan tidak
                            menekan tautannya — sibuk, tautannya terlewat, atau nomornya sudah ganti.
                            Tercatat atas nama Anda.
                        @else
                            Pakai ini hanya kalau supir benar-benar tidak bisa menekan tautannya sendiri —
                            tautannya hilang, HP mati, atau nomornya salah. Tercatat atas nama Anda.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('wms.delivery.tandai-sampai', $note) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1">Nama penerima</label>
                            <input type="text" name="received_by_name" maxlength="100" required
                                   class="form-control form-control-sm rounded-3"
                                   value="{{ old('received_by_name') }}" placeholder="Siapa yang menerima barangnya">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-1">Alasan</label>
                            <textarea name="arrival_manual_reason" rows="2" maxlength="500" required
                                      class="form-control form-control-sm rounded-3"
                                      placeholder="Kenapa supir tidak menekan konfirmasinya sendiri">{{ old('arrival_manual_reason') }}</textarea>
                        </div>
                        <button class="btn btn-sm btn-outline-warning rounded-3">
                            <i class="bi bi-check2-circle me-1"></i> Tandai Sampai
                        </button>
                    </form>
                </div>
                @endif

                {{-- FOTO BUKTI SAMPAI (Fase 12).

                     Dikumpulkan supaya DILIHAT. Bukti yang tersimpan rapi
                     tetapi tidak pernah muncul di layar mana pun sama saja
                     dengan tidak dikumpulkan — dan Surat Jalan inilah layar
                     yang dibuka orang saat sebuah pengiriman dipersoalkan.

                     ASALNYA DITULIS APA ADANYA. 'camera' berarti dijepret di
                     halaman konfirmasi saat itu juga; 'file' berarti dipilih
                     dari HP lewat jalur cadangan, dan bisa saja foto lama.
                     Keduanya tidak sama kuat, jadi tidak ditampilkan sama. --}}
                @if($note->arrival_photo_path)
                    @php($dariKamera = $note->arrival_photo_source === \App\Support\Outbound\ArrivalPhoto::SUMBER_KAMERA)
                    <div class="border rounded-4 overflow-hidden mb-3">
                        <a href="{{ route('wms.delivery.arrival-photo', $note) }}" target="_blank" rel="noopener">
                            <img src="{{ route('wms.delivery.arrival-photo', $note) }}"
                                 class="w-100" style="max-height:220px;object-fit:cover"
                                 alt="Foto barang di lokasi">
                        </a>
                        <div class="px-3 py-2 bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span class="badge rounded-pill {{ $dariKamera ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' }}">
                                <i class="bi bi-{{ $dariKamera ? 'camera-fill' : 'paperclip' }} me-1"></i>
                                {{ $dariKamera ? 'Dijepret di lokasi' : 'Dari berkas HP' }}
                            </span>
                            <small class="text-muted">
                                {{ $note->arrival_photo_taken_at?->format('d M Y H:i') }}
                            </small>
                        </div>
                    </div>
                @elseif($note->delivered_at)
                    {{-- Pengiriman lama, dikonfirmasi sebelum aturan foto ada.
                         Dikatakan apa adanya alih-alih dibiarkan kosong: yang
                         mencari fotonya dan tidak menemukannya akan mengira
                         sistemnya rusak, padahal fotonya memang tidak pernah
                         diminta. --}}
                    <div class="alert alert-secondary border-0 rounded-4 small">
                        <i class="bi bi-camera-video-off me-1"></i>
                        Tidak ada foto bukti sampai. Pengiriman ini dikonfirmasi sebelum foto
                        diwajibkan.
                    </div>
                @endif

                @if($note->epod_token)
                {{-- STATUS PESAN TERPISAH DARI STATUS BARANG. Truk tidak
                     menunggu WhatsApp; tetapi kegagalannya harus terlihat,
                     karena supir yang tidak menerima tautan tidak akan pernah
                     mengonfirmasi apa pun. --}}
                @php($gagal = $note->notify_status === \App\Models\DeliveryNote::NOTIFY_FAILED)
                @php($manual = $note->notify_status === \App\Models\DeliveryNote::NOTIFY_MANUAL)
                @php($kedaluwarsa = $note->tautanEpodKedaluwarsa())
                @php($sebutanTautan = $note->epod_to_customer ? 'pelanggan' : 'supir')

                <div class="alert alert-{{ $gagal ? 'danger' : ($manual ? 'warning' : 'success') }} border-0 rounded-3 small">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-whatsapp me-1"></i> {{ $note->notify_label }}
                    </div>
                    @if($note->notify_error)
                        <div class="mb-2">{{ $note->notify_error }}</div>
                    @endif
                    @if($kedaluwarsa)
                        {{-- Tautan supir mati sendiri (audit keamanan). Tombol
                             WhatsApp dan salin disembunyikan: yang dikirim lewat
                             sana hanyalah halaman 404. --}}
                        <div class="mb-2 text-danger fw-semibold">
                            <i class="bi bi-clock-history me-1"></i>
                            Tautan {{ $sebutanTautan }} kedaluwarsa {{ $note->epod_expires_at?->translatedFormat('d M, H:i') }}. Terbitkan tautan baru bila barangnya belum dikonfirmasi sampai.
                        </div>
                    @elseif($note->epod_expires_at && $note->status === \App\Models\DeliveryNote::STATUS_SHIPPED)
                        <div class="mb-2 text-muted">Tautan {{ $sebutanTautan }} berlaku sampai {{ $note->epod_expires_at->translatedFormat('d M, H:i') }}.</div>
                    @endif
                    @if($manual)
                        <div class="mb-2">
                            Sistem belum tersambung ke penyedia WhatsApp, jadi pesannya dikirim dari WhatsApp Anda sendiri.
                        </div>
                    @endif

                    <div class="d-flex flex-wrap gap-2 mt-2">
                        {{-- nomorEpod(), bukan driver_phone: mode manual
                             mengirim pesannya lewat WhatsApp Logistik sendiri,
                             dan membuka percakapan ke supir yang sudah pulang
                             ke Karawang berarti tautan berisi nama pelanggan
                             mendarat di HP orang yang salah. --}}
                        @if($note->nomorEpod() && ! $kedaluwarsa)
                        <a class="btn btn-sm btn-success rounded-3"
                           href="https://wa.me/{{ $note->nomorEpod() }}?text={{ rawurlencode($note->epod_to_customer ? $note->pesanUntukPelanggan() : $note->pesanUntukSupir()) }}"
                           target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp me-1"></i> Buka WhatsApp
                        </a>
                        @endif

                        @unless($kedaluwarsa)
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-3" id="salinTautan"
                                data-tautan="{{ $note->epodUrl() }}">
                            <i class="bi bi-clipboard me-1"></i> Salin tautan
                        </button>
                        @endunless

                        @if($gagal || $kedaluwarsa)
                        <form method="POST" action="{{ route('wms.delivery.resend', $note) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger rounded-3">
                                <i class="bi bi-arrow-clockwise me-1"></i> {{ $kedaluwarsa ? 'Terbitkan tautan baru' : 'Kirim ulang' }}
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
                @endif

                @if($note->sales_notify_status !== null)
                {{-- KABAR KE SALES — terpisah dari pesan supir di atas.

                     Ditampilkan di layar Logistik karena merekalah yang bisa
                     berbuat sesuatu kalau kabarnya tidak keluar: menelepon
                     Sales, atau meminta nomor HP-nya dilengkapi. Sales yang
                     tidak menerima apa pun tidak akan tahu ada yang harus
                     ia tanyakan. --}}
                @php($salesGagal = $note->sales_notify_status === \App\Models\DeliveryNote::NOTIFY_FAILED)
                @php($salesManual = $note->sales_notify_status === \App\Models\DeliveryNote::NOTIFY_MANUAL)
                @php($salesMenunggu = $note->sales_notify_status === \App\Models\DeliveryNote::NOTIFY_PENDING)

                <div class="alert alert-{{ $salesGagal ? 'danger' : ($salesManual || $salesMenunggu ? 'secondary' : 'success') }} border-0 rounded-3 small">
                    <div class="fw-semibold mb-1">
                        <i class="bi bi-whatsapp me-1"></i> WA ke Sales: {{ $note->sales_notify_label }}
                        @if($note->sales_notify_phone)
                            <span class="fw-normal font-monospace ms-1">({{ $note->sales_notify_phone }})</span>
                        @endif
                    </div>
                    @if($note->sales_notify_error)
                        <div>{{ $note->sales_notify_error }}</div>
                    @endif
                    @if($salesManual)
                        {{-- Dikatakan terang-terangan. Di mode manual pesan ini
                             TIDAK PERNAH keluar — tidak ada orang di sisi
                             perusahaan yang menekan kirim, karena yang memicunya
                             supir. Diam di sini akan dibaca "terkirim". --}}
                        <div>
                            Sistem belum tersambung ke penyedia WhatsApp, jadi kabar ini tidak terkirim.
                            Sales tetap menerima lonceng di Portal Sales.
                        </div>
                    @endif
                    @if($note->sales_notified_at)
                        <div class="text-muted">Terkirim {{ $note->sales_notified_at->format('d/m/Y H:i') }}</div>
                    @endif
                </div>
                @endif
            </div>
        </div>
