{{-- Tombol aksi satu batch. Dipakai blok Good Stock, Karantina, MAUPUN DDP
     supaya ketiganya tidak bisa diam-diam menyimpang satu sama lain.

     Tombol Karantina/Batalkan Karantina menyesuaikan diri dari $stock->status
     — tidak perlu tahu blok mana yang memanggilnya.

     Membutuhkan: $stock (InventoryStock), $sku (string). --}}
@can(\App\Support\Permission::INVENTORY_ADJUST)
<button type="button" class="btn btn-sm btn-outline-warning" title="Koreksi stok"
        data-bs-toggle="modal" data-bs-target="#modalAdjust"
        data-stock="{{ $stock->id }}" data-sku="{{ $sku }}"
        data-batch="{{ $stock->batch_no }}" data-qty="{{ $stock->qty_available }}"
        data-alloc="{{ $stock->qty_allocated }}">
    <i class="bi bi-pencil-square"></i>
</button>
@endcan
@can(\App\Support\Permission::INVENTORY_TRANSFER)
<button type="button" class="btn btn-sm btn-outline-primary" title="Pindah rak"
        data-bs-toggle="modal" data-bs-target="#modalTransfer"
        data-stock="{{ $stock->id }}" data-sku="{{ $sku }}"
        data-batch="{{ $stock->batch_no }}" data-qty="{{ $stock->qty_available }}"
        data-loc="{{ $stock->location?->code }}">
    <i class="bi bi-arrow-left-right"></i>
</button>
@endcan
@can(\App\Support\Permission::INVENTORY_QUARANTINE)
    @if($stock->status === \App\Models\InventoryStock::STATUS_ACTIVE)
        <button type="button" class="btn btn-sm btn-outline-warning" title="Karantina batch ini"
                data-bs-toggle="modal" data-bs-target="#modalKarantina"
                data-stock="{{ $stock->id }}" data-sku="{{ $sku }}"
                data-batch="{{ $stock->batch_no }}">
            <i class="bi bi-hourglass-split"></i>
        </button>
    @elseif($stock->status === \App\Models\InventoryStock::STATUS_QUARANTINE)
        {{-- Aksi langsung, bukan modal: tidak ada data tambahan yang perlu
             diisi, dan menahannya di balik modal hanya menambah klik. --}}
        <form method="POST" action="{{ route('wms.inventory.quarantine.release', $stock) }}" class="d-inline"
              onsubmit="return confirm('Batalkan karantina batch {{ $stock->batch_no }} sekarang? Batch akan langsung kembali jadi Good Stock.');">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-success" title="Batalkan karantina lebih awal">
                <i class="bi bi-check2-circle"></i>
            </button>
        </form>
    @endif

    {{-- Formula Lama: murni penanda informasi, jadi TIDAK dibatasi oleh
         status baris — batch yang sudah DDP atau sedang dikarantina pun
         tetap boleh diberi tahu formula lama/barunya. --}}
    <form method="POST" action="{{ route('wms.inventory.old-formula', $stock) }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-sm {{ $stock->is_old_formula ? 'btn-info' : 'btn-outline-info' }}"
                title="{{ $stock->is_old_formula ? 'Tandai sebagai Formula Baru' : 'Tandai sebagai Formula Lama' }}">
            <i class="bi bi-flask"></i>
        </button>
    </form>
@endcan
