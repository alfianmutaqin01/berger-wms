# Panduan Go-Live di VPS
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 1.0 — 15 September 2026
> **Untuk:** yang memasang sistem ini pertama kali di server, dan yang memeliharanya sesudahnya.
> **Rujukan teknis:** `docs/6_cicd_docker_setup.md` (mengapa dan bagaimana), `docker-compose.prod.yml` (apa yang berjalan).

Panduan ini berurutan. Setiap langkah punya cara memeriksa bahwa langkah itu berhasil — jangan lanjut sebelum pemeriksaannya lolos.

---

## 0. Yang Harus Disiapkan Dulu

| Kebutuhan | Keterangan |
|---|---|
| **VPS** | Ubuntu 22.04/24.04 LTS, minimal **2 vCPU, 4 GB RAM, 40 GB SSD**. Lokasi Jakarta/Singapura supaya cepat dari gudang |
| **Domain** | Mis. `wms.bergerpaints.co.id`. Akses ke pengaturan DNS-nya |
| **Akun Gmail pengirim** | `logisticsbpikrw@gmail.com` dengan App Password (`docs/8_panduan_email_gmail.md`) |
| **Kunci reCAPTCHA v2** | Dibuat di <https://www.google.com/recaptcha/admin> untuk domain di atas, tipe "Saya bukan robot" |
| **Repositori GitHub** | Akses baca untuk server (deploy key) |
| **Data awal** | Excel master produk, customer, lokasi rak, stok awal per gudang; daftar akun tim dengan email & nomor HP |

---

## 1. Siapkan Server

Masuk ke VPS sebagai root, lalu:

```bash
# Pengguna khusus deploy (jangan bekerja sebagai root)
adduser deploy && usermod -aG sudo deploy

# Firewall: hanya SSH, HTTP, HTTPS
ufw allow OpenSSH && ufw allow 80,443/tcp && ufw enable

# Docker Engine + Compose plugin (skrip resmi Docker)
curl -fsSL https://get.docker.com | sh
usermod -aG docker deploy

# Zona waktu server
timedatectl set-timezone Asia/Jakarta
```

**Periksa:** `docker compose version` menampilkan v2.x; `ufw status` hanya menampilkan 22, 80, 443.

---

## 2. Arahkan Domain ke VPS

Di pengaturan DNS domain, buat **record A**: `wms` → IP publik VPS.

**Periksa** (dari komputer mana pun): `nslookup wms.bergerpaints.co.id` menjawab IP VPS. Tunggu sampai benar sebelum langkah 5 — Caddy gagal meminta sertifikat kalau DNS belum mengarah.

---

## 3. Ambil Kode

Sebagai `deploy`:

```bash
sudo mkdir -p /opt/berger-wms && sudo chown deploy: /opt/berger-wms
ssh-keygen -t ed25519 -C "berger-wms-server"     # tambahkan isi ~/.ssh/id_ed25519.pub sebagai Deploy Key di GitHub
git clone git@github.com:alfianmutaqin/berger-wms.git /opt/berger-wms
cd /opt/berger-wms
git checkout v1.0.0                               # selalu tag rilis, bukan cabang
```

---

## 4. Isi `.env`

```bash
cp .env.production.example .env
chmod 600 .env
nano .env
```

Ganti **setiap** `<ISI>`. Bangkitkan sandi acak dengan `openssl rand -base64 32` untuk `DB_PASSWORD` dan `REDIS_PASSWORD`.

`APP_KEY` dibangkitkan setelah image ada (langkah 5). Sampai saat itu biarkan `<ISI>`.

> [!CAUTION]
> `.env` berisi sandi basis data, App Password Gmail, dan kunci reCAPTCHA. Jangan dikirim lewat chat, jangan di-commit, jangan disalin ke laptop pribadi. **`APP_KEY` tidak boleh diganti** setelah ada data — sesi dan data terenkripsi menjadi tak terbaca.

---

## 5. Bangun dan Jalankan

```bash
docker compose -f docker-compose.prod.yml build

# APP_KEY — salin hasilnya ke .env
docker compose -f docker-compose.prod.yml run --rm --no-deps --entrypoint php php-fpm artisan key:generate --show

docker compose -f docker-compose.prod.yml up -d
```

**Periksa:**

```bash
docker compose -f docker-compose.prod.yml ps            # semua "running"/"healthy"
docker compose -f docker-compose.prod.yml logs caddy    # "certificate obtained successfully"
curl -s https://wms.bergerpaints.co.id/health           # "status":"healthy"
```

---

## 6. Basis Data dan Akun Pertama

```bash
C="docker compose -f docker-compose.prod.yml exec -u www-data php-fpm php artisan"

$C migrate --force
$C db:seed --force
```

Seeder production membuat role, departemen, gudang, termin pembayaran, lokasi, dan **satu Super Admin bersandi acak** yang ditampilkan sekali di layar. Catat sandinya, login di `https://<domain>/login`, lalu **segera ganti sandi** di halaman Profil. Tidak ada akun contoh bersandi `password` yang dibuat di production.

> Seeder produk dan customer ikut berjalan. Bila data sungguhan akan diimpor dari Excel, periksa isi Master Produk/Customer sesudah seeding dan nonaktifkan yang bukan data sungguhan.

---

## 7. Data Awal

Sebagai Super Admin, lewat aplikasi:

1. **Master Lokasi Rak** — lengkapi/impor rak tiap gudang.
2. **Master Produk** → Impor Excel. Periksa jumlah baris hasil impor sama dengan berkas.
3. **Master Customer** → Impor Excel.
4. **Data Stok** → Impor Stok Awal per gudang. Cocokkan totalnya dengan hitung fisik.
5. **Manajemen Pengguna** — buat akun tiap orang. **Sales wajib punya email yang sah** (kabar pesanan dikirim ke sana) dan nomor HP (kabar barang sampai lewat WhatsApp).

---

## 8. Pemeriksaan Pra-Go-Live

```bash
docker compose -f docker-compose.prod.yml exec -u www-data php-fpm php artisan wms:cek-produksi
```

Ulangi sampai **tidak ada GAGAL**. Setiap PERINGATAN harus disetujui dengan sadar — mis. WhatsApp yang memang sengaja masih manual.

Lalu kirim satu email uji (`docs/8_panduan_email_gmail.md` bagian uji kirim) dan pastikan masuk ke kotak surat.

---

## 9. Cadangan

Container `backup` membuat cadangan otomatis tiap hari setelah pukul 01:00 WIB ke `/opt/berger-wms/backups/`, dan menyimpannya 30 hari.

**Buat satu sekarang dan uji pulihkan** — cadangan yang belum pernah dicoba dipulihkan hanya harapan:

```bash
B="docker compose -f docker-compose.prod.yml exec backup"
$B sh /skrip/cadangkan.sh sekarang
$B sh /skrip/pulihkan.sh $(date +%F) --uji           # harus berakhir "UJI PULIH BERHASIL."
```

**Salin ke luar VPS.** Cadangan di disk yang sama tidak menolong bila VPS rusak atau terhapus. Paling sederhana, dari komputer kantor seminggu sekali:

```bash
rsync -avz deploy@<ip-vps>:/opt/berger-wms/backups/ ./cadangan-berger-wms/
```

Isi cadangan adalah data operasional lengkap (termasuk data pelanggan). Simpan di tempat yang aksesnya terbatas.

---

## 10. Pemantauan

1. Daftar di <https://uptimerobot.com> (gratis), buat monitor **Keyword**: URL `https://<domain>/health`, kata kunci `"healthy"`, interval 5 menit, notifikasi ke email tim.
2. Monitor itu ikut berbunyi bila penjadwal atau worker antrean berhenti — keduanya dilaporkan di `/health`.

---

## 11. Deploy Otomatis (setelah go-live)

Di GitHub → Settings → Environments → buat `production`, isi secret:

| Secret | Isi |
|---|---|
| `SERVER_HOST` | IP VPS |
| `SERVER_USER` | `deploy` |
| `SERVER_SSH_KEY` | Private key yang public key-nya ada di `~deploy/.ssh/authorized_keys` |

Rilis berikutnya cukup: merge ke `main`, lalu `git tag v1.0.1 && git push origin v1.0.1`. Pipeline menjalankan test, membuat cadangan pra-deploy, membangun, migrasi, dan memeriksa `/health`. Rincian dan rollback: `docs/6_cicd_docker_setup.md` §6–7.

---

## 12. Hari-H

- [ ] Checklist `docs/5_testing_strategy.md` §10 tercentang semua
- [ ] UAT tiap peran ditandatangani (`docs/10_checklist_uat.md`)
- [ ] Tim tahu alamat login dan cara ganti sandi
- [ ] Satu orang ditunjuk memantau `/health` dan kotak email UptimeRobot di minggu pertama
- [ ] Nomor tag rilis dan tanggal cadangan terakhir dicatat — itulah titik kembali bila ada masalah
