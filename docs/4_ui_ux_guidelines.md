# Panduan UI/UX
## Sistem WMS & Sales Order — PT Berger Paints Indonesia

> **Versi:** 1.0  
> **Tanggal:** 14 Agustus 2026  
> **CSS Framework:** Bootstrap 5.3+  
> **Pendekatan:** Mobile-first (Sales) | Desktop-first (Warehouse/Admin)

---

## Daftar Isi

1. [Prinsip Desain](#1-prinsip-desain)
2. [Design System](#2-design-system)
3. [Portal Sales (Mobile-First)](#3-portal-sales-mobile-first)
4. [Portal Warehouse (Desktop-First)](#4-portal-warehouse-desktop-first)
5. [Portal Admin (Desktop-First)](#5-portal-admin-desktop-first)
6. [Komponen Bersama](#6-komponen-bersama)
7. [Responsivitas & Breakpoints](#7-responsivitas--breakpoints)
8. [Accessibility](#8-accessibility)

---

## 1. Prinsip Desain

### 1.1 Prinsip Utama

| Prinsip | Penerapan |
|---|---|
| **Clarity over Cleverness** | UI harus jelas tanpa perlu tutorial. Label, icon, dan warna status harus self-explanatory |
| **Context-appropriate Design** | Sales di toko → UI ringan, tap-friendly. Logistik di meja → UI data-dense, keyboard-friendly |
| **Consistent Visual Language** | Warna status, icon, dan komponen seragam di semua portal |
| **Error Prevention** | Modal konfirmasi untuk semua aksi destruktif. Validasi real-time di form |
| **Feedback Always** | Loading spinner saat AJAX, toast notification saat aksi berhasil/gagal, suara saat notifikasi masuk |

### 1.2 Batasan Desain

- ✅ Menggunakan Bootstrap 5.3+ (utility classes + components)
- ✅ Menggunakan Google Fonts (Inter atau Roboto)
- ✅ Menggunakan DataTables untuk semua tabel data
- ✅ Menggunakan Chart.js atau ApexCharts untuk grafik
- ❌ TIDAK menggunakan framework SPA (React/Vue)
- ❌ TIDAK menggunakan TailwindCSS
- ❌ TIDAK menggunakan custom CSS framework

---

## 2. Design System

### 2.1 Color Palette

#### Brand Colors
```css
:root {
    /* Primary — Brand Berger Paints (nuansa biru profesional) */
    --color-primary:        #1B4F8A;
    --color-primary-dark:   #0F3460;
    --color-primary-light:  #2E75B6;
    
    /* Secondary — Aksen emas/oranye */
    --color-secondary:      #E8871E;
    --color-secondary-dark: #C76E15;
    --color-secondary-light:#F4A84B;
}
```

#### Semantic Colors (Status)
```css
:root {
    /* Status Colors */
    --color-success:  #28A745;  /* Approved, Complete, Lunas */
    --color-warning:  #FFC107;  /* Pending, Menunggu, Terbatas */
    --color-danger:   #DC3545;  /* Rejected, Overdue, Habis, Error */
    --color-info:     #17A2B8;  /* Dalam Proses, Pengiriman */
    --color-muted:    #6C757D;  /* Draft, Inactive */
    
    /* Semi-Blind Stock Indicators */
    --stock-available: #28A745;  /* ✅ Tersedia */
    --stock-limited:   #FFC107;  /* ⚠️ Terbatas */
    --stock-empty:     #DC3545;  /* ❌ Habis */
}
```

#### Background & Surface
```css
:root {
    /* Backgrounds */
    --bg-body:        #F4F6F9;   /* Body background */
    --bg-card:        #FFFFFF;   /* Card/panel background */
    --bg-sidebar:     #1B2A4A;  /* Sidebar warehouse (dark) */
    --bg-navbar:      #1B4F8A;  /* Navbar warehouse */
    --bg-navbar-sales:#FFFFFF;  /* Navbar sales (light, mobile) */
    
    /* Text */
    --text-primary:   #212529;
    --text-secondary: #6C757D;
    --text-inverse:   #FFFFFF;
}
```

### 2.2 Typography

```css
/* Google Fonts Import */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

:root {
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    
    /* Font Sizes */
    --text-xs:    0.75rem;   /* 12px — label kecil, badge */
    --text-sm:    0.875rem;  /* 14px — body text, table cell */
    --text-base:  1rem;      /* 16px — body default */
    --text-lg:    1.125rem;  /* 18px — subheading */
    --text-xl:    1.25rem;   /* 20px — section title */
    --text-2xl:   1.5rem;    /* 24px — page title */
    --text-3xl:   1.875rem;  /* 30px — dashboard stat number */
}
```

### 2.3 Spacing & Sizing

```css
:root {
    /* Spacing Scale */
    --space-1:  0.25rem;   /* 4px */
    --space-2:  0.5rem;    /* 8px */
    --space-3:  1rem;      /* 16px */
    --space-4:  1.5rem;    /* 24px */
    --space-5:  2rem;      /* 32px */
    --space-6:  3rem;      /* 48px */
    
    /* Border Radius */
    --radius-sm:  0.375rem;  /* 6px — buttons, inputs */
    --radius-md:  0.5rem;    /* 8px — cards */
    --radius-lg:  0.75rem;   /* 12px — modals */
    --radius-full: 50%;      /* circle — avatars, badges */
    
    /* Shadows */
    --shadow-sm:  0 1px 2px rgba(0,0,0,0.05);
    --shadow-md:  0 4px 6px rgba(0,0,0,0.07);
    --shadow-lg:  0 10px 15px rgba(0,0,0,0.1);
    
    /* Touch Target (Mobile) */
    --min-touch-target: 44px;
}
```

---

## 3. Portal Sales (Mobile-First)

### 3.1 Layout Structure

```
┌──────────────────────────┐
│     Status Bar (OS)      │ ← Device status bar
├──────────────────────────┤
│  🔔 Berger Sales    👤  │ ← Top navbar (putih, compact)
├──────────────────────────┤
│                          │
│                          │
│     Content Area         │ ← Scrollable content
│     (Cards, Forms,       │
│      Lists, Timeline)    │
│                          │
│                          │
├──────────────────────────┤
│  🏠   📋   ➕   👥   ⚙️  │ ← Bottom navigation (fixed)
│ Home  Order  New  Cust  Me│
└──────────────────────────┘
```

### 3.2 Bottom Navigation Bar

```html
<!-- Fixed bottom navigation -->
<nav class="navbar fixed-bottom navbar-light bg-white border-top shadow-sm">
    <div class="container-fluid d-flex justify-content-around">
        <a href="/sales/dashboard" class="nav-link text-center">
            <i class="bi bi-house-fill"></i>
            <span class="d-block small">Home</span>
        </a>
        <a href="/sales/orders" class="nav-link text-center">
            <i class="bi bi-clipboard-data"></i>
            <span class="d-block small">Order</span>
        </a>
        <a href="/sales/orders/create" class="nav-link text-center">
            <div class="btn btn-primary rounded-circle p-2">
                <i class="bi bi-plus-lg text-white"></i>
            </div>
        </a>
        <a href="/sales/customers" class="nav-link text-center">
            <i class="bi bi-shop"></i>
            <span class="d-block small">Customer</span>
        </a>
        <a href="/sales/profile" class="nav-link text-center">
            <i class="bi bi-person"></i>
            <span class="d-block small">Profil</span>
        </a>
    </div>
</nav>
```

### 3.3 Halaman-Halaman Portal Sales

#### 3.3.1 Dashboard Sales
**Deskripsi:** Halaman utama setelah login. Menampilkan ringkasan data pribadi sales.

**Wireframe Layout:**
```
┌─────────────────────────────┐
│  Selamat Pagi, Ahmad! 👋    │
│  Senin, 14 Agustus 2026     │
├─────────────────────────────┤
│ ┌───────────┐ ┌───────────┐ │
│ │   📊 12   │ │   ⏳ 3    │ │
│ │ Transaksi │ │ Menunggu  │ │
│ │ Bulan Ini │ │ Approval  │ │
│ └───────────┘ └───────────┘ │
│ ┌───────────┐ ┌───────────┐ │
│ │   🚚 5    │ │   ✅ 4    │ │
│ │  Dalam    │ │ Complete  │ │
│ │ Pengiriman│ │ Bulan Ini │ │
│ └───────────┘ └───────────┘ │
├─────────────────────────────┤
│ 📋 Pesanan Terbaru          │
│ ┌───────────────────────── ┐│
│ │ PO-KRW-2026-00145  →    ││
│ │ Toko Jaya Makmur        ││
│ │ ⏳ Menunggu Diterima     ││
│ │ 14 Aug 2026, 09:15      ││
│ └───────────────────────── ┘│
│ ┌───────────────────────── ┐│
│ │ PO-KRW-2026-00142  →    ││
│ │ TB Sinar Baru           ││
│ │ 🚚 Dalam Pengiriman     ││
│ │ 13 Aug 2026, 14:30      ││
│ └───────────────────────── ┘│
│            ...              │
└─────────────────────────────┘
```

**Komponen:**
- 4 stat cards (grid 2x2) dengan angka besar dan icon
- List pesanan terbaru (card-based, swipeable)
- Setiap card pesanan menampilkan: nomor PO, nama customer, status (badge warna), tanggal
- Tap card → navigasi ke detail pesanan

#### 3.3.2 Form Buat Pesanan (New Order)
**Deskripsi:** Form multi-step untuk membuat pesanan baru.

**Wireframe Layout:**
```
┌─────────────────────────────┐
│  ← Buat Pesanan Baru       │
├─────────────────────────────┤
│                             │
│  Customer *                 │
│  ┌───────────────────────┐  │
│  │ 🔍 Cari customer...  │  │
│  └───────────────────────┘  │
│                             │
│  Gudang Tujuan *            │
│  ┌───────────────────────┐  │
│  │ ▼ Pilih gudang        │  │
│  └───────────────────────┘  │
│                             │
│  Pembayaran *               │
│  ┌───────────────────────┐  │
│  │ ▼ Cash / Transfer /   │  │
│  │   Tempo 30/60/90 hari │  │
│  └───────────────────────┘  │
│                             │
│  ── Item Pesanan ────────── │
│  ┌───────────────────────┐  │
│  │ 🔍 Cari produk...    │  │
│  │ Cat Tembok Putih 5Kg  │  │
│  │ ✅ Tersedia           │  │
│  │ Qty: [___100___]      │  │
│  │              [Hapus ❌]│  │
│  └───────────────────────┘  │
│  ┌───────────────────────┐  │
│  │ Cat Kayu Coklat 2.5Lt │  │
│  │ ⚠️ Terbatas           │  │
│  │ Qty: [___50____]      │  │
│  │              [Hapus ❌]│  │
│  └───────────────────────┘  │
│                             │
│  [+ Tambah Produk]         │
│                             │
│  Catatan (opsional)         │
│  ┌───────────────────────┐  │
│  │                       │  │
│  └───────────────────────┘  │
│                             │
│  ┌───────────────────────┐  │
│  │    📤 Submit Order    │  │
│  └───────────────────────┘  │
│                             │
│  ⚠️ Order ditutup pukul    │
│     15:00 WIB              │
└─────────────────────────────┘
```

**Fitur khusus:**
- **Pencarian customer:** Autocomplete/searchable dropdown
- **Indikator stok Semi-Blind:** Badge ✅⚠️❌ di samping nama produk (TANPA angka)
- **Validasi real-time:** Customer blocked → tampilkan alert merah, jam lewat 15:00 → tombol disabled
- **Multi-item:** Tombol "+ Tambah Produk" untuk menambah baris item

#### 3.3.3 Detail & Tracking Pesanan
**Deskripsi:** Timeline visual status pesanan + area upload bukti.

**Wireframe Layout:**
```
┌─────────────────────────────┐
│  ← Detail Pesanan          │
├─────────────────────────────┤
│  PO-KRW-2026-00145         │
│  Toko Jaya Makmur          │
│  🚚 Dalam Pengiriman       │
├─────────────────────────────┤
│  📦 Item Pesanan            │
│  ┌───────────────────────┐  │
│  │ Cat Tembok Putih 5Kg  │  │
│  │ Pesan: 100 | Kirim: 80│  │
│  └───────────────────────┘  │
│  ┌───────────────────────┐  │
│  │ Cat Kayu Coklat 2.5Lt │  │
│  │ Pesan: 50  | Kirim: 50│  │
│  └───────────────────────┘  │
├─────────────────────────────┤
│  📍 Timeline Status         │
│                             │
│  ✅ Order Dibuat            │
│  │  14 Aug, 09:15 - Ahmad  │
│  │                         │
│  ✅ Disetujui              │
│  │  14 Aug, 10:30 - Budi   │
│  │                         │
│  ✅ Picking Selesai        │
│  │  14 Aug, 11:45 - Cahyo  │
│  │                         │
│  ✅ Surat Jalan Terbit     │
│  │  14 Aug, 12:00 - Budi   │
│  │  SJ-KRW-2026-00089     │
│  │                         │
│  ⏳ Menunggu Bukti          │
│     Unggah foto SJ yg      │
│     sudah ditandatangani    │
│                             │
├─────────────────────────────┤
│  📷 Upload Bukti Surat Jalan│
│  ┌───────────────────────┐  │
│  │  📸 Buka Kamera       │  │
│  └───────────────────────┘  │
│  ┌───────────────────────┐  │
│  │  📁 Pilih dari Galeri │  │
│  └───────────────────────┘  │
│  Format: PNG/JPG, Max 5MB  │
│  Maks 3 foto               │
│                             │
│  ┌───────────────────────┐  │
│  │    📤 Upload Bukti    │  │
│  └───────────────────────┘  │
└─────────────────────────────┘
```

**Fitur khusus:**
- **Timeline vertikal:** Garis vertikal dengan node bulat berwarna (hijau=selesai, kuning=proses, abu=belum)
- **Upload foto:** 2 tombol — "Buka Kamera" (HTML5 `capture="camera"`) dan "Pilih dari Galeri"
- **Preview foto:** Thumbnail foto yang akan diupload sebelum submit
- **SLA display:** Setelah complete, tampilkan durasi total (contoh: "Selesai dalam 26 jam 45 menit")

#### 3.3.4 Form Ajukan Customer Baru
**Deskripsi:** Form sederhana untuk mengajukan toko/customer baru.

```
Fields:
- Nama Toko/Customer *
- Alamat Lengkap *
- Nama PIC (Person in Charge) *
- Nomor HP PIC *
- Tipe Pembayaran Default * (dropdown: Cash/Transfer/Tempo 30/60/90)
- Tombol: "Ajukan Customer"
```

---

## 4. Portal Warehouse (Desktop-First)

### 4.1 Layout Structure

```
┌──────────────────────────────────────────────────────────┐
│  🏭 Berger WMS    🔍 Search...    🔔(5)  Admin ▼       │ ← Top navbar
├────────────┬─────────────────────────────────────────────┤
│            │                                             │
│  📊 Dashboard│          Content Area                     │
│  📥 Inbound │          (Tables, Forms, Charts)           │
│  📦 Stok    │                                           │
│  📋 Pesanan │                                            │
│  🚚 Surat   │                                            │
│     Jalan   │                                            │
│  💳 Billing │                                            │
│  📈 Laporan │                                            │
│            │                                             │
│  ─────────  │                                            │
│  ⚙️ Settings│                                            │
│            │                                             │
├────────────┴─────────────────────────────────────────────┤
│  © 2026 PT Berger Paints Indonesia                       │
└──────────────────────────────────────────────────────────┘
```

### 4.2 Sidebar Navigation

```html
<!-- Sidebar (dark background, collapsed on mobile) -->
<nav class="sidebar bg-dark text-white" style="width: 260px;">
    <div class="sidebar-header p-3">
        <img src="/images/logo-berger.png" alt="Logo" height="40">
        <span class="ms-2 fw-bold">Berger WMS</span>
    </div>
    
    <ul class="nav flex-column">
        <!-- Menu items filtered by role -->
        <li class="nav-item">
            <a class="nav-link text-white" href="/warehouse/dashboard">
                <i class="bi bi-speedometer2 me-2"></i> Dashboard
            </a>
        </li>
        
        <!-- Tim Produksi: hanya lihat Inbound -->
        <!-- Operator: Inbound (Put-away) + Pesanan (Picking) -->
        <!-- Logistik: Semua menu warehouse -->
        
        <li class="nav-item">
            <a class="nav-link text-white" href="/warehouse/inbound">
                <i class="bi bi-box-arrow-in-down me-2"></i> Inbound
                <span class="badge bg-warning float-end">3</span>
            </a>
        </li>
        <!-- ... more items ... -->
    </ul>
</nav>
```

### 4.3 Halaman-Halaman Portal Warehouse

#### 4.3.1 Dashboard Warehouse/Logistik
**Deskripsi:** Overview operasional gudang secara keseluruhan.

**Wireframe Layout:**
```
┌──────────────────────────────────────────────────────┐
│  Dashboard                               Gudang: [KRW ▼]│
├──────────────────────────────────────────────────────┤
│ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐       │
│ │  156 │ │  12  │ │  8   │ │  134 │ │  5   │       │
│ │Total │ │Perlu │ │Dalam │ │ Com- │ │Over- │       │
│ │Trans.│ │Aprv. │ │Kirim │ │plete │ │due   │       │
│ └──────┘ └──────┘ └──────┘ └──────┘ └──────┘       │
├──────────────────────────────────────────────────────┤
│                                                      │
│  📊 Grafik Penjualan                [Bulan▼] [2026▼] │
│  ┌──────────────────────────────────────────────┐    │
│  │                                              │    │
│  │     Bar Chart: Transaksi per Bulan           │    │
│  │     (Jan - Des 2026)                         │    │
│  │                                              │    │
│  │  300|  ██                                    │    │
│  │  250|  ██  ██                                │    │
│  │  200|  ██  ██  ██      ██                    │    │
│  │  150|  ██  ██  ██  ██  ██  ██               │    │
│  │     +---+---+---+---+---+---+---+           │    │
│  │     Jan Feb Mar Apr May Jun Jul              │    │
│  └──────────────────────────────────────────────┘    │
│                                                      │
├───────────────────────┬──────────────────────────────┤
│  🏆 Produk Terlaris   │  📋 Pesanan Terbaru           │
│  ┌───────────────────┐│  ┌──────────────────────────┐│
│  │ 1. Cat Tembok 5Kg ││  │ PO-KRW-00145 ⏳ Pending ││
│  │    1,250 pcs      ││  │ Ahmad → Toko Jaya Makmur ││
│  │ 2. Cat Kayu 2.5Lt ││  │ 14 Aug 2026, 09:15      ││
│  │    890 pcs        ││  ├──────────────────────────┤│
│  │ 3. Thinner 1Lt    ││  │ PO-KRW-00144 ✅ Approved││
│  │    756 pcs        ││  │ Dewi → TB Sinar Baru    ││
│  │ ...               ││  │ 14 Aug 2026, 08:50      ││
│  └───────────────────┘│  └──────────────────────────┘│
└───────────────────────┴──────────────────────────────┘
```

**Fitur:**
- **Filter gudang:** Dropdown di kanan atas untuk filter berdasarkan dispatch code
- **5 stat cards** di atas (Total Transaksi, Perlu Approval, Dalam Pengiriman, Complete, Customer Overdue)
- **Grafik penjualan:** Bar chart dengan filter bulan/tahun/all-time
- **Produk terlaris:** Ranking table dengan filter periode
- **Pesanan terbaru:** Quick-access list ke pesanan yang butuh perhatian

#### 4.3.2 Halaman Inbound — Verifikasi Stok (Tim Logistik)
**Deskripsi:** Daftar barang yang sudah di-put-away oleh operator dan menunggu verifikasi.

**Wireframe Layout:**
```
┌──────────────────────────────────────────────────────────┐
│  Verifikasi Inbound                      [Filter Gudang ▼]│
├──────────────────────────────────────────────────────────┤
│  Dokumen: DOC-FAB-2026-0812                              │
│  Tgl Produksi: 12 Aug 2026 | Gudang: Karawang            │
│  Input oleh: Eko (Tim Produksi)                          │
├──────────────────────────────────────────────────────────┤
│  ┌──┬───────────────┬───────┬──────┬────────┬──────────┐ │
│  │☑ │ Produk        │ Batch │ Qty  │ Lokasi │ Status   │ │
│  ├──┼───────────────┼───────┼──────┼────────┼──────────┤ │
│  │☑ │Cat Tembok 5Kg │BT-001 │ 180  │ A-01-02│ ⏳       │ │
│  │☑ │Cat Tembok 5Kg │BT-001 │ 180  │ A-01-03│ ⏳       │ │
│  │☐ │Cat Tembok 5Kg │BT-001 │ 140  │ A-02-01│ ⏳       │ │
│  │☑ │Cat Kayu 2.5Lt │BT-002 │ 180  │ B-01-01│ ⏳       │ │
│  │☐ │Cat Kayu 2.5Lt │BT-002 │ 180  │ B-01-02│ ⏳       │ │
│  └──┴───────────────┴───────┴──────┴────────┴──────────┘ │
│                                                          │
│  [☑ Pilih Semua]                                        │
│                                                          │
│  ┌──────────────────┐  ┌──────────────────┐             │
│  │ ✏️ Edit Selected  │  │ ✅ Verifikasi    │             │
│  │                  │  │    Selected      │             │
│  └──────────────────┘  └──────────────────┘             │
│                                                          │
│  ⚠️ Setelah diverifikasi, stok akan aktif dan bisa       │
│     dialokasikan untuk pesanan.                          │
└──────────────────────────────────────────────────────────┘
```

**Fitur:**
- **Checkbox per baris:** Ceklis satu per satu
- **Checkbox "Pilih Semua":** Ceklis semua baris sekaligus
- **Tombol Edit:** Membuka modal edit untuk baris yang dipilih (ubah qty, lokasi, batch)
- **Tombol Verifikasi:** Konfirmasi dengan modal → stok aktif
- **Alert info:** Peringatan bahwa verifikasi bersifat final (koreksi hanya via menu Stok)

#### 4.3.3 Halaman Pesanan — Approval (Tim Logistik)
**Deskripsi:** Daftar pesanan masuk yang Menunggu Diterima.

**Wireframe Layout:**
```
┌──────────────────────────────────────────────────────────────┐
│  Pesanan Masuk              [Gudang: KRW ▼] [Status: All ▼] │
├──────────────────────────────────────────────────────────────┤
│  🔍 Cari nomor PO atau customer...                           │
├──────────────────────────────────────────────────────────────┤
│  ┌──────────┬──────────┬──────────┬────────┬────────┬──────┐ │
│  │ No. PO   │ Customer │ Sales    │ Items  │ Tgl    │ Aksi │ │
│  ├──────────┼──────────┼──────────┼────────┼────────┼──────┤ │
│  │ PO-00145 │ Toko Jaya│ Ahmad    │ 3 item │ 14 Aug │ 👁️ ✅❌│ │
│  │ PO-00146 │ TB Sinar │ Dewi     │ 5 item │ 14 Aug │ 👁️ ✅❌│ │
│  │ PO-00147 │ CV Abadi │ Fahri    │ 2 item │ 14 Aug │ 👁️ ✅❌│ │
│  └──────────┴──────────┴──────────┴────────┴────────┴──────┘ │
│                                                              │
│  Showing 1-10 of 12    [< 1 2 >]                             │
└──────────────────────────────────────────────────────────────┘
```

**Pada klik 👁️ (Detail), muncul modal/halaman:**
```
┌──────────────────────────────────────────────┐
│  Detail Pesanan PO-KRW-2026-00145           │
│  Customer: Toko Jaya Makmur                 │
│  Sales: Ahmad | Gudang: Karawang            │
│  Pembayaran: Tempo 30 Hari                  │
│  Catatan: "Kirim pagi ya"                   │
├──────────────────────────────────────────────┤
│  ┌──────────────┬──────┬──────┬──────┐      │
│  │ Produk       │Pesan │ Stok │Kirim │      │
│  ├──────────────┼──────┼──────┼──────┤      │
│  │Cat Tmbok 5Kg │ 100  │ 80   │  80  │      │
│  │Cat Kayu 2.5Lt│  50  │ 120  │  50  │      │
│  │Thinner 1Lt   │  30  │  0   │   0  │      │
│  └──────────────┴──────┴──────┴──────┘      │
│                                              │
│  ⚠️ 1 produk stok habis (Thinner 1Lt)       │
│  📊 Lost Sales: 20 pcs Cat Tembok + 30 pcs  │
│     Thinner                                  │
│                                              │
│  ┌──────────┐         ┌──────────────┐      │
│  │ ❌ Tolak  │         │ ✅ Approve   │      │
│  └──────────┘         └──────────────┘      │
│                                              │
│  Alasan tolak: [____________________]       │
└──────────────────────────────────────────────┘
```

**Fitur:**
- **Auto-adjustment preview:** Saat Logistik klik detail, sistem langsung menghitung stok vs pesanan dan menampilkan preview qty yang akan dikirm dan lost sales
- **Kolom "Kirim":** Qty yang akan di-approve (otomatis = min(pesanan, stok))
- **Alert partial:** Warning jika ada item yang tidak bisa dipenuhi penuh

#### 4.3.4 Halaman Picking List (Operator Gudang)
**Deskripsi:** Daftar item yang harus diambil dari rak, diurutkan berdasarkan lokasi.

```
┌──────────────────────────────────────────────────────┐
│  Picking List — PO-KRW-2026-00145                    │
│  Customer: Toko Jaya Makmur                         │
├──────────────────────────────────────────────────────┤
│  ┌──────┬───────────────┬───────┬──────┬──────────┐  │
│  │ Urut │ Lokasi Rak    │Produk │ Qty  │ Diambil? │  │
│  ├──────┼───────────────┼───────┼──────┼──────────┤  │
│  │  1   │ A-01-02       │Cat 5Kg│  80  │    ☑     │  │
│  │  2   │ B-01-01       │Cat2.5L│  50  │    ☐     │  │
│  └──────┴───────────────┴───────┴──────┴──────────┘  │
│                                                      │
│  Diurutkan: Rak A → G (terdekat ke terjauh)         │
│                                                      │
│  ┌──────────────────────────────────────────┐       │
│  │        ✅ Siap Loading                    │       │
│  └──────────────────────────────────────────┘       │
│                                                      │
│  ⚠️ Tombol aktif setelah semua item diceklis        │
└──────────────────────────────────────────────────────┘
```

#### 4.3.5 Halaman Cetak Surat Jalan (Tim Logistik)

```
┌──────────────────────────────────────────────┐
│  Cetak Surat Jalan — PO-KRW-2026-00145     │
├──────────────────────────────────────────────┤
│  Customer: Toko Jaya Makmur                 │
│  Alamat: Jl. Raya Karawang No. 123         │
│                                              │
│  Nama Supir *                                │
│  ┌──────────────────────────────┐           │
│  │ Pak Bambang                  │           │
│  └──────────────────────────────┘           │
│                                              │
│  Plat Nomor Kendaraan *                      │
│  ┌──────────────────────────────┐           │
│  │ B 1234 XYZ                   │           │
│  └──────────────────────────────┘           │
│                                              │
│  Deskripsi Kendaraan                         │
│  ┌──────────────────────────────┐           │
│  │ Truk CDE engkel              │           │
│  └──────────────────────────────┘           │
│                                              │
│  ┌──────────────────────────────────┐       │
│  │  🖨️ Cetak & Terbitkan Surat Jalan│       │
│  └──────────────────────────────────┘       │
│                                              │
│  Nomor SJ akan di-generate otomatis:        │
│  Preview: SJ-KRW-2026-00089                │
└──────────────────────────────────────────────┘
```

#### 4.3.6 Halaman Verifikasi Bukti Surat Jalan (Tim Logistik)

```
┌──────────────────────────────────────────────────────┐
│  Verifikasi Bukti — PO-KRW-2026-00145               │
├──────────────────────────────────────────────────────┤
│  Sales: Ahmad | Customer: Toko Jaya Makmur          │
│  SJ: SJ-KRW-2026-00089                             │
├──────────────────────────────────────────────────────┤
│                                                      │
│  📷 Foto Bukti Surat Jalan (diupload Sales):        │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐          │
│  │          │  │          │  │          │          │
│  │  Foto 1  │  │  Foto 2  │  │  Foto 3  │          │
│  │          │  │          │  │          │          │
│  └──────────┘  └──────────┘  └──────────┘          │
│  [🔍 Zoom Foto 1] [🔍 Zoom 2] [🔍 Zoom 3]         │
│                                                      │
│  ┌──────────────┐  ┌──────────────────────────┐     │
│  │ 📥 Download   │  │ ✅ Order Complete         │     │
│  │    Semua Foto │  │                          │     │
│  └──────────────┘  └──────────────────────────┘     │
│                                                      │
└──────────────────────────────────────────────────────┘
```

**Fitur:**
- **Gallery view:** Thumbnail grid foto yang diupload
- **Zoom/Lightbox:** Klik foto untuk memperbesar (modal full-screen)
- **Download:** Tombol download semua foto sekaligus (ZIP) atau per foto
- **Complete:** Setelah yakin valid, klik "Order Complete"

#### 4.3.7 Halaman Billing (Tim Logistik)

```
┌─────────────────────────────────────────────────────────────┐
│  Billing & Penagihan        [Status: Semua ▼] [Cari...  ]  │
├─────────────────────────────────────────────────────────────┤
│  ┌───────┬──────────┬───────┬───────┬──────────┬──────────┐ │
│  │No. PO │Customer  │Term   │Jatuh  │Status    │Aksi      │ │
│  │       │          │       │Tempo  │          │          │ │
│  ├───────┼──────────┼───────┼───────┼──────────┼──────────┤ │
│  │PO-145 │Toko Jaya │30 Hari│13 Sep │🟡Belum   │[Konfirmasi]│
│  │PO-139 │TB Sinar  │60 Hari│10 Oct │🟡Belum   │[Konfirmasi]│
│  │PO-128 │CV Abadi  │30 Hari│01 Sep │🔴Overdue │[Konfirmasi]│
│  │PO-120 │Toko Maju │30 Hari│15 Aug │✅Lunas   │[Lihat]    │
│  └───────┴──────────┴───────┴───────┴──────────┴──────────┘ │
│                                                             │
│  📊 Ringkasan: 3 Belum Bayar | 1 Overdue | 5 Lunas         │
└─────────────────────────────────────────────────────────────┘
```

**Pada klik "Konfirmasi":**
```
Modal:
┌──────────────────────────────┐
│  Konfirmasi Pembayaran       │
│  PO-KRW-2026-00145          │
│  Customer: Toko Jaya Makmur │
│                              │
│  Tanggal Pembayaran *        │
│  ┌────────────────────┐      │
│  │ 📅 14/08/2026       │      │
│  └────────────────────┘      │
│                              │
│  Catatan (opsional)          │
│  ┌────────────────────┐      │
│  │ Transfer BCA       │      │
│  └────────────────────┘      │
│                              │
│  [Batal]  [✅ Konfirmasi Lunas]│
└──────────────────────────────┘
```

---

## 5. Portal Admin (Desktop-First)

### 5.1 Menu Sidebar Admin

Menu sidebar Admin merupakan **superset** dari menu Warehouse, ditambah:

```
📊 Dashboard (semua data)
📥 Inbound (lihat semua, tidak bisa verifikasi)
📦 Stok (lihat + EDIT)
📋 Pesanan (lihat semua)
🚚 Surat Jalan (lihat semua)
💳 Billing (lihat semua)
📈 Laporan & Ekspor
──────────
👤 Manajemen User
📦 Master Produk
📂 Kategori Produk
📍 Master Lokasi Rak
🏭 Master Gudang
👥 Approval Customer
──────────
📝 Audit Log
⚙️ Pengaturan Sistem
```

### 5.2 Halaman Manajemen Stok (Manager/Super Admin)

```
┌──────────────────────────────────────────────────────────────┐
│  Manajemen Stok            [Gudang: All ▼] [Kategori: All ▼]│
├──────────────────────────────────────────────────────────────┤
│  🔍 Cari SKU atau nama produk...                             │
├──────────────────────────────────────────────────────────────┤
│  ┌──────┬──────────────┬───────┬────────┬──────┬──────┬────┐ │
│  │ SKU  │ Produk       │ Batch │Lokasi  │Avail │Alloc │Aksi│ │
│  ├──────┼──────────────┼───────┼────────┼──────┼──────┼────┤ │
│  │SK001 │Cat Tembok 5Kg│BT-001 │A-01-02 │ 80   │ 40   │ ✏️ │ │
│  │SK001 │Cat Tembok 5Kg│BT-001 │A-01-03 │ 180  │ 0    │ ✏️ │ │
│  │SK002 │Cat Kayu 2.5Lt│BT-002 │B-01-01 │ 120  │ 50   │ ✏️ │ │
│  └──────┴──────────────┴───────┴────────┴──────┴──────┴────┘ │
│                                                              │
│  [📥 Ekspor Excel]                                           │
└──────────────────────────────────────────────────────────────┘
```

**Pada klik ✏️ (Edit):**
```
Modal:
┌──────────────────────────────────┐
│  Edit Stok                       │
│  Cat Tembok Putih 5Kg (SK001)   │
│  Batch: BT-001 | Lokasi: A-01-02│
│                                  │
│  Qty Tersedia Saat Ini: 80      │
│  Qty Teralokasi: 40             │
│                                  │
│  Qty Baru *                      │
│  ┌────────────────────┐          │
│  │ 75                 │          │
│  └────────────────────┘          │
│  ⚠️ Min: 40 (tidak boleh kurang │
│     dari qty teralokasi)         │
│                                  │
│  Alasan Koreksi *                │
│  ┌────────────────────┐          │
│  │ Barang rusak di rak│          │
│  └────────────────────┘          │
│                                  │
│  [Batal]    [💾 Simpan Koreksi] │
└──────────────────────────────────┘
```

### 5.3 Halaman Audit Log

```
┌──────────────────────────────────────────────────────────────┐
│  Audit Log              [Tipe: All ▼] [User: All ▼]        │
│                         [Dari: ____] [Sampai: ____]         │
├──────────────────────────────────────────────────────────────┤
│  ┌──────────┬──────┬────────┬────────────────┬──────────┐   │
│  │ Waktu    │ User │ Aksi   │ Detail         │ IP       │   │
│  ├──────────┼──────┼────────┼────────────────┼──────────┤   │
│  │14/08 10:15│Admin │DELETE  │Hapus PO-00100 │192.168.1.5│  │
│  │14/08 09:30│Mgr   │ADJUST  │Stok SK001:    │192.168.1.3│  │
│  │          │      │        │80→75 (rusak)  │          │   │
│  │13/08 16:00│Admin │DELETE  │Hapus Customer │192.168.1.5│  │
│  │          │      │        │ID 45          │          │   │
│  └──────────┴──────┴────────┴────────────────┴──────────┘   │
│                                                              │
│  [📥 Ekspor Excel] [📂 Lihat Arsip]                         │
└──────────────────────────────────────────────────────────────┘
```

### 5.4 Halaman Pengaturan Sistem

```
┌──────────────────────────────────────────────────────────┐
│  Pengaturan Sistem                                       │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  🕐 Batas Waktu Order                                    │
│  ┌──────────┐                                           │
│  │ 15:00    │ WIB                                       │
│  └──────────┘                                           │
│                                                          │
│  📄 Pengaturan Nomor Dokumen                             │
│  Prefix Surat Jalan: [SJ___]                            │
│  Starting Number SJ: [1____]                            │
│  Prefix Nomor PO:    [PO___]                            │
│  Starting Number PO: [1____]                            │
│                                                          │
│  📷 Pengaturan Upload                                    │
│  Max Ukuran File: [5___] MB                             │
│  Max Jumlah File: [3___] per upload                     │
│                                                          │
│  🔒 Pengaturan Keamanan                                  │
│  Idle Timeout: [60__] menit                             │
│  Max Device:   [2___] per akun                          │
│  Max Login Attempts: [5___]                             │
│  Initial Lockout: [5___] menit                          │
│                                                          │
│  📦 Pengaturan Archival                                  │
│  Archive Audit Log: [24__] bulan                        │
│                                                          │
│  ┌──────────────────────────────┐                       │
│  │      💾 Simpan Pengaturan    │                       │
│  └──────────────────────────────┘                       │
└──────────────────────────────────────────────────────────┘
```

---

## 6. Komponen Bersama

### 6.1 Status Badge

```html
<!-- Badge warna berdasarkan status order -->
<span class="badge bg-warning text-dark">⏳ Menunggu Diterima</span>
<span class="badge bg-success">✅ Disetujui</span>
<span class="badge bg-danger">❌ Ditolak</span>
<span class="badge bg-info">🔄 Proses Picking</span>
<span class="badge bg-primary">📦 Siap Kirim</span>
<span class="badge bg-info">🚚 Dalam Pengiriman</span>
<span class="badge bg-secondary">📷 Menunggu Verifikasi</span>
<span class="badge bg-success">✅ Complete</span>
<span class="badge bg-warning text-dark">💳 Menunggu Bayar</span>

<!-- Badge Semi-Blind Stock -->
<span class="badge bg-success">✅ Tersedia</span>
<span class="badge bg-warning text-dark">⚠️ Terbatas</span>
<span class="badge bg-danger">❌ Habis</span>

<!-- Badge Billing -->
<span class="badge bg-warning text-dark">🟡 Belum Bayar</span>
<span class="badge bg-danger">🔴 Overdue</span>
<span class="badge bg-success">✅ Lunas</span>
```

### 6.2 Notification Toast

```html
<!-- Toast notification muncul di kanan atas -->
<div class="toast-container position-fixed top-0 end-0 p-3">
    <div class="toast" role="alert">
        <div class="toast-header bg-primary text-white">
            <i class="bi bi-bell-fill me-2"></i>
            <strong class="me-auto">Pesanan Baru</strong>
            <small>Baru saja</small>
            <button type="button" class="btn-close btn-close-white"></button>
        </div>
        <div class="toast-body">
            Pesanan baru #PO-KRW-2026-00145 dari Ahmad Menunggu Diterima.
        </div>
    </div>
</div>
```

### 6.3 Modal Konfirmasi

```html
<!-- Modal konfirmasi aksi destruktif -->
<div class="modal fade" id="confirmModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">⚠️ Konfirmasi</h5>
            </div>
            <div class="modal-body">
                <p>Apakah Anda yakin ingin menyetujui pesanan ini?</p>
                <p class="text-muted small">Tindakan ini tidak bisa dibatalkan.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary">Batal</button>
                <button class="btn btn-primary">Ya, Setujui</button>
            </div>
        </div>
    </div>
</div>
```

### 6.4 Empty State

```html
<!-- Ditampilkan saat tabel/list kosong -->
<div class="text-center py-5">
    <i class="bi bi-inbox display-1 text-muted"></i>
    <h5 class="mt-3 text-muted">Belum ada data</h5>
    <p class="text-muted">Data akan muncul di sini setelah ada transaksi.</p>
</div>
```

### 6.5 Notification Bell (Navbar)

```html
<!-- Bell icon di navbar dengan badge counter -->
<div class="dropdown">
    <button class="btn btn-link position-relative" data-bs-toggle="dropdown">
        <i class="bi bi-bell-fill fs-5"></i>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
              id="notif-count">
            5
        </span>
    </button>
    <div class="dropdown-menu dropdown-menu-end" style="width: 350px; max-height: 400px; overflow-y: auto;">
        <h6 class="dropdown-header">Notifikasi</h6>
        <!-- Notification items -->
        <a class="dropdown-item py-2 unread" href="#">
            <div class="d-flex">
                <i class="bi bi-cart-check text-success me-2 mt-1"></i>
                <div>
                    <small class="fw-bold">Pesanan Baru</small>
                    <p class="mb-0 small text-muted">PO-00145 dari Ahmad Menunggu Diterima</p>
                    <small class="text-muted">2 menit lalu</small>
                </div>
            </div>
        </a>
        <!-- ... more items ... -->
        <div class="dropdown-divider"></div>
        <a class="dropdown-item text-center small" href="/notifications">
            Lihat Semua Notifikasi
        </a>
    </div>
</div>
```

---

## 7. Responsivitas & Breakpoints

### 7.1 Bootstrap Breakpoints

| Breakpoint | Prefix | Pixels | Penggunaan |
|---|---|---|---|
| Extra small | (none) | < 576px | Smartphone portrait |
| Small | `sm` | ≥ 576px | Smartphone landscape |
| Medium | `md` | ≥ 768px | Tablet portrait |
| Large | `lg` | ≥ 992px | Tablet landscape / laptop |
| Extra large | `xl` | ≥ 1200px | Desktop |
| XXL | `xxl` | ≥ 1400px | Large desktop |

### 7.2 Responsivitas Per Portal

| Portal | Primary Viewport | Sidebar Behavior | Table Behavior |
|---|---|---|---|
| **Sales** | 360-428px | Tidak ada sidebar (bottom nav) | Card-based, no table | 
| **Warehouse** | 1280-1920px | Collapsed di < 992px, toggle button | Horizontal scroll di < 768px |
| **Admin** | 1280-1920px | Collapsed di < 992px, toggle button | Horizontal scroll di < 768px |

### 7.3 Tabel Responsif (Warehouse/Admin)

```html
<!-- Tabel dengan scroll horizontal di mobile -->
<div class="table-responsive">
    <table class="table table-hover" id="ordersTable">
        <!-- DataTables akan handle pagination, search, sorting -->
    </table>
</div>
```

---

## 8. Accessibility

### 8.1 Standar Minimum

| Aspek | Implementasi |
|---|---|
| **Labels** | Setiap `<input>` memiliki `<label>` yang terhubung via `for`/`id` |
| **Aria** | Semua button tanpa teks memiliki `aria-label` |
| **Contrast** | Rasio kontras teks minimal 4.5:1 (WCAG AA) |
| **Focus** | Focus indicator visible pada semua elemen interaktif |
| **Alt text** | Semua `<img>` memiliki `alt` yang deskriptif |
| **Keyboard** | Semua aksi bisa dilakukan tanpa mouse (tab navigation) |
| **Error messages** | Pesan error terhubung ke input via `aria-describedby` |
| **Loading state** | Spinner memiliki `role="status"` dan `aria-label="Loading"` |

### 8.2 Contoh Form Accessible

```html
<div class="mb-3">
    <label for="customer_id" class="form-label">
        Customer <span class="text-danger">*</span>
    </label>
    <select class="form-select" id="customer_id" name="customer_id" 
            required aria-describedby="customer_help customer_error">
        <option value="">Pilih customer...</option>
    </select>
    <div id="customer_help" class="form-text">
        Hanya customer yang sudah disetujui yang ditampilkan.
    </div>
    <div id="customer_error" class="invalid-feedback">
        Silakan pilih customer.
    </div>
</div>
```
