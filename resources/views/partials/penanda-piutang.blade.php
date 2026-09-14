{{-- Penanda piutang customer (PRD F-BILL-03) — INFORMASI, TIDAK PERNAH MEMBLOKIR.

     Dua tingkat: merah hanya untuk invoice yang benar-benar LEWAT jatuh tempo.
     Invoice yang masih berjalan cukup abu-abu; menandai semuanya merah membuat
     hampir setiap customer tempo selalu bertanda, dan penanda yang selalu
     menyala berhenti dibaca.

     $penanda: satu elemen dari CustomerBilling::penandaCustomer(), atau null. --}}
@if(! empty($penanda))
    @if($penanda['menunggak'] > 0)
        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger"
              title="{{ $penanda['menunggak'] }} invoice lewat jatuh tempo, terlama sejak {{ \Carbon\Carbon::parse($penanda['jatuh_tempo_terlama'])->translatedFormat('d M Y') }}">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>Menunggak {{ $penanda['lewat_terlama'] }} hari
        </span>
    @elseif($penanda['berjalan'] > 0 && ($lengkap ?? false))
        <span class="badge bg-secondary-subtle text-secondary-emphasis border"
              title="{{ $penanda['berjalan'] }} invoice belum lunas, belum lewat jatuh tempo">
            <i class="bi bi-hourglass-split me-1"></i>{{ $penanda['berjalan'] }} tagihan berjalan
        </span>
    @endif
@endif
