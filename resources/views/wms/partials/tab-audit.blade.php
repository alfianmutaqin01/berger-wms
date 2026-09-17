{{-- Dua layar audit, SATU menu.

     Log aktivitas dan kartu stok menjawab pertanyaan yang berbeda dan tidak
     bisa dilebur jadi satu tabel — yang satu berbaris per tindakan orang, yang
     satu per pergerakan angka, dan satu tindakan bisa melahirkan puluhan
     pergerakan. Yang bisa disatukan pintunya: keduanya dibuka dari satu menu,
     lalu berpindah lewat tab ini tanpa kembali ke sidebar.

     Tiap tab dijaga izinnya sendiri. Logistik dan Manager merekonsiliasi stok
     tetapi tidak membaca log perbuatan orang; Super Admin membaca keduanya.
     Yang hanya berhak atas satu tab tidak melihat tab satunya sama sekali —
     bukan melihatnya lalu ditolak. --}}
@php
    $bolehLog = auth()->user()?->can(\App\Support\Permission::ADMIN_AUDIT) ?? false;
    $bolehKartu = auth()->user()?->can(\App\Support\Permission::INVENTORY_LEDGER) ?? false;
@endphp

@if($bolehLog && $bolehKartu)
    <ul class="nav nav-pills gap-2 mb-3">
        <li class="nav-item">
            <a class="nav-link rounded-3 {{ request()->routeIs('wms.admin.activity-log') ? 'active' : 'text-body bg-light border' }}"
               href="{{ route('wms.admin.activity-log') }}">
                <i class="bi bi-clock-history me-1"></i> Log Aktivitas
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-3 {{ request()->routeIs('wms.inventory.kartu-stok') ? 'active' : 'text-body bg-light border' }}"
               href="{{ route('wms.inventory.kartu-stok') }}">
                <i class="bi bi-journal-text me-1"></i> Kartu Stok
            </a>
        </li>
    </ul>
@endif
