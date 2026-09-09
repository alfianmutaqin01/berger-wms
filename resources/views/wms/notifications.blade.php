{{-- Layout mengikuti portal yang sedang dipakai.

     Rutenya di luar kedua portal (lihat routes/web.php), jadi halaman ini
     dibuka Tim Sales MAUPUN orang gudang. Memaksa layouts.wms akan
     menampilkan sidebar Portal WMS kepada Sales — menu yang satu pun tidak
     bisa ia buka. --}}
@extends(auth()->user()?->hasRole(\App\Models\Role::SALES) ? 'layouts.soms' : 'layouts.wms')

@section('title', 'Semua Notifikasi')
@section('page_title', 'Notifikasi')

@section('content')
<div class="row mb-3 align-items-center">
    <div class="col-12 col-md-8">
        <h4 class="fw-bold text-dark mb-0">Notifikasi</h4>
        <p class="text-muted mb-0 small">
            Pemberitahuan yang menuntut tindakan Anda.
            @if($belumDibaca > 0)
                <span class="fw-semibold text-primary">{{ $belumDibaca }} belum dibaca.</span>
            @endif
        </p>
    </div>
    <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0">
        {{-- Tombolnya BENAR-BENAR menandai dibaca. Versi sebelumnya hanya
             memunculkan jendela "Berhasil" tanpa menyentuh apa pun, dan
             loncengnya tetap merah sesudah ditekan. --}}
        <form method="POST" action="{{ route('wms.notifications.read-all') }}" class="d-inline">
            @csrf
            <button class="btn btn-outline-primary fw-bold rounded-pill px-4 shadow-sm" @disabled($belumDibaca < 1)>
                <i class="bi bi-check2-all me-1"></i> Tandai Semua Dibaca
            </button>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success border-0 rounded-4 small">{{ session('success') }}</div>
@endif

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="list-group list-group-flush rounded-4">
            @forelse($notifikasi as $n)
                <a href="{{ route('wms.notifications.open', $n) }}"
                   class="list-group-item list-group-item-action p-3 p-md-4 border-bottom
                          {{ $n->read_at ? '' : 'bg-light border-start border-4 border-'.$n->warna }}">
                    <div class="d-flex w-100 justify-content-between align-items-start gap-3 mb-2">
                        <div class="d-flex align-items-start gap-2" style="min-width:0">
                            <i class="bi {{ $n->ikon }} text-{{ $n->warna }} fs-5 mt-1"></i>
                            <h6 class="mb-0 fw-bold text-{{ $n->read_at ? 'dark' : $n->warna }}">{{ $n->title }}</h6>
                        </div>
                        <small class="text-muted text-nowrap">
                            <i class="bi bi-clock me-1"></i>{{ $n->created_at?->translatedFormat('d M Y, H:i') }}
                        </small>
                    </div>
                    <p class="mb-0 small text-{{ $n->read_at ? 'muted' : 'dark' }} ms-4 ps-2">{{ $n->body }}</p>
                </a>
            @empty
                <div class="text-center text-muted py-5">
                    <i class="bi bi-bell-slash fs-1 d-block mb-3 opacity-50"></i>
                    <div class="fw-semibold">Belum ada notifikasi</div>
                    <div class="small">Pemberitahuan akan muncul di sini saat ada yang perlu Anda kerjakan.</div>
                </div>
            @endforelse
        </div>
    </div>
</div>

@if($notifikasi->hasPages())
    <div class="mt-3">{{ $notifikasi->links() }}</div>
@endif

{{-- Dikatakan, bukan dibiarkan orang menyimpulkan sendiri. Yang mencari
     kejadian empat bulan lalu berhak tahu bahwa yang ia cari memang sudah
     dibuang, bukan bahwa kejadiannya tidak pernah tercatat. --}}
<p class="text-center text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Notifikasi tersimpan {{ \App\Models\ActivityLog::UMUR_SIMPAN_HARI }} hari terakhir.
</p>
@endsection
