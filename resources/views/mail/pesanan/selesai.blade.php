{{-- Markdown: baris JANGAN diberi indentasi — empat spasi di depan dibaca sebagai blok kode. --}}
<x-mail::message>
# Ringkasan pesanan selesai

Halo {{ $namaSales }},

Pesanan **{{ $nomorPesanan }}** untuk **{{ $customer }}** sudah dinyatakan **{{ $status }}**.

@if($menungguBayar)
<x-mail::panel>
Barang sudah diterima customer, tetapi pembayarannya masih berjalan ({{ $termin ?? 'tempo' }}).
</x-mail::panel>
@endif

**Perjalanan pesanan**

<x-mail::table>
| Tahap | Waktu |
|:------|:------|
@foreach($linimasa as [$tahap, $waktu])
| {{ $tahap }} | {{ $waktu }} |
@endforeach
</x-mail::table>

@if($slaJam !== null)
Waktu proses (SLA): {{ number_format($slaJam, 1, ',', '.') }} jam.
@endif

**Rincian qty**

<x-mail::table>
| SKU | Produk | Dipesan | Diterima | Terkirim | Kurang |
|:----|:-------|--------:|---------:|---------:|-------:|
@foreach($baris as $b)
| {{ $b['sku'] }} | {{ $b['nama'] }} | {{ number_format($b['dipesan']) }} {{ $b['uom'] }} | {{ number_format($b['diterima']) }} | {{ number_format($b['terkirim']) }} | {{ $b['kurang'] > 0 ? number_format($b['kurang']) : '—' }} |
@endforeach
| **Total** | | **{{ number_format($total['dipesan']) }}** | **{{ number_format($total['diterima']) }}** | **{{ number_format($total['terkirim']) }}** | **{{ $total['kurang'] > 0 ? number_format($total['kurang']) : '—' }}** |
</x-mail::table>

@if($suratJalan !== [])
**Surat Jalan**

<x-mail::table>
| No. Surat Jalan | Berangkat | Sampai | Diterima oleh |
|:----------------|:----------|:-------|:--------------|
@foreach($suratJalan as $sj)
| {{ $sj['nomor'] }} | {{ $sj['berangkat'] }} | {{ $sj['sampai'] }} | {{ $sj['penerima'] }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="$urlPesanan">
Lihat pesanan
</x-mail::button>

Email ini dikirim otomatis oleh Berger WMS. No. internal: {{ $nomorInternal }}.
</x-mail::message>
