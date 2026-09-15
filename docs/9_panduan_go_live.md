# Panduan Go-Live di VPS
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 1.1 — 15 September 2026 *(audit keamanan: SSH, pengguna basis data, cadangan terenkripsi, pipeline)*
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

# Pembaruan keamanan OS terpasang sendiri tiap hari
apt-get update && apt-get install -y unattended-upgrades fail2ban
dpkg-reconfigure -f noninteractive unattended-upgrades
systemctl enable --now fail2ban      # memblokir IP yang menebak-nebak sandi SSH
```

### 1a. Kunci Pintu SSH

SSH adalah pintu ke seluruh server. Tebak-sandi SSH dari internet mulai dalam hitungan menit setelah VPS menyala.

1. **Dari laptop Anda**, pasang kunci SSH untuk `deploy` (sandi tidak akan dipakai lagi):
   ```bash
   ssh-copy-id deploy@<ip-vps>
   ssh deploy@<ip-vps>          # HARUS berhasil tanpa ditanya sandi sebelum lanjut
   ```
2. **Di server**, matikan login dengan sandi dan login root:
   ```bash
   sudo tee /etc/ssh/sshd_config.d/99-berger.conf <<'EOF'
   PasswordAuthentication no
   KbdInteractiveAuthentication no
   PermitRootLogin no
   MaxAuthTries 3
   EOF
   sudo sshd -t && sudo systemctl reload ssh
   ```
3. **Buka jendela terminal BARU** dan pastikan `ssh deploy@<ip-vps>` masih bisa masuk sebelum menutup sesi lama. Kalau gagal, sesi lama masih bisa memperbaikinya.

> [!WARNING]
> **Grup `docker` setara root.** Siapa pun yang bisa menjalankan `docker` bisa membaca seluruh isi server, termasuk `.env` dan cadangan. Hanya `deploy` yang boleh ada di grup itu, dan kunci SSH `deploy` diperlakukan seperti kunci root.

**Periksa:**
- `docker compose version` menampilkan v2.x.
- `ufw status` hanya menampilkan 22, 80, 443.
- `ssh root@<ip-vps>` ditolak.
- `ssh -o PubkeyAuthentication=no deploy@<ip-vps>` ditolak tanpa menanyakan sandi.
- `sudo fail2ban-client status sshd` menampilkan jail aktif.

> Docker membuka port container **melewati** ufw. Itu sebabnya `docker-compose.prod.yml` hanya mem-publish port Caddy. Jangan menambahkan `ports:` ke layanan lain.

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
cp .env.postgres.example .env.postgres
chmod 600 .env .env.postgres
nano .env
nano .env.postgres
mkdir -m 700 kunci-pemulihan          # kosong; dipakai hanya saat memulihkan cadangan
```

Ganti **setiap** `<ISI>`. Bangkitkan sandi acak dengan `openssl rand -base64 32` untuk `DB_PASSWORD`, `REDIS_PASSWORD`, dan `POSTGRES_PASSWORD`. Ketiganya harus berbeda.

**Dua pengguna basis data, dua berkas.**

| Berkas | Pengguna | Dipakai oleh |
|---|---|---|
| `.env` → `DB_USERNAME` | Pengguna aplikasi, **bukan superuser** | php-fpm, queue, scheduler |
| `.env.postgres` → `POSTGRES_USER` | Superuser PostgreSQL | Container `postgres` dan `backup` saja |

`.env` di-mount ke container aplikasi. Kalau aplikasinya tembus, penyerang bisa membaca isinya, dan karena itulah sandi superuser tidak boleh ada di sana. Pengguna aplikasi dibuat otomatis oleh `docker/postgres/init` saat container postgres pertama kali dijalankan. Sebagai pemilik basis data biasa, ia bisa menjalankan migrasi tetapi tidak bisa membaca berkas server atau menjalankan perintah sistem.

`APP_KEY` dibangkitkan setelah image ada (langkah 5). Sampai saat itu biarkan `<ISI>`. `BACKUP_KUNCI_PUBLIK` diisi di langkah 5 juga.

> [!NOTE]
> **Server yang terlanjur dipasang dengan `DB_USERNAME` superuser.** Skrip init hanya berjalan pada volume kosong. Untuk server semacam itu, buat superuser terpisah lalu turunkan hak pengguna aplikasi:
> ```bash
> docker compose -f docker-compose.prod.yml exec postgres psql -U <DB_USERNAME> -d <DB_DATABASE> \
>   -c "CREATE ROLE <POSTGRES_USER> LOGIN SUPERUSER PASSWORD '<POSTGRES_PASSWORD>';"
> docker compose -f docker-compose.prod.yml exec postgres psql -U <POSTGRES_USER> -d <DB_DATABASE> \
>   -c "ALTER ROLE <DB_USERNAME> NOSUPERUSER NOCREATEDB NOCREATEROLE;"
> ```
> `wms:cek-produksi` menandai GAGAL selama pengguna aplikasi masih superuser.

> [!CAUTION]
> `.env` berisi sandi basis data, App Password Gmail, dan kunci reCAPTCHA. Jangan dikirim lewat chat, jangan di-commit, jangan disalin ke laptop pribadi. **`APP_KEY` tidak boleh diganti** setelah ada data — sesi dan data terenkripsi menjadi tak terbaca.

---

## 5. Bangun dan Jalankan

```bash
docker compose -f docker-compose.prod.yml build

# APP_KEY — salin hasilnya ke .env
docker compose -f docker-compose.prod.yml run --rm --no-deps --entrypoint php php-fpm artisan key:generate --show

# Kunci cadangan — lihat kotak di bawah SEBELUM menjalankan ini
docker compose -f docker-compose.prod.yml run --rm --no-deps backup age-keygen

docker compose -f docker-compose.prod.yml up -d
```

> [!CAUTION]
> **Kunci cadangan.** `age-keygen` mencetak dua baris:
> - `# public key: age1…` → salin ke `.env` sebagai `BACKUP_KUNCI_PUBLIK`. Kunci ini tidak rahasia.
> - `AGE-SECRET-KEY-1…` → **kunci privat**. Simpan di password manager perusahaan, dipegang minimal dua orang (mis. IT dan Manager). **Jangan disimpan di server, jangan dikirim lewat chat atau email.**
>
> Cadangan hanya bisa dibuka dengan kunci privat ini. **Kunci hilang berarti seluruh cadangan tidak bisa dipakai.** Setelah dicatat, bersihkan layar terminal (`clear`).

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

Container `backup` membuat cadangan otomatis tiap hari setelah pukul 01:00 WIB ke `/opt/berger-wms/backups/`, dan menyimpannya 30 hari. Setiap berkas **dienkripsi** dengan `BACKUP_KUNCI_PUBLIK` (`*.dump.age`, `*.tgz.age`). Server hanya memegang kunci publik: penyerang yang menguasai VPS bisa menghapus cadangan, tetapi tidak bisa membaca isinya. Tanpa `BACKUP_KUNCI_PUBLIK`, container `backup` berhenti dan tidak membuat cadangan tanpa enkripsi.

**Buat satu sekarang dan uji pulihkan** — cadangan yang belum pernah dicoba dipulihkan hanya harapan. Uji pulih butuh kunci privat, yang ditaruh di server **hanya selama perintah ini berjalan**:

```bash
B="docker compose -f docker-compose.prod.yml exec backup"
$B sh /skrip/cadangkan.sh sekarang

install -m 600 /dev/stdin kunci-pemulihan/cadangan.key    # tempel AGE-SECRET-KEY-1…, Enter, Ctrl+D
$B sh /skrip/pulihkan.sh $(date +%F) --uji                 # harus berakhir "UJI PULIH BERHASIL."
shred -u kunci-pemulihan/cadangan.key                     # WAJIB — jangan tinggalkan kuncinya
```

Ulangi uji pulih **sebulan sekali**. Uji ini sekaligus membuktikan bahwa kunci privat di password manager masih yang benar.

**Salin ke luar VPS.** Cadangan di disk yang sama tidak menolong bila VPS rusak atau terhapus. Paling sederhana, dari komputer kantor seminggu sekali:

```bash
rsync -avz deploy@<ip-vps>:/opt/berger-wms/backups/ ./cadangan-berger-wms/
```

Lebih baik lagi: object storage dengan **Object Lock / versioning** (mis. S3, Backblaze B2, Wasabi) sehingga salinan tidak bisa dihapus dari server. Tanpa itu, penyerang yang menguasai VPS bisa menghapus cadangan lokal *dan* salinan yang bisa dijangkau server.

Berkasnya terenkripsi, tetapi tetap simpan di tempat yang aksesnya terbatas. Kunci privat **tidak pernah** disimpan di folder yang sama dengan cadangan.

---

## 10. Pemantauan

1. Daftar di <https://uptimerobot.com> (gratis), buat monitor **Keyword**: URL `https://<domain>/health`, kata kunci `"healthy"`, interval 5 menit, notifikasi ke email tim.
2. Monitor itu ikut berbunyi bila penjadwal atau worker antrean berhenti — keduanya dilaporkan di `/health`.

---

## 11. Deploy Otomatis (setelah go-live)

Di GitHub → Settings → Environments → buat `production`:

1. **Required reviewers**: centang, lalu pilih minimal satu orang **selain** yang biasa membuat tag rilis. Deploy menunggu persetujuannya. Tanpa ini, siapa pun yang bisa push tag bisa memasang kode ke server produksi.
2. **Deployment branches and tags**: batasi ke tag `v*`.
3. Isi secret:

| Secret | Isi |
|---|---|
| `SERVER_HOST` | IP VPS |
| `SERVER_USER` | `deploy` |
| `SERVER_SSH_KEY` | Private key **khusus pipeline** (bukan kunci laptop siapa pun). Buat dengan `ssh-keygen -t ed25519 -f berger-deploy -N ""`, tambahkan `berger-deploy.pub` ke `~deploy/.ssh/authorized_keys`, lalu hapus berkas privatnya dari laptop setelah ditempel ke GitHub |
| `SERVER_SSH_FINGERPRINT` | Sidik jari kunci host server. Jalankan **di server** `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` dan salin bagian `SHA256:…`. Tanpanya pipeline menerima server mana pun yang menjawab di IP itu |

> [!WARNING]
> Kunci `SERVER_SSH_KEY` membuka akun `deploy`, yang ada di grup `docker` dan karena itu setara root. Batasi siapa yang boleh mengubah secret dan workflow di repositori (Settings → Collaborators, branch protection untuk `.github/`). Bila kunci ini diduga bocor, hapus barisnya dari `authorized_keys` **hari itu juga**.

Action di workflow dikunci ke commit SHA, bukan tag. Dependabot mengusulkan pembaruannya lewat PR (`.github/dependabot.yml`); tinjau PR itu seperti PR kode biasa.

Rilis berikutnya cukup: merge ke `main`, lalu `git tag v1.0.1 && git push origin v1.0.1`. Pipeline menjalankan test, membuat cadangan pra-deploy, membangun, migrasi, dan memeriksa `/health`. Rincian dan rollback: `docs/6_cicd_docker_setup.md` §6–7.

---

## 12. Hari-H

- [ ] Checklist `docs/5_testing_strategy.md` §10 tercentang semua
- [ ] UAT tiap peran ditandatangani (`docs/10_checklist_uat.md`)
- [ ] Tim tahu alamat login dan cara ganti sandi
- [ ] Satu orang ditunjuk memantau `/health` dan kotak email UptimeRobot di minggu pertama
- [ ] Nomor tag rilis dan tanggal cadangan terakhir dicatat — itulah titik kembali bila ada masalah
- [ ] Minimal dua orang tahu cara membuka kunci privat cadangan di password manager
