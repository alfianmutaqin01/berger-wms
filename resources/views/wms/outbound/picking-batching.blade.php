@extends('layouts.wms')

@section('title', 'Daftar Picking')
@section('page_title', 'Daftar Picking')

@section('content')
{{-- LAYAR LOGISTIK. Yang menentukan pesanan mana berangkat bersama adalah
     orang yang tahu isi container, bukan operator yang berjalan ke rak.

     Satu daftar memuat BEBERAPA pesanan (keputusan pemilik produk): satu
     pesanan sering hanya beberapa item, sedangkan satu container memuat
     pesanan dari banyak toko sekaligus. --}}

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
    {{-- ------------------------------------------------ Antrean pesanan --}}
    <div class="col-12 col-xl-7">
        <form method="POST" action="{{ route('wms.picking.store') }}" id="formDaftar">
            @csrf
            {{-- Gudang tidak dipilih di layar bagi user yang terikat gudang:
                 WarehouseScope sudah menjepitnya, dan menyediakan pilihan yang
                 hanya berisi satu nilai hanya menambah langkah. --}}
            <input type="hidden" name="warehouse_id" id="gudangDaftar"
                   value="{{ $gudangSaya?->id ?? $filters['warehouse_id'] }}">

            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h5 class="fw-bold text-dark mb-0">
                                <i class="bi bi-ui-checks text-primary me-2"></i> Pesanan Siap Dipicking
                            </h5>
                            <small class="text-muted">Yang paling lama menunggu ada di atas.</small>
                        </div>
                        <button type="button" class="btn btn-primary rounded-3 px-3" id="tombolBuat" disabled
                                data-bs-toggle="modal" data-bs-target="#modalBuat">
                            <i class="bi bi-collection me-1"></i> Buat Daftar (<span id="jumlahPilih">0</span>)
                        </button>
                    </div>
                </div>

                <div class="card-body px-4 pt-3">
                    @if($gudangSaya === null && $gudangOptions->count() > 1)
                    <div class="alert alert-info border-0 rounded-3 small">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        Anda melihat semua gudang. Satu daftar picking hanya boleh berisi pesanan dari
                        <strong>satu gudang</strong> — orangnya berjalan kaki di satu bangunan.
                        Pilih gudangnya lebih dulu:
                        <select class="form-select form-select-sm mt-2" id="pilihGudang">
                            <option value="">— Pilih gudang —</option>
                            @foreach($gudangOptions as $gudang)
                                <option value="{{ $gudang->id }}" @selected($filters['warehouse_id'] == $gudang->id)>
                                    {{ $gudang->code }} — {{ $gudang->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:44px" class="text-center">
                                        <input class="form-check-input" type="checkbox" id="pilihSemua">
                                    </th>
                                    {{-- NOMOR SO YANG JADI ACUAN UTAMA, bukan nomor PO
                                         (keputusan pemilik produk). Nomor SO inilah yang
                                         nanti dicocokkan dengan Surat Jalan dari BC; nomor
                                         PO hanya berarti di dalam sistem ini. --}}
                                    <th>No. SO (BC)</th>
                                    <th>Customer</th>
                                    <th>Gudang</th>
                                    <th class="text-center">Item</th>
                                    <th>Diterima</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($antrean as $order)
                                <tr>
                                    <td class="text-center">
                                        <input class="form-check-input pilih-pesanan" type="checkbox"
                                               name="order_ids[]" value="{{ $order->id }}"
                                               data-gudang="{{ $order->warehouse_id }}">
                                    </td>
                                    <td>
                                        <span class="fw-bold font-monospace">{{ $order->bc_so_number ?? '—' }}</span>
                                        <div class="small text-muted font-monospace">PO {{ $order->order_number }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $order->customer?->name ?? '—' }}</div>
                                        <small class="text-muted font-monospace">{{ $order->customer?->code }}</small>
                                    </td>
                                    <td><span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $order->warehouse?->code }}</span></td>
                                    <td class="text-center">{{ $order->details_count }}</td>
                                    <td>
                                        <div>{{ $order->approved_at?->format('d M Y') ?? '—' }}</div>
                                        <small class="text-muted">{{ $order->approved_at?->format('H:i') }}</small>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                                        Tidak ada pesanan yang menunggu dipicking.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>

    {{-- ------------------------------------------------- Daftar berjalan --}}
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="bi bi-list-check text-primary me-2"></i> Daftar yang Sudah Disusun
                </h5>
                <small class="text-muted">Yang masih perlu dikerjakan ada di atas.</small>
            </div>

            <div class="card-body px-4 pt-3">
                <div class="list-group list-group-flush">
                @forelse($lists as $daftar)
                    <div class="list-group-item px-0 py-3">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <a href="{{ route('wms.picking.show', $daftar) }}"
                                   class="fw-bold font-monospace text-decoration-none">{{ $daftar->list_number }}</a>
                                <div class="small text-muted">
                                    {{ $daftar->orders_count }} pesanan · {{ $daftar->items_count }} baris ambil
                                    · {{ $daftar->warehouse?->code }}
                                </div>
                                @if($daftar->claimed_by)
                                    <div class="small text-muted">
                                        <i class="bi bi-person-badge me-1"></i>{{ $daftar->claimedBy?->full_name }}
                                    </div>
                                @endif
                            </div>
                            <div class="text-end">
                                <span class="badge bg-{{ $daftar->status_color }}-subtle text-{{ $daftar->status_color }}-emphasis">
                                    {{ $daftar->status_label }}
                                </span>
                                <div class="small text-muted mt-1">{{ $daftar->created_at?->format('d M H:i') }}</div>
                            </div>
                        </div>

                        @if($daftar->bolehDibubarkan())
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-3 mt-2 tombol-bubar"
                                data-bs-toggle="modal" data-bs-target="#modalBubar"
                                data-aksi="{{ route('wms.picking.cancel', $daftar) }}"
                                data-nomor="{{ $daftar->list_number }}">
                            <i class="bi bi-x-circle me-1"></i> Bubarkan
                        </button>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-list-check display-6 d-block mb-2 opacity-50"></i>
                        Belum ada daftar picking.
                    </div>
                @endforelse
                </div>

                <div class="mt-3">{{ $lists->links() }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Modal konfirmasi pembuatan: catatan opsional, lalu submit form di kiri. --}}
<div class="modal fade" id="modalBuat" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Buat Daftar Picking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    <span id="ringkasPilih" class="fw-semibold text-dark"></span> akan digabung menjadi
                    <strong>satu tugas pengambilan</strong>. Isinya dibekukan saat ini juga — batch dan rak yang
                    tertulis tidak akan berubah lagi setelah daftar dibuat.
                </p>

                <label class="form-label small fw-semibold">Catatan untuk operator (opsional)</label>
                <textarea class="form-control" id="catatanDaftar" rows="3" maxlength="1000"
                          placeholder="mis. muat ke container B, berangkat besok pagi"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary rounded-3" id="kirimDaftar">Buat Daftar</button>
            </div>
        </div>
    </div>
</div>

{{-- Satu modal dipakai bersama seluruh baris daftar. --}}
<div class="modal fade" id="modalBubar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formBubar" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Bubarkan Daftar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning border-0 rounded-3 small fw-semibold" id="bubarNomor"></div>
                <p class="text-muted small">
                    Pesanan di dalamnya kembali ke antrean dan bisa disusun ulang. Hanya bisa selama
                    <strong>belum ada satu baris pun</strong> yang ditandai operator.
                </p>
                <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                <textarea name="cancellation_reason" class="form-control" rows="3" minlength="10" maxlength="1000" required
                          placeholder="Minimal 10 karakter, mis. container batal berangkat hari ini"></textarea>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Tutup</button>
                <button type="submit" class="btn btn-danger rounded-3">Bubarkan</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formDaftar');
    const kotak = Array.from(document.querySelectorAll('.pilih-pesanan'));
    const semua = document.getElementById('pilihSemua');
    const tombol = document.getElementById('tombolBuat');
    const jumlah = document.getElementById('jumlahPilih');
    const gudangDaftar = document.getElementById('gudangDaftar');
    const pilihGudang = document.getElementById('pilihGudang');

    function terpilih() {
        return kotak.filter(k => k.checked);
    }

    // Satu daftar hanya boleh satu gudang. Bagi user lintas gudang, gudang
    // pertama yang dicentang MENGUNCI sisanya — mencegah kesalahan yang
    // baru ketahuan setelah tombol ditekan.
    function segarkan() {
        const dipilih = terpilih();
        const gudang = dipilih.length ? dipilih[0].dataset.gudang : null;

        kotak.forEach(function (k) {
            k.disabled = gudang !== null && k.dataset.gudang !== gudang;
            if (k.disabled) { k.closest('tr').classList.add('opacity-50'); }
            else { k.closest('tr').classList.remove('opacity-50'); }
        });

        if (gudang !== null) { gudangDaftar.value = gudang; }
        else if (pilihGudang) { gudangDaftar.value = pilihGudang.value; }

        jumlah.textContent = dipilih.length;
        tombol.disabled = dipilih.length === 0 || !gudangDaftar.value;

        const ringkas = document.getElementById('ringkasPilih');
        if (ringkas) { ringkas.textContent = dipilih.length + ' pesanan'; }
    }

    kotak.forEach(k => k.addEventListener('change', segarkan));

    if (semua) {
        semua.addEventListener('change', function () {
            kotak.forEach(function (k) {
                if (! k.disabled) { k.checked = semua.checked; }
            });
            segarkan();
        });
    }

    if (pilihGudang) {
        pilihGudang.addEventListener('change', function () {
            gudangDaftar.value = pilihGudang.value;
            segarkan();
        });
    }

    document.getElementById('kirimDaftar').addEventListener('click', function () {
        // Catatan hidup di dalam modal, di luar <form>. Disalin sebagai
        // input tersembunyi saat submit, bukan dipindahkan ke dalam form:
        // memindahkan elemen ke dalam <table> membuat parser HTML
        // melemparkannya keluar lagi (foster parenting).
        const catatan = document.getElementById('catatanDaftar').value;
        const tersembunyi = document.createElement('input');
        tersembunyi.type = 'hidden';
        tersembunyi.name = 'notes';
        tersembunyi.value = catatan;
        form.appendChild(tersembunyi);
        form.submit();
    });

    const formBubar = document.getElementById('formBubar');
    document.querySelectorAll('.tombol-bubar').forEach(function (t) {
        t.addEventListener('click', function () {
            formBubar.action = t.dataset.aksi;
            document.getElementById('bubarNomor').textContent = 'Daftar ' + t.dataset.nomor;
        });
    });

    segarkan();
});
</script>
@endsection
