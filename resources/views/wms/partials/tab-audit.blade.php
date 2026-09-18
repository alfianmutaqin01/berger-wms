{{-- Dua layar audit, SATU menu.

     Log aktivitas dan item ledger menjawab pertanyaan yang berbeda dan tidak
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
    $bolehLedger = auth()->user()?->can(\App\Support\Permission::INVENTORY_LEDGER) ?? false;
@endphp

@if($bolehLog && $bolehLedger)
    {{-- KELAS .btn, BUKAN .nav-pills.

         nav-pills mengambil warnanya dari --bs-nav-pills-link-active-bg, yang
         tidak ikut ditimpa soms-style.css — jadi tabnya menyala biru bawaan
         Bootstrap, bukan navy Berger. Kelas .btn-primary dan .btn-outline-
         primary SUDAH ditimpa ke #123962 di sana, sehingga memakainya berarti
         warnanya mengikuti tema WMS dengan sendirinya: kalau warnanya berubah
         suatu hari, tab ini ikut berubah tanpa ada yang perlu mengingatnya. --}}
    <div class="btn-group mb-3" role="group" aria-label="Pilih layar penelusuran">
        <a class="btn {{ request()->routeIs('wms.admin.activity-log') ? 'btn-primary' : 'btn-outline-primary' }}"
           href="{{ route('wms.admin.activity-log') }}"
           @if(request()->routeIs('wms.admin.activity-log')) aria-current="page" @endif>
            <i class="bi bi-clock-history me-1"></i> Log Aktivitas
        </a>
        <a class="btn {{ request()->routeIs('wms.inventory.item-ledger') ? 'btn-primary' : 'btn-outline-primary' }}"
           href="{{ route('wms.inventory.item-ledger') }}"
           @if(request()->routeIs('wms.inventory.item-ledger')) aria-current="page" @endif>
            <i class="bi bi-journal-text me-1"></i> Item Ledger
        </a>
    </div>
@endif
