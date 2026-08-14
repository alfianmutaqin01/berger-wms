# Strategi Testing
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 1.0  
> **Tanggal:** 14 Agustus 2026  
> **Testing Framework:** PHPUnit (Unit + Feature), Laravel Dusk (Browser)

---

## Daftar Isi

1. [Prinsip Testing](#1-prinsip-testing)
2. [Piramida Testing](#2-piramida-testing)
3. [Unit Tests](#3-unit-tests)
4. [Feature Tests (Integration)](#4-feature-tests)
5. [Browser Tests (E2E)](#5-browser-tests)
6. [Security Tests](#6-security-tests)
7. [Performance Tests](#7-performance-tests)
8. [User Acceptance Testing (UAT)](#8-user-acceptance-testing)
9. [Konfigurasi & Commands](#9-konfigurasi--commands)
10. [Checklist Go-Live](#10-checklist-go-live)

---

## 1. Prinsip Testing

### 1.1 Aturan Utama

| Aturan | Detail |
|---|---|
| **Test sebelum merge** | Tidak ada PR yang boleh merge ke `develop` tanpa passing all tests |
| **Isolasi database** | Setiap test menggunakan database transaction yang di-rollback (trait `RefreshDatabase`) |
| **Factory & Seeder** | Gunakan Laravel Factory untuk generate test data, bukan hardcode |
| **Assertions spesifik** | Assert hasil spesifik, bukan hanya "no error" |
| **Test negative cases** | Selalu test kasus gagal (unauthorized, invalid input, edge case) |

### 1.2 Cakupan Minimum

| Metrik | Target |
|---|---|
| Code coverage (Service classes) | ≥ 90% |
| Code coverage (Controllers) | ≥ 80% |
| Code coverage (Middleware) | ≥ 95% |
| Semua business rule | 100% (setiap aturan bisnis punya minimal 1 test) |
| Semua role access | 100% (setiap endpoint ditest untuk authorized + unauthorized role) |

---

## 2. Piramida Testing

```
            ┌───────────┐
            │  Browser  │  ← Sedikit, lambat, mahal
            │   Tests   │     (Dusk: 10-15 test scenarios)
            │  (E2E)    │
           ┌┴───────────┴┐
           │   Feature    │  ← Sedang, HTTP-level
           │    Tests     │     (60-80 test cases)
           │ (Integration)│
          ┌┴──────────────┴┐
          │   Unit Tests    │  ← Banyak, cepat, murah
          │  (Services,     │     (100-150 test cases)
          │   Models, etc.) │
          └─────────────────┘
```

---

## 3. Unit Tests

Unit test menguji satu class/method secara terisolasi. Dependencies di-mock jika perlu.

### 3.1 Service Tests

#### `FifoAllocationServiceTest`

| # | Test Case | Input | Expected |
|---|---|---|---|
| 1 | Alokasi normal — stok cukup | Order 100, Stok: [Batch A: 150] | Alokasi 100 dari Batch A |
| 2 | Alokasi FIFO — multiple batch | Order 200, Stok: [A:100 (lama), B:150 (baru)] | Alokasi 100 dari A + 100 dari B |
| 3 | Partial fulfillment — stok kurang | Order 200, Stok total: 150 | Alokasi 150, Lost: 50 |
| 4 | Stok habis total | Order 100, Stok: 0 | Alokasi 0, Lost: 100 |
| 5 | FIFO urutan benar | Stok: [Jan: 50, Mar: 80, Feb: 30] | Urutan alokasi: Jan → Feb → Mar |
| 6 | Multi-location same batch | Order 100, Stok: [Lok A: 60, Lok B: 80] same batch | Alokasi dari A dulu (60) + B (40) |
| 7 | Tidak mengurangi qty_allocated | Order 50, Stok: [available:80, allocated:20] | Alokasi hanya dari available |

#### `PalletSplitServiceTest`

| # | Test Case | Input | Expected |
|---|---|---|---|
| 1 | Split tepat habis | Qty 360, Max 180/palet | 2 palet @ 180 |
| 2 | Split dengan sisa | Qty 500, Max 180/palet | 3 palet: 180, 180, 140 |
| 3 | Qty kurang dari 1 palet | Qty 50, Max 180/palet | 1 palet @ 50 |
| 4 | Qty = 0 | Qty 0 | Error / 0 palet |
| 5 | Split cat 0.9Lt | Qty 2000, Max 720/palet | 3 palet: 720, 720, 560 |
| 6 | Split cat 15Lt | Qty 100, Max 40/palet | 3 palet: 40, 40, 20 |
| 7 | Split cat 20Lt | Qty 27, Max 27/palet | 1 palet @ 27 |
| 8 | Split cat 25Kg | Qty 72, Max 36/palet | 2 palet @ 36 |

#### `OrderProcessingServiceTest`

| # | Test Case | Expected |
|---|---|---|
| 1 | Create order — valid data | Order created, status pending, tracking recorded |
| 2 | Create order — customer blocked (billing unpaid) | Exception thrown, order NOT created |
| 3 | Create order — customer not approved | Exception thrown |
| 4 | Create order — after cutoff time (15:00) | Exception thrown |
| 5 | Approve order — normal | Status → approved, stock allocated, tracking recorded |
| 6 | Approve order — partial fulfillment | Qty adjusted, lost_qty calculated |
| 7 | Reject order — with reason | Status → rejected, notification sent |
| 8 | Complete order — cash/transfer | Status → completed, NO billing created |
| 9 | Complete order — tempo 30 | Status → completed_billing, billing created, due date +30 days |

#### `BillingServiceTest`

| # | Test Case | Expected |
|---|---|---|
| 1 | Create billing — tempo 30 | due_date = complete_date + 30 days |
| 2 | Create billing — tempo 60 | due_date = complete_date + 60 days |
| 3 | Create billing — tempo 90 | due_date = complete_date + 90 days |
| 4 | Check customer blocked — has unpaid billing | Returns TRUE (blocked) |
| 5 | Check customer blocked — all paid | Returns FALSE (not blocked) |
| 6 | Check customer blocked — cash customer | Returns FALSE (not blocked) |
| 7 | Confirm payment — update status | Status → paid, customer unblocked |
| 8 | Check overdue — past due date | Status auto-update to overdue |

#### `SlaCalculationServiceTest`

| # | Test Case | Expected |
|---|---|---|
| 1 | Calculate SLA — normal | Hours between submit and complete |
| 2 | Calculate SLA — same day | Correct fractional hours |
| 3 | Calculate SLA — multi-day | Correct total hours across days |

#### `StockMovementServiceTest`

| # | Test Case | Expected |
|---|---|---|
| 1 | Record IN movement | qty_change positive, qty_before/after correct |
| 2 | Record OUT movement | qty_change negative, qty_before/after correct |
| 3 | Record ADJUSTMENT (increase) | Correct delta, notes required |
| 4 | Record ADJUSTMENT (decrease) | Cannot go below qty_allocated |
| 5 | Record ALLOCATED | qty_available decreases, qty_allocated increases |

### 3.2 Model Tests

| Model | Test Cases |
|---|---|
| `User` | Relasi ke role, warehouse. Scope by role. MFA enabled check. |
| `Product` | Relasi ke category. Scope aktif. Max pallet qty accessor. |
| `InventoryStock` | Scope by warehouse. Scope available (qty > 0). FIFO scope (order by production_date). |
| `SalesOrder` | Relasi ke details, customer, user. Status scopes. SLA accessor. |
| `Customer` | Scope approved. Scope blocked (has unpaid billing). |
| `CustomerBilling` | Scope unpaid. Scope overdue. Due date calculation. |

---

## 4. Feature Tests

Feature tests mengirim HTTP request ke endpoint dan memverifikasi response, database state, dan side effects.

### 4.1 Auth Feature Tests

| # | Test | Method | URL | Expected |
|---|---|---|---|---|
| 1 | Login berhasil (email + password) | POST | /login | Redirect ke MFA page |
| 2 | Login gagal — email salah | POST | /login | Error message, failed_login_attempts +1 |
| 3 | Login gagal — password salah | POST | /login | Error message, failed_login_attempts +1 |
| 4 | Login lockout setelah 5x gagal | POST | /login (x5) | Account locked 5 menit |
| 5 | Progressive lockout | POST | /login (x6) | Locked 10 menit |
| 6 | MFA verify berhasil | POST | /mfa/verify | Redirect ke dashboard |
| 7 | MFA verify gagal | POST | /mfa/verify | Error, stay on MFA page |
| 8 | Akses halaman tanpa login | GET | /warehouse/dashboard | Redirect ke /login |
| 9 | Akses halaman tanpa MFA | GET | /warehouse/dashboard | Redirect ke /mfa/verify |
| 10 | Akses portal salah (sales → warehouse) | GET | /warehouse/dashboard | 403 Forbidden |
| 11 | Auto-logout setelah idle | GET | any (after 1h) | Redirect ke /login |
| 12 | Max 2 sessions — 3rd login | POST | /login (3rd device) | Oldest session terminated |

### 4.2 Master Data Feature Tests

| # | Test | Role | Expected |
|---|---|---|---|
| 1 | Create product — Super Admin | Super Admin | 201, product in DB |
| 2 | Create product — Sales (unauthorized) | Sales | 403 |
| 3 | Create warehouse — Manager | Manager | 201, warehouse in DB |
| 4 | Create customer — Sales submit | Sales | 201, status=pending |
| 5 | Approve customer — Manager | Manager | Status → approved |
| 6 | Reject customer — Manager | Manager | Status → rejected |
| 7 | Approve customer — Sales (unauthorized) | Sales | 403 |
| 8 | Create location — duplicate code | Manager | 422, validation error |

### 4.3 Inbound Feature Tests

| # | Test | Role | Expected |
|---|---|---|---|
| 1 | Create inbound — Tim Produksi | Produksi | Created, pallets auto-split |
| 2 | Create inbound — Sales (unauthorized) | Sales | 403 |
| 3 | Put-away — Operator assigns location | Operator | Location saved, status=verification_pending |
| 4 | Put-away — invalid location code | Operator | 422, validation error |
| 5 | Verify — Logistik checklist single | Logistik | Item verified, stock active |
| 6 | Verify — Logistik checklist all | Logistik | All items verified, all stock active |
| 7 | Verify — stock_movements recorded | Logistik | IN movement entries created |
| 8 | Edit during verify — change qty | Logistik | Qty updated before verify |

### 4.4 Outbound Feature Tests

| # | Test | Role | Expected |
|---|---|---|---|
| 1 | Create order — valid | Sales | Created, status=pending, notification sent |
| 2 | Create order — customer blocked (billing) | Sales | 422, error message |
| 3 | Create order — customer not approved | Sales | 422, error message |
| 4 | Create order — after 15:00 cutoff | Sales | 422, cutoff error message |
| 5 | Approve order — full stock | Logistik | All qty approved, stock allocated |
| 6 | Approve order — partial stock | Logistik | Qty adjusted, lost_qty recorded |
| 7 | Approve order — zero stock | Logistik | All lost_qty, qty_approved=0 |
| 8 | Approve order — FIFO correct | Logistik | Oldest batch allocated first |
| 9 | Reject order — with reason | Logistik | Status=rejected, notification sent |
| 10 | Mark picking done — Operator | Operator | Status=ready_to_ship |
| 11 | Print delivery note | Logistik | DN number generated, status=shipping |
| 12 | Upload proof — valid PNG | Sales | File saved, status=proof_uploaded |
| 13 | Upload proof — valid JPG | Sales | File saved |
| 14 | Upload proof — invalid PDF | Sales | 422, validation error |
| 15 | Upload proof — file too large (>5MB) | Sales | 422, validation error |
| 16 | Upload proof — more than 3 files | Sales | 422, validation error |
| 17 | Verify proof + complete (cash) | Logistik | Status=completed, no billing |
| 18 | Verify proof + complete (tempo 30) | Logistik | Status=completed_billing, billing created |
| 19 | Download proof files | Logistik | Files downloaded successfully |

### 4.5 Billing Feature Tests

| # | Test | Role | Expected |
|---|---|---|---|
| 1 | List unpaid billings | Logistik | Only unpaid shown |
| 2 | Confirm payment | Logistik | Status → paid |
| 3 | Customer unblocked after payment | Logistik | Sales can create new order |
| 4 | Overdue detection | System | Billing past due_date → status=overdue |

### 4.6 Stock Feature Tests

| # | Test | Role | Expected |
|---|---|---|---|
| 1 | View stock — Logistik | Logistik | Can see all stock data |
| 2 | View stock — Sales | Sales | 403 (cannot see stock) |
| 3 | Semi-blind indicator — stock > threshold | Sales | ✅ Tersedia |
| 4 | Semi-blind indicator — stock <= threshold | Sales | ⚠️ Terbatas |
| 5 | Semi-blind indicator — stock = 0 | Sales | ❌ Habis |
| 6 | Adjust stock — Manager | Manager | Qty updated, movement recorded |
| 7 | Adjust stock — Logistik (unauthorized) | Logistik | 403 |
| 8 | Adjust stock — below allocated qty | Manager | 422, cannot go below |

### 4.7 Dashboard & Notification Feature Tests

| # | Test | Expected |
|---|---|---|
| 1 | Sales dashboard — shows own data only | Only logged-in sales' transactions |
| 2 | Warehouse dashboard — shows all data | All transactions across all sales |
| 3 | Notification created on order submit | Notification in DB for Logistik users |
| 4 | Notification created on order approve | Notification in DB for Sales user |
| 5 | Notification marked as read | is_read=true, badge count decremented |
| 6 | WebSocket broadcast on order submit | Event dispatched to correct channel |

### 4.8 Audit Log Feature Tests

| # | Test | Expected |
|---|---|---|
| 1 | Delete transaction — Super Admin | Transaction soft-deleted, audit_log created |
| 2 | Delete transaction — Manager (unauthorized) | 403 |
| 3 | Audit log — immutable | Cannot update or delete audit_log records |
| 4 | View audit log — Manager | Can view |
| 5 | View audit log — Sales (unauthorized) | 403 |

---

## 5. Browser Tests (E2E)

Browser tests menggunakan **Laravel Dusk** untuk mensimulasikan interaksi pengguna nyata di browser.

### 5.1 Skenario E2E

| # | Skenario | Langkah | Verifikasi |
|---|---|---|---|
| 1 | **Login Flow Lengkap** | Input email → password → MFA code → dashboard | Dashboard tampil sesuai role |
| 2 | **Sales: Create Order** | Login Sales → New Order → Pilih customer → Add items → Submit | Order muncul di daftar pending |
| 3 | **Logistik: Approve Order** | Login Logistik → Pesanan → Detail → Approve | Status berubah, notification ke Sales |
| 4 | **Operator: Picking** | Login Operator → Picking List → Ceklis items → Siap Loading | Status berubah ke ready_to_ship |
| 5 | **Logistik: Print Delivery Note** | Login Logistik → Surat Jalan → Input supir/plat → Cetak | PDF tergenerate, status=shipping |
| 6 | **Sales: Upload Proof** | Login Sales → Detail Order → Upload foto → Submit | Foto tersimpan, status berubah |
| 7 | **Logistik: Verify & Complete** | Login Logistik → Verifikasi → Download → Complete | Status=completed |
| 8 | **Full Inbound Flow** | Produksi input → Operator put-away → Logistik verify | Stok aktif bertambah |
| 9 | **Billing Block Flow** | Complete order tempo → Sales coba order lagi → Blocked → Logistik confirm → Unblocked | Order blocked then allowed |
| 10 | **Cutoff Time** | Login Sales → Try submit after 15:00 | Submit button disabled |
| 11 | **Progressive Lockout** | Wrong password 5x → Locked → Wait → Try again | Progressive timing |
| 12 | **Notification Sound** | Sales submit → Logistik page open → Toast + sound | Visual + audio notification |

---

## 6. Security Tests

### 6.1 RBAC Access Control Tests

Untuk **setiap endpoint**, test bahwa:
- ✅ Authorized role can access
- ❌ Each unauthorized role gets 403
- ❌ Unauthenticated user gets redirected to login

```php
// Contoh: test semua role terhadap satu endpoint
public function test_only_manager_and_super_admin_can_edit_stock()
{
    // Should succeed
    $this->actingAs($superAdmin)->patch('/admin/stock/1', $data)->assertOk();
    $this->actingAs($manager)->patch('/admin/stock/1', $data)->assertOk();
    
    // Should fail
    $this->actingAs($logistik)->patch('/admin/stock/1', $data)->assertForbidden();
    $this->actingAs($operator)->patch('/admin/stock/1', $data)->assertForbidden();
    $this->actingAs($produksi)->patch('/admin/stock/1', $data)->assertForbidden();
    $this->actingAs($sales)->patch('/admin/stock/1', $data)->assertForbidden();
}
```

### 6.2 File Upload Security Tests

| # | Test | Input | Expected |
|---|---|---|---|
| 1 | Upload valid PNG | test.png (100KB) | 200, file saved |
| 2 | Upload valid JPG | test.jpg (500KB) | 200, file saved |
| 3 | Upload PHP file renamed to PNG | shell.php → shell.png | 422, rejected (MIME check) |
| 4 | Upload executable | malware.exe | 422, rejected |
| 5 | Upload oversized file | 6MB image | 422, rejected |
| 6 | Upload too many files | 4 files | 422, rejected (max 3) |
| 7 | Upload no file | Empty | 422, required |
| 8 | Direct URL access to uploaded file | GET /storage/delivery-proofs/file.png | 403 (must go through controller) |

### 6.3 CSRF Protection Tests

| # | Test | Expected |
|---|---|---|
| 1 | POST without CSRF token | 419 (Token Mismatch) |
| 2 | POST with invalid CSRF token | 419 |
| 3 | POST with valid CSRF token | Request processed |

### 6.4 Session Security Tests

| # | Test | Expected |
|---|---|---|
| 1 | Session fixation attempt | New session ID after login |
| 2 | Concurrent session limit (3rd device) | Oldest session terminated |
| 3 | Idle timeout | Session invalid after 1 hour |
| 4 | Session after logout | Cannot access protected routes |

---

## 7. Performance Tests

### 7.1 Database Query Performance

| Test | Query | Target | Method |
|---|---|---|---|
| FIFO allocation with 10K inventory records | `ORDER BY production_date ASC` | < 100ms | `DB::enableQueryLog()` |
| Dashboard stats aggregation | Multiple COUNT queries | < 500ms | Profiling |
| Order list with eager loading | 100 orders with details | < 200ms | No N+1 queries |
| Stock search with filters | WHERE + ORDER + LIMIT | < 100ms | Explain Analyze |

### 7.2 Response Time Targets

| Page | Target (Desktop) | Target (Mobile) |
|---|---|---|
| Login page | < 1s | < 2s |
| Dashboard | < 2s | < 3s |
| Order list (100 items) | < 2s | < 3s |
| Create order form | < 1.5s | < 2.5s |
| Approve order (FIFO calc) | < 3s | N/A |
| Print delivery note (PDF) | < 5s | N/A |
| Export Excel (1000 rows) | < 10s | N/A |

### 7.3 Concurrency Tests

| Test | Scenario | Expected |
|---|---|---|
| Simultaneous order approval | 2 Logistik approve different orders at same time | No double allocation |
| Simultaneous stock check | 10 users query stock simultaneously | Consistent results |
| Race condition on stock | Approve order while stock being adjusted | Transaction locks prevent conflict |

---

## 8. User Acceptance Testing (UAT)

### 8.1 UAT Participants

| Tester | Role | Test Focus |
|---|---|---|
| 1-2 Tim Produksi | Tim Produksi | Inbound input flow |
| 1-2 Operator Gudang | Operator | Put-away and picking flow |
| 1-2 Tim Logistik | Logistik | Full approval, delivery, billing flow |
| 2-3 Tim Sales | Sales | Order creation, tracking, upload |
| 1 Manager | Manager | Dashboard, reports, stock adjustment |
| 1 IT Admin | Super Admin | Full system configuration |

### 8.2 UAT Scenarios

#### Skenario 1: Siklus Inbound Lengkap
```
1. Tim Produksi login dan input data produksi (SKU, batch, qty)
2. Verifikasi: Palet otomatis terpecah dengan benar
3. Operator login, lihat daftar put-away
4. Operator input lokasi rak untuk setiap palet
5. Logistik login, lihat daftar verifikasi
6. Logistik ceklis satu per satu, lalu verifikasi
7. Verifikasi: Stok aktif bertambah sesuai qty yang diverifikasi
```

#### Skenario 2: Siklus Order Lengkap (Cash)
```
1. Sales login dari HP, buat pesanan baru
2. Pilih customer, tambah item, submit
3. Verifikasi: Notifikasi muncul di dashboard Logistik (dengan suara)
4. Logistik approve pesanan
5. Verifikasi: Stock teralokasi, notification ke Sales
6. Operator kerjakan picking list
7. Operator tandai siap loading
8. Logistik cetak Surat Jalan
9. Verifikasi: Status di HP Sales berubah ke "Dalam Pengiriman"
10. Sales upload foto bukti SJ dari kamera
11. Logistik download foto, verifikasi, klik Complete
12. Verifikasi: Status = Complete (tanpa billing)
```

#### Skenario 3: Siklus Order dengan Partial Fulfillment
```
1. Sales pesan 100 pcs, stok hanya 60
2. Logistik approve
3. Verifikasi: Qty approved = 60, Lost sales = 40
4. Lanjutkan proses sampai complete
5. Verifikasi: Laporan Lost Sales mencatat 40 pcs
```

#### Skenario 4: Siklus Billing (Tempo 30 Hari)
```
1. Complete order dengan payment term Tempo 30
2. Verifikasi: Billing record dibuat, due date = +30 hari
3. Sales coba buat order baru untuk customer yang sama
4. Verifikasi: Order DIBLOKIR dengan pesan error
5. Logistik konfirmasi pembayaran
6. Verifikasi: Customer unblocked
7. Sales berhasil buat order baru
```

#### Skenario 5: Keamanan & Akses
```
1. Sales coba akses URL warehouse → 403
2. Logistik coba edit stok → 403
3. Login salah 5 kali → akun terkunci
4. Login dengan MFA code → berhasil
5. Buka di device ke-3 → device pertama logout
```

---

## 9. Konfigurasi & Commands

### 9.1 Konfigurasi PHPUnit (`phpunit.xml`)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         testdox="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="pgsql"/>
        <env name="DB_DATABASE" value="wms_testing"/>
        <env name="CACHE_DRIVER" value="array"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="BROADCAST_DRIVER" value="null"/>
    </php>
</phpunit>
```

### 9.2 Commands

```bash
# Jalankan semua tests
php artisan test

# Jalankan hanya unit tests
php artisan test --testsuite=Unit

# Jalankan hanya feature tests
php artisan test --testsuite=Feature

# Jalankan test spesifik
php artisan test --filter=FifoAllocationServiceTest

# Jalankan dengan coverage report
php artisan test --coverage --min=80

# Jalankan browser tests (Dusk)
php artisan dusk

# Jalankan Dusk test spesifik
php artisan dusk --filter=FullOrderFlowTest

# Parallel testing (lebih cepat)
php artisan test --parallel --processes=4
```

### 9.3 Struktur Direktori Tests

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── FifoAllocationServiceTest.php
│   │   ├── PalletSplitServiceTest.php
│   │   ├── OrderProcessingServiceTest.php
│   │   ├── StockMovementServiceTest.php
│   │   ├── BillingServiceTest.php
│   │   ├── SlaCalculationServiceTest.php
│   │   └── NotificationServiceTest.php
│   ├── Models/
│   │   ├── UserTest.php
│   │   ├── ProductTest.php
│   │   ├── InventoryStockTest.php
│   │   ├── SalesOrderTest.php
│   │   ├── CustomerTest.php
│   │   └── CustomerBillingTest.php
│   └── Middleware/
│       ├── CheckRoleTest.php
│       ├── CheckMfaTest.php
│       ├── EnforceMaxSessionsTest.php
│       ├── CheckOrderCutoffTest.php
│       └── CheckCustomerBlockedTest.php
├── Feature/
│   ├── Auth/
│   │   ├── LoginTest.php
│   │   ├── MfaTest.php
│   │   ├── LockoutTest.php
│   │   └── SessionTest.php
│   ├── MasterData/
│   │   ├── ProductManagementTest.php
│   │   ├── WarehouseManagementTest.php
│   │   ├── LocationManagementTest.php
│   │   └── CustomerManagementTest.php
│   ├── Inbound/
│   │   ├── CreateInboundTest.php
│   │   ├── PutawayTest.php
│   │   └── VerifyInboundTest.php
│   ├── Outbound/
│   │   ├── CreateOrderTest.php
│   │   ├── ApproveOrderTest.php
│   │   ├── PickingTest.php
│   │   ├── DeliveryNoteTest.php
│   │   ├── UploadProofTest.php
│   │   └── CompleteOrderTest.php
│   ├── Billing/
│   │   ├── BillingListTest.php
│   │   ├── ConfirmPaymentTest.php
│   │   └── CustomerBlockTest.php
│   ├── Stock/
│   │   ├── ViewStockTest.php
│   │   ├── AdjustStockTest.php
│   │   └── SemiBlindIndicatorTest.php
│   ├── Dashboard/
│   │   ├── SalesDashboardTest.php
│   │   └── WarehouseDashboardTest.php
│   ├── Notification/
│   │   └── NotificationTest.php
│   ├── AuditLog/
│   │   └── AuditLogTest.php
│   └── Security/
│       ├── RbacAccessTest.php
│       ├── FileUploadSecurityTest.php
│       └── CsrfProtectionTest.php
└── Browser/  (Laravel Dusk)
    ├── LoginFlowTest.php
    ├── FullOrderFlowTest.php
    ├── FullInboundFlowTest.php
    ├── BillingBlockFlowTest.php
    └── NotificationSoundTest.php
```

---

## 10. Checklist Go-Live

### 10.1 Pre-Deployment Checklist

- [ ] Semua unit tests PASS
- [ ] Semua feature tests PASS
- [ ] Semua browser tests PASS (Dusk)
- [ ] Code coverage ≥ 80% overall
- [ ] RBAC: Setiap endpoint tested untuk semua 6 role
- [ ] File upload: Semua tipe file berbahaya ditolak
- [ ] Progressive lockout berfungsi sesuai spec
- [ ] MFA (Google Authenticator) berfungsi
- [ ] Session: Idle timeout 1 jam berfungsi
- [ ] Session: Max 2 device berfungsi
- [ ] Order cutoff 15:00 berfungsi
- [ ] Customer billing block berfungsi
- [ ] FIFO allocation menghasilkan urutan yang benar
- [ ] Partial fulfillment dan lost sales terhitung benar
- [ ] Pallet auto-split benar untuk semua UoM
- [ ] Notifikasi real-time + suara berfungsi
- [ ] Dashboard stats akurat
- [ ] Grafik penjualan data benar
- [ ] Excel export menghasilkan file valid
- [ ] PDF Surat Jalan tercetak dengan benar
- [ ] Audit log mencatat semua operasi sensitif
- [ ] Performance: Semua halaman < 3 detik
- [ ] Responsive: Sales portal mobile OK
- [ ] Responsive: Warehouse portal desktop OK
- [ ] Database migration berjalan clean (fresh + seed)
- [ ] Docker containers berjalan stabil 24 jam tanpa restart
- [ ] Backup database berhasil dan bisa di-restore
- [ ] UAT signoff dari setiap role
