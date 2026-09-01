{{-- Tombol aksi satu batch. Dipakai blok Good Stock maupun blok DDP supaya
     keduanya tidak bisa diam-diam menyimpang satu sama lain.

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
