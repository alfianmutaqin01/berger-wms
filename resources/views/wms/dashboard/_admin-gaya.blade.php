<style>
    /* =========================================================
       DASHBOARD PREMIUM STYLING
       ========================================================= */
    .dashboard-hero-card {
        background: linear-gradient(135deg, #0d2540 0%, #123962 60%, #1a4f85 100%);
        border-radius: 1.25rem;
        color: #ffffff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px -8px rgba(13, 37, 64, 0.25);
    }
    .dashboard-hero-card::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 320px;
        height: 320px;
        background: radial-gradient(circle, rgba(232, 135, 30, 0.22) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    .dashboard-hero-card::after {
        content: '';
        position: absolute;
        bottom: -80px;
        left: 20%;
        width: 280px;
        height: 280px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.07) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .pulse-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #10b981;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse-green 2s infinite;
    }
    @keyframes pulse-green {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    .section-header-tag {
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
    }

    .stat-card-link {
        text-decoration: none;
        display: block;
        height: 100%;
        color: inherit;
    }

    .stat-card {
        background: #ffffff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.85);
        transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.22s ease, border-color 0.22s ease;
        position: relative;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(18, 57, 98, 0.04);
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 14px 28px -6px rgba(18, 57, 98, 0.12);
        border-color: rgba(18, 57, 98, 0.2);
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: transparent;
    }

    .stat-card-warning::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    .stat-card-primary::before { background: linear-gradient(90deg, #123962, #1e5692); }
    .stat-card-info::before { background: linear-gradient(90deg, #0284c7, #38bdf8); }
    .stat-card-success::before { background: linear-gradient(90deg, #059669, #34d399); }
    .stat-card-danger::before { background: linear-gradient(90deg, #dc2626, #f87171); }
    .stat-card-indigo::before { background: linear-gradient(90deg, #4f46e5, #818cf8); }
    .stat-card-purple::before { background: linear-gradient(90deg, #7c3aed, #a78bfa); }
    .stat-card-secondary::before { background: linear-gradient(90deg, #475569, #94a3b8); }

    .stat-icon-badge {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
        transition: transform 0.2s ease;
    }
    .stat-card:hover .stat-icon-badge {
        transform: scale(1.06);
    }

    .stat-card-warning .stat-icon-badge { background: #fef3c7; color: #b45309; }
    .stat-card-primary .stat-icon-badge { background: #e0e9f5; color: #123962; }
    .stat-card-info .stat-icon-badge { background: #e0f2fe; color: #0369a1; }
    .stat-card-success .stat-icon-badge { background: #d1fae5; color: #047857; }
    .stat-card-danger .stat-icon-badge { background: #fee2e2; color: #b91c1c; }
    .stat-card-indigo .stat-icon-badge { background: #e0e7ff; color: #4338ca; }
    .stat-card-purple .stat-icon-badge { background: #ede9fe; color: #6d28d9; }
    .stat-card-secondary .stat-icon-badge { background: #f1f5f9; color: #475569; }

    .action-chevron {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        color: #94a3b8;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }
    .stat-card:hover .action-chevron {
        background: #123962;
        color: #ffffff;
        transform: translateX(3px);
    }

    .supervision-card {
        background: #ffffff;
        border-radius: 1.15rem;
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 2px 8px rgba(18, 57, 98, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .supervision-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px -4px rgba(18, 57, 98, 0.09);
    }

    .activity-row {
        transition: background-color 0.15s ease;
    }
    .activity-row:hover {
        background-color: #f8fafc;
    }

    /* =========================================================
       LAYAR PONSEL (< 576px)

       Sembilan kartu bertumpuk satu per baris berarti sepuluh
       layar gulir sebelum sampai grafik — pada layar 360px itu
       membuat dashboard lebih lambat dibaca daripada membuka
       menunya satu per satu. Di bawah sini kartunya dua per
       baris dan seluruh ukurannya dikecilkan bersama-sama:
       memperkecil kolomnya saja hanya menghasilkan kartu sempit
       berisi angka raksasa yang terpotong.
       ========================================================= */
    @media (max-width: 575.98px) {
        .dashboard-hero-card { border-radius: .9rem; }
        .dashboard-hero-card .p-4 { padding: 1rem !important; }
        .dashboard-hero-card h3 { font-size: 1.1rem; }
        .dashboard-hero-card p { font-size: .78rem; }

        .stat-card { border-radius: .85rem; }
        .stat-card .card-body { padding: .75rem !important; }
        .stat-card h2 { font-size: 1.35rem; }
        .stat-card h6 { font-size: .74rem; }
        .stat-card .small,
        .stat-card small { font-size: .68rem; line-height: 1.25; }

        .stat-icon-badge {
            width: 32px; height: 32px;
            border-radius: 9px;
            font-size: .95rem;
        }

        /* Tanda panah hanya hiasan yang menunjukkan kartunya bisa
           ditekan — di layar sentuh seluruh kartunya memang sudah
           bisa ditekan, jadi ia cuma memakan lebar. */
        .action-chevron { display: none !important; }

        .supervision-card { border-radius: .85rem; }
        .supervision-card .card-body { padding: .85rem !important; }
    }
</style>
