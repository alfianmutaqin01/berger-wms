@extends('layouts.wms')

@section('title', 'Penomoran Dokumen')
@section('page_title', 'Penomoran Dokumen')

@section('content')
<div class="row mb-3">
    <div class="col-12 col-lg-9">
        <h4 class="fw-bold text-dark mb-1">Penomoran Dokumen</h4>
        <p class="text-muted small mb-0">
            Format dan nomor terakhir yang benar-benar terpakai. Halaman ini hanya menampilkan
            keadaan — tidak ada yang bisa diubah dari sini.
        </p>
    </div>
</div>

{{-- ALASANNYA DIKATAKAN, bukan dibiarkan orang mengira fiturnya belum jadi.

     Halaman ini dulu menyodorkan kolom prefix dan "nomor urut berikutnya"
     yang bisa diketik — nilainya karangan yang bahkan tidak cocok dengan
     format sungguhan, dan tombolnya tidak menyimpan apa pun. Membuatnya
     benar-benar bisa diubah justru lebih berbahaya daripada membiarkannya
     kosong. --}}
<div class="alert alert-warning border-0 rounded-4 small">
    <h6 class="fw-bold mb-2"><i class="bi bi-lock-fill me-2"></i>Kenapa tidak bisa diubah</h6>
    <ul class="mb-0 ps-3">
        <li class="mb-1">
            <strong>Mengganti awalan di tengah jalan memecah riwayat.</strong> Dokumen tahun yang
            sama jadi punya dua bentuk, dan pencarian berdasarkan nomor berhenti bekerja untuk
            salah satunya.
        </li>
        <li>
            <strong>Menggeser nomor urut mundur menghasilkan nomor kembar.</strong> Nomornya
            dijaga kunci unik di basis data, jadi akibatnya bukan data kotor melainkan pembuatan
            pesanan yang berhenti total untuk semua orang.
        </li>
    </ul>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3 ps-md-4">Jenis Dokumen</th>
                        <th>Format</th>
                        <th class="text-end">Nomor Terakhir</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($jenis as $d)
                        <tr>
                            <td class="ps-3 ps-md-4">
                                <div class="fw-semibold text-dark">{{ $d['nama'] }}</div>
                                <small class="text-muted">{{ $d['keterangan'] }}</small>
                            </td>
                            <td class="font-monospace">{{ $d['contoh'] }}</td>
                            <td class="text-end pe-3 pe-md-4">
                                @if($d['contoh'] === '—')
                                    <span class="text-muted">—</span>
                                @else
                                    <span class="fw-bold">{{ $urut[$d['tipe']] ?? 0 }}</span>
                                    <small class="text-muted d-block" style="font-size:.7rem">bulan ini</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Nomor urut dimulai ulang tiap periode dan dikunci per gudang, jadi dua gudang bisa
    memakai nomor urut yang sama pada hari yang sama tanpa bertabrakan.
</p>
@endsection
