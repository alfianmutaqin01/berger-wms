{{-- ============================================ SECTION: PENGAWASAN (Manager & Super Admin Saja)
     CATATAN PENTING: Logistik TIDAK boleh melihat teks 'Pengawasan' maupun kartu di bawah ini.
     Sesuai aturan Permission & pengetesan test_angka_kartu_terlarang_tidak_ikut_terkirim_ke_halaman. --}}
@if(isset($m['koreksi_stok']) || isset($m['stocktake']) || isset($m['pengguna']))
    <div class="d-flex align-items-center justify-content-between mb-3 mt-4">
        <div class="d-flex align-items-center gap-2">
            <div class="p-1 rounded-2 bg-secondary-subtle text-secondary">
                <i class="bi bi-shield-lock fs-6"></i>
            </div>
            <h6 class="section-header-tag mb-0">Pengawasan</h6>
        </div>
        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-3 py-1 small">
            Khusus Manager &amp; Admin
        </span>
    </div>

    <div class="row g-3 mb-4">
        {{-- KOREKSI STOK --}}
        @isset($m['koreksi_stok'])
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card h-100 supervision-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon-badge" style="background: #f1f5f9; color: #334155; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-sliders"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Koreksi Stok</h6>
                            </div>
                            <span class="badge bg-light text-muted border rounded-pill small">
                                {{ $m['koreksi_stok']['hari'] }} hari terakhir
                            </span>
                        </div>
                        <div class="d-flex align-items-baseline gap-2 mb-2">
                            <h2 class="mb-0 fw-bold text-dark">{{ $m['koreksi_stok']['jumlah'] }}</h2>
                            <span class="text-muted">kali penyesuaian</span>
                        </div>
                        {{-- "Pergeseran neto stok" tidak menjelaskan apa pun; ia
                             hanya mengulang kata "neto". Yang sebenarnya ingin
                             diketahui: setelah semua koreksi digabung, catatan
                             stok jadi LEBIH BANYAK atau LEBIH SEDIKIT — dan itu
                             dua kabar yang sangat berbeda. --}}
                        @php
                            $neto = $m['koreksi_stok']['neto'];
                        @endphp
                        <div class="pt-2 border-top">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge rounded-pill px-2 py-1 {{ $neto < 0 ? 'bg-danger-subtle text-danger' : ($neto > 0 ? 'bg-success-subtle text-success' : 'bg-light text-muted border') }}">
                                    {{ $neto > 0 ? '+' : '' }}{{ number_format($neto) }} unit
                                </span>
                                <span class="text-dark small fw-medium">
                                    @if($neto > 0)
                                        Barang lebih banyak daripada catatan
                                    @elseif($neto < 0)
                                        Barang kurang dari catatan
                                    @else
                                        Koreksinya saling menutup
                                    @endif
                                </span>
                            </div>
                            {{-- Peringatan bahwa neto BISA MENYEMBUNYIKAN kesalahan
                                 harus ada di kartunya, bukan cuma di kode: angka
                                 kecil di sini tidak berarti tidak ada masalah. --}}
                            <small class="text-muted d-block mt-1" style="font-size:.72rem">
                                Gabungan seluruh koreksi. Tambah dan kurang bisa saling menutup,
                                jadi angka kecil belum tentu berarti aman — lihat
                                <a href="{{ route('wms.reports.show', 'pergerakan-stok') }}" class="text-decoration-none">rinciannya</a>.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        @endisset

        {{-- STOCKTAKE --}}
        @isset($m['stocktake'])
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card h-100 supervision-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon-badge" style="background: #f1f5f9; color: #334155; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-check2-square"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Stocktake</h6>
                            </div>
                            @if($m['stocktake']['berjalan'] > 0)
                                <span class="badge bg-info-subtle text-info-emphasis rounded-pill small">
                                    <span class="pulse-dot me-1" style="background-color: #0284c7;"></span>
                                    {{ $m['stocktake']['berjalan'] }} sesi berjalan
                                </span>
                            @else
                                <span class="badge bg-light text-muted border rounded-pill small">Tidak ada sesi aktif</span>
                            @endif
                        </div>
                        @if($m['stocktake']['terakhir'])
                            <div class="d-flex align-items-baseline gap-2 mb-2">
                                <h2 class="mb-0 fw-bold {{ $m['stocktake']['terakhir']['selisih_qty'] > 0 ? 'text-warning-emphasis' : 'text-success' }}">
                                    {{ number_format($m['stocktake']['terakhir']['selisih_qty']) }}
                                </h2>
                                <span class="text-muted">unit selisih</span>
                            </div>
                            <div class="pt-2 border-top">
                                <div class="text-muted small">
                                    <strong>{{ $m['stocktake']['terakhir']['selisih_baris'] }} baris meleset</strong> &middot;
                                    {{ $m['stocktake']['terakhir']['referensi'] }} ({{ $m['stocktake']['terakhir']['tanggal'] }})
                                </div>
                            </div>
                        @else
                            <div class="mb-2">
                                <h2 class="mb-0 fw-bold text-muted">&mdash;</h2>
                            </div>
                            <div class="pt-2 border-top">
                                <span class="text-muted small">Belum ada sesi stocktake yang disahkan</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endisset

        {{-- PENGGUNA --}}
        @isset($m['pengguna'])
            <div class="col-12 col-sm-6 col-md-4">
                <div class="card h-100 supervision-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="stat-icon-badge" style="background: #f1f5f9; color: #334155; width: 38px; height: 38px; font-size: 1.1rem;">
                                    <i class="bi bi-people"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-0">Pengguna</h6>
                            </div>
                            <span class="badge bg-success-subtle text-success rounded-pill small">
                                <span class="pulse-dot me-1"></span>
                                {{ $m['pengguna']['sesi_hidup'] }} online
                            </span>
                        </div>
                        <div class="d-flex align-items-baseline gap-2 mb-2">
                            <h2 class="mb-0 fw-bold text-dark">{{ $m['pengguna']['aktif'] }}</h2>
                            <span class="text-muted">karyawan aktif</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 pt-2 border-top">
                            <span class="text-muted small">
                                {{ $m['pengguna']['sesi_hidup'] }} sesi aktif dalam 30 menit &middot;
                                <span class="text-secondary">{{ $m['pengguna']['nonaktif'] }} dinonaktifkan</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        @endisset
    </div>
@endif
