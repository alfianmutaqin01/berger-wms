@extends('layouts.wms')

@section('title', 'Kapasitas Palet')
@section('page_title', 'Kapasitas Palet')

@section('content')
{{-- SATU ANGKA MENUTUP SATU UKURAN, BUKAN SATU PRODUK. Itu inti gunanya layar
     ini: "20 L PAIL = 36" langsung berlaku untuk seluruh produk berukuran itu,
     termasuk produk yang belum dibuat. Mengisinya satu per satu di Master
     Produk mengerjakan pekerjaan yang sama dua ratus kali. --}}

<div class="alert alert-info border-0 rounded-4 d-flex gap-3 mb-4">
    <i class="bi bi-stack fs-4"></i>
    <div class="small">
        <div class="fw-bold mb-1">Berapa banyak muat di satu palet, menurut ukuran kemasannya.</div>
        Angka ini yang dipakai memecah hasil produksi jadi beberapa palet dan membaca isi rak.
        Angkanya <strong>tidak bisa dihitung dari rumus</strong> — 20 Liter memuat 27 pcs sementara
        20 Kg memuat 36 pcs, karena wadahnya yang berbeda. Karena itu ia diisi orang gudang, bukan sistem.
        <div class="mt-2">
            Aturan yang <strong>menyebut wadah</strong> (PAIL, TIN, ...) mengalahkan aturan yang tidak.
            Pakai itu bila satu ukuran ternyata berbeda antar wadah; selebihnya cukup satu aturan umum.
        </div>
    </div>
</div>

{{-- ---------------------------------------------------- Pekerjaan tersisa --}}
@if($belum->isNotEmpty() || $tanpaUkuran > 0)
    <div class="card shadow-sm border-0 rounded-4 mb-4 border-start border-4 border-warning">
        <div class="card-body p-4">
            <h6 class="fw-bold text-dark mb-1">
                <i class="bi bi-exclamation-triangle text-warning me-2"></i>Ukuran yang belum punya aturan
            </h6>
            <p class="small text-muted mb-3">
                Produk berukuran ini <strong>tidak bisa dipecah jadi palet</strong> sampai aturannya ada.
                Klik salah satu untuk mengisi formulir di bawah.
            </p>

            @if($belum->isNotEmpty())
                <div class="d-flex flex-wrap gap-2">
                    @foreach($belum as $b)
                        <button type="button"
                                class="btn btn-sm btn-outline-warning rounded-3 isi-aturan"
                                data-unit="{{ $b->pack_unit }}"
                                data-size="{{ rtrim(rtrim(number_format((float) $b->pack_size, 3, '.', ''), '0'), '.') }}"
                                data-uom="{{ $b->uom }}">
                            <span class="font-monospace fw-semibold">
                                {{ rtrim(rtrim(number_format((float) $b->pack_size, 3, '.', ''), '0'), '.') }}
                                {{ $b->pack_unit }}
                            </span>
                            {{ $b->uom ?? '—' }}
                            <span class="badge bg-warning text-dark ms-1">{{ number_format($b->jumlah) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            @if($tanpaUkuran > 0)
                {{-- DIPISAH, karena jalan keluarnya bukan di layar ini. Produk
                     yang ukuran kemasannya kosong tidak punya apa pun untuk
                     dicocokkan dengan aturan mana pun. --}}
                <div class="alert alert-secondary border-0 rounded-3 small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>{{ number_format($tanpaUkuran) }} produk lagi</strong> tidak punya ukuran kemasan
                    (pack size / satuan) sama sekali, jadi aturan ukuran tidak bisa menolong mereka.
                    Lengkapi kemasannya lewat
                    <a href="{{ route('wms.products.index') }}" class="alert-link">Master Produk</a>,
                    atau isi angka paletnya khusus untuk produk itu di sana.
                </div>
            @endif
        </div>
    </div>
@endif

<div class="row g-4">
    {{-- ------------------------------------------------- Formulir tambah --}}
    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-plus-circle text-primary me-2"></i>Tambah Aturan</h5>
            </div>
            <form method="POST" action="{{ route('wms.admin.pallet-capacity.store') }}" class="card-body px-4 pt-3">
                @csrf

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Ukuran <span class="text-danger">*</span></label>
                        <input type="number" step="0.001" min="0.001" name="pack_size" id="inpSize"
                               value="{{ old('pack_size') }}" class="form-control @error('pack_size') is-invalid @enderror"
                               placeholder="20" required>
                        @error('pack_size')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary">Satuan <span class="text-danger">*</span></label>
                        <select name="pack_unit" id="inpUnit" class="form-select @error('pack_unit') is-invalid @enderror" required>
                            @foreach($units as $u)
                                <option value="{{ $u }}" @selected(old('pack_unit') === $u)>{{ $u }}</option>
                            @endforeach
                        </select>
                        @error('pack_unit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Wadah</label>
                    <input type="text" name="uom" id="inpUom" value="{{ old('uom') }}" maxlength="20"
                           class="form-control @error('uom') is-invalid @enderror" placeholder="PAIL / TIN — kosongkan bila sama semua">
                    @error('uom')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        Kosongkan agar berlaku untuk <strong>semua wadah</strong> pada ukuran itu.
                        Isi hanya bila wadah tertentu memang berbeda.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Maks per Palet <span class="text-danger">*</span></label>
                    <input type="number" min="1" max="100000" name="max_qty_per_pallet"
                           value="{{ old('max_qty_per_pallet') }}"
                           class="form-control @error('max_qty_per_pallet') is-invalid @enderror" placeholder="36" required>
                    @error('max_qty_per_pallet')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">Keterangan</label>
                    <input type="text" name="note" value="{{ old('note') }}" maxlength="200"
                           class="form-control" placeholder="mis. hasil ukur ulang gudang Karawang, Okt 2026">
                    <div class="form-text">Kenapa angkanya segitu. Angka tanpa keterangan adalah angka yang tidak berani diubah orang berikutnya.</div>
                </div>

                <button class="btn btn-primary w-100 rounded-3 fw-bold">
                    <i class="bi bi-check2 me-1"></i> Simpan Aturan
                </button>
            </form>
        </div>
    </div>

    {{-- ------------------------------------------------- Daftar aturan --}}
    <div class="col-12 col-xl-8">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold text-dark mb-0"><i class="bi bi-list-check text-primary me-2"></i>Aturan Berlaku</h5>
                <small class="text-muted">
                    Kolom <strong>produk</strong> menghitung yang benar-benar memakai aturan ini —
                    yang punya angka khusus di Master Produk tidak ikut.
                </small>
            </div>

            <div class="card-body px-4 pt-3">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ukuran</th>
                                <th>Wadah</th>
                                <th class="text-end">Maks / palet</th>
                                <th class="text-end">Produk</th>
                                <th>Keterangan</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($aturan as $a)
                            <form method="POST" action="{{ route('wms.admin.pallet-capacity.update', $a) }}">
                                @csrf @method('PUT')
                                <tr>
                                    <td class="font-monospace fw-semibold text-nowrap">
                                        {{ rtrim(rtrim(number_format((float) $a->pack_size, 3, '.', ''), '0'), '.') }}
                                        {{ $a->pack_unit }}
                                    </td>
                                    <td>
                                        @if($a->uom)
                                            <span class="badge bg-primary-subtle text-primary-emphasis">{{ $a->uom }}</span>
                                        @else
                                            <span class="text-muted small">semua wadah</span>
                                        @endif
                                    </td>
                                    <td class="text-end" style="width:130px">
                                        <input type="number" name="max_qty_per_pallet" min="1" max="100000" required
                                               value="{{ $a->max_qty_per_pallet }}"
                                               class="form-control form-control-sm text-end fw-bold">
                                    </td>
                                    <td class="text-end">
                                        @if($a->terdampak > 0)
                                            <span class="fw-semibold">{{ number_format($a->terdampak) }}</span>
                                        @else
                                            <span class="text-muted small" title="Belum ada produk berukuran ini, atau seluruhnya sudah punya angka khusus.">0</span>
                                        @endif
                                    </td>
                                    <td style="min-width:200px">
                                        <input type="text" name="note" maxlength="200" value="{{ $a->note }}"
                                               class="form-control form-control-sm" placeholder="—">
                                        @if($a->updatedBy)
                                            <div class="small text-muted mt-1">
                                                Diubah {{ $a->updated_at?->format('d M Y') }} oleh {{ $a->updatedBy->full_name }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary rounded-3" title="Simpan angka baris ini">
                                            <i class="bi bi-check2"></i>
                                        </button>
                                        {{-- Tombol hapus di luar form ini: form bersarang
                                             tidak sah di HTML, dan yang terjadi kalau
                                             dipaksakan adalah tombol yang mengirim ke
                                             alamat yang salah. --}}
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-3 tombol-hapus"
                                                data-bs-toggle="modal" data-bs-target="#modalHapus"
                                                data-aksi="{{ route('wms.admin.pallet-capacity.destroy', $a) }}"
                                                data-sebutan="{{ $a->sebutan }}"
                                                data-terdampak="{{ $a->terdampak }}"
                                                title="Hapus aturan ini">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </form>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-stack display-6 d-block mb-2 opacity-50"></i>
                                    Belum ada satu aturan pun.
                                    <div class="small">Tanpa aturan, tidak ada produk yang bisa dipecah jadi palet.</div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-secondary border-0 rounded-3 small mt-3 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Ukuran dan wadah sebuah aturan tidak bisa diubah — hanya angkanya. Mengubah ukurannya berarti
                    aturan ini diam-diam berpindah menutupi kelompok produk yang berbeda, sementara kelompok
                    lamanya kehilangan aturannya tanpa ada yang menyadarinya. Untuk itu: hapus, lalu buat yang baru.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalHapus" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="formHapus" class="modal-content border-0 rounded-4">
            @csrf @method('DELETE')
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">Hapus Aturan Kapasitas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Menghapus aturan <strong id="hpSebutan" class="font-monospace"></strong>.</p>
                {{-- JUMLAH PRODUK TERDAMPAK DISEBUT SEBELUM TERJADI, bukan
                     sesudah: produk yang kehilangan aturannya berhenti bisa
                     dipecah jadi palet, dan gejalanya baru muncul di layar
                     penerimaan barang berikutnya. --}}
                <div class="alert alert-warning border-0 rounded-3 small mb-0" id="hpPeringatan">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong><span id="hpJumlah"></span> produk</strong> akan kehilangan kapasitas paletnya dan
                    tidak bisa dipecah jadi palet sampai ada aturan penggantinya.
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger fw-bold">Hapus Aturan</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Pintasan dari daftar "belum punya aturan" ke formulir: ukuran yang
        // sudah terbaca sistem tidak perlu diketik ulang — dan salah ketik di
        // sini menghasilkan aturan yang tidak cocok dengan produk mana pun.
        document.querySelectorAll('.isi-aturan').forEach(function (tombol) {
            tombol.addEventListener('click', function () {
                document.getElementById('inpSize').value = tombol.dataset.size || '';
                document.getElementById('inpUnit').value = tombol.dataset.unit || 'L';
                document.getElementById('inpUom').value = tombol.dataset.uom || '';
                document.getElementById('inpSize').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        });

        const modal = document.getElementById('modalHapus');
        if (! modal) return;

        modal.addEventListener('show.bs.modal', function (e) {
            const b = e.relatedTarget;
            const jumlah = parseInt(b.dataset.terdampak || '0', 10);

            document.getElementById('formHapus').action = b.dataset.aksi;
            modal.querySelector('#hpSebutan').textContent = b.dataset.sebutan || '';
            modal.querySelector('#hpJumlah').textContent = jumlah.toLocaleString('id-ID');
            modal.querySelector('#hpPeringatan').hidden = jumlah === 0;
        });
    });
</script>
@endpush
