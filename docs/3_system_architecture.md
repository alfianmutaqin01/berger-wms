# Arsitektur Sistem
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 1.0  
> **Tanggal:** 14 Agustus 2026  
> **Pola Arsitektur:** Single Database, Multi-Portal (Monolithic)

---

## Daftar Isi

1. [Arsitektur Tingkat Tinggi (High-Level)](#1-arsitektur-tingkat-tinggi)
2. [Arsitektur Lapisan (Layered Architecture)](#2-arsitektur-lapisan)
3. [Arsitektur Keamanan](#3-arsitektur-keamanan)
4. [Arsitektur Event & Notifikasi](#4-arsitektur-event--notifikasi)
5. [Arsitektur Queue & Background Jobs](#5-arsitektur-queue--background-jobs)
6. [Arsitektur Caching](#6-arsitektur-caching)
7. [Arsitektur Deployment](#7-arsitektur-deployment)
8. [Alur Request Lifecycle](#8-alur-request-lifecycle)
9. [Disaster Recovery & Backup](#9-disaster-recovery--backup)

---

## 1. Arsitektur Tingkat Tinggi

### 1.1 Diagram Arsitektur Utama

```mermaid
graph TB
    subgraph CLIENTS ["👥 Client Layer"]
        SALES_APP["📱 Portal Sales<br>(Mobile Browser)"]
        WH_APP["🖥️ Portal Warehouse<br>(Desktop Browser)"]
        ADMIN_APP["🖥️ Portal Admin<br>(Desktop Browser)"]
    end

    subgraph NGINX_LAYER ["🔒 Reverse Proxy"]
        NGINX["Nginx<br>SSL Termination<br>Static Assets<br>Rate Limiting"]
    end

    subgraph APP_LAYER ["⚙️ Application Layer (Docker)"]
        LARAVEL["Laravel 11+<br>(PHP-FPM 8.2+)"]
        HORIZON["Laravel Horizon<br>(Queue Worker)"]
        SCHEDULER["Laravel Scheduler<br>(Cron Jobs)"]
    end

    subgraph DATA_LAYER ["💾 Data Layer (Docker)"]
        POSTGRES[("PostgreSQL 16+<br>Primary Database")]
        REDIS[("Redis 7+<br>Cache / Session / Queue")]
    end

    subgraph REALTIME_LAYER ["📡 Real-time Layer"]
        SOKETI["Soketi / Pusher<br>WebSocket Server"]
    end

    SALES_APP -->|HTTPS| NGINX
    WH_APP -->|HTTPS| NGINX
    ADMIN_APP -->|HTTPS| NGINX
    
    NGINX -->|FastCGI| LARAVEL
    LARAVEL -->|Eloquent ORM| POSTGRES
    LARAVEL -->|Cache/Session/Queue| REDIS
    LARAVEL -->|Broadcast Events| SOKETI
    HORIZON -->|Process Jobs from| REDIS
    SCHEDULER -->|Trigger Jobs| REDIS
    
    SOKETI -->|WebSocket Push| SALES_APP
    SOKETI -->|WebSocket Push| WH_APP
    SOKETI -->|WebSocket Push| ADMIN_APP
```

### 1.2 Deskripsi Arsitektur

Sistem ini menggunakan arsitektur **Monolithic Multi-Portal** di mana satu instance Laravel melayani tiga portal berbeda melalui routing dan middleware:

| Portal | Target Device | Pengguna | URL Pattern |
|---|---|---|---|
| **Portal Sales** | Smartphone (Mobile-first) | Tim Sales | `/sales/*` |
| **Portal Warehouse** | Desktop/Tablet (Desktop-first) | Tim Logistik, Operator Gudang, Tim Produksi | `/warehouse/*` |
| **Portal Admin** | Desktop (Desktop-first) | Super Admin, Manager | `/admin/*` |

Semua portal **berbagi satu database** PostgreSQL yang sama, namun diisolasi melalui:
- **Route Groups** terpisah di `routes/web.php`
- **Middleware RBAC** yang memfilter akses per role
- **Blade Layout** berbeda per portal (sales layout vs warehouse layout)
- **Controller namespace** berbeda per portal

---

## 2. Arsitektur Lapisan (Layered Architecture)

### 2.1 Diagram Lapisan

```mermaid
graph TB
    subgraph PRESENTATION ["1️⃣ Presentation Layer"]
        BLADE["Laravel Blade Templates"]
        BOOTSTRAP["Bootstrap 5 CSS"]
        JS["JavaScript (Vanilla + AJAX)"]
        ECHO["Laravel Echo (WebSocket Client)"]
    end
    
    subgraph APPLICATION ["2️⃣ Application Layer"]
        CONTROLLERS["Controllers"]
        REQUESTS["Form Requests (Validation)"]
        MIDDLEWARE["Middleware Stack"]
        RESOURCES["View Composers"]
    end
    
    subgraph DOMAIN ["3️⃣ Domain Layer (Business Logic)"]
        SERVICES["Service Classes"]
        EVENTS["Events & Listeners"]
        JOBS["Queue Jobs"]
        POLICIES["Authorization Policies"]
    end
    
    subgraph INFRASTRUCTURE ["4️⃣ Infrastructure Layer"]
        MODELS["Eloquent Models"]
        REPOS["Query Builders"]
        CACHE_SVC["Cache Manager"]
        BROADCAST["Broadcasting"]
        STORAGE["File Storage"]
        EXPORT["Excel/PDF Export"]
    end
    
    subgraph EXTERNAL ["5️⃣ External Layer"]
        DB[("PostgreSQL")]
        REDIS_EXT[("Redis")]
        FILESYSTEM[("Local/S3 Storage")]
        WS_SERVER["WebSocket Server"]
    end
    
    PRESENTATION --> APPLICATION
    APPLICATION --> DOMAIN
    DOMAIN --> INFRASTRUCTURE
    INFRASTRUCTURE --> EXTERNAL
```

### 2.2 Penjelasan Setiap Lapisan

#### Layer 1: Presentation (Frontend)
- **Blade Templates:** Render HTML per portal (sales, warehouse, admin)
- **Bootstrap 5:** Framework CSS untuk responsivitas
- **JavaScript:** Interaksi UI, AJAX calls, chart rendering, camera capture
- **Laravel Echo:** Client-side WebSocket listener untuk notifikasi real-time

#### Layer 2: Application (Koordinator)
- **Controllers:** Menerima HTTP request, memanggil Service, mengembalikan View/JSON
- **Form Requests:** Validasi input sebelum masuk ke business logic
- **Middleware:** Authentication, RBAC, portal separation, session enforcement, order cutoff
- **View Composers:** Menyediakan data global ke semua view (notif count, user info)

#### Layer 3: Domain (Business Logic)
- **Service Classes:** Encapsulasi logika bisnis kompleks:
  - `FifoAllocationService` — Algoritma FIFO untuk alokasi stok
  - `PalletSplitService` — Pemecahan qty ke palet
  - `OrderProcessingService` — Orchestrasi alur order (validate → allocate → track)
  - `StockMovementService` — Pencatatan setiap mutasi stok ke ledger
  - `BillingService` — Pembuatan dan pengecekan billing
  - `SlaCalculationService` — Perhitungan durasi SLA
  - `NotificationService` — Pengiriman notifikasi
  - `AuditService` — Pencatatan audit log
- **Events & Listeners:** Event-driven untuk notifikasi dan side-effects
- **Queue Jobs:** Background processing untuk operasi berat
- **Policies:** Authorization rules per model (siapa boleh apa)

#### Layer 4: Infrastructure (Data Access)
- **Eloquent Models:** Mapping tabel database ke PHP objects
- **Cache Manager:** Abstraksi Redis caching
- **Broadcasting:** Abstraksi pengiriman WebSocket events
- **File Storage:** Manajemen upload file (foto bukti Surat Jalan)
- **Excel/PDF Export:** Generate laporan

#### Layer 5: External (Infrastruktur Eksternal)
- PostgreSQL, Redis, File System, WebSocket Server

---

## 3. Arsitektur Keamanan

### 3.1 Lapisan Keamanan (Defense in Depth)

```mermaid
graph LR
    subgraph L1 ["Layer 1: Network"]
        HTTPS["HTTPS/TLS"]
        RATE["Rate Limiting<br>(Nginx)"]
    end
    
    subgraph L2 ["Layer 2: Authentication"]
        LOGIN["Email/Password"]
        BOT_L["Google reCAPTCHA v2<br>(anti-bot)"]
        LOCKOUT["Progressive Lockout"]
    end
    
    subgraph L3 ["Layer 3: Session"]
        SESSION["Session Management"]
        MAX_DEV["Max 2 Devices"]
        IDLE["1 Hour Idle Timeout"]
    end
    
    subgraph L4 ["Layer 4: Authorization"]
        RBAC_L["RBAC Middleware"]
        POLICY["Model Policies"]
        PORTAL["Portal Isolation"]
    end
    
    subgraph L5 ["Layer 5: Input Validation"]
        CSRF["CSRF Token"]
        XSS_L["XSS Prevention"]
        UPLOAD["File Upload Validation"]
        SQL_L["SQL Injection Prevention"]
    end
    
    subgraph L6 ["Layer 6: Audit"]
        AUDIT["Immutable Audit Log"]
        STOCK_LOG["Stock Movement Ledger"]
    end
    
    L1 --> L2 --> L3 --> L4 --> L5 --> L6
```

### 3.2 Middleware Stack (Urutan Eksekusi)

Setiap request HTTP melewati middleware dalam urutan berikut:

```
1. EncryptCookies
2. StartSession
3. VerifyCsrfToken
4. ShareErrorsFromSession
5. ──────────────────────── (Laravel Default di atas)
6. auth                   → Cek user sudah login?                    [SUDAH ADA]
7. session.track          → Max 2 device + idle timeout 1 jam         [SUDAH ADA]
                            (TrackUserSession: satu middleware untuk keduanya)
8. portal:{wms|sales}     → Cegah akses silang antar-portal           [SUDAH ADA]
                            (EnsurePortalAccess)
9. CheckRole:{roles}      → Cek user punya role yang diizinkan?       [rencana]
10. CheckOrderCutoff      → (Khusus route order) Cek belum lewat jam 15:00?  [rencana]
11. TrackAuditLog         → Record ke audit_logs (untuk operasi CUD)  [rencana]
```

### 3.3 Alur Autentikasi Lengkap

```mermaid
sequenceDiagram
    actor User
    participant Browser
    participant Nginx
    participant Laravel
    participant Redis
    participant PostgreSQL
    participant ReCAPTCHA as Google reCAPTCHA

    User->>Browser: Buka halaman login
    Browser->>Nginx: GET /login
    Nginx->>Laravel: Forward request
    Laravel->>Browser: Render login form (+ widget reCAPTCHA)

    User->>Browser: Input email + password + centang "Saya bukan robot"
    Browser->>Laravel: POST /login (kredensial + token reCAPTCHA)
    Laravel->>ReCAPTCHA: Verifikasi token (siteverify)
    ReCAPTCHA->>Laravel: valid / tidak valid
    Laravel->>PostgreSQL: Cek email exists

    alt Email tidak ditemukan ATAU token anti-bot tidak valid
        Laravel->>PostgreSQL: Log ke login_attempts
        Laravel->>Browser: "Email atau Password salah" (pesan generik)
    else Email ditemukan
        Laravel->>PostgreSQL: Cek akun aktif & tidak terkunci?
        alt Akun nonaktif
            Laravel->>Browser: "Akun Anda tidak aktif. Hubungi Administrator."
        else Akun terkunci
            Laravel->>Browser: "Akun terkunci sampai pukul HH:MM"
        else Akun siap login
            Laravel->>PostgreSQL: Verifikasi password (Bcrypt)
            alt Password salah
                Laravel->>PostgreSQL: Increment failed_login_attempts
                alt Attempts >= 3
                    Laravel->>PostgreSQL: Set locked_until (5/10/30/60/120 menit)
                    Laravel->>Browser: "Akun terkunci selama X menit"
                else Attempts < 3
                    Laravel->>Browser: "Email atau Password salah"
                end
            else Password benar
                Laravel->>PostgreSQL: Reset failed_login_attempts = 0
                Laravel->>Redis: Simpan session (regenerate ID)
                Laravel->>PostgreSQL: Cek active sessions
                alt Sessions >= 2
                    Laravel->>PostgreSQL: Delete oldest user_session
                end
                Laravel->>PostgreSQL: Insert user_session (device_token)
                Laravel->>Browser: Set cookie device_token + redirect dashboard (by role)
            end
        end
    end
```

> [!NOTE]
> **Tidak ada langkah verifikasi terpisah setelah password.** reCAPTCHA diverifikasi pada request `POST /login` yang sama (PRD v1.2) — berbeda dengan rancangan MFA/TOTP lama yang memerlukan halaman `/mfa/verify` tersendiri.

### 3.4 File Upload Security

```
Upload Request (POST /sales/orders/{id}/proof)
│
├── 1. CSRF Token Check (Middleware)
│
├── 2. Authentication Check (Middleware)
│
├── 3. Form Request Validation:
│   ├── files.* → required | file | mimes:png,jpg,jpeg
│   ├── files.* → max:5120 (5MB in KB)
│   ├── files   → max:3 (max 3 files)
│   └── Reject jika tidak valid
│
├── 4. Server-side MIME Verification:
│   ├── Baca file header (magic bytes)
│   ├── Verifikasi benar-benar image (bukan PHP/shell)
│   └── Reject jika file berbahaya
│
├── 5. File Processing:
│   ├── Generate unique filename (UUID + timestamp)
│   ├── Strip EXIF metadata
│   ├── Simpan ke storage/app/delivery-proofs/ (non-public)
│   └── Catat di tabel delivery_proofs
│
└── 6. Serving:
    └── File hanya bisa diakses via Controller route (bukan direct URL)
    └── Controller cek authorization sebelum serve file
```

---

## 4. Arsitektur Event & Notifikasi

### 4.1 Event-Driven Flow

Sistem menggunakan **Laravel Events & Listeners** untuk memisahkan aksi utama dari efek samping (notifikasi, logging, dll).

```mermaid
graph LR
    subgraph TRIGGERS ["Aksi Pemicu"]
        T1["Sales Submit PO"]
        T2["Logistik Approve"]
        T3["Operator Picking Done"]
        T4["Logistik Cetak SJ"]
        T5["Sales Upload Bukti"]
        T6["Logistik Verify Bukti"]
        T7["Logistik Konfirmasi Bayar"]
        T8["Stok Diverifikasi"]
    end
    
    subgraph EVENTS ["Laravel Events"]
        E1["OrderSubmitted"]
        E2["OrderApproved"]
        E3["OrderReadyToShip"]
        E4["DeliveryNoteIssued"]
        E5["ProofUploaded"]
        E6["OrderCompleted"]
        E7["BillingPaid"]
        E8["StockVerified"]
    end
    
    subgraph LISTENERS ["Listeners (Side Effects)"]
        L1["SendNotification"]
        L2["UpdateOrderTracking"]
        L3["BroadcastToWebSocket"]
        L4["RecordStockMovement"]
        L5["CreateBillingRecord"]
        L6["CalculateSla"]
    end
    
    T1 --> E1
    T2 --> E2
    T3 --> E3
    T4 --> E4
    T5 --> E5
    T6 --> E6
    T7 --> E7
    T8 --> E8
    
    E1 --> L1
    E1 --> L2
    E1 --> L3
    E2 --> L1
    E2 --> L2
    E2 --> L3
    E2 --> L4
    E3 --> L1
    E3 --> L2
    E3 --> L3
    E4 --> L1
    E4 --> L2
    E4 --> L3
    E5 --> L1
    E5 --> L2
    E5 --> L3
    E6 --> L1
    E6 --> L2
    E6 --> L3
    E6 --> L5
    E6 --> L6
    E7 --> L1
    E7 --> L3
    E8 --> L1
    E8 --> L3
    E8 --> L4
```

### 4.2 WebSocket Channel Architecture

```
Channels:
├── private-user.{userId}           → Notifikasi personal per user
├── private-warehouse.{warehouseId} → Event per gudang (untuk Logistik/Operator)
└── private-admin                   → Event untuk Manager/Super Admin

Contoh:
- Sales submit PO untuk gudang KRW
  → Broadcast ke channel: private-warehouse.1 (gudang KRW)
  → Semua Logistik yang membuka dashboard gudang KRW menerima notifikasi

- Logistik approve PO dari Sales ID 5
  → Broadcast ke channel: private-user.5
  → Sales ID 5 menerima notifikasi di HP-nya
```

### 4.3 Notifikasi dengan Suara

```javascript
// Frontend (JavaScript) - Listener
window.Echo.private(`user.${userId}`)
    .listen('OrderApproved', (event) => {
        // 1. Tampilkan toast notification
        showToast(event.title, event.message);
        
        // 2. Update badge counter di bell icon
        incrementNotificationBadge();
        
        // 3. Mainkan suara notifikasi
        playNotificationSound();
        
        // 4. Update data di halaman (jika relevan)
        if (currentPage === 'orders') {
            refreshOrderTable();
        }
    });

function playNotificationSound() {
    const audio = new Audio('/sounds/notification.mp3');
    audio.volume = 0.5;
    audio.play().catch(() => {
        // Browser mungkin memblokir autoplay
        // Tampilkan visual indicator sebagai fallback
    });
}
```

---

## 5. Arsitektur Queue & Background Jobs

### 5.1 Queue Configuration

```
Queue Driver: Redis
Queue Monitor: Laravel Horizon

Queues (ordered by priority):
├── high      → Notifikasi real-time, WebSocket broadcast
├── default   → Order processing, FIFO allocation, stock movements
├── low       → Report generation, Excel export, archival
└── backups   → Database backup, cleanup jobs
```

### 5.2 Job yang Menggunakan Queue

| Job | Queue | Deskripsi |
|---|---|---|
| `SendNotificationJob` | high | Kirim notifikasi + broadcast WebSocket |
| `ProcessOrderApprovalJob` | default | FIFO allocation + stock movement recording |
| `CalculateSlaJob` | default | Hitung durasi SLA saat order complete |
| `GenerateExcelReportJob` | low | Generate laporan Excel (bisa lambat) |
| `ArchiveOldDataJob` | low | Pindahkan data lama ke tabel archive |
| `DatabaseBackupJob` | backups | pg_dump + compress + rotate |
| `CleanExpiredSessionsJob` | low | Hapus session yang sudah expired |
| `UpdateBillingOverdueJob` | default | Cek dan update status billing overdue |

### 5.3 Scheduled Jobs (Cron)

| Schedule | Job | Deskripsi |
|---|---|---|
| Setiap 1 menit | `CleanExpiredSessions` | Hapus session idle > 1 jam |
| Setiap 5 menit | `UpdateBillingOverdue` | Cek billing yang melewati jatuh tempo |
| Setiap hari 02:00 | `DatabaseBackup` | Backup PostgreSQL |
| Setiap bulan 1st 03:00 | `ArchiveOldData` | Archive data lama |

---

## 6. Arsitektur Caching

### 6.1 Cache Strategy

| Data | Cache Driver | TTL | Invalidation |
|---|---|---|---|
| Session | Redis | 60 menit (idle) | On logout / idle |
| System Settings | Redis | 24 jam | On settings update |
| Product list (per warehouse) | Redis | 1 jam | On product CRUD |
| Dashboard stats (aggregates) | Redis | 5 menit | Time-based expiry |
| User permissions/roles | Redis | Session lifetime | On role change |
| Semi-blind stock indicators | Redis | 5 menit | On stock change |
| Notification count (unread) | Redis | Real-time update | On notification event |

### 6.2 Cache Invalidation Strategy

```php
// Contoh: Saat stok berubah, invalidate related caches
class StockMovementService
{
    public function recordMovement(StockMovement $movement): void
    {
        // ... simpan ke database ...
        
        // Invalidate caches
        Cache::tags(['stock', "warehouse:{$movement->warehouse_id}"])->flush();
        Cache::forget("dashboard:stats:{$movement->warehouse_id}");
        Cache::forget("product:availability:{$movement->product_id}:{$movement->warehouse_id}");
    }
}
```

---

## 7. Arsitektur Deployment

### 7.1 Docker Compose Architecture

```mermaid
graph TB
    subgraph DOCKER ["Docker Compose Stack"]
        subgraph FRONTEND_NET ["Frontend Network"]
            NGINX_C["nginx<br>:80 / :443"]
        end
        
        subgraph APP_NET ["Application Network"]
            PHP_FPM["php-fpm<br>(Laravel App)<br>:9000"]
            HORIZON_C["horizon<br>(Queue Worker)"]
            SCHEDULER_C["scheduler<br>(Cron)"]
        end
        
        subgraph DATA_NET ["Data Network"]
            PG_C["postgres<br>:5432"]
            REDIS_C["redis<br>:6379"]
            SOKETI_C["soketi<br>:6001"]
        end
        
        subgraph STORAGE_VOL ["Persistent Volumes"]
            PG_VOL[("pg_data")]
            REDIS_VOL[("redis_data")]
            APP_VOL[("app_storage")]
            BACKUP_VOL[("backups")]
        end
    end
    
    NGINX_C --> PHP_FPM
    PHP_FPM --> PG_C
    PHP_FPM --> REDIS_C
    PHP_FPM --> SOKETI_C
    HORIZON_C --> PG_C
    HORIZON_C --> REDIS_C
    SCHEDULER_C --> PG_C
    SCHEDULER_C --> REDIS_C
    
    PG_C --> PG_VOL
    REDIS_C --> REDIS_VOL
    PHP_FPM --> APP_VOL
    SCHEDULER_C --> BACKUP_VOL
```

### 7.2 Container Specifications

| Container | Image | CPU | Memory | Restart |
|---|---|---|---|---|
| `nginx` | nginx:alpine | 0.5 | 256MB | always |
| `php-fpm` | Custom (PHP 8.2-fpm) | 2.0 | 1GB | always |
| `horizon` | Same as php-fpm | 1.0 | 512MB | always |
| `scheduler` | Same as php-fpm | 0.5 | 256MB | always |
| `postgres` | postgres:16-alpine | 2.0 | 2GB | always |
| `redis` | redis:7-alpine | 0.5 | 512MB | always |
| `soketi` | quay.io/soketi/soketi | 0.5 | 256MB | always |

### 7.3 Volume Mapping

```yaml
volumes:
  pg_data:         # PostgreSQL data files
  redis_data:      # Redis persistence (RDB)
  app_storage:     # Laravel storage/ (uploads, logs, cache)
  backups:         # Database backup files
```

---

## 8. Alur Request Lifecycle

### 8.1 Request Flow (Contoh: Sales Submit PO)

```mermaid
sequenceDiagram
    actor Sales
    participant Browser
    participant Nginx
    participant Middleware as Middleware Stack
    participant Controller as OrderController
    participant Service as OrderProcessingService
    participant DB as PostgreSQL
    participant Redis
    participant Queue as Laravel Horizon
    participant WS as Soketi WebSocket

    Sales->>Browser: Klik "Submit Order"
    Browser->>Nginx: POST /sales/orders (HTTPS)
    Nginx->>Middleware: Forward (FastCGI)
    
    Note over Middleware: CSRF Check ✓
    Note over Middleware: Auth Check ✓
    Note over Middleware: Session Track (max 2 device + idle) ✓
    Note over Middleware: Portal Check (sales) ✓
    Note over Middleware: Role Check (sales) ✓
    Note over Middleware: Order Cutoff Check (< 15:00?) ✓
    
    Middleware->>Controller: StoreOrderRequest (validated)
    Controller->>Service: createOrder(data)
    
    Service->>DB: BEGIN TRANSACTION
    Service->>DB: Check customer not blocked (billing)
    Service->>DB: Insert sales_orders
    Service->>DB: Insert sales_order_details
    Service->>DB: Insert order_trackings (status: pending)
    Service->>DB: COMMIT
    
    Service->>Queue: Dispatch SendNotificationJob (high priority)
    Service->>Controller: Return order object
    
    Controller->>Browser: Redirect to /sales/orders/{id} with success message
    
    Queue->>DB: Insert notification for Logistik users
    Queue->>Redis: Broadcast to private-warehouse.{id}
    Redis->>WS: Push event
    WS->>Browser: OrderSubmitted event (Logistik's browser)
    Note over Browser: 🔔 Sound + Toast notification
```

---

## 9. Disaster Recovery & Backup

### 9.1 Backup Strategy

| Komponen | Metode | Frekuensi | Retensi |
|---|---|---|---|
| PostgreSQL (Full) | `pg_dump` + gzip | Harian (02:00 WIB) | 30 hari |
| PostgreSQL (WAL) | Continuous archiving | Real-time | 7 hari |
| Redis (RDB) | Redis BGSAVE | Setiap 15 menit | 3 snapshot |
| Upload Files | Rsync ke backup volume | Harian | 90 hari |
| Application Code | Git repository | Setiap commit | Unlimited |

### 9.2 Recovery Procedures

```
Level 1 — Application Error:
  → Rollback ke Docker image sebelumnya
  → Downtime: < 5 menit

Level 2 — Data Corruption:
  → Restore dari pg_dump backup terakhir
  → Replay WAL dari titik korupsi
  → Downtime: 15-60 menit (tergantung ukuran DB)

Level 3 — Hardware Failure:
  → Setup ulang Docker stack di server baru
  → Restore database dari backup
  → Restore uploads dari backup
  → Downtime: 1-4 jam
```

### 9.3 Backup Verification

```
Schedule: Mingguan (Minggu, 04:00 WIB)
Process:
  1. Copy backup terbaru ke environment test
  2. Restore database
  3. Jalankan health check queries:
     - SELECT COUNT(*) FROM inventory_stocks
     - SELECT COUNT(*) FROM sales_orders
     - SELECT MAX(created_at) FROM audit_logs
  4. Bandingkan dengan production counts
  5. Log hasil verifikasi
```
