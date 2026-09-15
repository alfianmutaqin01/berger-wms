# Checklist UAT (User Acceptance Test)
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 1.0 — 15 September 2026
> **Untuk:** perwakilan tiap peran yang menguji sistem sebelum go-live, dan koordinator UAT.
> **Lingkungan:** server staging/production dengan HTTPS, **bukan** laptop pengembang — kamera HP, email, dan WhatsApp hanya bisa diuji di sana.

## Cara Memakai

1. Koordinator menyiapkan akun uji untuk tiap peran (§0), di gudang yang sama.
2. Setiap penguji mengerjakan bagiannya **berurutan**, dengan perangkat yang sungguh akan dipakai (Sales & supir: HP; gudang & kantor: komputer).
3. Centang ✅ bila hasil sesuai kolom "Yang harus terjadi". Bila tidak, tulis di **Catatan temuan** di akhir peran: nomor langkah, apa yang terjadi, tangkapan layar.
4. Temuan dibagi dua: **Penghalang** (go-live ditunda) dan **Perbaikan** (boleh menyusul).
5. Setiap peran menandatangani lembar persetujuan di akhir dokumen.

Alur uji saling menyambung: pesanan yang dibuat Sales di §3 diterima Logistik di §4, diambil Operator di §5, dan seterusnya. Kerjakan bersama dalam satu sesi.

Alur yang sama sudah diuji otomatis (`tests/Feature/Alur/AlurPesananTest.php`). UAT memastikan hal yang tidak bisa dibuktikan test: **tampilan, kemudahan, perangkat sungguhan, dan pesan yang benar-benar sampai.**

> Di luar scope go-live, jangan dicari: Scan QR rak, cetak dokumen/PDF, notifikasi bersuara (PRD v1.4).

---

## 0. Persiapan (Koordinator)

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 0.1 | Buat akun: Manager, Logistik, Produksi, Operator Gudang, 2 Sales (A dan B) — email & HP sungguhan | Akun tampil di Manajemen Pengguna | ☐ |
| 0.2 | Pastikan ada ≥ 2 produk berstok dan 2 customer (1 tunai, 1 tempo 30 hari) | Tampil di Data Stok & Master Customer | ☐ |
| 0.3 | Buka `https://<domain>/health` | `"status":"healthy"`, `penjadwal` dan `antrean` `true` | ☐ |

---

## 1. Semua Peran — Masuk & Akun

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 1.1 | Buka alamat dengan `http://` | Dialihkan ke `https://`, ada ikon gembok | ☐ |
| 1.2 | Login tanpa mencentang "Saya bukan robot" | Ditolak | ☐ |
| 1.3 | Login dengan sandi salah 3 kali | Terkunci sementara dari perangkat itu, ada keterangan jam boleh mencoba lagi | ☐ |
| 1.3a | Super Admin menonaktifkan akun yang sedang login di HP lain, lalu HP itu membuka menu | HP itu langsung keluar dengan pesan "Akun Anda dinonaktifkan" | ☐ |
| 1.4 | Login benar | Masuk ke dashboard sesuai peran | ☐ |
| 1.5 | Profil → ganti sandi | Berhasil; perangkat lain yang login ikut keluar | ☐ |
| 1.6 | Login di perangkat ke-3 | Perangkat terlama keluar (maks. 2 perangkat) | ☐ |
| 1.7 | Diamkan halaman > 1 jam, lalu klik menu | Diminta login ulang | ☐ |
| 1.8 | Menu di sidebar | Hanya menu yang boleh untuk peran itu yang tampil | ☐ |

---

## 2. Tim Produksi — Barang Masuk

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 2.1 | Input Produksi → unggah Excel produksi, pilih tanggal | Pratinjau tampil: jumlah palet terpecah sesuai kapasitas palet produk | ☐ |
| 2.2 | Unggah berkas yang sama sekali lagi | Baris duplikat ditandai di pratinjau | ☐ |
| 2.3 | Simpan | Nomor dokumen IN-… terbit; Riwayat Produksi menampilkan "Menunggu Put-away" | ☐ |
| 2.4 | Permintaan Material → buat MRF untuk bahan produksi | Tautan persetujuan terkirim ke WhatsApp atasan (atau tombol "Buka WhatsApp" pada mode manual) | ☐ |
| 2.5 | Atasan membuka tautan di HP dan menyetujui | Status MRF berubah; tidak perlu login | ☐ |

---

## 3. Sales (HP) — Pesanan

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 3.1 | Pesanan Baru → cari customer tunai dengan mengetik sebagian nama | Customer ditemukan cepat | ☐ |
| 3.2 | Tambah produk | Indikator ketersediaan tampil **tanpa angka stok** | ☐ |
| 3.3 | Simpan Draft, lalu buka lagi dan Kirim | Status "Menunggu Diterima" | ☐ |
| 3.4 | Buat pesanan kedua untuk customer tempo | Terkirim | ☐ |
| 3.5 | Pilih customer yang punya tagihan lewat jatuh tempo (bila ada) | Muncul peringatan "Menunggak", pesanan **tetap bisa dikirim** | ☐ |
| 3.6 | Setelah pukul 15:00 WIB | Tombol Kirim terkunci, Simpan Draft tetap bisa | ☐ |
| 3.7 | Sales B membuka alamat pesanan milik Sales A (salin tautannya) | "Tidak ditemukan" | ☐ |

---

## 4. Logistik — Menerima, Surat Jalan, Verifikasi

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 4.1 | Lonceng | Ada kabar pesanan baru dari §3 | ☐ |
| 4.2 | Terima Pesanan → buka pesanan tunai, isi nomor SO BC, terima sebagian (mis. 8 dari 10) | Diterima; stok tercadang | ☐ |
| 4.3 | **Sales A**: kotak email | Email "pesanan diterima" menyebut dipesan 10 / diterima 8 | ☐ |
| 4.4 | Tolak satu pesanan dengan alasan | Sales menerima lonceng + email berisi alasannya | ☐ |
| 4.5 | Daftar Picking → pilih pesanan, susun daftar | Daftar PL-… terbit, pesanan hilang dari antrean | ☐ |
| 4.6 | *(setelah §5)* Surat Jalan (BC) → impor ekspor SJ dari BC | SJ berpasangan dengan pesanan lewat nomor SO; yang tidak cocok tampil sebagai "yatim" | ☐ |
| 4.7 | Pasangkan SJ yatim ke pesanannya | Nomor SO pesanan disamakan dengan dokumen BC | ☐ |
| 4.8 | Buka SJ, isi supir, No. WA, plat → Berangkatkan | Status "Dalam Pengiriman"; tautan konfirmasi terkirim ke WA supir | ☐ |
| 4.9 | **Sales A**: email | Email "barang dikirim" berisi nama & nomor supir | ☐ |
| 4.10 | *(setelah §6)* Verifikasi Bukti SJ → buka foto, Selesaikan | Pesanan tunai "Selesai"; pesanan tempo "Selesai – Menunggu Pembayaran" | ☐ |
| 4.11 | **Sales A**: email | Email ringkasan pesanan selesai | ☐ |
| 4.12 | Riwayat Outstanding → pesanan dengan kekurangan → Kirim Ulang | Nomor SO sama, pesanan kembali ke antrean picking | ☐ |
| 4.13 | Verifikasi Logistik → dokumen produksi dari §2 *(setelah §5)* | Selisih qty dari Operator ditandai; setelah disahkan stok aktif bertambah | ☐ |
| 4.14 | Penolakan Customer → proses laporan retur | Barang dialokasikan ke Good Stock / DDP sesuai pilihan | ☐ |
| 4.15 | Laporan & Analisis → pratinjau → Unduh Excel | Berkas terbuka di Excel, angka bisa dijumlahkan | ☐ |

---

## 5. Operator Gudang (HP/tablet) — Put-away & Picking

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 5.1 | Put-away → dokumen dari §2 | Saran rak berkapasitas tampil | ☐ |
| 5.2 | Ketik kode rak, ubah qty aktual satu palet | Tersimpan; dokumen pindah ke Verifikasi | ☐ |
| 5.3 | Ketik kode rak yang salah / gudang lain | Ditolak dengan pesan jelas | ☐ |
| 5.4 | Proses Picking → ambil daftar dari §4.5 | Daftar menjadi milik operator ini | ☐ |
| 5.5 | Operator lain mencoba mengambil daftar yang sama | Ditolak | ☐ |
| 5.6 | Tandai baris terambil; satu baris tandai **kurang** dengan alasan | Kekurangan tercatat beserta alasannya | ☐ |
| 5.7 | Siap Loading | Pesanan "Siap Kirim"; kekurangan tampil sebagai outstanding | ☐ |
| 5.8 | Stocktake: hitung satu rak | Angka tersimpan, stok **belum** berubah sebelum disahkan | ☐ |

---

## 6. Supir & Sales — Barang Sampai

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 6.1 | Supir membuka tautan WA dari §4.8 di HP | Terbuka tanpa login, berisi nomor SJ dan barang | ☐ |
| 6.2 | Ambil foto dengan kamera, isi nama penerima, konfirmasi | Terkirim; tautan yang sama tidak bisa dipakai konfirmasi dua kali | ☐ |
| 6.3 | **Sales A**: lonceng, email, WhatsApp | Kabar barang sampai (WA hanya pada mode Cloud API/Fonnte) | ☐ |
| 6.4 | Sales membuka pesanan → unggah foto Surat Jalan bertanda tangan dari kamera HP | Status "Menunggu Verifikasi Bukti" | ☐ |
| 6.5 | Unggah berkas PDF atau > 5 MB | Ditolak dengan pesan jelas | ☐ |

---

## 7. Manager — Pengawasan & Billing

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 7.1 | Dashboard | Angka hari ini sesuai kegiatan UAT | ☐ |
| 7.2 | Data Stok → koreksi qty satu baris dengan alasan | Tersimpan dan tercatat di ledger | ☐ |
| 7.3 | Billing & Piutang → tab Berjalan | Tagihan pesanan tempo dari §4.10, jatuh tempo = tanggal sampai + 30 hari | ☐ |
| 7.4 | *(Logistik)* Konfirmasi Lunas metode Giro tanpa nomor giro | Ditolak — nomor giro wajib | ☐ |
| 7.5 | Konfirmasi Lunas dengan nomor giro | Pindah ke tab Lunas; pesanan "Selesai" | ☐ |
| 7.6 | Batalkan konfirmasi lunas dengan alasan | Tagihan kembali belum lunas | ☐ |
| 7.7 | Keesokan paginya (07:00) | Lonceng pengingat tagihan segera/lewat jatuh tempo | ☐ |
| 7.8 | Menu Input Produksi, Put-away, Proses Picking | **Tidak tampil** untuk Manager | ☐ |
| 7.9 | Manajemen Pengguna → coba ubah akun Super Admin | Ditolak | ☐ |

---

## 8. Super Admin — Sistem

| # | Langkah | Yang harus terjadi | ✅ |
|---|---|---|---|
| 8.1 | Log Aktivitas → saring per pelaku dan tindakan | Kegiatan UAT tercatat (koreksi stok, lunas, dsb.), rincian terbaca | ☐ |
| 8.2 | Setelan Operasional → ubah satu nilai | Tersimpan dan berlaku | ☐ |
| 8.3 | Kapasitas Palet → ubah aturan satu produk | Input produksi berikutnya memakai kapasitas baru | ☐ |
| 8.4 | Nonaktifkan akun Sales B | Sales B tidak bisa login | ☐ |

---

## Catatan Temuan

| Peran | Langkah | Yang terjadi | Penghalang / Perbaikan | Ditangani |
|---|---|---|---|---|
| | | | | |
| | | | | |
| | | | | |

---

## Persetujuan

Dengan menandatangani, perwakilan peran menyatakan alur peran tersebut siap dipakai pada go-live, dengan catatan temuan di atas.

| Peran | Nama | Tanggal | Tanda tangan |
|---|---|---|---|
| Manager | | | |
| Tim Logistik | | | |
| Tim Produksi | | | |
| Operator Gudang | | | |
| Tim Sales | | | |
| Super Admin | | | |
