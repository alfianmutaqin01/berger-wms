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
                            <span class="badge bg-warning-subtle text-warning-emphasis">Belum ada No. SO yang sama</span>
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
                        {{-- Kosongnya daftar punya tiga sebab yang berbeda, dan
                             tindak lanjutnya berbeda pula. Menyebutnya "tidak ada
                             yang cocok" saja meninggalkan orang tanpa langkah
                             berikutnya. --}}
                        @if(! $diagnosa['pelanggan_dikenal'])
                        <div class="alert alert-warning border-0 rounded-3 small mb-0">
                            <div class="fw-semibold mb-1">Kode pelanggan di Surat Jalan tidak dikenal sistem ini</div>
                            Kode <span class="font-monospace">{{ $note->customer_code ?: '(kosong)' }}</span>
                            tidak ada di Master Customer, jadi sistem tidak bisa menyodorkan calon pesanan.
                            Tambahkan pelanggannya di Master Customer lebih dulu, atau periksa kode di BC.
                        </div>
                        @elseif($diagnosa['punya_pesanan'] === 0)
                        <div class="alert alert-warning border-0 rounded-3 small mb-0">
                            <div class="fw-semibold mb-1">Pelanggan ini belum punya pesanan sama sekali di sistem</div>
                            Surat Jalan-nya ada di BC, tetapi pesanannya tidak pernah masuk ke sistem ini —
                            jadi ini <strong>bukan salah ketik nomor SO</strong>, dan tidak ada yang bisa dipasangkan.
                            Biasanya berarti pesanan itu dibuat langsung di BC tanpa lewat Portal Sales.
                            Kalau seharusnya ada, minta Sales memasukkannya lalu terima pesanannya dulu.
                        </div>
                        @else
                        <div class="alert alert-warning border-0 rounded-3 small mb-0">
                            <div class="fw-semibold mb-1">Pesanan pelanggan ini ada, tapi tidak ada yang bisa dipasangkan</div>
                            <ul class="mb-0 ps-3">
                                @if($diagnosa['sudah_punya_sj'] > 0)
                                    <li>{{ $diagnosa['sudah_punya_sj'] }} pesanan <strong>sudah punya Surat Jalan</strong> sendiri.</li>
                                @endif
                                @if($diagnosa['di_luar_tahap'] > 0)
                                    <li>
                                        {{ $diagnosa['di_luar_tahap'] }} pesanan sudah lewat tahap ini
                                        (sudah berangkat atau selesai) — Surat Jalan tidak bisa dipasangkan mundur.
                                    </li>
                                @endif
                                <li>
                                    Yang bisa dipasangkan hanya pesanan yang sudah diterima dan
                                    <strong>belum berangkat</strong>.
                                </li>
                            </ul>
                        </div>
                        @endif
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
                    angka di sistem. Selisih ini akan tercatat di Riwayat Mutasi untuk ditelusuri saat stocktake.
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
