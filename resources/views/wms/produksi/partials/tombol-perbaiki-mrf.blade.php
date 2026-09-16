{{-- Jalan keluar dari penolakan, di tempat alasannya dibaca.

     Penolakan dulu adalah jalan buntu: permintaan yang ditolak karena satu
     baris keliru memaksa Produksi mengetik ulang seluruhnya sebagai permintaan
     baru — dan permintaan barunya tidak punya hubungan apa pun dengan yang
     ditolak, sehingga atasan dan Logistik tidak pernah tahu ini pengajuan
     kedua atas hal yang sama.

     Hanya pemohonnya sendiri yang melihat tombol ini; servernya memeriksa hal
     yang sama, jadi menyembunyikannya di sini soal kejelasan, bukan keamanan. --}}
@if($mrf->requested_by === auth()->id())
    @can(\App\Support\Permission::MRF_CREATE)
        <a href="{{ route('wms.mrf.edit', $mrf) }}" class="btn btn-sm btn-light rounded-3 mt-2">
            <i class="bi bi-pencil-square me-1"></i> Perbaiki &amp; Ajukan Ulang
        </a>
    @endcan
@endif
