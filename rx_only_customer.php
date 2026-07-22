<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';
    include 'auth_check.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    $role = $_SESSION['role'] ?? 'staff';

    // ============================================================
    // === PAGINATION & FILTER CONFIG =============================
    // ============================================================
    $per_page   = 15;
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $offset     = ($page - 1) * $per_page;

    $search     = trim($_GET['search'] ?? '');
    $filter_date_from = trim($_GET['date_from'] ?? '');
    $filter_date_to   = trim($_GET['date_to'] ?? '');

    // ============================================================
    // === BUILD QUERY ============================================
    // ============================================================
    $where_parts = ["ce.invoice_number = '00'"]; // CORE FILTER: RX only
    $params      = [];
    $types       = '';

    if ($search !== '') {
        $where_parts[] = "(ce.customer_name LIKE ? OR ce.examination_code LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $types   .= 'ss';
    }

    if ($filter_date_from !== '') {
        $where_parts[] = "ce.examination_date >= ?";
        $params[] = $filter_date_from;
        $types   .= 's';
    }

    if ($filter_date_to !== '') {
        $where_parts[] = "ce.examination_date <= ?";
        $params[] = $filter_date_to;
        $types   .= 's';
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where_parts);

    // COUNT TOTAL
    $count_sql  = "SELECT COUNT(*) AS total FROM customer_examinations ce $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total_rows = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $total_pages = max(1, ceil($total_rows / $per_page));

    // MAIN SELECT
    $data_sql = "SELECT
                    ce.id,
                    ce.examination_date,
                    ce.examination_code,
                    ce.customer_name,
                    ce.gender,
                    ce.age,
                    ce.new_r_sph, ce.new_r_cyl, ce.new_r_ax, ce.new_r_add, ce.new_r_visus,
                    ce.new_l_sph, ce.new_l_cyl, ce.new_l_ax, ce.new_l_add, ce.new_l_visus,
                    ce.pd_dist,
                    ce.symptoms,
                    ce.created_by
                 FROM customer_examinations ce
                 $where_sql
                 ORDER BY ce.examination_date DESC, ce.id DESC
                 LIMIT ? OFFSET ?";

    $data_params = array_merge($params, [$per_page, $offset]);
    $data_types  = $types . 'ii';
    $data_stmt   = $conn->prepare($data_sql);
    $data_stmt->bind_param($data_types, ...$data_params);
    $data_stmt->execute();
    $result = $data_stmt->get_result();
    $rows   = $result->fetch_all(MYSQLI_ASSOC);

    // ============================================================
    // === SUMMARY STATS ==========================================
    // ============================================================
    $stat_today = $conn->query("SELECT COUNT(*) AS c FROM customer_examinations WHERE invoice_number='00' AND examination_date = CURDATE()")->fetch_assoc()['c'] ?? 0;
    $stat_total = $total_rows; // already filtered
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RX Only Customers - <?php echo htmlspecialchars($STORE_NAME); ?></title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ============================================================ */
        /* === PAGE LAYOUT ============================================= */
        /* ============================================================ */
        h2 {
            text-align: center;
            margin-bottom: 30px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        /* ============================================================ */
        /* === SUMMARY STAT CARDS ===================================== */
        /* ============================================================ */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: #25282a;
            border: 1px solid #333;
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            box-shadow: inset 3px 3px 8px #1a1c1d;
        }
        .stat-card.green  { border-color: #00ff8844; box-shadow: inset 3px 3px 8px #1a1c1d, 0 0 12px rgba(0,255,136,0.07); }
        .stat-card.yellow { border-color: #ffcc0044; box-shadow: inset 3px 3px 8px #1a1c1d, 0 0 12px rgba(255,204,0,0.07);  }
        .stat-label {
            font-size: 0.65em;
            color: #666;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .stat-value {
            font-size: 2em;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }
        .stat-card.green  .stat-value { color: #00ff88; }
        .stat-card.yellow .stat-value { color: #ffcc00; }

        /* ============================================================ */
        /* === FILTER BAR ============================================= */
        /* ============================================================ */
        .filter-bar {
            background: #25282a;
            border: 1px solid #333;
            border-radius: 14px;
            padding: 18px 16px;
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: inset 3px 3px 8px #1a1c1d;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            width: 100%;
        }
        .filter-row-dates {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            width: 100%;
        }
        .filter-group label {
            font-size: 0.65em;
            color: #666;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-weight: bold;
            text-align: left;
        }
        .filter-group input {
            background: #1a1c1d;
            border: 1px solid #333;
            border-radius: 8px;
            color: #eee;
            padding: 10px 12px;
            font-size: 0.85em;
            outline: none;
            width: 100%;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .filter-group input:focus { border-color: #00ff88; }
        .filter-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            width: 100%;
        }
        .btn-filter {
            background: #00ff88;
            color: #0a0a0a;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 700;
            font-size: 0.85em;
            cursor: pointer;
            letter-spacing: 1px;
            transition: opacity 0.2s;
            white-space: nowrap;
            text-align: center;
        }
        .btn-filter:hover { opacity: 0.85; }
        .btn-reset {
            background: #25282a;
            color: #888;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 12px;
            font-size: 0.85em;
            cursor: pointer;
            letter-spacing: 1px;
            transition: all 0.2s;
            white-space: nowrap;
            text-align: center;
        }
        .btn-reset:hover { border-color: #ff6666; color: #ff6666; }

        /* ============================================================ */
        /* === RESULT INFO BAR ======================================== */
        /* ============================================================ */
        .result-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            font-size: 0.78em;
            color: #666;
        }
        .result-info .result-count { color: #00ff88; font-weight: bold; }

        /* ============================================================ */
        /* === CUSTOMER TABLE ========================================= */
        /* ============================================================ */
        .rx-table-wrapper {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid #333;
            box-shadow: inset 3px 3px 8px #1a1c1d;
            margin-bottom: 20px;
        }
        .rx-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82em;
            min-width: 780px;
        }
        .rx-table thead tr {
            background: #1e2123;
            border-bottom: 2px solid #00ff8833;
        }
        .rx-table th {
            padding: 13px 14px;
            text-align: left;
            color: #00ff88;
            font-size: 0.72em;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .rx-table th.center { text-align: center; }
        .rx-table tbody tr {
            border-bottom: 1px solid #252829;
            transition: background 0.15s;
        }
        .rx-table tbody tr:last-child { border-bottom: none; }
        .rx-table tbody tr:hover { background: rgba(0,255,136,0.03); }
        .rx-table td {
            padding: 12px 14px;
            color: #ccc;
            vertical-align: middle;
        }
        .rx-table td.center { text-align: center; }

        /* Name & code */
        .td-name {
            font-weight: 700;
            color: #eee;
            font-size: 0.95em;
        }
        .td-code {
            color: #00ff88;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
        }
        .td-date {
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            color: #aaa;
            white-space: nowrap;
        }

        /* Gender badge */
        .badge-gender {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.72em;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        .badge-female { background: #3a1a2a; color: #ff88cc; border: 1px solid #ff88cc44; }
        .badge-male   { background: #1a2a3a; color: #88ccff; border: 1px solid #88ccff44; }

        /* Prescription mini display */
        .rx-mini {
            font-family: 'Courier New', monospace;
            font-size: 0.8em;
            color: #aaa;
            line-height: 1.6;
        }
        .rx-mini span.eye-lbl {
            color: #00ff88;
            font-weight: bold;
            margin-right: 4px;
        }
        .rx-mini span.zero { color: #444; }

        /* Action buttons */
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 0.72em;
            font-weight: bold;
            cursor: pointer;
            letter-spacing: 0.5px;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-view-detail {
            background: rgba(0,204,255,0.12);
            color: #00ccff;
            border: 1px solid #00ccff33;
        }
        .btn-view-detail:hover { background: rgba(0,204,255,0.22); border-color: #00ccff66; }

        .btn-convert {
            background: rgba(0,255,136,0.12);
            color: #00ff88;
            border: 1px solid #00ff8833;
            margin-top: 5px;
        }
        .btn-convert:hover { background: rgba(0,255,136,0.22); border-color: #00ff8866; }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #555;
        }
        .empty-icon { font-size: 3em; margin-bottom: 12px; opacity: 0.5; }
        .empty-text { font-size: 0.9em; letter-spacing: 1px; }

        /* ============================================================ */
        /* === PAGINATION ============================================= */
        /* ============================================================ */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .page-btn {
            background: #25282a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #888;
            padding: 8px 13px;
            font-size: 0.8em;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .page-btn:hover { border-color: #00ff88; color: #00ff88; }
        .page-btn.active {
            background: #00ff88;
            color: #0a0a0a;
            border-color: #00ff88;
            font-weight: bold;
        }
        .page-btn.disabled { opacity: 0.3; pointer-events: none; }

        /* ============================================================ */
        /* === DETAIL MODAL OVERLAY =================================== */
        /* ============================================================ */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.75);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #1e2123;
            border: 1px solid #00ff8833;
            border-radius: 18px;
            width: 100%;
            max-width: 520px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
            box-shadow: 0 0 40px rgba(0,255,136,0.15);
            animation: modal-in 0.25s ease;
        }
        @keyframes modal-in {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .modal-title {
            color: #00ff88;
            font-size: 1em;
            font-weight: 700;
            letter-spacing: 2px;
            text-align: center;
            margin-bottom: 20px;
            text-transform: uppercase;
        }
        .modal-section-label {
            font-size: 0.62em;
            color: #555;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid #252829;
        }
        .modal-info-row {
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 0.82em;
        }
        .modal-info-label {
            color: #666;
            min-width: 100px;
            flex-shrink: 0;
        }
        .modal-info-value { color: #ddd; font-weight: 500; }
        .modal-info-value.green { color: #00ff88; font-family: monospace; }
        .modal-info-value.yellow { color: #ffcc00; }
        .modal-pres-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Courier New', monospace;
            font-size: 0.8em;
            margin-top: 8px;
            margin-bottom: 14px;
        }
        .modal-pres-table th {
            color: #555;
            text-align: center;
            padding: 5px;
            border-bottom: 1px solid #252829;
            font-size: 0.82em;
            letter-spacing: 1px;
        }
        .modal-pres-table th:first-child { text-align: left; }
        .modal-pres-table td {
            text-align: center;
            padding: 7px 5px;
            color: #00ff88;
            border-bottom: 1px solid #1e2123;
        }
        .modal-pres-table td:first-child { text-align: left; color: #aaa; font-weight: bold; }
        .modal-pres-table tr:last-child td { border-bottom: none; }
        .modal-pres-table td.zero { color: #333; }
        .modal-divider {
            height: 1px;
            background: linear-gradient(to right, transparent, #2a2d30, transparent);
            margin: 14px 0;
        }
        .symptom-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 4px;
        }
        .symptom-tag {
            background: rgba(255,204,0,0.1);
            border: 1px solid #ffcc0033;
            color: #ffcc00;
            font-size: 0.72em;
            padding: 3px 9px;
            border-radius: 20px;
            font-weight: bold;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .btn-close-modal {
            background: #25282a;
            color: #888;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 0.82em;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
        }
        .btn-close-modal:hover { border-color: #ff6666; color: #ff6666; }
        .btn-convert-modal {
            background: #00ff88;
            color: #0a0a0a;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 0.82em;
            cursor: pointer;
            font-weight: 700;
            letter-spacing: 1px;
            transition: opacity 0.2s;
        }
        .btn-convert-modal:hover { opacity: 0.85; }

        /* ============================================================ */
        /* === RX ONLY BADGE ========================================== */
        /* ============================================================ */
        .badge-rx-only {
            display: inline-block;
            background: #1a2a1a;
            border: 1px solid #00ff8833;
            color: #00ff88;
            font-size: 0.65em;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 1px;
            font-weight: bold;
            vertical-align: middle;
            margin-left: 6px;
        }

        /* ============================================================ */
        /* === RESPONSIVE ============================================= */
        /* ============================================================ */
        @media (max-width: 600px) {
            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .result-info { flex-direction: column; align-items: flex-start; gap: 4px; }
        }
    </style>
    <!-- button logout, back animation for logo -->
    <style>
        .neu-button.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
            filter: grayscale(1);
        }

        /* ===== New neumorphic style for Back & Logout buttons ===== */
        .neu-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: #1c1e22;
            border: none;
            border-radius: 32px;
            padding: 6px 16px 6px 6px;
            cursor: pointer;
            box-shadow:
                6px 6px 14px rgba(0, 0, 0, 0.55),
                -6px -6px 14px rgba(255, 255, 255, 0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            font-family: inherit;
        }

        .neu-pill-btn:hover {
            box-shadow:
                6px 6px 16px rgba(0, 0, 0, 0.6),
                -6px -6px 16px rgba(255, 255, 255, 0.04);
        }

        .neu-pill-btn:active {
            transform: scale(0.96);
        }

        /* Overflow hidden so the icon can slide across without spilling out */
        .neu-pill-btn {
            overflow: hidden;
        }

        .neu-pill-icon {
            width: 32px;
            height: 32px;
            min-width: 32px;
            border-radius: 50%;
            background: #17181b;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(103, 232, 249, 0.35);
            transition: box-shadow 0.15s ease, transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Pressed state: icon slides to the right, text fades and slides out */
        .neu-pill-btn.pressed {
            box-shadow:
                inset 4px 4px 10px rgba(0, 0, 0, 0.6),
                inset -4px -4px 10px rgba(255, 255, 255, 0.03);
        }

        .neu-pill-btn.pressed .neu-pill-icon {
            transform: translateX(calc(100% + 24px));
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-btn.pressed .neu-pill-text {
            opacity: 0;
            transform: translateX(15px);
        }

        .neu-pill-btn.pressed .neu-pill-icon,
        .neu-pill-btn:active .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(103, 232, 249, 0.7);
        }

        .neu-pill-icon svg {
            width: 15px;
            height: 15px;
            stroke: #7fe3f0;
            filter: drop-shadow(0 0 4px rgba(103, 232, 249, 0.8));
        }

        .neu-pill-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
            text-align: left;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .neu-pill-text .line1 {
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.4px;
            color: #f2f2f2;
        }

        .neu-pill-text .line2 {
            font-weight: 400;
            font-size: 9px;
            letter-spacing: 0.4px;
            color: #9a9da1;
        }

        /* Logout variant: warm amber/orange tone instead of cyan */
        .neu-pill-btn.logout-variant .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 10px rgba(255, 138, 101, 0.4);
        }

        .neu-pill-btn.logout-variant.pressed .neu-pill-icon {
            box-shadow:
                inset 3px 3px 6px rgba(0, 0, 0, 0.6),
                inset -3px -3px 6px rgba(255, 255, 255, 0.04),
                0 0 18px rgba(255, 138, 101, 0.75);
        }

        .neu-pill-btn.logout-variant .neu-pill-icon svg {
            stroke: #ff8a65;
            filter: drop-shadow(0 0 4px rgba(255, 138, 101, 0.8));
        }

        /* ===== Logo zoom (fly window) effect ===== */
        .logo-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0);
            backdrop-filter: blur(0px);
            -webkit-backdrop-filter: blur(0px);
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: background 0.3s ease, opacity 0.3s ease, backdrop-filter 0.3s ease;
        }

        .logo-backdrop.active {
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            opacity: 1;
            pointer-events: auto;
        }

        .logo-box img {
            cursor: pointer;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        top 0.35s cubic-bezier(0.4, 0, 0.2, 1),
                        left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .logo-box img.zoomed {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(2.8);
            z-index: 1000;
        }

        /* Center the header block (logout button + logo/name/address group)
           on PC to match how it already appears centered on mobile. Only
           the container's own horizontal position is changed here — the
           internal layout is left exactly as in the original code. */
        .header-container {
            margin-left: auto !important;
            margin-right: auto !important;
            width: fit-content !important;
        }
    </style>
</head>

<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column;">

            <!-- HEADER -->
            <div class="header-container">
                <button type="button" class="logout-btn neu-pill-btn logout-variant" id="logoutBtn" onclick="handleLogoutClick(this)">
                    <span class="neu-pill-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                    </span>
                    <span class="neu-pill-text">
                        <span class="line1">LOGOUT</span>
                    </span>
                </button>
            
                <div class="brand-section">
                    <div class="logo-box">
                        <img id="storeLogo" src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;" onclick="zoomInLogo(this)" ondblclick="zoomOutLogo(this)">
                    </div>
                    <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                    <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                </div>
            </div>

            <!-- MAIN CARD -->
            <div class="main-card" style="margin-left:auto; margin-right:auto; width:100%;">
                <h2>RX ONLY CUSTOMERS</h2>
                <div style="text-align: center; margin-top: -20px; margin-bottom: 28px;">
                    <span class="badge-rx-only">EXAM ONLY</span>
                </div>

                <!-- ===== STAT CARDS ===== -->
                <div class="stat-grid">
                    <div class="stat-card yellow">
                        <span class="stat-label">Today</span>
                        <span class="stat-value"><?php echo $stat_today; ?></span>
                    </div>
                    <div class="stat-card green">
                        <span class="stat-label">Filtered Total</span>
                        <span class="stat-value"><?php echo number_format($stat_total); ?></span>
                    </div>
                </div>

                <!-- ===== FILTER BAR ===== -->
                <form method="GET" action="" id="filterForm">
                    <div class="filter-bar">
                        <!-- Row 1: Search full width -->
                        <div class="filter-group">
                            <label>Search Name / Code</label>
                            <input type="text" name="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   placeholder="e.g. JOHN or LZ/EC/001">
                        </div>
                        <!-- Row 2: Date From + Date To bergandengan -->
                        <div class="filter-row-dates">
                            <div class="filter-group">
                                <label>Date From</label>
                                <input type="date" name="date_from"
                                       value="<?php echo htmlspecialchars($filter_date_from); ?>">
                            </div>
                            <div class="filter-group">
                                <label>Date To</label>
                                <input type="date" name="date_to"
                                       value="<?php echo htmlspecialchars($filter_date_to); ?>">
                            </div>
                        </div>
                        <!-- Row 3: Buttons -->
                        <div class="filter-actions">
                            <button type="submit" class="btn-filter">&#8981; SEARCH</button>
                            <button type="button" class="btn-reset" onclick="resetFilter()">&#x2715; RESET</button>
                        </div>
                    </div>
                    <input type="hidden" name="page" value="1">
                </form>

                <!-- ===== RESULT INFO ===== -->
                <div class="result-info">
                    <span>Showing <span class="result-count"><?php echo count($rows); ?></span> of <span class="result-count"><?php echo number_format($total_rows); ?></span> records</span>
                    <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                </div>

                <!-- ===== TABLE ===== -->
                <div class="rx-table-wrapper">
                    <?php if (empty($rows)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">👁</div>
                            <div class="empty-text">NO RX ONLY CUSTOMERS FOUND</div>
                            <?php if ($search || $filter_date_from || $filter_date_to): ?>
                                <div style="margin-top: 8px; font-size:0.75em; color:#444;">Try adjusting your search or date filter.</div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                    <table class="rx-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Exam Code</th>
                                <th>Name</th>
                                <th class="center">Gender</th>
                                <th class="center">Age</th>
                                <th>Prescription (OD / OS)</th>
                                <th class="center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Format prescription display — defined ONCE, outside the loop
                        function fv($v, $zero = '—') {
                            if ($v === '' || $v === null || $v === '0.00' || $v === '0') return '<span class="zero">' . $zero . '</span>';
                            return htmlspecialchars($v);
                        }

                        $row_num = $offset + 1;
                        foreach ($rows as $row):
                            $date_fmt = date('d/m/Y', strtotime($row['examination_date']));
                            $gender_lower = strtolower($row['gender'] ?? 'female');
                            $gender_class = ($gender_lower === 'male') ? 'badge-male' : 'badge-female';
                        ?>
                        <tr>
                            <td style="color:#555; font-size:0.82em;"><?php echo $row_num++; ?></td>
                            <td class="td-date"><?php echo $date_fmt; ?></td>
                            <td class="td-code"><?php echo htmlspecialchars($row['examination_code']); ?></td>
                            <td class="td-name"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                            <td class="center">
                                <span class="badge-gender <?php echo $gender_class; ?>">
                                    <?php echo htmlspecialchars(strtoupper($row['gender'] ?? 'F')); ?>
                                </span>
                            </td>
                            <td class="center" style="color:#aaa; font-family:monospace;">
                                <?php echo ($row['age'] > 0) ? $row['age'] : '—'; ?>
                            </td>
                            <td>
                                <div class="rx-mini">
                                    <div>
                                        <span class="eye-lbl">OD</span>
                                        <?php echo fv($row['new_r_sph'],'—'); ?> /
                                        <?php echo fv($row['new_r_cyl'],'—'); ?> ×
                                        <?php echo fv($row['new_r_ax'],'—'); ?>
                                        <?php if ($row['new_r_add'] && $row['new_r_add'] !== '0.00'): ?>
                                            <span style="color:#ffcc00;"> ADD <?php echo htmlspecialchars($row['new_r_add']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="eye-lbl">OS</span>
                                        <?php echo fv($row['new_l_sph'],'—'); ?> /
                                        <?php echo fv($row['new_l_cyl'],'—'); ?> ×
                                        <?php echo fv($row['new_l_ax'],'—'); ?>
                                        <?php if ($row['new_l_add'] && $row['new_l_add'] !== '0.00'): ?>
                                            <span style="color:#ffcc00;"> ADD <?php echo htmlspecialchars($row['new_l_add']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="center">
                                <button class="btn-action btn-view-detail"
                                        onclick="openDetail(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                    🔍 Detail
                                </button>
                                <br>
                                <button class="btn-action btn-convert"
                                        onclick="confirmConvert(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['customer_name'])); ?>')">
                                    🛒 Convert to Invoice
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- ===== PAGINATION ===== -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                    $base_qs = http_build_query(['search' => $search, 'date_from' => $filter_date_from, 'date_to' => $filter_date_to]);

                    // Prev
                    $prev_class = ($page <= 1) ? 'page-btn disabled' : 'page-btn';
                    echo "<a href='?{$base_qs}&page=" . max(1, $page-1) . "' class='{$prev_class}'>‹ Prev</a>";

                    // Pages
                    $start_page = max(1, $page - 2);
                    $end_page   = min($total_pages, $page + 2);
                    if ($start_page > 1) echo "<span class='page-btn disabled'>…</span>";
                    for ($p = $start_page; $p <= $end_page; $p++) {
                        $cls = ($p === $page) ? 'page-btn active' : 'page-btn';
                        echo "<a href='?{$base_qs}&page={$p}' class='{$cls}'>{$p}</a>";
                    }
                    if ($end_page < $total_pages) echo "<span class='page-btn disabled'>…</span>";

                    // Next
                    $next_class = ($page >= $total_pages) ? 'page-btn disabled' : 'page-btn';
                    echo "<a href='?{$base_qs}&page=" . min($total_pages, $page+1) . "' class='{$next_class}'>Next ›</a>";
                    ?>
                </div>
                <?php endif; ?>

            </div><!-- /main-card -->
        </div><!-- /content-area -->

        <div class="btn-group">
            <button type="button" class="neu-pill-btn" id="backBtn" onclick="handleBackClick(this)">
                <span class="neu-pill-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                </span>
                <span class="neu-pill-text">
                    <span class="line1">RETURN TO</span>
                    <span class="line2">PREVIOUS PAGE</span>
                </span>
            </button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>
    </div><!-- /main-wrapper -->
    <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        
    <!-- ================================================================ -->
    <!-- === DETAIL MODAL =============================================== -->
    <!-- ================================================================ -->
    <div id="detailModal" class="modal-overlay" onclick="closeDetailOnBackdrop(event)">
        <div class="modal-box">
            <div class="modal-title" id="modal_title">CUSTOMER DETAIL</div>

            <!-- Patient Info -->
            <div class="modal-section-label">Patient Info</div>
            <div class="modal-info-row"><span class="modal-info-label">Name</span><span class="modal-info-value green" id="md_name"></span></div>
            <div class="modal-info-row"><span class="modal-info-label">Exam Code</span><span class="modal-info-value green" id="md_code"></span></div>
            <div class="modal-info-row"><span class="modal-info-label">Date</span><span class="modal-info-value" id="md_date"></span></div>
            <div class="modal-info-row"><span class="modal-info-label">Gender</span><span class="modal-info-value" id="md_gender"></span></div>
            <div class="modal-info-row"><span class="modal-info-label">Age</span><span class="modal-info-value" id="md_age"></span></div>
            <div class="modal-info-row"><span class="modal-info-label">Created By</span><span class="modal-info-value" id="md_created_by"></span></div>

            <div class="modal-divider"></div>

            <!-- Symptoms -->
            <div class="modal-section-label">Symptoms / Complaints</div>
            <div class="symptom-tags" id="md_symptoms"></div>

            <div class="modal-divider"></div>

            <!-- Prescription -->
            <div class="modal-section-label">New Prescription</div>
            <table class="modal-pres-table">
                <thead>
                    <tr>
                        <th>Eye</th>
                        <th>SPH</th>
                        <th>CYL</th>
                        <th>AXIS</th>
                        <th>ADD</th>
                        <th>VA</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>OD (R)</td>
                        <td id="md_r_sph"></td>
                        <td id="md_r_cyl"></td>
                        <td id="md_r_ax"></td>
                        <td id="md_r_add"></td>
                        <td id="md_r_va"></td>
                    </tr>
                    <tr>
                        <td>OS (L)</td>
                        <td id="md_l_sph"></td>
                        <td id="md_l_cyl"></td>
                        <td id="md_l_ax"></td>
                        <td id="md_l_add"></td>
                        <td id="md_l_va"></td>
                    </tr>
                </tbody>
            </table>
            <div style="text-align:center; font-family:monospace; font-size:0.85em; color:#888; margin-top:6px;">
                PD: <span id="md_pd" style="color:#00ff88; font-weight:bold;"></span>
            </div>

            <div class="modal-footer">
                <button class="btn-close-modal" onclick="closeDetail()">CLOSE</button>
                <button class="btn-convert-modal" id="md_convert_btn" onclick="">🛒 CONVERT TO INVOICE</button>
            </div>
        </div>
    </div>

    <script>
        // ================================================================
        // === FILTER RESET ===============================================
        // ================================================================
        function resetFilter() {
            window.location.href = '<?php echo basename($_SERVER['PHP_SELF']); ?>';
        }

        // ================================================================
        // === DETAIL MODAL ===============================================
        // ================================================================
        let _currentDetailId = null;

        function fmtVal(v, fallback = '—') {
            if (!v || v === '0.00' || v === '0') return '<span style="color:#333;">' + fallback + '</span>';
            return v;
        }

        function openDetail(row) {
            _currentDetailId = row.id;

            // Patient info
            document.getElementById('md_name').textContent        = row.customer_name || '—';
            document.getElementById('md_code').textContent        = row.examination_code || '—';
            document.getElementById('md_date').textContent        = row.examination_date || '—';
            document.getElementById('md_gender').textContent      = row.gender || '—';
            document.getElementById('md_age').textContent         = (row.age && row.age > 0) ? row.age + ' y.o.' : '—';
            document.getElementById('md_created_by').textContent  = row.created_by || '—';

            // Symptoms
            const sympWrap = document.getElementById('md_symptoms');
            sympWrap.innerHTML = '';
            if (row.symptoms && row.symptoms.trim() !== '') {
                row.symptoms.split(',').forEach(s => {
                    s = s.trim();
                    if (s) {
                        const tag = document.createElement('span');
                        tag.className = 'symptom-tag';
                        tag.textContent = s;
                        sympWrap.appendChild(tag);
                    }
                });
            } else {
                sympWrap.innerHTML = '<span style="color:#444; font-size:0.85em;">—</span>';
            }

            // Prescription
            function setTd(id, val) {
                const el = document.getElementById(id);
                if (!el) return;
                const empty = (!val || val === '0.00' || val === '0');
                el.innerHTML = empty ? '<span style="color:#333;">—</span>' : val;
            }
            setTd('md_r_sph', row.new_r_sph);  setTd('md_r_cyl', row.new_r_cyl);
            setTd('md_r_ax',  row.new_r_ax);   setTd('md_r_add', row.new_r_add);
            setTd('md_r_va',  row.new_r_visus);
            setTd('md_l_sph', row.new_l_sph);  setTd('md_l_cyl', row.new_l_cyl);
            setTd('md_l_ax',  row.new_l_ax);   setTd('md_l_add', row.new_l_add);
            setTd('md_l_va',  row.new_l_visus);
            document.getElementById('md_pd').textContent = row.pd_dist || '—';

            // Convert button
            const convertBtn = document.getElementById('md_convert_btn');
            convertBtn.onclick = function() {
                closeDetail();
                confirmConvert(row.id, row.customer_name);
            };

            // Open modal
            document.getElementById('detailModal').classList.add('open');
        }

        function closeDetail() {
            document.getElementById('detailModal').classList.remove('open');
            _currentDetailId = null;
        }

        function closeDetailOnBackdrop(event) {
            if (event.target === document.getElementById('detailModal')) closeDetail();
        }

        // ================================================================
        // === CONVERT TO INVOICE =========================================
        // ================================================================
        async function confirmConvert(examId, customerName) {
            const result = await Swal.fire({
                title: 'CONVERT TO INVOICE?',
                html: `<div style="font-size:0.9em; color:#ccc;">
                           <strong style="color:#00ff88;">${customerName}</strong><br>
                           <span style="color:#888; font-size:0.85em;">A new invoice will be created for this customer and you will be redirected to the invoice page.</span>
                       </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#00ff88',
                cancelButtonColor: '#555',
                confirmButtonText: '✓ YES, CREATE INVOICE',
                cancelButtonText: 'CANCEL',
                background: '#25282a',
                color: '#fff'
            });

            if (!result.isConfirmed) return;

            // Fetch next invoice number
            let nextInvoice = '001';
            try {
                const resp = await fetch('get_next_invoice.php');
                nextInvoice = (await resp.text()).trim() || '001';
            } catch (e) {
                console.warn('Could not fetch next invoice number, using default.');
            }

            // Call backend to update invoice_number
            try {
                const formData = new FormData();
                formData.append('exam_id', examId);
                formData.append('invoice_number', nextInvoice);

                const upd = await fetch('update_exam_invoice.php', {
                    method: 'POST',
                    body: formData
                });
                const updJson = await upd.json().catch(() => ({ success: false, error: 'Invalid response' }));

                if (!updJson.success) throw new Error(updJson.error || 'Update failed');

                // Redirect to invoice
                window.location.href = 'invoice.php?inv=' + encodeURIComponent(nextInvoice);

            } catch (err) {
                Swal.fire({
                    title: 'ERROR',
                    text: err.message,
                    icon: 'error',
                    background: '#25282a',
                    color: '#fff',
                    confirmButtonColor: '#ff4466'
                });
            }
        }
    </script>
    <!-- button logout, back animation for logo -->
    <script>
            // Single tap/click on the logo zooms it in (only if not already zoomed).
            function zoomInLogo(imgEl) {
                if (imgEl.classList.contains('zoomed')) return;
                imgEl.classList.add('zoomed');
                document.getElementById('logoBackdrop').classList.add('active');
            }

            // Double tap/click zooms it back out.
            function zoomOutLogo(imgEl) {
                imgEl.classList.remove('zoomed');
                document.getElementById('logoBackdrop').classList.remove('active');
            }

            // Animate the new pill-style Back button before navigating
            function handleBackClick(element) {
                const icon = element.querySelector('.neu-pill-icon');
                const text = element.querySelector('.neu-pill-text');

                // Make sure nothing else fights with our manual animation.
                element.style.transition = 'none';
                text.style.transition = 'none';

                const startWidth = element.offsetWidth;
                // Target: just the round icon left, with the button's own
                // left/right padding preserved (6px left, 6px right when collapsed).
                const targetWidth = icon.offsetWidth + 12;

                // Hide the text immediately so only the shrinking pill is visible.
                text.style.opacity = '0';

                const duration = 400; // ms
                const startTime = performance.now();

                function step(now) {
                    const elapsed = now - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);

                    const currentWidth = startWidth - (startWidth - targetWidth) * eased;
                    element.style.width = currentWidth + 'px';

                    if (progress < 1) {
                        requestAnimationFrame(step);
                    } else {
                        // back direction
                        window.location.href = 'customer.php';
                    }
                }
                requestAnimationFrame(step);
            }

            // Animate the new pill-style Logout button before logging out
            function handleLogoutClick(element) {
                element.classList.add('pressed');
                setTimeout(() => {
                    window.location.href = 'logout.php';
                }, 220);
            }

            // Function executed when a button is clicked
            function handleButtonClick(element) {
                // 1. Get the URL from the data-url attribute
                const targetUrl = element.getAttribute('data-url');
                
                // 2. Save this URL to localStorage as the active button identity
                localStorage.setItem('activeMenuUrl', targetUrl);
                
                // 3. Add the active class immediately (for an instant visual effect)
                document.querySelectorAll('.neu-button').forEach(btn => btn.classList.remove('active'));
                element.classList.add('active');

                // 4. Navigate to the page
                window.location.href = targetUrl;
            }

            // Function that runs automatically when the page is refreshed or returned to (Back)
            window.addEventListener('DOMContentLoaded', () => {
                const activeUrl = localStorage.getItem('activeMenuUrl');
                
                if (activeUrl) {
                    document.querySelectorAll('.neu-button').forEach(btn => {
                        // If the button's data-url matches the one in memory, activate it!
                        if (btn.getAttribute('data-url') === activeUrl) {
                            btn.classList.add('active');
                        }
                    });
                }
            });
        </script>
</body>
</html>