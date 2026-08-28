# Desain Database
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 1.1  
> **Tanggal:** 26 Agustus 2026 *(revisi dari v1.0, 14 Agustus 2026)*  
> **Database Engine:** PostgreSQL 16+  
> **ORM:** Laravel Eloquent  

> [!NOTE]
> **Perubahan v1.1** (menyusul PRD v1.1):
> - `customers` — kolom approval dihapus, diganti `is_active` + `created_by` + `credit_limit`
> - `products` — tambah `shelf_life_months` (default 30)
> - `inventory_stocks` — tambah `expiry_date`, `ddp_reason`, `sales_return_detail_id`; `status` menjadi `active`/`ddp`/`expired`
> - `stock_movements` — tambah tipe `TRANSFER_OUT`, `TRANSFER_IN`, `RETURN_IN`
> - **Tabel baru:** `sales_returns`, `sales_return_details`
> - Total migration 25 → 28

---

## Daftar Isi

1. [Prinsip Desain](#1-prinsip-desain)
2. [Entity Relationship Diagram (ERD)](#2-entity-relationship-diagram-erd)
3. [Definisi Tabel](#3-definisi-tabel)
4. [Relasi Antar Tabel](#4-relasi-antar-tabel)
5. [Strategi Indexing](#5-strategi-indexing)
6. [Strategi Archival](#6-strategi-archival)
7. [Seed Data Awal](#7-seed-data-awal)
8. [Urutan Migration](#8-urutan-migration)

---

## 1. Prinsip Desain

### 1.1 Konvensi Umum
- **Nama tabel:** `snake_case`, plural (contoh: `sales_orders`, `inventory_stocks`)
- **Nama kolom:** `snake_case` (contoh: `qty_available`, `created_at`)
- **Primary Key:** `id` — `BIGINT UNSIGNED AUTO INCREMENT` (Laravel default)
- **Foreign Key:** `{tabel_singular}_id` (contoh: `user_id`, `product_id`)
- **Timestamps:** Semua tabel memiliki `created_at` dan `updated_at`
- **Soft Deletes:** Diterapkan pada tabel master dan transaksi utama (`deleted_at`)
- **UUID:** Tidak digunakan demi performa join dan simplicity

### 1.2 Prinsip Integritas Data
- **Referential Integrity:** Semua foreign key menggunakan `RESTRICT` pada delete untuk mencegah penghapusan data yang masih direferensi.
- **Stock Ledger:** Tabel `stock_movements` berfungsi sebagai *journal/ledger* — setiap perubahan qty stok HARUS tercatat di sini.
- **Immutable Audit:** Tabel `audit_logs` tidak memiliki `updated_at` — data hanya INSERT, tidak pernah UPDATE atau DELETE.
- **Atomic Transactions:** Semua operasi yang melibatkan perubahan stok harus dibungkus dalam database transaction.

---

## 2. Entity Relationship Diagram (ERD)

```mermaid
erDiagram
    roles ||--o{ users : "has many"
    warehouses ||--o{ users : "default warehouse"
    warehouses ||--o{ locations : "has many"
    warehouses ||--o{ inbound_headers : "target warehouse"
    warehouses ||--o{ sales_orders : "dispatch to"
    
    users ||--o{ user_sessions : "has many"
    users ||--o{ login_attempts : "has many"
    users ||--o{ notifications : "has many"
    users ||--o{ inbound_headers : "created by"
    users ||--o{ sales_orders : "created by"
    users ||--o{ audit_logs : "performed by"
    
    product_categories ||--o{ products : "has many"
    
    products ||--o{ inbound_details : "received as"
    products ||--o{ inventory_stocks : "stored as"
    products ||--o{ sales_order_details : "ordered as"
    products ||--o{ stock_movements : "moved"
    
    locations ||--o{ inventory_stocks : "stored at"
    
    inbound_headers ||--o{ inbound_details : "has many"
    inbound_details ||--o{ inventory_stocks : "creates"
    
    customers ||--o{ sales_orders : "places"
    customers ||--o{ customer_billings : "billed to"
    
    sales_orders ||--o{ sales_order_details : "has many"
    sales_orders ||--o{ order_trackings : "tracked by"
    sales_orders ||--o{ delivery_notes : "delivered via"
    sales_orders ||--o{ delivery_proofs : "proven by"
    sales_orders ||--o{ customer_billings : "generates"
    sales_orders ||--o{ sales_returns : "returned via"
    
    sales_returns ||--o{ sales_return_details : "has many"
    sales_return_details ||--o{ inventory_stocks : "restocks as"
    products ||--o{ sales_return_details : "returned as"
    
    customer_billings ||--o{ billing_payments : "paid via"

    roles {
        bigint id PK
        string name
        string slug
    }
    
    users {
        bigint id PK
        string name
        string email UK
        string password
        bigint role_id FK
        bigint warehouse_id FK
        int failed_login_attempts
        timestamp locked_until
        timestamp last_lockout_at
        int lockout_count
    }
    
    warehouses {
        bigint id PK
        string code UK
        string name
        string address
        boolean is_active
    }
    
    products {
        bigint id PK
        string sku UK
        string name
        text description
        bigint category_id FK
        string uom
        int max_qty_per_pallet
        smallint shelf_life_months
    }
    
    locations {
        bigint id PK
        bigint warehouse_id FK
        string code UK
        string rack
        string floor_level
        string row_position
    }
    
    customers {
        bigint id PK
        string name
        text address
        string pic_name
        string pic_phone
        string default_payment_term
        decimal credit_limit
        boolean is_active
        bigint created_by FK
    }
    
    sales_returns {
        bigint id PK
        string return_number UK
        bigint sales_order_id FK
        bigint customer_id FK
        bigint warehouse_id FK
        string status
        string proof_file_path
        bigint reported_by FK
        bigint processed_by FK
        text check_notes
    }
    
    sales_return_details {
        bigint id PK
        bigint sales_return_id FK
        bigint product_id FK
        string batch_no
        date production_date
        int qty_reported
        int qty_received
        string rejection_reason
        string allocation
        bigint location_id FK
    }
    
    inventory_stocks {
        bigint id PK
        bigint product_id FK
        bigint location_id FK
        bigint warehouse_id FK
        string batch_no
        int qty_available
        int qty_allocated
        date production_date
        date expiry_date
        string status
        string ddp_reason
        bigint verified_by FK
        timestamp verified_at
        bigint inbound_detail_id FK
        bigint sales_return_detail_id FK
    }
    
    sales_orders {
        bigint id PK
        string order_number UK
        bigint customer_id FK
        bigint user_id FK
        bigint warehouse_id FK
        string payment_term
        string status
        timestamp submitted_at
        timestamp completed_at
        decimal sla_hours
    }
    
    stock_movements {
        bigint id PK
        bigint product_id FK
        bigint location_id FK
        bigint warehouse_id FK
        string movement_type
        int qty_change
        string reference_type
        bigint reference_id
        string batch_no
        bigint user_id FK
        text notes
    }
```

---

## 3. Definisi Tabel

### 3.1 Tabel Keamanan & Autentikasi

#### `roles`
Menyimpan definisi peran pengguna dalam sistem.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `name` | VARCHAR(50) | NOT NULL | Nama tampilan (contoh: "Super Admin") |
| `slug` | VARCHAR(50) | NOT NULL, UNIQUE | Kode internal (contoh: "super_admin") |
| `description` | TEXT | NULLABLE | Deskripsi peran |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

#### `users`
Menyimpan data autentikasi dan profil pengguna.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `name` | VARCHAR(100) | NOT NULL | Nama lengkap |
| `email` | VARCHAR(150) | NOT NULL, UNIQUE | Email login |
| `password` | VARCHAR(255) | NOT NULL | Bcrypt hash |
| `role_id` | BIGINT UNSIGNED | FK → roles.id | Peran pengguna |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Gudang default |
| `failed_login_attempts` | INTEGER | DEFAULT 0 | Counter percobaan gagal (password salah ATAU verifikasi anti-bot gagal — satu counter bersama, PRD §6.1 F-AUTH-03) |
| `locked_until` | TIMESTAMP | NULLABLE | Waktu akun bisa dicoba lagi |
| `last_lockout_at` | TIMESTAMP | NULLABLE | Waktu terakhir terkunci |
| `lockout_count` | INTEGER | DEFAULT 0 | Counter berapa kali terkunci |
| `is_active` | BOOLEAN | DEFAULT TRUE | Status akun aktif/nonaktif |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete |

#### `user_sessions`
Melacak sesi aktif per user untuk enforce max 2 device.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `user_id` | BIGINT UNSIGNED | FK → users.id | |
| `session_id` | VARCHAR(255) | NOT NULL, UNIQUE | Token device, diterbitkan `AuthController` saat login (BUKAN Laravel session ID — session ID Laravel berubah tiap `regenerate()`/tanpa cookie, sehingga tidak stabil dipakai sebagai identitas device; lihat cookie `device_token`, dikecualikan dari enkripsi di `bootstrap/app.php`) |
| `ip_address` | VARCHAR(45) | NULLABLE | IPv4/IPv6 |
| `user_agent` | TEXT | NULLABLE | Browser/Device info |
| `last_activity_at` | TIMESTAMP | NOT NULL | Waktu aktivitas terakhir |
| `created_at` | TIMESTAMP | | Login time |

#### `login_attempts`
Mencatat history percobaan login (untuk analisis keamanan).

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `email` | VARCHAR(150) | NOT NULL | Email yang dicoba |
| `ip_address` | VARCHAR(45) | NULLABLE | |
| `user_agent` | TEXT | NULLABLE | |
| `is_successful` | BOOLEAN | NOT NULL | Berhasil atau gagal |
| `failure_reason` | VARCHAR(50) | NULLABLE | "wrong_password", "locked", "inactive", "recaptcha_failed" |
| `created_at` | TIMESTAMP | | |

---

### 3.2 Tabel Master Data

#### `warehouses`
Master data gudang / dispatch code.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `code` | VARCHAR(20) | NOT NULL, UNIQUE | Dispatch code (contoh: "KRW", "JKT") |
| `name` | VARCHAR(100) | NOT NULL | Nama gudang |
| `address` | TEXT | NULLABLE | Alamat lengkap |
| `is_active` | BOOLEAN | DEFAULT TRUE | Status aktif |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete |

#### `product_categories`
Kategori/grup produk untuk pelaporan dan filter.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `name` | VARCHAR(100) | NOT NULL, UNIQUE | Nama kategori — tampil sebagai "Product Type" (contoh: "Alk Primer", "AMC", "Apex Emulsion") |
| `description` | TEXT | NULLABLE | |
| `is_active` | BOOLEAN | DEFAULT TRUE | Hanya kategori aktif yang muncul di dropdown |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete |

#### `products`
Master data SKU/produk.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sku` | VARCHAR(50) | NOT NULL, UNIQUE | Kode SKU dari ERP, contoh: `ID1-F00113202225` |
| `name` | VARCHAR(200) | NOT NULL | Nama produk (kolom "Description" pada ekspor ERP) |
| `description` | TEXT | NULLABLE | Deskripsi tambahan |
| `product_code` | VARCHAR(10) | NOT NULL | Kode lini produk, contoh: `0011` = Royale Smart Clean |
| `shade_code` | VARCHAR(10) | NOT NULL | Kode warna, contoh: `3202` = White, `B050` = Vanilla Sky |
| `pack_code` | VARCHAR(10) | NOT NULL | Kode kemasan, contoh: `225` = 2.5 L, `320` = 20 L |
| `category_id` | BIGINT UNSIGNED | FK → product_categories.id, NULLABLE | "Product Type" (Alk Primer, AMC, dst.) |
| `uom` | VARCHAR(20) | NOT NULL | Satuan kemasan dari ERP: KG, TIN, PAIL, CAN |
| `pack_size` | DECIMAL(10,3) | NULLABLE | Ukuran **wadah** (nominal), contoh: 20 untuk pail 20 Ltr. **Dasar aturan palet** |
| `pack_unit` | VARCHAR(2) | NULLABLE | `L` atau `KG` — satuan dari `pack_size` |
| `unit_volume` | DECIMAL(10,3) | NULLABLE | Volume **isi sebenarnya** menurut ERP — bisa lebih kecil dari `pack_size` |
| `net_weight` | DECIMAL(10,3) | NULLABLE | Berat bersih (kg) |
| `gross_weight` | DECIMAL(10,3) | NULLABLE | Berat kotor (kg) |
| `max_qty_per_pallet` | INTEGER | NULLABLE | Kapasitas maks per palet, dihitung otomatis (lihat catatan) |
| `shelf_life_months` | SMALLINT | NOT NULL, DEFAULT 30 | Masa simpan dalam bulan. Dasar perhitungan `expiry_date` |
| `stock_threshold_low` | INTEGER | DEFAULT 50 | Batas "Terbatas" untuk Semi-Blind indicator |
| `is_active` | BOOLEAN | DEFAULT TRUE | Apakah produk masih aktif diproduksi |
| `created_by` | BIGINT UNSIGNED | FK → users.id, NULLABLE | Pembuat data |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete |

> [!IMPORTANT]
> **`sku` adalah gabungan tiga kode.** Polanya: `ID1-F` + `product_code` + `shade_code` + `pack_code`. Contoh: `ID1-F` + `0011` + `3202` + `225` = `ID1-F00113202225`. Ketiga komponen tetap disimpan terpisah agar bisa difilter (mis. "semua produk warna 3202") tanpa membedah string SKU. SKU hasil impor disimpan **apa adanya**, sehingga data tetap benar bila ERP suatu saat memakai awalan lain.

> [!IMPORTANT]
> **Tabel ini TIDAK menyimpan jumlah stok.** Kolom `Inventory` pada ekspor ERP (mis. 108, 126, 72) adalah **hasil penjumlahan**, bukan data master. Di sistem ini stok tinggal di `inventory_stocks`, terpecah per gudang × lokasi × batch × tanggal kedaluwarsa — pemecahan itulah yang membuat FIFO (§7.2) dan aturan kedaluwarsa (§7.2.1) bisa berjalan. Angka stok pada layar dihitung dengan `SUM(qty_available) WHERE status='active' AND expiry_date > CURRENT_DATE`.
>
> Ada test regresi (`ProductManagementTest::test_tabel_produk_tidak_menyimpan_jumlah_stok`) yang menggagalkan build bila kolom bernama `stock`, `qty`, `quantity`, atau `inventory` menyelinap masuk ke tabel ini.

> [!CAUTION]
> **`pack_size` vs `unit_volume` — jangan tertukar.** Keduanya sama-sama angka liter, tapi artinya berbeda:
>
> | Kolom | Arti | Contoh (Blue Smoke 20Ltr) |
> |---|---|---|
> | `pack_size` | Ukuran **wadah** | `20.000` |
> | `unit_volume` | **Isi sebenarnya** menurut ERP | `19.400` |
>
> Wadah tinting base sengaja tidak diisi penuh agar ada ruang untuk pewarna. **Aturan palet WAJIB memakai `pack_size`** — satu pail tetap memakan tempat satu pail 20 L di atas palet, berapa pun isinya. Memakai `unit_volume` membuat sebagian besar produk salah dianggap tidak punya aturan palet.
>
> `pack_size` dan `pack_unit` diisi otomatis dengan membaca ukuran di ujung nama produk ("…20Ltr", "…4Kg") lewat `App\Support\PackSize`.

> [!NOTE]
> **`max_qty_per_pallet` NULLABLE, berbeda dari rancangan awal.** Kapasitas palet dihitung dari tabel aturan gudang (`App\Support\PalletCapacity`) berdasarkan `pack_unit` + `pack_size`. Satuan ikut menentukan hasilnya — **20 L memuat 27 pcs, sedangkan 20 Kg memuat 36 pcs** — sehingga tidak bisa diturunkan dari rumus volume/berat semata.
>
> Ukuran di luar daftar aturan (mis. 0.25 L) sengaja menghasilkan NULL, **bukan angka tebakan**: salah menghitung kapasitas palet berarti salah membentuk palet di lantai gudang. Produk semacam itu ditandai di halaman Master Produk agar Manager mengisinya manual.
>
> **Aturan kapasitas palet:** 0.9 L / 0.9 Kg / 1 Kg → 720 · 2.5 L / 3.6 L / 4 Kg / 5 Kg → 180 · 15 L → 40 · 18 L / 20 L → 27 · 18 Kg / 20 Kg / 25 Kg → 36

> [!NOTE]
> **Product Type "Tidak ditemukan" pada ekspor ERP** dipetakan menjadi `category_id = NULL`, bukan dibuatkan kategori bernama itu. Nilai tersebut adalah penanda bahwa pencarian kategori di ERP gagal — bila dijadikan kategori, masalah datanya akan tersamarkan. Di layar tampil sebagai badge "Belum berkategori".

#### `locations`
Master lokasi rak penyimpanan di gudang.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
Kode berpola `[Rak]-[Level]-[Sel]`, contoh `B-01-01` = Rak B, Level 1, Sel 1.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Gudang pemilik |
| `code` | VARCHAR(20) | NOT NULL, UNIQUE per gudang | Kode bin lengkap (contoh: `B-01-01`) |
| `rack` | VARCHAR(5) | NOT NULL | Kode rak — satu atau **dua** huruf (`B` … `ZD`) |
| `level` | TINYINT UNSIGNED | NOT NULL | Level 1–5 (seluruh rak setinggi 5 level) |
| `cell` | SMALLINT UNSIGNED | NOT NULL | Nomor sel/kolom pada level tersebut |
| `zone` | VARCHAR(30) | NULLABLE | `Fast` / `Slow` / `Middle Moving Area` |
| `is_active` | BOOLEAN | DEFAULT TRUE | Bin non-aktif tidak dipilih proses put-away |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete |

> [!IMPORTANT]
> **`code` unik PER GUDANG, bukan global** — berbeda dari rancangan awal. Penamaan rak `A/B/C` lazim berulang di gudang berbeda; memaksa unik global akan menolak gudang kedua yang memakai penamaan yang sama. Unique constraint-nya `(warehouse_id, code)`.

> [!IMPORTANT]
> **`level` dan `cell` bertipe angka, bukan string** (rancangan awal memakai `VARCHAR(5)` untuk `floor_level`/`row_position`). Alasannya pengurutan: dengan string, `B-01-10` jatuh **sebelum** `B-01-02` karena dibandingkan sebagai teks — keliru saat operator menyusuri rak berurutan. Nama kolom juga disesuaikan dengan istilah yang dipakai di lapangan (`level`, `cell`).
>
> Komponen `rack`/`level`/`cell` **diturunkan dari `code`** saat menyimpan (lihat `Location::parseCode`), sehingga mustahil tidak sinkron.

> [!NOTE]
> **Denah gudang WH-01: 2.264 bin pada 29 rak.** Tidak ada Rak "A". Pada sebagian besar rak, **Level 4–5 memuat lebih banyak sel daripada Level 1–3** karena bagian bawah terpotong jalur forklift.
>
> | Rak | Level 1–3 | Level 4–5 | Per rak | Zona |
> |---|---|---|---|---|
> | B–G | 11 sel | 13 sel | 59 | Fast Moving |
> | H–I | 8 sel | 10 sel | 44 | Fast Moving |
> | J–O | 12 sel | 14 sel | 64 | Fast Moving |
> | P | 20 sel | 20 sel | 100 | Slow Moving |
> | Q–T | 18 sel | 20 sel | 94 | Slow Moving |
> | U–V | 18 sel | 20 sel | 94 | Middle Moving |
> | W–X | 18 sel | 18 sel | 90 | Middle Moving |
> | Y–ZD | 19 sel | 21 sel | 99 | Middle Moving |
>
> **Total per zona:** Fast 826 · Slow 476 · Middle 962 = **2.264**.
>
> Dibangkitkan `LocationSeeder` dari aturan di atas, bukan disalin baris per baris. Seeder memeriksa sendiri hasilnya terhadap ketiga angka zona dan menggagalkan proses bila tidak cocok — sehingga salah ketik satu angka pada aturan tidak bisa lolos diam-diam.

> [!WARNING]
> **Ejaan zona pada ekspor ERP salah: "Midle Moving Area".** `Location::normalizeZone()` menormalkannya menjadi `Middle Moving Area`. Tanpa itu, impor akan menghasilkan dua zona berbeda yang sebenarnya sama.

#### `customers`
Data pelanggan / toko.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
Struktur kolom mengikuti ekspor ERP Berger: `No./id | Ship-to Code | Name | Phone No. | Contact | Email | Address | Address 2 | Territory Code`.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `code` | VARCHAR(30) | NOT NULL, UNIQUE | "No./id" pada ERP, contoh: `IDI10101` |
| `ship_to_code` | VARCHAR(30) | NULLABLE | Nomor pelanggan di ERP. Kosong bila belum terdaftar di sana |
| `name` | VARCHAR(200) | NOT NULL | Nama toko/distributor |
| `phone` | VARCHAR(25) | NULLABLE | Disimpan sebagai digit saja, dengan kode negara (`6289531435435`) |
| `contact_name` | VARCHAR(100) | NULLABLE | "Contact" — nama orang yang dihubungi |
| `email` | VARCHAR(150) | NULLABLE | |
| `address` | TEXT | NOT NULL | "Address" — alamat jalan |
| `address_2` | TEXT | NULLABLE | "Address 2" — kelurahan/kecamatan/kota |
| `territory_code` | VARCHAR(30) | NULLABLE | "Territory Code", contoh: `PROJECT` |
| `is_active` | BOOLEAN | NOT NULL, DEFAULT TRUE | Hanya customer aktif yang muncul di form Buat Pesanan |
| `created_by` | BIGINT UNSIGNED | FK → users.id, NULLABLE | Manager/Super Admin yang mendaftarkan |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete |

> [!NOTE]
> **Alamat disimpan dua kolom, ditampilkan satu.** `address` dan `address_2` dipertahankan terpisah agar impor/ekspor ERP tetap setara. Untuk tampilan keduanya digabung lewat accessor `full_address` (`"JL. PAMOYANAN NO. 15 RT 01 RW 01, MEKARMANIK, CIMENYAN"`), sehingga di tabel hanya ada satu kolom ALAMAT.

> [!IMPORTANT]
> **Syarat pembayaran & limit kredit TIDAK ada di tabel ini** — berbeda dari rancangan awal yang memuat `default_payment_term` dan `credit_limit`.
>
> Keputusan bisnis: termin dipilih Sales **per-pesanan**, bukan sifat tetap milik pelanggan — satu pelanggan bisa memakai termin berbeda antar pesanan. Keduanya kini tinggal di tabel `payment_terms` (lihat di bawah) yang mengisi dropdown pada form Buat Pesanan.
>
> Kolom `pic_name`/`pic_phone` juga dihapus; ERP hanya menyediakan satu kolom "Contact" yang dipetakan ke `contact_name`.
>
> Ada test regresi (`CustomerManagementTest::test_tabel_pelanggan_tidak_menyimpan_termin_dan_limit_kredit`) yang menggagalkan build bila kolom termin/limit kredit menyelinap kembali ke tabel ini.

#### `payment_terms`
Syarat pembayaran beserta plafon kreditnya. Berdiri sendiri, tidak menempel di `customers`.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `code` | VARCHAR(30) | NOT NULL, UNIQUE | `cash`, `transfer`, `tempo_30`, `tempo_60`, `tempo_90` |
| `name` | VARCHAR(100) | NOT NULL | Label pada dropdown, contoh: "Tempo 30 Hari" |
| `days` | SMALLINT | NOT NULL, DEFAULT 0 | Hari jatuh tempo; `0` = dibayar di muka |
| `credit_limit` | DECIMAL(15,2) | NULLABLE | Plafon kredit yang melekat pada termin |
| `is_active` | BOOLEAN | DEFAULT TRUE | Hanya termin aktif yang muncul di dropdown |
| `sort_order` | SMALLINT | DEFAULT 0 | Urutan tampil |
| `created_at` / `updated_at` | TIMESTAMP | | |

> [!NOTE]
> Sistem **belum punya proses pembayaran sama sekali**. Tabel ini disiapkan lebih awal agar form Buat Pesanan (Fase 5) sudah bisa memakai dropdown yang benar, dan modul Billing (Fase 8) tidak perlu mengubah struktur lagi. `credit_limit` sengaja NULL sampai plafonnya ditetapkan.

> [!IMPORTANT]
> **Perubahan v1.1.** Alur pengajuan customer oleh Sales dihapus (lihat PRD §6.2 F-MASTER-06). Kolom `status` (pending/approved/rejected), `requested_by`, `approved_by`, `approved_at`, dan `rejection_reason` **dihapus**, digantikan `is_active` + `created_by`. Customer dibuat langsung oleh Manager/Super Admin dan langsung aktif.
>
> **Status menunggak tidak disimpan sebagai kolom.** Penanda `⚠ Menunggak` dihitung *on-the-fly* dari `customer_billings` yang berstatus belum lunas, sehingga selalu akurat tanpa risiko data basi. Gunakan Eloquent accessor / scope, bukan kolom denormalisasi.

---

### 3.3 Tabel Inbound (Barang Masuk)

#### `inbound_headers`
Header dokumen penerimaan barang dari pabrik.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `document_number` | VARCHAR(50) | NOT NULL | Nomor dokumen fisik pabrik (input manual) |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Gudang tujuan |
| `production_date` | DATE | NOT NULL | Tanggal produksi |
| `status` | ENUM | NOT NULL, DEFAULT 'draft' | 'draft', 'putaway_pending', 'verification_pending', 'verified', 'partial_verified' |
| `notes` | TEXT | NULLABLE | Catatan tambahan |
| `created_by` | BIGINT UNSIGNED | FK → users.id | Tim Produksi yang input |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete |

#### `inbound_details`
Rincian per item per palet dalam satu inbound.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `inbound_header_id` | BIGINT UNSIGNED | FK → inbound_headers.id | Header induk |
| `product_id` | BIGINT UNSIGNED | FK → products.id | Produk yang diterima |
| `batch_no` | VARCHAR(50) | NOT NULL | Nomor batch produksi |
| `total_qty` | INTEGER | NOT NULL | Total qty sebelum split palet |
| `pallet_no` | INTEGER | NOT NULL | Urutan palet (1, 2, 3, ...) |
| `pallet_qty` | INTEGER | NOT NULL | Qty aktual di palet ini |
| `location_id` | BIGINT UNSIGNED | FK → locations.id, NULLABLE | Lokasi rak (diisi oleh Operator) |
| `putaway_by` | BIGINT UNSIGNED | FK → users.id, NULLABLE | Operator yang menempatkan |
| `putaway_at` | TIMESTAMP | NULLABLE | Waktu penempatan |
| `is_verified` | BOOLEAN | DEFAULT FALSE | Sudah diverifikasi Logistik? |
| `verified_by` | BIGINT UNSIGNED | FK → users.id, NULLABLE | Logistik yang memverifikasi |
| `verified_at` | TIMESTAMP | NULLABLE | Waktu verifikasi |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

---

### 3.4 Tabel Inventory (Stok)

#### `inventory_stocks`
**Tabel paling kritis.** Menyimpan data stok aktual yang ada di gudang, per produk per lokasi per batch.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `product_id` | BIGINT UNSIGNED | FK → products.id | Produk |
| `location_id` | BIGINT UNSIGNED | FK → locations.id | Lokasi rak |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Gudang (denormalized untuk performa) |
| `batch_no` | VARCHAR(50) | NOT NULL | Nomor batch |
| `qty_available` | INTEGER | NOT NULL, DEFAULT 0 | Qty yang tersedia untuk dialokasikan |
| `qty_allocated` | INTEGER | NOT NULL, DEFAULT 0 | Qty yang sudah dialokasikan untuk order |
| `production_date` | DATE | NOT NULL | Tanggal produksi (untuk FIFO) |
| `expiry_date` | DATE | NOT NULL | `production_date` + `products.shelf_life_months` |
| `status` | ENUM | NOT NULL, DEFAULT 'active' | 'active', 'ddp', 'expired' |
| `ddp_reason` | VARCHAR(100) | NULLABLE | Alasan masuk DDP: 'EXPIRED', 'RETURN_DAMAGED', 'WRITE_OFF', 'OPNAME' |
| `inbound_detail_id` | BIGINT UNSIGNED | FK → inbound_details.id, NULLABLE | Asal palet inbound (NULL bila berasal dari retur) |
| `sales_return_detail_id` | BIGINT UNSIGNED | FK → sales_return_details.id, NULLABLE | Asal retur (NULL bila berasal dari inbound) |
| `verified_by` | BIGINT UNSIGNED | FK → users.id | Logistik yang memverifikasi |
| `verified_at` | TIMESTAMP | NOT NULL | Waktu stok aktif |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

> [!IMPORTANT]
> **Constraint:** `qty_available` >= 0, `qty_allocated` >= 0. Tidak boleh ada stok minus.  
> **Composite Index:** (`product_id`, `warehouse_id`, `status`, `production_date`) — untuk query FIFO.  
> **Index tambahan:** (`status`, `expiry_date`) — untuk *sweep* harian batch kedaluwarsa dan peringatan dini 90 hari.

**Status stok dan artinya:**

| `status` | Ikut FIFO? | Muncul di Picking List? | Keterangan |
|---|:---:|:---:|---|
| `active` | ✅ | ✅ | Good Stock, layak jual |
| `ddp` | ❌ | ❌ | Rusak / karantina (retur, write-off, temuan opname) |
| `expired` | ❌ | ❌ | Lewat `expiry_date`, dipindahkan otomatis oleh scheduled job |

> [!WARNING]
> **Query FIFO WAJIB menyaring `status = 'active'` DAN `expiry_date > CURRENT_DATE`.** Melewatkan salah satunya berarti barang rusak atau kedaluwarsa berpotensi terkirim ke pelanggan. Lihat PRD §7.2 dan §7.2.1.

#### `stock_movements`
**Tabel Ledger.** Mencatat SETIAP mutasi stok sebagai jurnal yang tidak boleh di-update atau di-delete.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `product_id` | BIGINT UNSIGNED | FK → products.id | Produk yang dimutasi |
| `location_id` | BIGINT UNSIGNED | FK → locations.id, NULLABLE | Lokasi rak (null jika adjustment) |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Gudang |
| `movement_type` | ENUM | NOT NULL | 'IN', 'OUT', 'ALLOCATED', 'DEALLOCATED', 'ADJUSTMENT', **'TRANSFER_OUT'**, **'TRANSFER_IN'**, **'RETURN_IN'** |
| `qty_change` | INTEGER | NOT NULL | Perubahan qty (positif = tambah, negatif = kurang) |
| `qty_before` | INTEGER | NOT NULL | Qty sebelum perubahan |
| `qty_after` | INTEGER | NOT NULL | Qty setelah perubahan |
| `reference_type` | VARCHAR(50) | NOT NULL | 'inbound', 'sales_order', 'adjustment', **'stock_transfer'**, **'sales_return'** |
| `reference_id` | BIGINT UNSIGNED | NOT NULL | ID dari tabel referensi |
| `batch_no` | VARCHAR(50) | NULLABLE | Nomor batch (jika relevan) |
| `notes` | TEXT | NULLABLE | Catatan (wajib diisi untuk ADJUSTMENT, TRANSFER, dan RETURN_IN) |
| `user_id` | BIGINT UNSIGNED | FK → users.id | Siapa yang melakukan |
| `created_at` | TIMESTAMP | | Waktu mutasi (immutable) |

> [!CAUTION]
> Tabel ini bersifat **IMMUTABLE** (append-only). Tidak ada operasi UPDATE atau DELETE. Ini adalah audit trail finansial untuk stok.

**Catatan tipe mutasi baru (v1.1):**

| Tipe | Kapan dipakai | Aturan |
|---|---|---|
| `TRANSFER_OUT` / `TRANSFER_IN` | Perpindahan stok antar lokasi rak atau antar gudang | Selalu **berpasangan** dalam satu transaksi database. Total `qty_change` kedua entri harus nol |
| `RETURN_IN` | Barang retur diterima kembali di gudang | Satu entri per baris retur. `batch_no` dan `production_date` **wajib sama dengan aslinya** agar FIFO & expiry tidak rusak |
| `ADJUSTMENT` (reason `EXPIRED`) | Scheduled job memindahkan batch kedaluwarsa ke DDP | Bukan perubahan qty, melainkan perubahan `status`. Tetap dicatat demi jejak audit |

> [!NOTE]
> **Keputusan desain: transfer stok TIDAK memakai tabel header terpisah.** Perpindahan cukup direkam sebagai pasangan `TRANSFER_OUT`/`TRANSFER_IN` di ledger ini. Alasannya: transfer adalah mutasi stok murni tanpa siklus hidup dokumen (tidak ada approval, tidak ada status bertahap), sehingga tabel header hanya akan menduplikasi data. Nomor dokumen `TRF-{YYYY}-` dibangkitkan dari `document_sequences` dan disimpan di kolom `reference_id` + `notes`.

### 3.5 Tabel Outbound (Sales Order)

#### `sales_orders`
Header pesanan penjualan.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `order_number` | VARCHAR(30) | NOT NULL, UNIQUE | Nomor PO otomatis (contoh: "PO-KRW-2026-00001") |
| `customer_id` | BIGINT UNSIGNED | FK → customers.id | Pelanggan |
| `user_id` | BIGINT UNSIGNED | FK → users.id | Sales yang membuat |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Gudang tujuan (dispatch code) |
| `payment_term` | ENUM | NOT NULL | 'cash', 'transfer', 'tempo_30', 'tempo_60', 'tempo_90' |
| `status` | ENUM | NOT NULL, DEFAULT 'pending' | Lihat daftar status di bawah |
| `submitted_at` | TIMESTAMP | NOT NULL | Waktu submit oleh Sales (awal SLA) |
| `approved_at` | TIMESTAMP | NULLABLE | Waktu approve oleh Logistik |
| `approved_by` | BIGINT UNSIGNED | FK → users.id, NULLABLE | Logistik yang approve |
| `rejected_at` | TIMESTAMP | NULLABLE | Waktu reject (jika ditolak) |
| `rejected_by` | BIGINT UNSIGNED | FK → users.id, NULLABLE | |
| `rejection_reason` | TEXT | NULLABLE | Alasan penolakan |
| `picking_completed_at` | TIMESTAMP | NULLABLE | Waktu picking selesai |
| `completed_at` | TIMESTAMP | NULLABLE | Waktu order complete (akhir SLA) |
| `sla_hours` | DECIMAL(8,2) | NULLABLE | Durasi SLA dalam jam |
| `notes` | TEXT | NULLABLE | Catatan dari Sales |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |
| `deleted_at` | TIMESTAMP | NULLABLE | Soft delete (hanya Super Admin) |

**Daftar Status Sales Order:**

| Status | Kode Enum | Deskripsi |
|---|---|---|
| Menunggu Diterima | `pending` | PO baru disubmit Sales |
| Disetujui | `approved` | Logistik sudah approve, FIFO allocated |
| Ditolak | `rejected` | Logistik menolak PO |
| Proses Picking | `picking` | Operator sedang mengambil barang |
| Siap Kirim | `ready_to_ship` | Barang sudah di loading dock |
| Dalam Pengiriman | `shipping` | Surat Jalan sudah dicetak |
| Menunggu Verifikasi Bukti | `proof_uploaded` | Sales sudah upload bukti SJ |
| Complete | `completed` | Logistik sudah verifikasi bukti |
| Complete (Menunggu Bayar) | `completed_billing` | Complete tapi menunggu pembayaran tempo |

#### `sales_order_details`
Rincian item per PO.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sales_order_id` | BIGINT UNSIGNED | FK → sales_orders.id | Header PO |
| `product_id` | BIGINT UNSIGNED | FK → products.id | Produk yang dipesan |
| `qty_ordered` | INTEGER | NOT NULL | Qty yang diminta Sales |
| `qty_approved` | INTEGER | DEFAULT 0 | Qty yang disetujui (setelah auto-adjustment) |
| `qty_shipped` | INTEGER | DEFAULT 0 | Qty yang benar-benar dikirim |
| `lost_qty` | INTEGER | DEFAULT 0 | Selisih: qty_ordered - qty_approved (Lost Sales) |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

> [!NOTE]
> `lost_qty = qty_ordered - qty_approved`. Kolom ini dihitung dan disimpan saat approval untuk kemudahan pelaporan.

#### `sales_order_allocations`
Detail alokasi FIFO per item pesanan — menghubungkan order detail ke inventory stock spesifik.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sales_order_detail_id` | BIGINT UNSIGNED | FK → sales_order_details.id | Detail PO |
| `inventory_stock_id` | BIGINT UNSIGNED | FK → inventory_stocks.id | Stok yang dialokasikan |
| `qty_allocated` | INTEGER | NOT NULL | Qty yang dialokasikan dari stok ini |
| `created_at` | TIMESTAMP | | |

#### `delivery_notes`
Surat Jalan.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sales_order_id` | BIGINT UNSIGNED | FK → sales_orders.id | PO terkait |
| `delivery_number` | VARCHAR(30) | NOT NULL, UNIQUE | Nomor SJ otomatis |
| `driver_name` | VARCHAR(100) | NOT NULL | Nama supir |
| `vehicle_plate` | VARCHAR(20) | NOT NULL | Plat nomor kendaraan |
| `vehicle_description` | VARCHAR(100) | NULLABLE | Deskripsi kendaraan |
| `printed_at` | TIMESTAMP | NOT NULL | Waktu cetak |
| `printed_by` | BIGINT UNSIGNED | FK → users.id | Logistik yang mencetak |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

#### `delivery_proofs`
Bukti foto Surat Jalan yang ditandatangani.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sales_order_id` | BIGINT UNSIGNED | FK → sales_orders.id | PO terkait |
| `file_path` | VARCHAR(500) | NOT NULL | Path penyimpanan file |
| `file_name` | VARCHAR(255) | NOT NULL | Nama file asli |
| `file_size` | INTEGER | NOT NULL | Ukuran file dalam bytes |
| `mime_type` | VARCHAR(50) | NOT NULL | 'image/png' atau 'image/jpeg' |
| `uploaded_by` | BIGINT UNSIGNED | FK → users.id | Sales/Kurir yang upload |
| `created_at` | TIMESTAMP | | |

---

### 3.5.1 Tabel Retur (Reverse Logistics)

#### `sales_returns`
Header dokumen retur. Satu dokumen per pelaporan penolakan oleh Sales.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `return_number` | VARCHAR(30) | NOT NULL, UNIQUE | Format `RTN-{YYYY}{MM}-{urut}` |
| `sales_order_id` | BIGINT UNSIGNED | FK → sales_orders.id | PO asal barang yang diretur |
| `customer_id` | BIGINT UNSIGNED | FK → customers.id | Pelanggan yang menolak (denormalized) |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Gudang tujuan pengembalian |
| `status` | ENUM | NOT NULL, DEFAULT 'pending_check' | 'pending_check', 'processed', 'cancelled' |
| `proof_file_path` | VARCHAR(500) | NOT NULL | Foto SJ bercatatan penolakan (wajib) |
| `reported_by` | BIGINT UNSIGNED | FK → users.id | Sales yang melapor |
| `reported_at` | TIMESTAMP | NOT NULL | Waktu pelaporan |
| `processed_by` | BIGINT UNSIGNED | FK → users.id, NULLABLE | Petugas gudang yang mengecek fisik |
| `processed_at` | TIMESTAMP | NULLABLE | Waktu pengecekan selesai |
| `check_notes` | TEXT | NULLABLE | Catatan pengecekan fisik (wajib saat memproses) |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

#### `sales_return_details`
Rincian barang yang diretur beserta keputusan alokasinya.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sales_return_id` | BIGINT UNSIGNED | FK → sales_returns.id, ON DELETE CASCADE | Header retur |
| `product_id` | BIGINT UNSIGNED | FK → products.id | SKU yang diretur |
| `batch_no` | VARCHAR(50) | NOT NULL | Batch asli dari pengiriman — **wajib dipertahankan** |
| `production_date` | DATE | NOT NULL | Tanggal produksi asli — **wajib dipertahankan** |
| `qty_reported` | INTEGER | NOT NULL | Qty yang dilaporkan Sales |
| `qty_received` | INTEGER | NULLABLE | Qty yang benar-benar diterima gudang |
| `rejection_reason` | ENUM | NOT NULL | Alasan penolakan pelanggan: 'damaged_packaging', 'poor_quality', 'wrong_variant', 'over_delivery' |
| `allocation` | ENUM | NULLABLE | 'GR' (kembali ke Good Stock) atau 'DDP' (karantina/rusak) |
| `location_id` | BIGINT UNSIGNED | FK → locations.id, NULLABLE | Lokasi penempatan setelah diproses |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

> [!IMPORTANT]
> **`batch_no` dan `production_date` WAJIB disalin dari pengiriman aslinya**, tidak boleh dibuat baru. Membuat batch baru akan merusak dua hal sekaligus: urutan FIFO (barang lama akan tampak baru dan mengendap di gudang) dan perhitungan `expiry_date` (barang kedaluwarsa akan tampak masih segar).

> [!NOTE]
> **Validasi expiry saat retur:** bila `production_date + shelf_life_months <= CURRENT_DATE` pada saat retur diproses, sistem **memaksa** `allocation = 'DDP'` meskipun petugas memilih 'GR'. Barang kedaluwarsa tidak boleh kembali ke Good Stock dalam kondisi apa pun.

---

### 3.6 Tabel Billing (Penagihan)

#### `customer_billings`
Catatan piutang untuk pembayaran tempo.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sales_order_id` | BIGINT UNSIGNED | FK → sales_orders.id, UNIQUE | 1 PO = 1 billing |
| `customer_id` | BIGINT UNSIGNED | FK → customers.id | Customer yang ditagih |
| `payment_term` | ENUM | NOT NULL | 'tempo_30', 'tempo_60', 'tempo_90' |
| `billing_date` | DATE | NOT NULL | Tanggal billing dibuat (= tanggal order complete) |
| `due_date` | DATE | NOT NULL | Tanggal jatuh tempo |
| `status` | ENUM | NOT NULL, DEFAULT 'unpaid' | 'unpaid', 'paid', 'overdue' |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

#### `billing_payments`
Konfirmasi pembayaran oleh Logistik.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `customer_billing_id` | BIGINT UNSIGNED | FK → customer_billings.id | Billing yang dibayar |
| `paid_date` | DATE | NOT NULL | Tanggal pembayaran diterima |
| `confirmed_by` | BIGINT UNSIGNED | FK → users.id | Logistik yang konfirmasi |
| `notes` | TEXT | NULLABLE | Catatan pembayaran |
| `created_at` | TIMESTAMP | | |

---

### 3.7 Tabel Tracking & Audit

#### `order_trackings`
Timeline histori setiap perubahan status PO. Digunakan untuk tampilan timeline di portal Sales dan kalkulasi SLA.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `sales_order_id` | BIGINT UNSIGNED | FK → sales_orders.id | PO terkait |
| `status` | VARCHAR(30) | NOT NULL | Status saat itu |
| `description` | TEXT | NULLABLE | Deskripsi/pesan status |
| `user_id` | BIGINT UNSIGNED | FK → users.id | Siapa yang melakukan perubahan |
| `created_at` | TIMESTAMP | | Waktu perubahan status |

#### `notifications`
Notifikasi in-app untuk semua role.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `user_id` | BIGINT UNSIGNED | FK → users.id | Penerima notifikasi |
| `title` | VARCHAR(200) | NOT NULL | Judul notifikasi |
| `message` | TEXT | NOT NULL | Isi pesan |
| `type` | VARCHAR(50) | NOT NULL | Tipe: 'order', 'inbound', 'billing', 'customer', 'stock' |
| `reference_type` | VARCHAR(50) | NULLABLE | Model yang dirujuk |
| `reference_id` | BIGINT UNSIGNED | NULLABLE | ID model yang dirujuk |
| `is_read` | BOOLEAN | DEFAULT FALSE | Sudah dibaca? |
| `read_at` | TIMESTAMP | NULLABLE | Waktu dibaca |
| `created_at` | TIMESTAMP | | |

#### `audit_logs`
Jejak audit immutable untuk semua operasi sensitif.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `user_id` | BIGINT UNSIGNED | FK → users.id | Siapa yang melakukan |
| `action` | ENUM | NOT NULL | 'create', 'update', 'delete', 'adjustment' |
| `auditable_type` | VARCHAR(100) | NOT NULL | Nama model (contoh: "App\\Models\\InventoryStock") |
| `auditable_id` | BIGINT UNSIGNED | NOT NULL | ID record yang terpengaruh |
| `old_values` | JSON | NULLABLE | Data sebelum perubahan |
| `new_values` | JSON | NULLABLE | Data setelah perubahan |
| `ip_address` | VARCHAR(45) | NULLABLE | IP pengguna |
| `user_agent` | TEXT | NULLABLE | Browser/device |
| `created_at` | TIMESTAMP | | Immutable — tidak ada updated_at |

> [!CAUTION]
> Tabel ini bersifat **IMMUTABLE**. Tidak ada kolom `updated_at` atau `deleted_at`. Record HANYA di-INSERT, tidak pernah di-UPDATE atau di-DELETE.

---

### 3.8 Tabel Sistem

#### `system_settings`
Pengaturan sistem yang bisa dikonfigurasi Super Admin.

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `key` | VARCHAR(100) | NOT NULL, UNIQUE | Kunci pengaturan |
| `value` | TEXT | NOT NULL | Nilai pengaturan |
| `description` | TEXT | NULLABLE | Deskripsi untuk UI admin |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

**Setting keys yang dibutuhkan:**

| Key | Default Value | Deskripsi |
|---|---|---|
| `order_cutoff_time` | `15:00` | Batas waktu order harian (WIB) |
| `delivery_note_prefix` | `SJ` | Prefix nomor Surat Jalan |
| `delivery_note_starting_number` | `1` | Starting number SJ |
| `order_number_prefix` | `PO` | Prefix nomor PO |
| `order_number_starting_number` | `1` | Starting number PO |
| `max_upload_size_mb` | `5` | Max ukuran upload file (MB) |
| `max_upload_files` | `3` | Max jumlah file upload per proof |
| `session_idle_timeout_minutes` | `60` | Durasi idle sebelum auto-logout |
| `max_concurrent_sessions` | `2` | Max device per akun |
| `max_login_attempts` | `5` | Max percobaan login sebelum lockout |
| `initial_lockout_minutes` | `5` | Durasi lockout pertama (menit) |
| `audit_archive_months` | `24` | Umur audit log sebelum di-archive |

#### `document_sequences`
Penomoran otomatis untuk dokumen (PO, SJ).

| Kolom | Tipe | Constraint | Deskripsi |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO INCREMENT | |
| `warehouse_id` | BIGINT UNSIGNED | FK → warehouses.id | Per gudang |
| `document_type` | VARCHAR(30) | NOT NULL | 'sales_order', 'delivery_note' |
| `year` | INTEGER | NOT NULL | Tahun |
| `last_number` | INTEGER | NOT NULL, DEFAULT 0 | Nomor terakhir yang digunakan |
| `created_at` | TIMESTAMP | | |
| `updated_at` | TIMESTAMP | | |

> **Unique Constraint:** (`warehouse_id`, `document_type`, `year`)

---

## 4. Relasi Antar Tabel

### 4.1 Ringkasan Relasi

| Tabel Induk | Tabel Anak | Tipe Relasi | FK Column |
|---|---|---|---|
| `roles` | `users` | One-to-Many | `users.role_id` |
| `warehouses` | `users` | One-to-Many | `users.warehouse_id` |
| `warehouses` | `locations` | One-to-Many | `locations.warehouse_id` |
| `warehouses` | `inbound_headers` | One-to-Many | `inbound_headers.warehouse_id` |
| `warehouses` | `sales_orders` | One-to-Many | `sales_orders.warehouse_id` |
| `warehouses` | `inventory_stocks` | One-to-Many | `inventory_stocks.warehouse_id` |
| `product_categories` | `products` | One-to-Many | `products.category_id` |
| `products` | `inbound_details` | One-to-Many | `inbound_details.product_id` |
| `products` | `inventory_stocks` | One-to-Many | `inventory_stocks.product_id` |
| `products` | `sales_order_details` | One-to-Many | `sales_order_details.product_id` |
| `locations` | `inventory_stocks` | One-to-Many | `inventory_stocks.location_id` |
| `inbound_headers` | `inbound_details` | One-to-Many | `inbound_details.inbound_header_id` |
| `inbound_details` | `inventory_stocks` | One-to-One | `inventory_stocks.inbound_detail_id` |
| `customers` | `sales_orders` | One-to-Many | `sales_orders.customer_id` |
| `customers` | `customer_billings` | One-to-Many | `customer_billings.customer_id` |
| `sales_orders` | `sales_order_details` | One-to-Many | `sales_order_details.sales_order_id` |
| `sales_orders` | `order_trackings` | One-to-Many | `order_trackings.sales_order_id` |
| `sales_orders` | `delivery_notes` | One-to-One | `delivery_notes.sales_order_id` |
| `sales_orders` | `delivery_proofs` | One-to-Many | `delivery_proofs.sales_order_id` |
| `sales_orders` | `customer_billings` | One-to-One | `customer_billings.sales_order_id` |
| `sales_order_details` | `sales_order_allocations` | One-to-Many | `sales_order_allocations.sales_order_detail_id` |
| `inventory_stocks` | `sales_order_allocations` | One-to-Many | `sales_order_allocations.inventory_stock_id` |
| `customer_billings` | `billing_payments` | One-to-One | `billing_payments.customer_billing_id` |
| `users` | `user_sessions` | One-to-Many | `user_sessions.user_id` |
| `users` | `login_attempts` | One-to-Many | (by email, no FK) |
| `users` | `notifications` | One-to-Many | `notifications.user_id` |
| `users` | `audit_logs` | One-to-Many | `audit_logs.user_id` |

---

## 5. Strategi Indexing

### 5.1 Index Kritis untuk Performa

```sql
-- FIFO Query: Mencari stok tertua per produk per gudang
CREATE INDEX idx_inventory_fifo 
ON inventory_stocks (product_id, warehouse_id, production_date ASC)
WHERE qty_available > 0 AND status = 'active';

-- Stok per gudang: Dashboard dan laporan
CREATE INDEX idx_inventory_warehouse 
ON inventory_stocks (warehouse_id, product_id);

-- Pesanan per status: Dashboard dan filter
CREATE INDEX idx_orders_status 
ON sales_orders (status, warehouse_id);

-- Pesanan per sales: Dashboard sales
CREATE INDEX idx_orders_user 
ON sales_orders (user_id, status, created_at DESC);

-- Pesanan per customer: Check billing block
CREATE INDEX idx_orders_customer 
ON sales_orders (customer_id, status);

-- Billing unpaid: Check customer block
CREATE INDEX idx_billing_unpaid 
ON customer_billings (customer_id, status)
WHERE status = 'unpaid';

-- Notifikasi unread per user
CREATE INDEX idx_notifications_unread 
ON notifications (user_id, is_read, created_at DESC)
WHERE is_read = FALSE;

-- Audit log: Pencarian per tabel/record
CREATE INDEX idx_audit_auditable 
ON audit_logs (auditable_type, auditable_id, created_at DESC);

-- Stock movements: Pencarian per referensi
CREATE INDEX idx_movements_reference 
ON stock_movements (reference_type, reference_id);

-- Stock movements: Pencarian per produk
CREATE INDEX idx_movements_product 
ON stock_movements (product_id, warehouse_id, created_at DESC);

-- Login attempts: Check lockout
CREATE INDEX idx_login_email 
ON login_attempts (email, created_at DESC);

-- User sessions: Enforce max devices
CREATE INDEX idx_sessions_user 
ON user_sessions (user_id, last_activity_at DESC);

-- Document sequences: Generate next number
CREATE UNIQUE INDEX idx_doc_seq_unique 
ON document_sequences (warehouse_id, document_type, year);
```

---

## 6. Strategi Archival

### 6.1 Tabel yang Membutuhkan Archival

| Tabel | Kriteria Archival | Tabel Archive |
|---|---|---|
| `audit_logs` | `created_at` > 2 tahun | `audit_logs_archive` |
| `login_attempts` | `created_at` > 6 bulan | `login_attempts_archive` |
| `notifications` | `created_at` > 1 tahun AND `is_read` = TRUE | `notifications_archive` |
| `stock_movements` | `created_at` > 2 tahun | `stock_movements_archive` |
| `order_trackings` | `created_at` > 2 tahun | `order_trackings_archive` |

### 6.2 Proses Archival

```
SCHEDULE: Monthly (1st day of month, 02:00 WIB)
PROCESS:
  1. BEGIN TRANSACTION
  2. INSERT INTO {table}_archive SELECT * FROM {table} WHERE created_at < threshold
  3. DELETE FROM {table} WHERE created_at < threshold
  4. COMMIT
  5. Log hasil archival ke audit_logs
```

> [!NOTE]
> Tabel archive memiliki struktur identik dengan tabel asli. Data archive tetap bisa diakses melalui menu "Arsip" di panel Admin.

---

## 7. Seed Data Awal

### 7.1 Roles

```
| id | name             | slug             |
|----|------------------|------------------|
| 1  | Super Admin      | super_admin      |
| 2  | Manager          | manager          |
| 3  | Tim Logistik     | logistics        |
| 4  | Tim Produksi     | production       |
| 5  | Operator Gudang  | warehouse_operator |
| 6  | Tim Sales        | sales            |
```

### 7.2 System Settings

```
| key                          | value | description                           |
|------------------------------|-------|---------------------------------------|
| order_cutoff_time            | 15:00 | Batas waktu order harian (WIB)       |
| delivery_note_prefix         | SJ    | Prefix nomor Surat Jalan             |
| delivery_note_starting_number| 1     | Starting number SJ                   |
| order_number_prefix          | PO    | Prefix nomor PO                      |
| order_number_starting_number | 1     | Starting number PO                   |
| max_upload_size_mb           | 5     | Max ukuran upload file (MB)          |
| max_upload_files             | 3     | Max jumlah file upload per proof     |
| session_idle_timeout_minutes | 60    | Durasi idle sebelum auto-logout      |
| max_concurrent_sessions      | 2     | Max device per akun                  |
| max_login_attempts           | 5     | Max percobaan login sebelum lockout  |
| initial_lockout_minutes      | 5     | Durasi lockout pertama (menit)       |
| audit_archive_months         | 24    | Umur audit log sebelum di-archive    |
| default_shelf_life_months    | 30    | Masa simpan default produk baru      |
| expiry_warning_days          | 90    | Ambang peringatan dini kedaluwarsa   |
| return_number_prefix         | RTN   | Prefix nomor dokumen retur           |
| transfer_number_prefix       | TRF   | Prefix nomor dokumen transfer stok   |
```

---

## 8. Urutan Migration

Migration harus dijalankan sesuai urutan dependensi. Berikut urutan yang benar:

```
01. create_roles_table
02. create_warehouses_table
03. create_users_table                    (FK: roles, warehouses)
04. create_user_sessions_table            (FK: users)
05. create_login_attempts_table
06. create_product_categories_table
07. create_products_table                 (FK: product_categories)
08. create_locations_table                (FK: warehouses)
09. create_payment_terms_table            (tanpa FK)
09b. create_customers_table               (FK: users)
10. create_inbound_headers_table          (FK: warehouses, users)
11. create_inbound_details_table          (FK: inbound_headers, products, locations, users)
12. create_inventory_stocks_table         (FK: products, locations, warehouses, users, inbound_details)
13. create_stock_movements_table          (FK: products, locations, warehouses, users)
14. create_sales_orders_table             (FK: customers, users, warehouses)
15. create_sales_order_details_table      (FK: sales_orders, products)
16. create_sales_order_allocations_table  (FK: sales_order_details, inventory_stocks)
17. create_delivery_notes_table           (FK: sales_orders, users)
18. create_delivery_proofs_table          (FK: sales_orders, users)
19. create_sales_returns_table            (FK: sales_orders, customers, warehouses, users)
20. create_sales_return_details_table     (FK: sales_returns, products, locations)
21. create_customer_billings_table        (FK: sales_orders, customers)
22. create_billing_payments_table         (FK: customer_billings, users)
23. create_order_trackings_table          (FK: sales_orders, users)
24. create_notifications_table            (FK: users)
25. create_audit_logs_table               (FK: users)
26. create_system_settings_table
27. create_document_sequences_table       (FK: warehouses)
28. add_sales_return_fk_to_inventory_stocks_table
```

> [!IMPORTANT]
> **Dependensi melingkar `inventory_stocks` ↔ `sales_return_details`.**
> `inventory_stocks` (no. 12) memiliki kolom `sales_return_detail_id` yang menunjuk tabel no. 20 — yang belum ada saat migration 12 dijalankan.
>
> **Solusi:** pada migration 12, buat kolom tersebut sebagai kolom biasa yang nullable **tanpa** foreign key constraint. Constraint-nya ditambahkan belakangan lewat migration no. 28. Jangan menukar urutan 12 dan 20 — `sales_return_details` sendiri bergantung pada `products` dan `locations`.

> [!TIP]
> Gunakan `php artisan migrate:fresh --seed` saat development. Seeder harus mengisi: roles, system_settings, dan 1 user Super Admin default.
