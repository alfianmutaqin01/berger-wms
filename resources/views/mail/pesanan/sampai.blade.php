{{-- Markdown: baris JANGAN diberi indentasi — empat spasi di depan dibaca sebagai blok kode. --}}
<x-mail::message>
# Barang sudah sampai

Halo {{ $namaSales }},

Barang pesanan **{{ $nomorPesanan }}** untuk **{{ $customer }}** sudah dikonfirmasi **sampai** oleh supir.

- **Surat Jalan:** {{ $suratJalan }}
- **Sampai:** {{ $sampai }}
@if($penerima)
- **Diterima oleh:** {{ $penerima }}
@endif
- **Supir / kendaraan:** {{ $supir }} / {{ $plat }}

<x-mail::panel>
**Langkah berikutnya:** unggah foto Surat Jalan bertanda tangan agar pesanan bisa ditutup.
</x-mail::panel>

<x-mail::button :url="$urlPesanan">
Unggah bukti Surat Jalan
</x-mail::button>

Email ini dikirim otomatis oleh Berger WMS. No. internal: {{ $nomorInternal }}.
</x-mail::message>
