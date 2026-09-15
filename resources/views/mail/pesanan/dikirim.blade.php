{{-- Markdown: baris JANGAN diberi indentasi — empat spasi di depan dibaca sebagai blok kode. --}}
<x-mail::message>
# Barang dalam pengiriman

Halo {{ $namaSales }},

Barang pesanan **{{ $nomorPesanan }}** untuk **{{ $customer }}** sudah berangkat dari gudang.

- **Surat Jalan:** {{ $suratJalan }}
- **Berangkat:** {{ $berangkat }}
- **Supir:** {{ $supir }}
- **Kendaraan:** {{ $plat }}

**Isi Surat Jalan ini**

<x-mail::table>
| SKU | Produk | Qty |
|:----|:-------|----:|
@foreach($muatan as $m)
| {{ $m['sku'] }} | {{ $m['nama'] }} | {{ number_format($m['qty']) }} {{ $m['uom'] }} |
@endforeach
</x-mail::table>

@if($sisa !== [])
<x-mail::panel>
**Masih ada yang belum terkirim** dari pesanan ini:
</x-mail::panel>

<x-mail::table>
| SKU | Produk | Dipesan | Terkirim | Belum |
|:----|:-------|--------:|---------:|------:|
@foreach($sisa as $s)
| {{ $s['sku'] }} | {{ $s['nama'] }} | {{ number_format($s['dipesan']) }} {{ $s['uom'] }} | {{ number_format($s['terkirim']) }} | {{ number_format($s['belum']) }} |
@endforeach
</x-mail::table>
@else
Seluruh qty pesanan ini sudah terkirim.
@endif

Anda akan menerima email lagi saat supir mengonfirmasi barang sampai.

<x-mail::button :url="$urlPesanan">
Lihat pesanan
</x-mail::button>

Email ini dikirim otomatis oleh Berger WMS. No. internal: {{ $nomorInternal }}.
</x-mail::message>
