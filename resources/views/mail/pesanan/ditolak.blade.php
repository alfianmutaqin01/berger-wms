{{-- Markdown: baris JANGAN diberi indentasi — empat spasi di depan dibaca sebagai blok kode. --}}
<x-mail::message>
# Pesanan ditolak Logistik

Halo {{ $namaSales }},

Pesanan **{{ $nomorPesanan }}** untuk **{{ $customer }}** ditolak Logistik pada {{ $ditolakPada }} oleh {{ $ditolakOleh }}.

<x-mail::panel>
**Alasan:** {{ $alasan }}
</x-mail::panel>

Pesanan ini masih bisa diperbaiki lalu diajukan ulang dari Portal Sales.

@if($baris !== [])
<x-mail::table>
| SKU | Produk | Dipesan |
|:----|:-------|--------:|
@foreach($baris as $b)
| {{ $b['sku'] }} | {{ $b['nama'] }} | {{ number_format($b['dipesan']) }} {{ $b['uom'] }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="$urlPesanan">
Perbaiki pesanan
</x-mail::button>

Email ini dikirim otomatis oleh Berger WMS. No. internal: {{ $nomorInternal }}.
</x-mail::message>
