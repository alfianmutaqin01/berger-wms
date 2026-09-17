@extends('layouts.wms')

@section('title', 'Log Aktivitas')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-clock-history me-2"></i>Log Aktivitas</h4>
        <p class="text-muted small mb-0">
            Siapa melakukan apa, kapan. Hanya dapat dibuka Super Admin, dan tidak dapat diubah maupun dihapus
            oleh siapa pun — termasuk dari halaman ini.
        </p>
    </div>
    <span class="badge bg-dark-subtle text-dark-emphasis border">{{ number_format($logs->total()) }} catatan</span>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Cari</label>
                <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm"
                       placeholder="Nomor transaksi, catatan, atau nama pelaku">
            </div>
            {{-- JENIS TRANSAKSI berdiri sendiri, bukan dilebur ke "Tindakan".
                 Keduanya pertanyaan yang berbeda: tindakan menjawab APA yang
                 dilakukan (menyetujui, membatalkan), jenis menjawab DOKUMEN
                 APA. Menelusuri satu dokumen berarti memilih jenisnya lalu
                 membaca seluruh tindakan atasnya — bukan sebaliknya. --}}
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Jenis transaksi</label>
                <select name="jenis" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($jenisOptions as $kelas => $label)
                        <option value="{{ $kelas }}" @selected($filters['jenis'] === $kelas)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Tindakan</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($actions as $kode => $label)
                        <option value="{{ $kode }}" @selected($filters['action'] === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Pelaku</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" @selected((string) $filters['user_id'] === (string) $u->id)>
                            {{ $u->full_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Gudang</label>
                <select name="warehouse_id" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" @selected((string) $filters['warehouse_id'] === (string) $w->id)>
                            {{ $w->code }} — {{ $w->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Rentang tanggal</label>
                <div class="input-group input-group-sm">
                    <input type="date" name="dari" value="{{ $filters['dari'] }}" class="form-control">
                    <input type="date" name="sampai" value="{{ $filters['sampai'] }}" class="form-control">
                </div>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button class="btn btn-sm btn-dark px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
                {{-- "Reset Filter", bukan "Bersihkan". Tombol ini hanya mengosongkan
                     penyaring di atas, tetapi pada halaman log kata "bersihkan" terbaca
                     sebagai membuang isinya — padahal log tidak bisa dihapus siapa pun,
                     dan yang mengira baru saja menghapus jejak menyimpulkan hal yang
                     keliru tentang apa yang barusan dia lakukan. --}}
                <a href="{{ route('wms.admin.activity-log') }}" class="btn btn-sm btn-outline-secondary"
                   title="Kosongkan penyaring — isi log tidak berubah">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Filter
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:140px">Waktu</th>
                    <th style="width:160px">Pelaku</th>
                    <th style="width:70px">Jenis</th>
                    <th style="width:140px">Nomor Transaksi</th>
                    <th style="width:160px">Tindakan</th>
                    <th>Keterangan</th>
                    <th style="width:90px">Gudang</th>
                </tr>
            </thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="small text-nowrap">
                        {{ $log->created_at?->translatedFormat('d M Y') }}
                        <div class="text-muted font-monospace">{{ $log->created_at?->format('H:i:s') }}</div>
                    </td>
                    <td class="small">
                        {{-- Nama yang DISALIN saat kejadian, bukan nama sekarang.
                             Kalau akunnya sudah dihapus, jejaknya tetap terbaca. --}}
                        <div class="fw-semibold">{{ $log->pelaku }}</div>
                        <div class="text-muted" style="font-size:.72rem">
                            {{ $log->user_role ?? '—' }}
                            @if($log->user_id === null && $log->user_name !== null)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">akun dihapus</span>
                            @endif
                        </div>
                    </td>
                    {{-- Kode pendek saja di kolomnya sendiri: itu yang tertulis
                         di dokumen fisik dan yang diucapkan orang gudang. Nama
                         panjangnya tetap terbaca di tooltip dan di penyaring. --}}
                    <td class="small">
                        @if($log->subject_type)
                            <span class="badge bg-dark-subtle text-dark-emphasis border font-monospace"
                                  title="{{ $log->label_jenis }}">{{ $log->kode_jenis }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    {{-- Nomornya DISALIN saat kejadian, jadi tetap terbaca
                         walau dokumennya sudah tidak ada lagi. --}}
                    <td class="small font-monospace text-break">
                        {{ $log->reference_number ?? '—' }}
                    </td>
                    <td>
                        <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                            {{ $log->action_label }}
                        </span>
                    </td>
                    <td class="small">
                        {{ $log->description }}
                        @if($log->properties)
                            <button class="btn btn-link btn-sm p-0 ms-1 text-decoration-none" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#rinci-{{ $log->id }}">
                                rincian
                            </button>
                            <div class="collapse mt-2" id="rinci-{{ $log->id }}">
                                <div class="bg-light rounded-3 p-2 font-monospace" style="font-size:.72rem">
                                    @foreach($log->properties as $kunci => $nilai)
                                        <div class="text-break">
                                            <span class="text-muted">{{ $kunci }}:</span>
                                            {{-- Nilainya bisa daftar atau data bertingkat, bukan
                                                 hanya teks — lihat ActivityLog::tampilkanNilai(). --}}
                                            {{ \App\Models\ActivityLog::tampilkanNilai($nilai) }}
                                        </div>
                                    @endforeach
                                    @if($log->ip_address)
                                        <div><span class="text-muted">ip:</span> {{ $log->ip_address }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </td>
                    <td class="small">
                        <span class="font-monospace">{{ $log->warehouse?->code ?? '—' }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-5">
                        Belum ada aktivitas yang cocok dengan penyaring ini.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
        <div class="card-footer bg-white border-0 py-3">{{ $logs->links() }}</div>
    @endif
</div>

{{-- BATAS SIMPANNYA DIKATAKAN, bukan dibiarkan disimpulkan sendiri.
     Yang mencari kejadian empat bulan lalu dan tidak menemukannya akan
     menyimpulkan kejadiannya tidak pernah tercatat — kesimpulan yang salah,
     dan justru pada halaman yang dipakai saat ada sesuatu yang perlu
     dipertanggungjawabkan. --}}
<p class="text-center text-muted small mt-3 mb-0">
    <i class="bi bi-clock-history me-1"></i>
    Riwayat disimpan {{ \App\Models\ActivityLog::umurSimpanHari() }} hari terakhir, lalu dibuang otomatis.
    Log tidak bisa diubah maupun dihapus satu per satu.
</p>
@endsection
