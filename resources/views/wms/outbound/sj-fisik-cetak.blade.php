<!DOCTYPE html>
{{-- LEMBAR YANG IKUT MASUK KE DALAM AMPLOP.

     SELALU BAHASA INDONESIA, tidak ikut penukar bahasa di navbar. Ini kertas,
     bukan layar: ia dibaca orang yang tidak punya akun — kurir, orang yang
     dititipi — dan dibaca berminggu-minggu setelah dicetak, ketika pilihan
     bahasa siapa pun sudah lama tidak ada hubungannya dengan isinya.

     Tanpa CDN sama sekali. Kertas yang dicetak di komputer tanpa internet
     tidak boleh berubah bentuk gara-gara berkas gaya yang gagal dimuat. --}}
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $paket->code }} — Lembar Serah Terima</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 12px; }
        .kop { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .kop h1 { font-size: 16px; margin: 0 0 2px; letter-spacing: .5px; }
        .kop small { color: #555; }
        .nomor { text-align: right; }
        .nomor .kode { font-size: 20px; font-weight: bold; font-family: "Courier New", monospace; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { border: 1px solid #999; padding: 5px 7px; text-align: left; vertical-align: top; }
        th { background: #eee; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        td.mono, th.mono { font-family: "Courier New", monospace; }
        .rincian { margin-top: 12px; width: 100%; border: 0; }
        .rincian td { border: 0; padding: 2px 0; }
        .rincian td:first-child { width: 130px; color: #555; }
        .total { margin-top: 10px; font-size: 14px; font-weight: bold; }
        .ttd { display: flex; gap: 16px; margin-top: 28px; }
        .ttd div { flex: 1; border: 1px solid #999; padding: 8px; height: 110px; font-size: 11px; }
        .ttd strong { display: block; margin-bottom: 4px; }
        .ttd span { display: block; margin-top: 62px; border-top: 1px solid #555; padding-top: 3px; color: #555; }
        .catatan { margin-top: 14px; font-size: 11px; color: #444; border-left: 3px solid #999; padding-left: 8px; }
        .cetak { margin-bottom: 14px; }
        .cetak button { padding: 7px 16px; font-size: 13px; cursor: pointer; }
        @media print { .cetak { display: none; } body { padding: 0; } }
    </style>
</head>
<body>

<div class="cetak">
    <button type="button" onclick="window.print()">Cetak lembar ini</button>
</div>

<div class="kop">
    <div>
        <h1>LEMBAR SERAH TERIMA SURAT JALAN</h1>
        <small>PT Berger Paints Indonesia &middot; {{ $paket->warehouse?->name ?? '—' }}</small>
    </div>
    <div class="nomor">
        <div class="kode">{{ $paket->code }}</div>
        <small>{{ $paket->sent_at?->format('d/m/Y H:i') }}</small>
    </div>
</div>

<table class="rincian">
    <tr>
        <td>Dikirim lewat</td>
        <td><strong>{{ $paket->carrier_label }}</strong> &mdash; {{ $paket->carrier_name }}</td>
    </tr>
    @if($paket->tracking_no)
    <tr>
        <td>Nomor resi</td>
        <td class="mono">{{ $paket->tracking_no }}</td>
    </tr>
    @endif
    <tr>
        <td>Diserahkan oleh</td>
        <td>{{ $paket->sentBy?->full_name ?? '—' }}</td>
    </tr>
    @if($paket->notes)
    <tr>
        <td>Catatan</td>
        <td>{{ $paket->notes }}</td>
    </tr>
    @endif
</table>

<table>
    <thead>
        <tr>
            <th style="width:32px">No</th>
            <th class="mono" style="width:120px">Surat Jalan</th>
            <th class="mono" style="width:110px">No. SO (BC)</th>
            <th>Pelanggan</th>
            <th style="width:90px">Sampai</th>
            <th style="width:70px">Sesuai?</th>
        </tr>
    </thead>
    <tbody>
    @foreach($paket->items as $i => $item)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td class="mono">{{ $item->deliveryNote?->document_no ?? '—' }}</td>
            <td class="mono">{{ $item->deliveryNote?->bc_so_number ?? '—' }}</td>
            <td>{{ $item->deliveryNote?->customer?->name ?? '—' }}</td>
            <td>{{ $item->deliveryNote?->delivered_at?->format('d/m/Y') ?? '—' }}</td>
            {{-- Kolom kosong yang DISENGAJA. Orang HO mencentangnya dengan
                 pulpen sambil menghitung lembar, lalu memindahkannya ke layar.
                 Menghitung di layar sambil membalik kertas tidak bisa
                 dilakukan dua tangan sekaligus. --}}
            <td></td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="total">Total: {{ $paket->items->count() }} lembar Surat Jalan</div>

<div class="catatan">
    Mohon cocokkan jumlah lembar di dalam amplop dengan daftar di atas. Bila ada yang tidak sesuai
    atau tidak ditemukan, tandai pada kolom terakhir lalu laporkan lewat sistem WMS
    (menu Terima SJ Fisik, paket {{ $paket->code }}).
</div>

<div class="ttd">
    <div>
        <strong>Diserahkan</strong>
        {{ $paket->warehouse?->name ?? 'Gudang' }}
        <span>{{ $paket->sentBy?->full_name ?? '' }}</span>
    </div>
    <div>
        <strong>Dibawa</strong>
        {{ $paket->carrier_name }}
        <span>Nama &amp; tanda tangan</span>
    </div>
    <div>
        <strong>Diterima</strong>
        Kantor Pusat
        <span>Nama &amp; tanda tangan</span>
    </div>
</div>

</body>
</html>
