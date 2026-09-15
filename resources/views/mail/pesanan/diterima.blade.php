{{-- Markdown: baris JANGAN diberi indentasi — empat spasi di depan dibaca sebagai blok kode. --}}
<x-mail::message>
# Pesanan diterima Logistik

Halo {{ $namaSales }},

Pesanan **{{ $nomorPesanan }}** untuk **{{ $customer }}** sudah diterima Logistik pada {{ $diterimaPada }} oleh {{ $diterimaOleh }}.

@if($totalSelisih > 0)
<x-mail::panel>
**Tidak semua qty diterima.** Dipesan {{ number_format($totalDipesan) }}, diterima {{ number_format($totalDiterima) }} — kurang {{ number_format($totalSelisih) }}. Mohon kabarkan ke customer.
</x-mail::panel>
@else
Seluruh qty yang dipesan diterima ({{ number_format($totalDiterima) }}).
@endif

<x-mail::table>
| SKU | Produk | Dipesan | Diterima | Kurang |
|:----|:-------|--------:|---------:|-------:|
@foreach($baris as $b)
| {{ $b['sku'] }} | {{ $b['nama'] }} | {{ number_format($b['dipesan']) }} {{ $b['uom'] }} | {{ number_format($b['diterima']) }} | {{ $b['selisih'] > 0 ? number_format($b['selisih']) : '—' }} |
@endforeach
</x-mail::table>

@if($menungguStok > 0)
Catatan: {{ number_format($menungguStok) }} unit dari yang diterima **masih menunggu stok** masuk gudang, jadi belum bisa langsung disiapkan.
@endif

@if(filled($catatan))
**Catatan Logistik:** {{ $catatan }}
@endif

<x-mail::button :url="$urlPesanan">
Lihat pesanan
</x-mail::button>

Email ini dikirim otomatis oleh Berger WMS. No. internal: {{ $nomorInternal }}.
</x-mail::message>
