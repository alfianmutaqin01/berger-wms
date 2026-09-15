# CI/CD dengan Docker & GitHub
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 2.0
> **Tanggal:** 14 September 2026 *(ditulis ulang dari v1.1, 26 Agustus 2026)*
> **Teknologi:** Docker Compose, GitHub Actions, Caddy, Nginx, PHP-FPM 8.3, PostgreSQL 16, Redis 7

> [!IMPORTANT]
> **Kenapa ditulis ulang (Fase 13).** Versi 1.x menyalin isi berkas konfigurasi ke dalam dokumen, lalu keduanya berjalan sendiri-sendiri. Saat audit pra-go-live, salinan itu masih menjalankan Horizon (tidak terpasang), Soketi (tidak dipakai), dan tahap build Node (tidak ada view yang memakainya). Berkas override production-nya bahkan membuka PostgreSQL dan Redis ke internet, karena Compose **menggabungkan** `ports: []` dengan milik berkas dasar, bukan menimpanya.
>
> Dokumen ini sekarang hanya menjelaskan **mengapa** dan **bagaimana**. **Isi konfigurasi ada di berkasnya sendiri** — bacalah berkas itu, bukan salinan di sini.

---

## Daftar Isi

1. [Peta Berkas](#1-peta-berkas)
2. [Pengembangan (Windows + Docker Desktop)](#2-pengembangan)
3. [Image & Container](#3-image--container)
4. [Production (VPS)](#4-production-vps)
5. [Alur Git & CI](#5-alur-git--ci)
6. [Deploy (CD)](#6-deploy-cd)
7. [Rollback](#7-rollback)
8. [Pemantauan](#8-pemantauan)
9. [Galat yang Pernah Terjadi](#9-galat-yang-pernah-terjadi)

---

## 1. Peta Berkas

| Berkas | Isi |
|---|---|
| `docker-compose.yml` | Stack **pengembangan**: nginx (8080), php-fpm, queue, scheduler, postgres, redis. Kode dipasang lewat bind mount |
| `docker-compose.prod.yml` | Stack **production**, berkas mandiri (bukan override): caddy, nginx, php-fpm, queue, scheduler, postgres, redis, backup |
| `docker/php/Dockerfile` | Image PHP: tahap `composer-deps` → `development` → `production` |
| `docker/php/php.ini`, `php-production.ini` | Konfigurasi PHP bersama, dan penimpa khusus production (OPcache tanpa cek mtime) |
| `docker/php/dev-entrypoint.sh` | Pengembangan: mengembalikan kepemilikan `storage/` ke www-data saat start |
| `docker/php/prod-entrypoint.sh` | Production: membangun cache config/route/view/event sebagai www-data lalu menjalankan perintahnya |
| `docker/nginx/Dockerfile`, `default.conf`, `nginx.conf` | Nginx: berkas statis + FastCGI; IP asli dari proxy; batas laju POST /login |
| `docker/caddy/Caddyfile` | HTTPS otomatis (Let's Encrypt) + HSTS untuk `APP_DOMAIN` |
| `docker/backup/cadangkan.sh`, `pulihkan.sh` | Cadangan harian basis data + berkas unggahan; pemulihan dan uji pulih |
| `.env.example` | Template pengembangan |
| `.env.production.example` | Template production — setiap `<ISI>` wajib diganti |
| `.env.ci` | Lingkungan GitHub Actions (kunci reCAPTCHA dummy agar test gagal-reCAPTCHA benar-benar diuji) |
| `.github/workflows/test.yml` | CI: Pint + seluruh test |
| `.github/workflows/deploy.yml` | CD: test ulang untuk tag, lalu deploy ke VPS lewat SSH |
| `uji.sh`, `bin/artisan` | Jalan pintas pengujian dan artisan (selalu sebagai www-data) |

---

## 2. Pengembangan

```bash
docker compose up -d                 # sekali, atau setelah restart komputer
./bin/artisan migrate --seed         # basis data pengembangan
./uji.sh ubah                        # test yang menyangkut perubahan
./uji.sh penuh                       # WAJIB hijau sebelum commit
```

Aplikasi: `http://localhost:8080`. Tanpa Node/npm — tidak ada langkah build frontend.

> [!WARNING]
> **Artisan selalu sebagai www-data.** `docker compose exec php-fpm php artisan …` tanpa `-u www-data` membuat berkas milik root di `storage/`, dan halaman mati dengan `touch(): Utime failed`. Pakai `./bin/artisan`, atau sertakan `-u www-data`.

---

## 3. Image & Container

**Tahap `production` di `docker/php/Dockerfile`** menyalin vendor hasil `composer install --no-dev` dan seluruh kode, membuat tautan `public/storage`, dan memakai `prod-entrypoint.sh`. `.env` **tidak** ikut image (`.dockerignore`); ia dipasang dari server saat container jalan. Karena itu cache konfigurasi dibangun di entrypoint, bukan saat build — membangunnya saat build berarti membekukan konfigurasi kosong.

**Image nginx production** menyalin `public/` ke dalam image. Foto profil dilayani dari volume `app_storage` yang dipasang read-only.

**Tiga container memakai image aplikasi yang sama** dengan perintah berbeda:

| Service | Perintah | Catatan |
|---|---|---|
| `php-fpm` | `php-fpm` | Master root, worker www-data. Satu-satunya yang mengompilasi view |
| `queue` | `queue:work redis --tries=3 --timeout=60 --max-time=3600` | Berhenti sendiri tiap jam, dihidupkan lagi `restart: always` |
| `scheduler` | `schedule:work` | Sapuan stok, pengingat billing, pembersihan, detak |

---

## 4. Production (VPS)

Langkah pemasangan pertama dari nol: **`docs/9_panduan_go_live.md`**.

### 4.1 Yang terbuka ke internet

Hanya **Caddy** di port 80 dan 443. Nginx, PHP-FPM, PostgreSQL, dan Redis hanya bisa dicapai dari jaringan internal Docker. Tetap pasang firewall VPS (`ufw allow 22,80,443`) sebagai lapis kedua.

```
Internet ─▶ Caddy (HTTPS, HSTS) ─▶ nginx (statis, batas laju) ─▶ php-fpm ─▶ postgres / redis
                                                                  queue ──┘
                                                              scheduler ──┘
                                        backup ─▶ postgres + volume app_storage ─▶ ./backups
```

### 4.2 Keamanan di sisi aplikasi

| Lapisan | Di mana |
|---|---|
| HTTPS + HSTS | `docker/caddy/Caddyfile` |
| Proxy tepercaya (skema https, IP asli) | `bootstrap/app.php` → `trustProxies`; `docker/nginx/default.conf` → `set_real_ip_from` |
| Cookie hanya lewat HTTPS | `SESSION_SECURE_COOKIE=true` |
| CSP, Permissions-Policy, nosniff | `app/Http/Middleware/SecurityHeaders.php` |
| Aset CDN versi terkunci + SRI | view Blade; dijaga `RouteSecurityTest` |
| Berkas privat tidak dilayani langsung | `config/filesystems.php` → `serve => false` |
| Redis bersandi, kebijakan `noeviction` | `docker-compose.prod.yml` (antrean tidak boleh digusur saat memori penuh) |

### 4.3 Memeriksa konfigurasi

```bash
docker compose -f docker-compose.prod.yml exec -u www-data php-fpm php artisan wms:cek-produksi
```

GAGAL berarti belum boleh dipakai: APP_DEBUG menyala, kunci reCAPTCHA kosong, antrean tidak berdetak, akun bersandi `password`, dan seterusnya.

---

## 5. Alur Git & CI

| Cabang | Fungsi |
|---|---|
| `main` | Yang terpasang di production; tag rilis `vX.Y.Z` dibuat dari sini |
| `develop` | Integrasi; setiap push menjalankan CI |
| `feat/fase-N-<nama>` | Pekerjaan per fase, PR ke `develop` |

Aturannya: `./uji.sh penuh` hijau di lokal → commit → push cabang → PR ke `develop` → CI hijau → merge.

**CI (`test.yml`)** berjalan pada PR ke `develop`/`main`, push ke `develop`, dan dipanggil ulang oleh `deploy.yml`:
1. **Lint** — `./vendor/bin/pint --test`. Pelanggaran gaya menggagalkan pipeline.
2. **Test** — PostgreSQL 16 + Redis 7 sebagai service, `cp .env.ci .env`, migrasi, `php artisan test`.

---

## 6. Deploy (CD)

```bash
git checkout main && git merge --ff-only develop && git push
git tag v1.0.0 && git push origin v1.0.0     # memicu deploy.yml
```

`deploy.yml`:
1. Menjalankan ulang seluruh CI untuk tag tersebut.
2. SSH ke server (`/opt/berger-wms`), `git checkout` tag.
3. **Cadangan pra-deploy** (`cadangkan.sh sekarang`) — titik pulih tepat sebelum migrasi.
4. `docker compose -f docker-compose.prod.yml build` lalu `up -d --remove-orphans`.
5. `migrate --force`, `queue:restart`.
6. Memanggil `https://APP_DOMAIN/health` hingga sehat (maks. 1 menit); kalau tidak, deploy ditandai gagal.

Secret GitHub (Settings → Environments → `production`): `SERVER_HOST`, `SERVER_USER`, `SERVER_SSH_KEY`.

Image dibangun **di server**, tidak didorong ke registry. Satu VPS, satu jalur.

---

## 7. Rollback

**Kode** — pasang tag sebelumnya:

```bash
cd /opt/berger-wms
git checkout v1.0.0
docker compose -f docker-compose.prod.yml up -d --build
```

**Basis data** — migrasi di proyek ini sebagian besar menambah kolom/tabel, sehingga kode versi lama biasanya tetap berjalan di atas skema baru. Bila migrasi rilis merusak data, pulihkan cadangan pra-deploy:

```bash
docker compose -f docker-compose.prod.yml stop php-fpm queue scheduler
docker compose -f docker-compose.prod.yml exec backup sh /skrip/pulihkan.sh <YYYY-MM-DD>
docker compose -f docker-compose.prod.yml up -d
```

Cadangan harian dan cadangan pra-deploy pada hari yang sama menulis berkas yang sama; yang tersimpan adalah yang terakhir.

---

## 8. Pemantauan

| Sumber | Cara |
|---|---|
| `/health` | JSON `database`, `redis`, `storage`, `penjadwal`, `antrean`. 503 bila ada yang `false`. Pasang pemantau luar (UptimeRobot, gratis) tiap 5 menit |
| Log aplikasi | `docker compose -f docker-compose.prod.yml exec php-fpm tail -f storage/logs/laravel-$(date +%F).log` |
| Log container | `docker compose -f docker-compose.prod.yml logs -f queue scheduler backup caddy` |
| Status email ke Sales | Riwayat Penerimaan → rincian pesanan → kartu "Email ke Sales" |
| Status WhatsApp | Rincian Surat Jalan → status kirim ke supir / Sales |
| Cadangan | `ls -lh backups/db backups/berkas` |

`penjadwal: false` → container `scheduler` berhenti. `antrean: false` → container `queue` berhenti atau Redis penuh; email dan WhatsApp tertahan sampai hidup lagi (tidak hilang).

---

## 9. Galat yang Pernah Terjadi

| Gejala | Sebab | Obat |
|---|---|---|
| `touch(): Utime failed: Operation not permitted` | Berkas di `storage/framework/views` milik root | Pengembangan: `docker compose restart php-fpm`. Hindari dengan `./bin/artisan` |
| Semua halaman 502 setelah `up -d` | nginx menyimpan IP php-fpm lama | Sudah dicegah `resolver 127.0.0.11` di `default.conf` |
| Semua POST di test 419 | `APP_ENV` dari container menimpa phpunit | `force="true"` di `phpunit.xml` |
| Login selalu terpental di http:// lokal | `SESSION_SECURE_COOKIE=true` di lingkungan tanpa HTTPS | Biarkan `false` di lokal |
| Login ditolak semua orang di production | Kunci reCAPTCHA kosong (gagal tertutup, disengaja) | Isi `RECAPTCHA_SITE_KEY`/`SECRET_KEY`, recreate container |
| Email ke Sales tidak pernah sampai | `MAIL_MAILER=log`, atau worker antrean mati | `wms:cek-produksi`; periksa `/health` → `antrean` |
| Sertifikat HTTPS tidak terbit | DNS belum mengarah ke VPS, atau port 80 tertutup | `docker compose -f docker-compose.prod.yml logs caddy` |
