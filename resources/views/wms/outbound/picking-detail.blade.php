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

                {{-- UNDUHAN BARU MUNCUL SETELAH SELESAI, dan itu bukan sekadar
                     kerapian. Selama picking berjalan, qty diambil masih
                     berubah tiap kali operator menandai satu baris; berkas yang
                     keluar di tengah jalan menyatakan "diambil 0" untuk barang
                     yang lima menit lagi sudah di troli. Berkas itu lalu
                     beredar di luar sistem sebagai angka yang terlihat resmi
                     dan memunculkan selisih yang tidak pernah ada. --}}
                @if($list->status === \App\Models\PickingList::STATUS_COMPLETED)
                    <div class="mt-2">
                        <a href="{{ route('wms.picking.unduh', $list) }}" data-tanpa-pemuat
                           class="btn btn-sm btn-success rounded-3">
                            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                        </a>
                        <div class="text-muted mt-1" style="font-size:.72rem">
                            Untuk dicocokkan dengan BC
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @php($persen = $ringkas['total'] > 0 ? round($ringkas['selesai'] / $ringkas['total'] * 100) : 0)
        <div class="progress mt-3" style="height:8px">
            <div class="progress-bar bg-success" id="bilahKemajuan" style="width: {{ $persen }}%"></div>
        </div>

        {{-- Daftar TRANSFER: tidak ada pesanan di dalamnya, dan yang perlu
             diketahui operator justru ke mana barangnya pergi. --}}
        @if($list->transfer)
            <div class="alert alert-info border-0 rounded-3 mt-3 mb-0 small">
                <i class="bi bi-arrow-left-right me-1"></i>
                <strong>Kiriman antar gudang {{ $list->transfer->transfer_number }}</strong> —
                dari {{ $list->transfer->fromWarehouse?->name ?? 'gudang ini' }}
                ke <strong>{{ $list->transfer->toWarehouse?->name ?? 'gudang tujuan' }}</strong>.
                Barang ini <strong>tidak menuju pelanggan</strong>: begitu Anda menekan Loading, kirimannya
                berangkat dan menunggu diterima tim logistik di sana.
            </div>
        @endif

        {{-- Daftar MRF: barangnya tidak naik kendaraan apa pun. Ia berpindah
             tangan di tempat, dan titik serah terimanya WAJIB disebutkan —
             kalau tidak, barangnya berdiri tanpa alamat. --}}
        @if($list->requisition)
            <div class="alert alert-primary border-0 rounded-3 mt-3 mb-0 small">
                <i class="bi bi-clipboard2-check me-1"></i>
                <strong>Permintaan material {{ $list->requisition->mrf_number }}</strong> —
                untuk <strong>{{ $list->requisition->nama_divisi }}</strong>
                ({{ $list->requisition->nama_pemohon }}, {{ $list->requisition->jenis_label }}).
                Barang ini <strong>tidak menuju pelanggan dan tidak naik truk</strong>: taruh di titik
                transit, lalu tekan <strong>Serah Terima</strong>. Begitu ditekan, barangnya
                <strong>langsung tercatat atas nama {{ $list->requisition->nama_divisi }}</strong> —
                tidak ada konfirmasi susulan dari sana.
            </div>
        @endif

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

@include('wms.outbound._picking-baris')

@if($bolehDikerjakan || $bolehMelepasTugas)
<div class="modal fade" id="modalLepasTugas" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('wms.picking.release', $list) }}" class="modal-content rounded-4 border-0">
            @csrf
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-arrow-counterclockwise text-secondary me-2"></i>Lepas Tugas Picking
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Daftar <strong class="font-monospace">{{ $list->list_number }}</strong> kembali ke antrean
                    dan bisa diambil operator lain. Isinya <strong>tidak dibubarkan</strong> — kalau susunannya
                    memang perlu diubah, Logistik yang mengaturnya dari halaman Daftar Picking.
                </p>

                @if($ringkas['selesai'] > 0)
                    {{-- Wajib disebut. Operator yang sudah menandai beberapa rak
                         berhak tahu bahwa tandanya akan hilang — dan itu memang
                         harus hilang, karena operator berikutnya tidak boleh
                         mewarisi tanda yang tidak ia buat sendiri. --}}
                    <div class="alert alert-warning border-0 rounded-3 small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>{{ $ringkas['selesai'] }} baris sudah ditandai</strong> dan tandanya akan
                        dikosongkan. Stok di rak tidak berubah sama sekali — yang mengurangi stok hanya
                        tombol penyelesaian daftar ini, dan itu belum ditekan.
                    </div>
                @endif

                <div class="mb-2">
                    <label class="form-label small fw-semibold">Alasan <span class="text-danger">*</span></label>
                    <textarea name="release_reason" class="form-control" rows="2" maxlength="500" required
                              placeholder="Contoh: pengiriman digeser ke besok. / Dioper ke Pak Dedi."></textarea>
                    <small class="text-muted">Terbaca Logistik saat ia mengatur ulang daftar picking.</small>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-warning fw-bold">Lepas Tugas</button>
            </div>
        </form>
    </div>
</div>
@endif

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
                    Selisihnya akan dicatat sebagai <strong>koreksi stok</strong> saat daftar ini diselesaikan,
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

@include('wms.outbound._picking-skrip')
@endif
@endsection
