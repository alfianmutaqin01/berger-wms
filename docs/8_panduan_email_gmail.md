# Panduan Menyalakan Email Otomatis lewat Gmail
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Tanggal:** 14 September 2026
> **Akun pengirim:** `logisticsbpikrw@gmail.com`
> **Waktu yang dibutuhkan:** ± 15 menit

Sistem mengirim email ke **Sales pemilik pesanan** saat pesanan diterima atau ditolak, saat barang berangkat dan sampai, dan saat pesanan selesai (ringkasan). Supaya email benar-benar terkirim, sistem butuh **App Password** dari akun Gmail pengirim.

**App Password** adalah kata sandi khusus 16 huruf yang hanya dipakai sistem untuk mengirim email. Kata sandi ini **bukan** kata sandi login Gmail. Kalau bocor, cukup App Password-nya yang dicabut, dan kata sandi Gmail tetap aman.

---

## Yang perlu disiapkan

- [ ] Kata sandi login akun `logisticsbpikrw@gmail.com`
- [ ] HP yang nomornya bisa menerima SMS/telepon untuk verifikasi
- [ ] Akses ke server tempat Berger WMS berjalan (untuk mengubah berkas `.env`)
- [ ] Satu alamat email aktif untuk tes kirim, misalnya email pribadi Anda

---

## Langkah 1 — Masuk ke akun Google pengirim

1. Buka **https://myaccount.google.com** di browser.
2. Pastikan akun yang aktif adalah **logisticsbpikrw@gmail.com** (lihat foto profil di pojok kanan atas). Kalau yang aktif akun lain, klik foto profil lalu **Tambahkan akun lain** atau **Ganti akun**.

> [!WARNING]
> Salah akun adalah kesalahan yang paling sering terjadi. App Password dari akun lain tidak akan bisa dipakai, dan pesan galatnya tidak menyebut bahwa akunnya salah.

---

## Langkah 2 — Nyalakan Verifikasi 2 Langkah

App Password **hanya muncul** pada akun yang Verifikasi 2 Langkah-nya aktif.

1. Di menu kiri, klik **Keamanan** (*Security*).
2. Di bagian **Cara Anda login ke Google** (*How you sign in to Google*), klik **Verifikasi 2 Langkah** (*2-Step Verification*).
3. Kalau statusnya sudah **Aktif**, lanjut ke Langkah 3.
4. Kalau belum aktif, klik **Aktifkan verifikasi 2 langkah** lalu ikuti petunjuknya:
   - Masukkan kata sandi Gmail bila diminta.
   - Tambahkan **nomor HP**, lalu pilih **SMS** atau **Panggilan telepon**.
   - Masukkan kode yang dikirim ke HP.
   - Klik **Aktifkan**.

> [!IMPORTANT]
> Gunakan **nomor HP** atau **aplikasi Authenticator** sebagai metode verifikasi. Jika akun hanya memakai *security key* (kunci fisik), menu App Password tidak akan muncul.

> [!TIP]
> Karena akun ini dipakai bersama tim Logistik, catat nomor HP yang didaftarkan dan siapa pemegangnya. Tanpa HP itu, tidak ada yang bisa masuk ke akun dari perangkat baru.

---

## Langkah 3 — Buat App Password

1. Buka langsung **https://myaccount.google.com/apppasswords**.
   Cara lain: **Keamanan** → **Verifikasi 2 Langkah** → gulir ke paling bawah → **Sandi aplikasi** (*App passwords*).
2. Masukkan kata sandi Gmail lagi bila diminta.
3. Di kolom **Nama aplikasi** (*App name*), ketik nama yang jelas, misalnya:
   ```
   Berger WMS - Server Karawang
   ```
   Nama ini hanya penanda, tetapi penting: kalau suatu hari perlu dicabut, dari namanya ketahuan App Password mana yang dipakai server.
4. Klik **Buat** (*Create*).
5. Muncul kotak kuning berisi **16 huruf**, contohnya:
   ```
   abcd efgh ijkl mnop
   ```
6. **Salin sekarang.** Google hanya menampilkannya **sekali**; setelah kotak ditutup, huruf-hurufnya tidak bisa dilihat lagi. Kalau terlanjur tertutup, hapus App Password itu lalu buat yang baru.
7. Klik **Selesai** (*Done*).

> [!CAUTION]
> Jangan kirim App Password lewat WhatsApp grup, jangan tempel di dokumen bersama, dan jangan di-commit ke Git. Siapa pun yang memegangnya bisa mengirim email atas nama akun ini.

---

## Langkah 4 — Isi setelan di server

1. Buka berkas **`.env`** di folder proyek `berger-wms` pada server. Berkas ini tidak ikut ke Git.
2. Cari bagian `# Mail`, lalu ganti isinya menjadi:

   ```env
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.gmail.com
   MAIL_PORT=587
   MAIL_USERNAME=logisticsbpikrw@gmail.com
   MAIL_PASSWORD=abcdefghijklmnop
   MAIL_FROM_ADDRESS="logisticsbpikrw@gmail.com"
   MAIL_FROM_NAME="Berger WMS - Logistik"
   MAIL_REPLY_TO_ADDRESS="logisticsbpikrw@gmail.com"
   ```

3. Ganti `abcdefghijklmnop` dengan App Password dari Langkah 3, **ditulis rapat tanpa spasi**.
4. Simpan berkasnya.

Beberapa hal yang wajib diperhatikan:

| Isian | Aturan |
|---|---|
| `MAIL_MAILER` | Harus `smtp`. Selama masih `log`, email hanya ditulis ke log dan **tidak pernah terkirim**. |
| `MAIL_USERNAME` dan `MAIL_FROM_ADDRESS` | **Harus sama persis.** Kalau berbeda, Gmail menimpa pengirimnya atau email masuk folder spam. |
| `MAIL_PASSWORD` | App Password 16 huruf, **bukan** kata sandi login Gmail. |
| `MAIL_REPLY_TO_ADDRESS` | Tujuan balasan kalau Sales menekan *Reply*. Boleh diganti alamat Logistik lain. |

---

## Langkah 5 — Terapkan setelan

Jalankan dari folder proyek di server, **berurutan**:

```bash
# 1. Buat tabel catatan email (sekali saja, saat pertama kali)
docker compose exec -u www-data php-fpm php artisan migrate

# 2. Buang setelan lama yang tersimpan di cache
docker compose exec -u www-data php-fpm php artisan config:clear

# 3. Restart pekerja antrean — WAJIB
docker compose restart queue
```

> [!IMPORTANT]
> **Langkah 3 jangan dilewati.** Email dikirim oleh pekerja antrean (container `queue`), dan pekerja yang sudah berjalan masih memakai setelan lama sampai di-restart. Tanpa restart, halaman web terlihat normal, tetapi email tetap tidak keluar.

> [!NOTE]
> Selalu sertakan `-u www-data`. Perintah `artisan` yang dijalankan sebagai root membuat berkas milik root, dan halaman web bisa mati dengan galat `touch(): Utime failed`.

---

## Langkah 6 — Tes kirim satu email

Ganti `alamat-tes@contoh.com` dengan email Anda sendiri, lalu jalankan:

```bash
docker compose exec -u www-data php-fpm php artisan tinker --execute='\Illuminate\Support\Facades\Mail::raw("Tes email dari Berger WMS. Jika ini sampai, setelan Gmail sudah benar.", fn ($m) => $m->to("alamat-tes@contoh.com")->subject("Tes SMTP Berger WMS")); echo "TERKIRIM\n";'
```

Jalankan di **Git Bash** atau terminal Linux, **bukan PowerShell**. Windows PowerShell membuang tanda kutip ganda di dalam perintah, sehingga tinker menerima kode yang rusak.

**Hasil yang diharapkan:**
- Terminal menampilkan `TERKIRIM`.
- Dalam 1–2 menit, email **"Tes SMTP Berger WMS"** masuk ke kotak masuk alamat tes, dengan pengirim **Berger WMS - Logistik**.
- Periksa juga folder **Spam**. Kalau emailnya ada di sana, tandai **Bukan spam**.

Kalau muncul galat, lihat bagian [Jika ada masalah](#jika-ada-masalah).

---

## Langkah 7 — Tes lewat alur pesanan sungguhan

1. Buka **Manajemen Pengguna** dan pastikan akun Sales yang akan dipakai tes punya **alamat email aktif**. Data contoh seperti `budi.s@berger.co.id` harus diganti alamat yang benar-benar ada.
2. Dengan akun Sales itu, ajukan satu pesanan kecil.
3. Dengan akun Logistik, **terima** pesanan tersebut.
4. Buka **Penerimaan → Riwayat → rincian pesanan tadi**. Gulir ke bawah sampai bagian **Email ke Sales**:

   | Status | Artinya |
   |---|---|
   | **Terkirim** | Selesai. Sales menerima email. |
   | **Menunggu dikirim** | Masih di antrean. Tunggu sebentar lalu muat ulang; kalau lebih dari 2 menit, periksa container `queue`. |
   | **Gagal** | Baca kolom **Keterangan**; penyebabnya tertulis di sana. |
   | **Tidak dikirim** | Kabarnya sudah tidak berlaku saat antrean sampai (misalnya pesanan dibatalkan). Ini bukan kerusakan. |

5. Minta Sales tersebut memeriksa kotak masuknya: harus ada email **"[Berger WMS] Pesanan diterima — …"**.

✅ **Selesai.** Mulai saat ini email untuk kelima kejadian terkirim otomatis.

---

## Jika ada masalah

| Pesan galat / gejala | Penyebab | Cara memperbaiki |
|---|---|---|
| `535-5.7.8 Username and Password not accepted` | App Password salah, masih mengandung spasi, atau yang dipakai kata sandi login Gmail | Salin ulang App Password tanpa spasi. Kalau tidak yakin, hapus lalu buat baru (Langkah 3). Jalankan lagi Langkah 5. |
| `534-5.7.9 Application-specific password required` | `MAIL_PASSWORD` berisi kata sandi login Gmail, bukan App Password | Buat App Password (Langkah 3) lalu isi ke `MAIL_PASSWORD`. |
| Menu **Sandi aplikasi** tidak ada | Verifikasi 2 Langkah belum aktif, hanya memakai *security key*, atau akun ikut *Advanced Protection* | Aktifkan Verifikasi 2 Langkah dengan nomor HP (Langkah 2). *Advanced Protection* memang mematikan App Password. |
| `Connection could not be established` / `timed out` | Jaringan server memblokir port 587 | Minta tim IT membuka koneksi keluar ke `smtp.gmail.com` port 587. |
| Terminal `TERKIRIM`, tapi email tidak ada | Masuk folder Spam, atau `MAIL_MAILER` masih `log` | Periksa folder Spam. Pastikan `MAIL_MAILER=smtp`, lalu ulangi Langkah 5. |
| Tes tinker berhasil, email pesanan tetap tidak keluar | Container `queue` belum di-restart atau mati | `docker compose restart queue`, lalu periksa dengan `docker compose ps`. |
| Status **Gagal**: *"belum punya alamat email yang valid"* | Email akun Sales kosong atau salah tulis | Perbaiki di **Manajemen Pengguna**. |
| `550-5.4.5 Daily user sending limit exceeded` | Batas harian Gmail (± 500 email) terlampaui | Tunggu 24 jam. Dengan volume Berger saat ini, hal ini hanya terjadi kalau ada yang salah (misalnya email terkirim berulang). |

Untuk melihat galat lengkap dari antrean:

```bash
docker compose logs queue --tail=50
```

---

## Perawatan

- **Ganti kata sandi Gmail = App Password ikut hangus.** Setiap kali kata sandi akun `logisticsbpikrw@gmail.com` diganti, Google mencabut semua App Password-nya. Setelah itu, **buat App Password baru** dan ulangi Langkah 4–6. Tanpa itu, semua email berikutnya berstatus Gagal dengan galat `535`.
- **Mencabut akses server:** buka https://myaccount.google.com/apppasswords lalu klik ikon tempat sampah di samping *Berger WMS - Server Karawang*. Email berhenti terkirim seketika, dan akun Gmail tidak terpengaruh.
- **Catat pemegangnya:** siapa yang tahu kata sandi Gmail, nomor HP mana yang dipakai untuk Verifikasi 2 Langkah, dan kapan App Password terakhir dibuat.
- **Periksa sesekali** bagian *Email ke Sales* pada beberapa pesanan. Status **Gagal** yang menumpuk adalah tanda paling awal bahwa ada yang perlu diperbaiki.

---

## Referensi

- [Sign in with app passwords — Google Account Help](https://support.google.com/accounts/answer/185833)
- [How to create app passwords — Google Workspace Knowledge](https://knowledge.workspace.google.com/kb/how-to-create-app-passwords-000009237?hl=en)
- [Gmail App Passwords: Setup and Gotchas — Nylas](https://cli.nylas.com/guides/gmail-app-password-setup)
