@extends('layouts.wms')

@section('title', 'Daftar Picking '.$list->list_number)
@section('page_title', 'Daftar Picking '.$list->list_number)

@section('content')
{{-- Layar kerja operator, dan sekaligus layar periksa bagi Logistik.

     URUTAN BARIS DITENTUKAN KODE RAK (F-OUT-03 #3), bukan urutan pesanan.
     Operator berjalan sekali dari rak depan ke belakang; mengurutkannya per
     pesanan berarti ia bolak-balik ke rak yang sama sebanyak jumlah pesanan
     — dan itu justru yang mau dihindari dengan menggabungkan pesanan.

     MENANDAI BARIS TIDAK MEMUAT ULANG HALAMAN (temuan lapangan pemilik
     produk). Satu daftar bisa berisi 100 baris; kalau tiap ketukan memuat
     ulang, operator yang sedang di baris ke-80 dilempar kembali ke atas dan
     harus menggulir turun lagi — seratus kali dalam satu tugas. Yang terjadi
     berikutnya bukan operator yang sabar menggulir, melainkan operator yang
     berhenti menandai satu per satu dan menandai semuanya di akhir dari
     ingatan. Itu menghapus seluruh guna penandaan ini. --}}

<a href="{{ route('wms.picking.queue') }}" class="btn btn-sm btn-light rounded-3 mb-3">
    <i class="bi bi-arrow-left me-1"></i> Kembali ke daftar tugas
</a>

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

{{-- ------------------------------------------------------------ Ringkasan --}}
<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <span class="badge bg-{{ $list->status_color }}-subtle text-{{ $list->status_color }}-emphasis mb-2">
                    {{ $list->status_label }}
                </span>
                <div class="small text-muted">
                    {{ $list->warehouse?->name }} ·
                    disusun {{ $list->createdBy?->full_name ?? '—' }}, {{ $list->created_at?->format('d M Y H:i') }}
                    @if($list->claimed_by)
                        <div><i class="bi bi-person-badge me-1"></i>Dikerjakan {{ $list->claimedBy?->full_name }}</div>
                    @endif
                </div>
                @if($list->notes)
                    <div class="alert alert-light border rounded-3 small mt-2 mb-0">
                        <i class="bi bi-sticky me-1"></i>{{ $list->notes }}
                    </div>
                @endif
            </div>

            <div class="text-end">
                <div class="h4 fw-bold mb-0">
                    <span id="angkaSelesai">{{ $ringkas['selesai'] }}</span> / {{ $ringkas['total'] }}
                </div>
                <div class="small text-muted">baris ditandai</div>
                <span class="badge bg-warning-subtle text-warning-emphasis mt-1" id="lencanaKurang"
                      @if($ringkas['kurang'] < 1) hidden @endif>
                    <span id="angkaKurang">{{ $ringkas['kurang'] }}</span> baris kurang
                </span>
            </div>
        </div>

        @php($persen = $ringkas['total'] > 0 ? round($ringkas['selesai'] / $ringkas['total'] * 100) : 0)
        <div class="progress mt-3" style="height:8px">
            <div class="progress-bar bg-success" id="bilahKemajuan" style="width: {{ $persen }}%"></div>
        </div>

        {{-- Pesanan yang ikut dalam daftar ini. Operator perlu tahu barang
             ini untuk siapa saat memisahkannya di loading dock. --}}
        <div class="d-flex flex-wrap gap-2 mt-3">
            @foreach($list->orders as $order)
                {{-- Nomor SO yang ditonjolkan: itu yang dicocokkan dengan
                     Surat Jalan dari BC. Nomor PO hanya berarti di sini. --}}
                <span class="badge bg-light text-dark border">
                    <span class="font-monospace fw-bold">{{ $order->bc_so_number ?? $order->order_number }}</span>
                    · {{ $order->customer?->name ?? '—' }}
                </span>
            @endforeach
        </div>
    </div>
</div>

{{-- --------------------------------------------------------- Baris ambil --}}
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-geo-alt text-primary me-2"></i> Urutan Pengambilan</h5>
            <small class="text-muted">Berurutan menurut kode rak — jalan sekali, dari depan ke belakang.</small>
        </div>

        @if($bolehDikerjakan)
        <div class="d-flex align-items-center gap-3 flex-wrap">
            {{-- Dengan 100 baris, menyembunyikan yang sudah selesai membuat
                 sisanya mengerut ke arah operator. Tidak dinyalakan sendiri:
                 baris yang tiba-tiba hilang terbaca sebagai kesalahan. --}}
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="sembunyikanSelesai">
                <label class="form-check-label small text-muted" for="sembunyikanSelesai">
                    Sembunyikan yang sudah ditandai
                </label>
            </div>

            <form method="POST" action="{{ route('wms.picking.complete', $list) }}" id="formSelesai"
                  onsubmit="return confirm('Selesaikan daftar ini? Stok di rak akan berkurang dan pesanannya berpindah ke Siap Kirim.');">
                @csrf
                <button class="btn btn-success btn-lg rounded-3 px-4" id="tombolSiapLoading"
                        @disabled($ringkas['selesai'] < $ringkas['total'])>
                    <i class="bi bi-box-seam me-1"></i> Siap Loading
                </button>
            </form>
        </div>
        @endif
    </div>

    <div class="card-body px-4 pt-3">
        @if($bolehDikerjakan)
        <div class="alert alert-info border-0 rounded-3 small" id="catatanBelumLengkap"
             @if($ringkas['selesai'] >= $ringkas['total']) hidden @endif>
            <i class="bi bi-info-circle-fill me-2"></i>
            Tombol <strong>Siap Loading</strong> aktif setelah seluruh baris ditandai. Baris yang terlewat
            berarti barang yang tidak ikut naik ke kendaraan tanpa ada yang tahu.
        </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tabelPicking">
                <thead class="table-light">
                    <tr>
                        <th style="width:120px">Rak</th>
                        <th>Produk</th>
                        <th>Batch</th>
                        <th>Untuk</th>
                        <th class="text-end">Qty</th>
                        <th class="text-center">Status</th>
                        @if($bolehDikerjakan)<th class="text-end" style="width:220px">Aksi</th>@endif
                    </tr>
                </thead>
                <tbody>
                @foreach($baris as $item)
                    @php($ditandai = $item->sudahDitandai())
                    {{-- id dipakai dua hal: sasaran anchor pada jalan mundur
                         tanpa JavaScript, dan sasaran pembaruan satu baris. --}}
                    <tr id="baris-{{ $item->id }}" class="baris-ambil" data-status="{{ $item->status }}">
                        <td>
                            <span class="fw-bold fs-5 font-monospace">{{ $item->location?->code ?? '—' }}</span>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $item->product?->sku }}</div>
                            <small class="text-muted">{{ $item->product?->name }}</small>
                        </td>
                        <td>
                            <span class="font-monospace">{{ $item->batch_no ?? '—' }}</span>
                            <div class="small text-muted">{{ $item->production_date?->format('d M Y') }}</div>
                        </td>
                        <td>
                            <div class="small">{{ $item->salesOrder?->customer?->name ?? '—' }}</div>
                            <small class="text-muted font-monospace">
                                {{ $item->salesOrder?->bc_so_number ?? $item->salesOrder?->order_number }}
                            </small>
                        </td>
                        <td class="text-end">
                            <span class="fw-bold fs-5">{{ $item->qty_to_pick }}</span>
                            <div class="small text-muted">{{ $item->product?->uom }}</div>
                            <div class="small text-warning-emphasis fw-semibold sel-ditemukan"
                                 @if($item->status !== \App\Models\PickingListItem::STATUS_SHORT) hidden @endif>
                                ditemukan <span class="nilai-ditemukan">{{ $item->qty_picked }}</span>
                            </div>
                        </td>

                        {{-- Ketiga bentuk status sudah ada di halaman sejak awal
                             dan hanya ditampilkan bergantian. Menyusun HTML dari
                             JavaScript berarti tampilan baris yang sama ditulis
                             di dua tempat, dan cepat atau lambat keduanya
                             berbeda. --}}
                        <td class="text-center sel-status">
                            <span class="status-pending" @if($ditandai) hidden @endif>
                                <i class="bi bi-circle text-muted fs-4"></i>
                            </span>
                            <span class="status-picked" @if($item->status !== \App\Models\PickingListItem::STATUS_PICKED) hidden @endif>
                                <i class="bi bi-check-circle-fill text-success fs-4"></i>
                            </span>
                            <span class="status-short" @if($item->status !== \App\Models\PickingListItem::STATUS_SHORT) hidden @endif>
                                <i class="bi bi-exclamation-triangle-fill text-warning fs-4"></i>
                                <div class="small text-muted nilai-alasan">{{ $item->discrepancy_reason }}</div>
                            </span>
                        </td>

                        @if($bolehDikerjakan)
                        <td class="text-end">
                            <div class="aksi-pending" @if($ditandai) hidden @endif>
                                {{-- Jalur cepat: satu ketuk. Isian qty sengaja
                                     TIDAK ada di sini — kalau tiap baris minta
                                     angka, operator mengetik angka yang sama
                                     ratusan kali sehari dan berhenti membacanya. --}}
                                <form method="POST" action="{{ route('wms.picking.item.pick', [$list, $item]) }}"
                                      class="d-inline aksi-picking" data-baris="{{ $item->id }}">
                                    @csrf
                                    <button class="btn btn-sm btn-success rounded-3 px-3">
                                        <i class="bi bi-check-lg me-1"></i> Ambil
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-warning rounded-3 tombol-selisih"
                                        data-bs-toggle="modal" data-bs-target="#modalSelisih"
                                        data-aksi="{{ route('wms.picking.item.short', [$list, $item]) }}"
                                        data-baris="{{ $item->id }}"
                                        data-sku="{{ $item->product?->sku }}"
                                        data-rak="{{ $item->location?->code }}"
                                        data-qty="{{ $item->qty_to_pick }}">
                                    Kurang
                                </button>
                            </div>

                            <div class="aksi-ditandai" @if(! $ditandai) hidden @endif>
                                <form method="POST" action="{{ route('wms.picking.item.reset', [$list, $item]) }}"
                                      class="d-inline aksi-picking" data-baris="{{ $item->id }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary rounded-3">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Batal tanda
                                    </button>
                                </form>
                            </div>
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($bolehDikerjakan)
{{-- Pintu keadaan khusus. Sengaja di balik satu ketukan tambahan supaya
     jalur normal tetap satu ketuk, tetapi tetap ADA — tanpanya, operator yang
     menemukan rak kurang hanya bisa menandai barang yang tidak ia ambil, atau
     berhenti dan menahan pengiriman. --}}
<div class="modal fade" id="modalSelisih" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formSelisih" class="modal-content rounded-4 border-0 aksi-picking">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">Barang di Rak Kurang</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning border-0 rounded-3 small">
                    <div class="fw-semibold" id="selisihSku"></div>
                    <div id="selisihRak"></div>
                </div>

                <div class="alert alert-danger border-0 rounded-3 small" id="selisihGalat" hidden></div>

                <label class="form-label small fw-semibold">
                    Berapa yang benar-benar ada di rak? <span class="text-danger">*</span>
                </label>
                <input type="number" name="qty_picked" id="selisihQty" class="form-control form-control-lg mb-1"
                       min="0" required>
                <div class="form-text mb-3">
                    Tertulis di daftar: <strong id="selisihTertulis"></strong>. Isi 0 kalau raknya kosong sama sekali.
                </div>

                <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                <textarea name="discrepancy_reason" id="selisihAlasan" class="form-control" rows="3"
                          minlength="10" maxlength="1000" required
                          placeholder="Minimal 10 karakter, mis. rak hanya berisi 8 kaleng, sisanya tidak ditemukan"></textarea>

                <p class="text-muted small mt-3 mb-0">
                    Selisihnya akan dicatat sebagai <strong>koreksi stok</strong> saat Siap Loading ditekan,
                    lengkap dengan alasan ini — bukan sebagai barang yang keluar ke customer.
                </p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary rounded-3" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning rounded-3">Catat Selisih</button>
            </div>
        </form>
    </div>
</div>

{{-- Pemberitahuan mengambang. Sengaja TIDAK menyisipkan apa pun ke aliran
     halaman: kotak pesan yang muncul di atas tabel menggeser seluruh baris ke
     bawah, dan baris yang bergeser tepat saat jari menuju tombol berikutnya
     adalah cara membuat operator menekan baris yang salah. --}}
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
    <div class="toast align-items-center border-0 shadow" id="kabar" role="status" aria-live="polite">
        <div class="d-flex">
            <div class="toast-body" id="kabarPesan"></div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabel = document.getElementById('tabelPicking');
    const total = document.querySelectorAll('.baris-ambil').length;

    const angkaSelesai = document.getElementById('angkaSelesai');
    const angkaKurang = document.getElementById('angkaKurang');
    const lencanaKurang = document.getElementById('lencanaKurang');
    const bilah = document.getElementById('bilahKemajuan');
    const tombolSelesai = document.getElementById('tombolSiapLoading');
    const catatanBelum = document.getElementById('catatanBelumLengkap');
    const sembunyikan = document.getElementById('sembunyikanSelesai');

    const kabarEl = document.getElementById('kabar');
    const modalEl = document.getElementById('modalSelisih');
    const formSelisih = document.getElementById('formSelisih');
    const galatSelisih = document.getElementById('selisihGalat');

    let pewaktuKabar = null;

    /*
     | Toast dan Modal DITANGANI TANPA API JavaScript Bootstrap.
     |
     | `bootstrap` di proyek ini diimpor ke dalam modul app.js dan TIDAK
     | diekspos ke window (lihat resources/js/app.js), jadi
     | `new bootstrap.Toast(...)` dari skrip inline seperti ini akan mati
     | dengan ReferenceError — dan matinya diam-diam, hanya di console.
     | Atribut data-bs-* tetap bekerja karena penanganannya didelegasikan
     | dari modul itu; yang tidak ada hanyalah pemanggilan langsungnya.
     |
     | Menampilkan toast cukup dengan menambah kelas .show (Bootstrap
     | menanganinya lewat CSS), dan menutup modal cukup dengan menekan
     | tombol dismiss-nya sendiri.
     */
    function beriKabar(pesan, jenis) {
        if (! kabarEl) { return; }

        kabarEl.className = 'toast show align-items-center border-0 shadow text-bg-' +
            (jenis === 'error' ? 'danger' : (jenis === 'warning' ? 'warning' : 'success'));
        document.getElementById('kabarPesan').textContent = pesan;

        clearTimeout(pewaktuKabar);
        pewaktuKabar = setTimeout(function () {
            kabarEl.classList.remove('show');
        }, jenis === 'error' ? 6000 : 2500);
    }

    function tutupModal() {
        const dismiss = modalEl ? modalEl.querySelector('[data-bs-dismiss="modal"]') : null;
        if (dismiss) { dismiss.click(); }
    }

    /** Memperbarui satu baris di tempatnya, tanpa menyentuh yang lain. */
    function perbaruiBaris(data) {
        const baris = document.getElementById('baris-' + data.id);
        if (! baris) { return; }

        baris.dataset.status = data.status;
        baris.classList.remove('table-success', 'table-warning');

        const tampilkan = function (pemilih, tampil) {
            const el = baris.querySelector(pemilih);
            if (el) { el.hidden = ! tampil; }
        };

        tampilkan('.status-pending', data.status === 'pending');
        tampilkan('.status-picked', data.status === 'picked');
        tampilkan('.status-short', data.status === 'short');
        tampilkan('.sel-ditemukan', data.status === 'short');
        tampilkan('.aksi-pending', data.status === 'pending');
        tampilkan('.aksi-ditandai', data.status !== 'pending');

        if (data.status === 'picked') { baris.classList.add('table-success'); }
        if (data.status === 'short') {
            baris.classList.add('table-warning');
            const ditemukan = baris.querySelector('.nilai-ditemukan');
            const alasan = baris.querySelector('.nilai-alasan');
            if (ditemukan) { ditemukan.textContent = data.qty_picked; }
            if (alasan) { alasan.textContent = data.alasan || ''; }
        }

        terapkanPenyembunyian();
    }

    function perbaruiRingkasan(r) {
        angkaSelesai.textContent = r.selesai;
        angkaKurang.textContent = r.kurang;
        lencanaKurang.hidden = r.kurang < 1;
        bilah.style.width = (r.total > 0 ? Math.round(r.selesai / r.total * 100) : 0) + '%';

        const lengkap = r.selesai >= r.total;
        if (tombolSelesai) { tombolSelesai.disabled = ! lengkap; }
        if (catatanBelum) { catatanBelum.hidden = lengkap; }
    }

    /**
     * Membawa baris BERIKUTNYA yang belum ditandai ke tengah layar.
     *
     * Ini inti perbaikannya. Bukan sekadar "jangan kembali ke atas" —
     * sasaran operator selanjutnya yang datang menghampiri, sehingga
     * tangannya tidak perlu meninggalkan tombol.
     */
    function lompatKeBerikutnya(dariId) {
        const semua = Array.from(document.querySelectorAll('.baris-ambil'));
        const mulai = semua.findIndex(b => b.id === 'baris-' + dariId);

        const berikut = semua.slice(mulai + 1).find(b => b.dataset.status === 'pending')
            || semua.find(b => b.dataset.status === 'pending');

        if (berikut) {
            berikut.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // Tidak ada lagi yang tersisa: yang dicari operator sekarang adalah
        // tombol Siap Loading, bukan baris.
        if (tombolSelesai) {
            tombolSelesai.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function terapkanPenyembunyian() {
        if (! sembunyikan) { return; }
        document.querySelectorAll('.baris-ambil').forEach(function (baris) {
            baris.hidden = sembunyikan.checked && baris.dataset.status !== 'pending';
        });
    }

    if (sembunyikan) { sembunyikan.addEventListener('change', terapkanPenyembunyian); }

    /** Mengirim satu aksi baris tanpa memuat ulang halaman. */
    function kirim(form, idBaris) {
        const tombol = form.querySelector('button[type="submit"], button:not([type])');
        if (tombol) { tombol.disabled = true; }

        return fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: new FormData(form),
        })
            .then(function (respons) {
                return respons.json().then(function (data) {
                    return { ok: respons.ok, data: data };
                });
            })
            .then(function (hasil) {
                if (! hasil.ok) {
                    // Galat validasi datang sebagai {errors:{...}}, penolakan
                    // aturan sebagai {pesan}. Keduanya bukan alasan untuk
                    // menandai baris di layar.
                    const errors = hasil.data.errors;
                    const pesan = errors
                        ? Object.values(errors).flat().join(' ')
                        : (hasil.data.pesan || 'Gagal menyimpan. Coba lagi.');
                    throw new Error(pesan);
                }

                perbaruiBaris(hasil.data.item);
                perbaruiRingkasan(hasil.data.ringkas);
                beriKabar(hasil.data.pesan, hasil.data.jenis);

                if (hasil.data.item.status !== 'pending') {
                    lompatKeBerikutnya(idBaris);
                }

                return true;
            })
            .catch(function (galat) {
                beriKabar(galat.message || 'Gagal menyimpan. Periksa jaringan lalu coba lagi.', 'error');

                return false;
            })
            .finally(function () {
                if (tombol) { tombol.disabled = false; }
            });
    }

    // Tombol Ambil dan Batal tanda di dalam tabel.
    if (tabel) {
        tabel.addEventListener('submit', function (peristiwa) {
            const form = peristiwa.target.closest('.aksi-picking');
            if (! form) { return; }

            peristiwa.preventDefault();
            kirim(form, form.dataset.baris);
        });
    }

    // Pintu selisih.
    document.querySelectorAll('.tombol-selisih').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            formSelisih.action = tombol.dataset.aksi;
            formSelisih.dataset.baris = tombol.dataset.baris;
            document.getElementById('selisihSku').textContent = tombol.dataset.sku || '';
            document.getElementById('selisihRak').textContent = 'Rak ' + (tombol.dataset.rak || '—');
            document.getElementById('selisihTertulis').textContent = tombol.dataset.qty;
            galatSelisih.hidden = true;

            // Batas atas mengikuti baris yang ditekan: mengambil LEBIH banyak
            // daripada yang dicadangkan berarti mengambil jatah pesanan lain
            // dari batch yang sama.
            const qty = document.getElementById('selisihQty');
            qty.max = Number(tombol.dataset.qty) - 1;
            qty.value = '';
            document.getElementById('selisihAlasan').value = '';
        });
    });

    if (formSelisih) {
        formSelisih.addEventListener('submit', function (peristiwa) {
            peristiwa.preventDefault();

            kirim(formSelisih, formSelisih.dataset.baris).then(function (berhasil) {
                if (berhasil) {
                    tutupModal();
                } else {
                    // Galatnya ditulis DI DALAM modal juga: kabar mengambang
                    // di sudut layar mudah terlewat ketika perhatian sedang
                    // tertuju ke kotak isian yang baru saja ditolak.
                    galatSelisih.textContent = document.getElementById('kabarPesan').textContent;
                    galatSelisih.hidden = false;
                }
            });
        });
    }
});
</script>
@endif
@endsection
