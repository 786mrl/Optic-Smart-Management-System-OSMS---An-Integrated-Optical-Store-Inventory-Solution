<?php
    session_start();
    include 'db_config.php';
    include 'activity_helper.php';
    include 'config_helper.php';
    include 'auth_check.php';

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
    $role = $_SESSION['role'] ?? 'staff';

    // ─────────────────────────────────────────────
    // FILTER PARAMS
    // ─────────────────────────────────────────────
    $search        = trim($_GET['search'] ?? '');
    $filter_brand  = trim($_GET['brand'] ?? '');
    $filter_struct = trim($_GET['structure'] ?? '');
    $filter_gender = trim($_GET['gender'] ?? '');
    $filter_month  = trim($_GET['month'] ?? '');   // YYYY-MM
    $page          = max(1, intval($_GET['page'] ?? 1));
    $per_page      = 20;
    $offset        = ($page - 1) * $per_page;

    // ─────────────────────────────────────────────
    // HELPER: build WHERE clause for main query
    // ─────────────────────────────────────────────
    function buildWhere($conn, $search, $filter_brand, $filter_struct, $filter_gender, $filter_month) {
        $where = ["co.frame_ufc IS NOT NULL", "co.frame_ufc != ''"];
        $params = []; $types = '';

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(ce.customer_name LIKE ? OR co.invoice_number LIKE ? OR co.frame_ufc LIKE ?)";
            $params[] = $like; $params[] = $like; $params[] = $like;
            $types .= 'sss';
        }
        if ($filter_brand !== '') {
            $like = '%' . $filter_brand . '%';
            $where[] = "(fm.brand = ? OR fs.brand = ? OR cf_brand.brand_key LIKE ?)";
            $params[] = $filter_brand; $params[] = $filter_brand; $params[] = $like;
            $types .= 'sss';
        }
        if ($filter_struct !== '') {
            $where[] = "COALESCE(fm.structure, fs.structure) = ?";
            $params[] = $filter_struct; $types .= 's';
        }
        if ($filter_gender !== '') {
            $where[] = "COALESCE(fm.gender_category, fs.gender_category) = ?";
            $params[] = $filter_gender; $types .= 's';
        }
        if ($filter_month !== '') {
            $where[] = "DATE_FORMAT(co.order_date, '%Y-%m') = ?";
            $params[] = $filter_month; $types .= 's';
        }
        return [implode(' AND ', $where), $params, $types];
    }

    // ─────────────────────────────────────────────
    // MAIN PURCHASE HISTORY QUERY
    // ─────────────────────────────────────────────
    [$where_clause, $params, $types] = buildWhere($conn, $search, $filter_brand, $filter_struct, $filter_gender, $filter_month);

    $base_query = "
        FROM customer_orders co
        LEFT JOIN customer_examinations ce ON CONVERT(ce.invoice_number USING utf8mb4) = CONVERT(co.invoice_number USING utf8mb4)
        LEFT JOIN frames_main fm           ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs         ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN custom_frames cf_brand   ON CONVERT(cf_brand.invoice_number USING utf8mb4) = CONVERT(co.invoice_number USING utf8mb4)
        WHERE $where_clause
    ";

    // Count total
    $count_sql  = "SELECT COUNT(*) AS total $base_query";
    $count_stmt = $conn->prepare($count_sql);
    if ($count_stmt === false) die("Query error (count): " . $conn->error);
    if ($types !== '') $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total_rows  = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $total_pages = max(1, ceil($total_rows / $per_page));

    // Data rows
    $data_sql = "
        SELECT
            co.invoice_number,
            co.customer_number,
            co.frame_ufc,
            co.order_date,
            co.due_date,
            co.order_status,
            co.total_amount,
            co.amount_paid,
            co.lens_name,
            ce.customer_name,
            ce.gender AS cust_gender,
            ce.age,
            COALESCE(fm.brand,           fs.brand,          cf_brand.brand_key)  AS frame_brand,
            COALESCE(fm.frame_code,      fs.frame_code)                          AS frame_code,
            COALESCE(fm.color_code,      fs.color_code)                          AS color_code,
            COALESCE(fm.material,        fs.material)                            AS material,
            COALESCE(fm.lens_shape,      fs.lens_shape)                          AS lens_shape,
            COALESCE(fm.structure,       fs.structure)                           AS structure,
            COALESCE(fm.gender_category, fs.gender_category)                     AS frame_gender,
            COALESCE(fm.sell_price,      fs.sell_price,     cf_brand.sell_price) AS frame_sell_price,
            CASE
                WHEN fm.ufc IS NOT NULL      THEN 'main'
                WHEN fs.ufc IS NOT NULL      THEN 'staging'
                WHEN cf_brand.id IS NOT NULL THEN 'custom'
                ELSE 'unknown'
            END AS frame_source
        $base_query
        ORDER BY co.order_date DESC, co.id DESC
        LIMIT ? OFFSET ?
    ";

    $data_params = array_merge($params, [$per_page, $offset]);
    $data_types  = $types . 'ii';
    $data_stmt   = $conn->prepare($data_sql);
    if ($data_stmt === false) die("Query error (data): " . $conn->error);
    $data_stmt->bind_param($data_types, ...$data_params);
    $data_stmt->execute();
    $data_result = $data_stmt->get_result();

    // ─────────────────────────────────────────────
    // ANALYTICS QUERIES
    // ─────────────────────────────────────────────


    // 1. Top brands
    $brand_rows = $conn->query("
        SELECT COALESCE(fm.brand, fs.brand, cf.brand_key) AS brand,
               COUNT(*) AS total
        FROM customer_orders co
        LEFT JOIN frames_main fm   ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN custom_frames cf ON CONVERT(cf.invoice_number USING utf8mb4) = CONVERT(co.invoice_number USING utf8mb4)
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
        GROUP BY brand ORDER BY total DESC LIMIT 6
    ");

    // 2. Structure breakdown
    $struct_rows = $conn->query("
        SELECT COALESCE(fm.structure, fs.structure) AS structure,
               COUNT(*) AS total
        FROM customer_orders co
        LEFT JOIN frames_main fm   ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
          AND COALESCE(fm.structure, fs.structure) IS NOT NULL
        GROUP BY structure ORDER BY total DESC
    ");

    // 3. Gender category of frames sold
    $gender_rows = $conn->query("
        SELECT COALESCE(fm.gender_category, fs.gender_category) AS gender_cat,
               COUNT(*) AS total
        FROM customer_orders co
        LEFT JOIN frames_main fm   ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
          AND COALESCE(fm.gender_category, fs.gender_category) IS NOT NULL
        GROUP BY gender_cat
    ");

    // 4. Monthly trend (last 6 months)
    $trend_rows = $conn->query("
        SELECT DATE_FORMAT(co.order_date, '%Y-%m') AS mon,
               COUNT(*) AS total,
               SUM(co.total_amount) AS revenue
        FROM customer_orders co
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
          AND co.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY mon ORDER BY mon ASC
    ");

    // 5. Top 5 best-selling frames (ufc)
    $top_frame_rows = $conn->query("
        SELECT co.frame_ufc,
               COALESCE(fm.brand, fs.brand, cf.brand_key) AS brand,
               COALESCE(fm.frame_code, fs.frame_code) AS frame_code,
               COALESCE(fm.color_code, fs.color_code) AS color_code,
               COUNT(*) AS sold,
               SUM(co.total_amount) AS revenue
        FROM customer_orders co
        LEFT JOIN frames_main fm   ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN custom_frames cf ON CONVERT(cf.invoice_number USING utf8mb4) = CONVERT(co.invoice_number USING utf8mb4)
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
        GROUP BY co.frame_ufc, brand, frame_code, color_code
        ORDER BY sold DESC LIMIT 5
    ");

    // 6. Material breakdown
    $material_rows = $conn->query("
        SELECT COALESCE(fm.material, fs.material) AS material,
               COUNT(*) AS total
        FROM customer_orders co
        LEFT JOIN frames_main fm   ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
          AND COALESCE(fm.material, fs.material) IS NOT NULL
        GROUP BY material ORDER BY total DESC LIMIT 6
    ");

    // 7. Summary stats
    $stats = $conn->query("
        SELECT
            COUNT(*) AS total_orders,
            COUNT(DISTINCT co.frame_ufc) AS unique_frames,
            SUM(co.total_amount) AS gross_revenue,
            AVG(COALESCE(fm.sell_price, fs.sell_price, cf.sell_price)) AS avg_frame_price
        FROM customer_orders co
        LEFT JOIN frames_main fm   ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN custom_frames cf ON CONVERT(cf.invoice_number USING utf8mb4) = CONVERT(co.invoice_number USING utf8mb4)
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
    ")->fetch_assoc();

    // ─────────────────────────────────────────────
    // FILTER OPTIONS for dropdowns
    // ─────────────────────────────────────────────
    $brand_options = $conn->query("
        SELECT DISTINCT COALESCE(fm.brand, fs.brand) AS b
        FROM customer_orders co
        LEFT JOIN frames_main fm   ON CONVERT(fm.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        LEFT JOIN frame_staging fs ON CONVERT(fs.ufc USING utf8mb4) = CONVERT(co.frame_ufc USING utf8mb4)
        WHERE co.frame_ufc IS NOT NULL AND co.frame_ufc != ''
          AND COALESCE(fm.brand, fs.brand) IS NOT NULL
        ORDER BY b
    ");

    // Status labels
    $status_map = [
        1 => ['label' => 'ORDER',      'color' => '#f1c40f'],
        2 => ['label' => 'PROCESSING', 'color' => '#3498db'],
        3 => ['label' => 'READY',      'color' => '#81C784'],
        4 => ['label' => 'DONE',       'color' => '#2ecc71'],
        5 => ['label' => 'CANCELLED',  'color' => '#e74c3c'],
    ];

    // Collect analytics data into PHP arrays for JS
    $js_brand   = []; while ($r = $brand_rows->fetch_assoc())    $js_brand[]   = $r;
    $js_struct  = []; while ($r = $struct_rows->fetch_assoc())   $js_struct[]  = $r;
    $js_gender  = []; while ($r = $gender_rows->fetch_assoc())   $js_gender[]  = $r;
    $js_trend   = []; while ($r = $trend_rows->fetch_assoc())    $js_trend[]   = $r;
    $js_material= []; while ($r = $material_rows->fetch_assoc()) $js_material[]= $r;
    $top_frames = []; while ($r = $top_frame_rows->fetch_assoc()) $top_frames[] = $r;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Frame Purchase History</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --bg-dark:      #23272a;
            --bg-card:      #1e2124;
            --bg-glass:     rgba(255,255,255,0.03);
            --shadow-light: #2d3236;
            --shadow-dark:  #191c1e;
            --accent-gold:  #f1c40f;
            --accent-green: #81C784;
            --accent-blue:  #5dade2;
            --accent-red:   #e74c3c;
            --accent-teal:  #1abc9c;
            --text-main:    #e8e8e8;
            --text-muted:   #888;
            --border:       rgba(255,255,255,0.06);
        }

        /* ── ANALYTICS GRID ── */
        .analytics-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-bar {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .stat-card .stat-label {
            font-size: 10px;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .stat-card .stat-value {
            font-size: 26px;
            font-weight: 700;
            color: var(--accent-gold);
            line-height: 1.1;
        }

        .stat-card .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
        }

        .chart-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
        }

        .chart-card h3 {
            font-size: 11px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            user-select: none;
        }

        .chart-card h3 .card-toggle-icon {
            font-size: 11px;
            color: var(--text-muted);
            transition: transform 0.3s ease;
            flex-shrink: 0;
        }

        .chart-card.collapsed h3 {
            margin-bottom: 0;
        }

        .chart-card.collapsed h3 .card-toggle-icon {
            transform: rotate(-90deg);
        }

        .chart-card .card-body {
            overflow: hidden;
            max-height: 600px;
            transition: max-height 0.3s ease, opacity 0.3s ease;
            opacity: 1;
        }

        .chart-card.collapsed .card-body {
            max-height: 0;
            opacity: 0;
        }

        .chart-card canvas {
            max-height: 180px;
        }

        /* ── CARD-LEVEL EMPTY STATE ── */
        .card-empty-state {
            text-align: center;
            padding: 28px 10px;
            color: var(--text-muted);
        }
        .card-empty-state .card-empty-icon {
            font-size: 32px;
            margin-bottom: 8px;
            opacity: 0.5;
        }
        .card-empty-state .card-empty-text {
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        /* ── TOP FRAMES LIST ── */
        .top-frame-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .top-frame-item:last-child { border-bottom: none; }

        .rank-badge {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--bg-dark);
            box-shadow: 3px 3px 7px var(--shadow-dark), -2px -2px 6px var(--shadow-light);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700;
            color: var(--accent-gold);
            flex-shrink: 0;
        }

        .top-frame-info { flex: 1; min-width: 0; }
        .top-frame-ufc  { font-size: 11px; font-weight: 700; color: var(--accent-teal); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .top-frame-meta { font-size: 10px; color: var(--text-muted); }

        .top-frame-sold {
            text-align: right;
            flex-shrink: 0;
        }
        .top-frame-sold .n   { font-size: 18px; font-weight: 700; color: var(--accent-green); }
        .top-frame-sold .lbl { font-size: 9px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }

        /* ── FILTER BAR ── */
        .filter-bar {
            background: var(--bg-card);
            border-radius: 14px;
            padding: 16px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 20px;
            box-shadow: 4px 4px 10px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        }

        .filter-bar label {
            font-size: 9px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            display: block;
            margin-bottom: 5px;
        }

        .filter-bar input,
        .filter-bar select {
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-main);
            padding: 8px 12px;
            font-size: 12px;
            box-shadow: inset 2px 2px 5px var(--shadow-dark);
            outline: none;
            min-width: 130px;
        }

        .filter-bar input:focus,
        .filter-bar select:focus {
            border-color: rgba(241,196,15,0.3);
        }

        .btn-filter, .btn-reset-filter {
            padding: 9px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            transition: all 0.2s;
            box-shadow: 4px 4px 8px var(--shadow-dark), -3px -3px 7px var(--shadow-light);
        }

        .btn-filter       { background: var(--bg-dark); color: var(--accent-gold); border: 1px solid rgba(241,196,15,0.15); }
        .btn-reset-filter { background: var(--bg-dark); color: var(--text-muted);  border: 1px solid var(--border); }
        .btn-filter:hover       { text-shadow: 0 0 8px rgba(241,196,15,0.5); }
        .btn-reset-filter:hover { color: var(--accent-red); }

        /* ── TABLE ── */
        .purchase-table-wrap {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 6px 6px 14px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
            margin-bottom: 20px;
        }

        .purchase-table-wrap h2 {
            font-size: 13px;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            margin: 0 0 16px;
        }

        .table-wrapper { overflow-x: auto; }

        .purchase-table-wrap table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .purchase-table-wrap thead th {
            font-size: 9px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .purchase-table-wrap tbody td {
            padding: 13px 12px;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
            vertical-align: middle;
        }

        .purchase-table-wrap tbody tr:last-child td { border-bottom: none; }
        .purchase-table-wrap tbody tr:hover td { background: rgba(255,255,255,0.02); }

        .ufc-tag {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            font-weight: 700;
            color: var(--accent-teal);
            background: rgba(26,188,156,0.08);
            padding: 3px 8px;
            border-radius: 5px;
            white-space: nowrap;
        }

        .source-badge {
            font-size: 9px;
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .src-main    { background: rgba(241,196,15,0.12);  color: var(--accent-gold); }
        .src-staging { background: rgba(93,173,226,0.12);  color: var(--accent-blue); }
        .src-custom  { background: rgba(129,199,132,0.12); color: var(--accent-green); }
        .src-unknown { background: rgba(136,136,136,0.12); color: var(--text-muted); }

        .status-pill {
            display: inline-block;
            font-size: 9px;
            padding: 3px 9px;
            border-radius: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .amount-col { text-align: right; font-variant-numeric: tabular-nums; }
        .amount-col .paid   { color: var(--accent-green); font-weight: 600; }
        .amount-col .unpaid { color: var(--accent-red); font-size: 10px; }

        /* ── PAGINATION ── */
        .pagination {
            display: flex;
            gap: 6px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .pg-btn {
            padding: 6px 14px;
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            font-size: 11px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 3px 3px 6px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
            transition: color 0.2s;
        }
        .pg-btn:hover   { color: var(--accent-gold); border-color: rgba(241,196,15,0.2); }
        .pg-btn.active  { color: var(--accent-gold); border-color: rgba(241,196,15,0.35); }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: var(--text-muted);
        }
        .empty-state .empty-icon { font-size: 40px; margin-bottom: 12px; }

        /* ── SECTION TOGGLE ── */
        .section-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            cursor: pointer;
            user-select: none;
        }
        .section-toggle span {
            font-size: 12px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--accent-gold);
        }
        .toggle-icon { transition: transform 0.3s; font-size: 12px; }
        .toggle-icon.closed { transform: rotate(-90deg); }

        /* ── RESULT COUNT ── */
        .result-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 14px;
        }
        .result-meta strong { color: var(--accent-gold); }

        @media (max-width: 600px) {
            .stat-bar { grid-template-columns: 1fr 1fr; }
            .analytics-section { grid-template-columns: 1fr; }
            .chart-card h3 { font-size: 10px; }
            .chart-card canvas { max-height: 220px; }
            .chart-card .card-body { max-height: 700px; }
        }
    </style>
</head>
<body>

<div class="main-wrapper">
    <div class="content-area" style="flex-direction: column;">

        <!-- HEADER -->
        <div class="header-container" style="margin-left:auto; margin-right:auto; width:100%;">
            <button class="logout-btn" onclick="window.location.href='logout.php';">
                <span>Logout</span>
            </button>
            <div class="brand-section">
                <div class="logo-box">
                    <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height:40px;">
                </div>
                <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
            </div>
        </div>

        <div class="main-card" style="margin-left:auto; margin-right:auto;">
        <div class="glass-window">

            <!-- ════════════════════════════════════ -->
            <!--  ANALYTICS SECTION                  -->
            <!-- ════════════════════════════════════ -->
            <div class="section-toggle" onclick="toggleSection('analytics')">
                <span>📊 Frame Sales Analytics</span>
                <span class="toggle-icon" id="icon-analytics">▼</span>
            </div>

            <div id="analytics">

                <!-- STAT CARDS -->
                <div class="stat-bar">
                    <div class="stat-card">
                        <div class="stat-label">Total Orders (with frame)</div>
                        <div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div>
                        <div class="stat-sub">All time</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Unique Frame SKUs Sold</div>
                        <div class="stat-value" style="color:var(--accent-teal);"><?php echo number_format($stats['unique_frames']); ?></div>
                        <div class="stat-sub">Distinct UFC codes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Gross Revenue</div>
                        <div class="stat-value" style="color:var(--accent-green); font-size:20px;">
                            Rp <?php echo number_format($stats['gross_revenue'] ?? 0, 0, ',', '.'); ?>
                        </div>
                        <div class="stat-sub">Total order amount</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Avg Frame Sell Price</div>
                        <div class="stat-value" style="color:var(--accent-blue); font-size:20px;">
                            Rp <?php echo number_format($stats['avg_frame_price'] ?? 0, 0, ',', '.'); ?>
                        </div>
                        <div class="stat-sub">Per frame unit</div>
                    </div>
                </div>

                <!-- CHARTS ROW -->
                <div class="analytics-section">

                    <!-- Top Brands -->
                    <div class="chart-card collapsed" id="card-brand">
                        <h3 onclick="toggleCard('card-brand')">
                            <span>🏆 Top Brands Sold</span>
                            <span class="card-toggle-icon">▼</span>
                        </h3>
                        <div class="card-body">
                            <?php if (count($js_brand) > 0): ?>
                                <canvas id="chartBrand"></canvas>
                            <?php else: ?>
                                <div class="card-empty-state">
                                    <div class="card-empty-icon">🏆</div>
                                    <div class="card-empty-text">No brand data yet</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Structure Breakdown -->
                    <div class="chart-card collapsed" id="card-struct">
                        <h3 onclick="toggleCard('card-struct')">
                            <span>👓 Frame Structure</span>
                            <span class="card-toggle-icon">▼</span>
                        </h3>
                        <div class="card-body">
                            <?php if (count($js_struct) > 0): ?>
                                <canvas id="chartStruct"></canvas>
                            <?php else: ?>
                                <div class="card-empty-state">
                                    <div class="card-empty-icon">👓</div>
                                    <div class="card-empty-text">No structure data yet</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Gender Category -->
                    <div class="chart-card collapsed" id="card-gender">
                        <h3 onclick="toggleCard('card-gender')">
                            <span>⚧ Frame Gender Category</span>
                            <span class="card-toggle-icon">▼</span>
                        </h3>
                        <div class="card-body">
                            <?php if (count($js_gender) > 0): ?>
                                <canvas id="chartGender"></canvas>
                            <?php else: ?>
                                <div class="card-empty-state">
                                    <div class="card-empty-icon">⚧</div>
                                    <div class="card-empty-text">No gender category data yet</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Material -->
                    <div class="chart-card collapsed" id="card-material">
                        <h3 onclick="toggleCard('card-material')">
                            <span>🔩 Material Breakdown</span>
                            <span class="card-toggle-icon">▼</span>
                        </h3>
                        <div class="card-body">
                            <?php if (count($js_material) > 0): ?>
                                <canvas id="chartMaterial"></canvas>
                            <?php else: ?>
                                <div class="card-empty-state">
                                    <div class="card-empty-icon">🔩</div>
                                    <div class="card-empty-text">No material data yet</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Monthly Trend (full width) -->
                    <div class="chart-card collapsed" id="card-trend" style="grid-column: 1 / -1;">
                        <h3 onclick="toggleCard('card-trend')">
                            <span>📈 Monthly Sales Trend (Last 6 Months)</span>
                            <span class="card-toggle-icon">▼</span>
                        </h3>
                        <div class="card-body">
                            <?php if (count($js_trend) > 0): ?>
                                <canvas id="chartTrend" style="max-height:200px;"></canvas>
                            <?php else: ?>
                                <div class="card-empty-state">
                                    <div class="card-empty-icon">📈</div>
                                    <div class="card-empty-text">No sales trend data for the last 6 months yet</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top 5 Frames -->
                    <div class="chart-card collapsed" id="card-topframes" style="grid-column: 1 / -1;">
                        <h3 onclick="toggleCard('card-topframes')">
                            <span>🥇 Top 5 Best-Selling Frames</span>
                            <span class="card-toggle-icon">▼</span>
                        </h3>
                        <div class="card-body">
                        <?php if (count($top_frames) > 0): ?>
                            <?php foreach ($top_frames as $i => $tf): ?>
                            <div class="top-frame-item">
                                <div class="rank-badge"><?php echo $i + 1; ?></div>
                                <div class="top-frame-info">
                                    <div class="top-frame-ufc"><?php echo htmlspecialchars($tf['frame_ufc']); ?></div>
                                    <div class="top-frame-meta">
                                        <?php echo htmlspecialchars($tf['brand'] ?? '-'); ?>
                                        <?php if (!empty($tf['frame_code'])): ?> · <?php echo htmlspecialchars($tf['frame_code']); ?><?php endif; ?>
                                        <?php if (!empty($tf['color_code'])): ?> · <?php echo htmlspecialchars($tf['color_code']); ?><?php endif; ?>
                                    </div>
                                </div>
                                <div class="top-frame-sold">
                                    <div class="n"><?php echo $tf['sold']; ?></div>
                                    <div class="lbl">sold</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="card-empty-state">
                                <div class="card-empty-icon">🥇</div>
                                <div class="card-empty-text">No best-selling frame data yet</div>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div><!-- /analytics -->

            <!-- ════════════════════════════════════ -->
            <!--  FILTER BAR                         -->
            <!-- ════════════════════════════════════ -->
            <form method="GET" action="">
                <div class="filter-bar">
                    <div>
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Name / Invoice / UFC"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div>
                        <label>Brand</label>
                        <select name="brand">
                            <option value="">All Brands</option>
                            <?php while ($bo = $brand_options->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($bo['b']); ?>"
                                    <?php echo ($filter_brand === $bo['b']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bo['b']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div>
                        <label>Structure</label>
                        <select name="structure">
                            <option value="">All</option>
                            <option value="full-rim"     <?php echo ($filter_struct==='full-rim')     ? 'selected':''; ?>>Full-Rim</option>
                            <option value="semi-rimless" <?php echo ($filter_struct==='semi-rimless') ? 'selected':''; ?>>Semi-Rimless</option>
                            <option value="rimless"      <?php echo ($filter_struct==='rimless')      ? 'selected':''; ?>>Rimless</option>
                        </select>
                    </div>
                    <div>
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">All</option>
                            <option value="men"    <?php echo ($filter_gender==='men')    ? 'selected':''; ?>>Men</option>
                            <option value="female" <?php echo ($filter_gender==='female') ? 'selected':''; ?>>Female</option>
                            <option value="unisex" <?php echo ($filter_gender==='unisex') ? 'selected':''; ?>>Unisex</option>
                        </select>
                    </div>
                    <div>
                        <label>Month</label>
                        <input type="month" name="month" value="<?php echo htmlspecialchars($filter_month); ?>">
                    </div>
                    <div style="display:flex; gap:8px; align-items:flex-end;">
                        <button type="submit" class="btn-filter">FILTER</button>
                        <a href="customer_frame_purchase.php" class="btn-reset-filter" style="text-decoration:none; padding:9px 14px; border-radius:8px; font-size:11px; font-weight:700; background:var(--bg-dark); color:var(--text-muted); border:1px solid var(--border); box-shadow:4px 4px 8px var(--shadow-dark),-3px -3px 7px var(--shadow-light);">RESET</a>
                    </div>
                </div>
            </form>

            <!-- ════════════════════════════════════ -->
            <!--  PURCHASE HISTORY TABLE             -->
            <!-- ════════════════════════════════════ -->
            <div class="purchase-table-wrap">
                <h2>📋 Frame Purchase History</h2>

                <div class="result-meta">
                    Showing <strong><?php echo number_format($total_rows); ?></strong> record(s)
                    <?php if ($search || $filter_brand || $filter_struct || $filter_gender || $filter_month): ?>
                        — <span style="color:var(--accent-gold);">Filter active</span>
                    <?php endif; ?>
                </div>

                <?php if ($total_rows > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Frame UFC</th>
                                <th>Brand</th>
                                <th>Code</th>
                                <th>Color</th>
                                <th>Material</th>
                                <th>Structure</th>
                                <th>Frame Gender</th>
                                <th>Lens</th>
                                <th>Order Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th style="text-align:right;">Total</th>
                                <th style="text-align:right;">Paid</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = $offset + 1;
                            while ($row = $data_result->fetch_assoc()):
                                $st = $status_map[$row['order_status']] ?? ['label' => $row['order_status'], 'color' => '#aaa'];
                                $sisa = $row['total_amount'] - $row['amount_paid'];
                                $src_class = 'src-' . ($row['frame_source'] ?? 'unknown');
                            ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?php echo $no++; ?></td>
                                <td style="font-size:11px; color:var(--accent-gold); font-weight:600; white-space:nowrap;">
                                    <?php echo htmlspecialchars($row['invoice_number']); ?>
                                </td>
                                <td>
                                    <div style="font-weight:600; font-size:12px;"><?php echo htmlspecialchars($row['customer_name'] ?? '-'); ?></div>
                                    <?php if (!empty($row['age'])): ?>
                                        <div style="font-size:10px; color:var(--text-muted);">
                                            <?php echo $row['cust_gender'] === 'MALE' ? '♂' : '♀'; ?>
                                            <?php echo $row['age']; ?> y.o
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="ufc-tag"><?php echo htmlspecialchars($row['frame_ufc']); ?></span></td>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($row['frame_brand'] ?? '-'); ?></td>
                                <td style="color:var(--text-muted);"><?php echo htmlspecialchars($row['frame_code'] ?? '-'); ?></td>
                                <td style="color:var(--text-muted);"><?php echo htmlspecialchars($row['color_code'] ?? '-'); ?></td>
                                <td style="color:var(--text-muted);"><?php echo htmlspecialchars($row['material'] ?? '-'); ?></td>
                                <td style="color:var(--text-muted); white-space:nowrap;"><?php echo htmlspecialchars($row['structure'] ?? '-'); ?></td>
                                <td style="color:var(--text-muted);"><?php echo htmlspecialchars($row['frame_gender'] ?? '-'); ?></td>
                                <td style="font-size:11px; color:var(--text-muted); max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($row['lens_name'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($row['lens_name'] ?? '-'); ?>
                                </td>
                                <td style="white-space:nowrap; color:var(--text-muted); font-size:11px;"><?php echo $row['order_date']; ?></td>
                                <td style="white-space:nowrap; color:var(--text-muted); font-size:11px;"><?php echo $row['due_date'] ?? '-'; ?></td>
                                <td>
                                    <span class="status-pill" style="background:<?php echo $st['color']; ?>22; color:<?php echo $st['color']; ?>;">
                                        <?php echo $st['label']; ?>
                                    </span>
                                </td>
                                <td class="amount-col">
                                    <div>Rp <?php echo number_format($row['total_amount'], 0, ',', '.'); ?></div>
                                </td>
                                <td class="amount-col">
                                    <div class="paid">Rp <?php echo number_format($row['amount_paid'], 0, ',', '.'); ?></div>
                                    <?php if ($sisa > 0): ?>
                                        <div class="unpaid">–Rp <?php echo number_format($sisa, 0, ',', '.'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="source-badge <?php echo $src_class; ?>">
                                        <?php echo strtoupper($row['frame_source'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                    $qs = http_build_query(array_filter([
                        'search'    => $search,
                        'brand'     => $filter_brand,
                        'structure' => $filter_struct,
                        'gender'    => $filter_gender,
                        'month'     => $filter_month,
                    ]));
                    $qs_sep = $qs ? '&' : '';

                    if ($page > 1): ?>
                        <a class="pg-btn" href="?<?php echo $qs . $qs_sep; ?>page=<?php echo $page-1; ?>">‹ Prev</a>
                    <?php endif;

                    $range_start = max(1, $page - 2);
                    $range_end   = min($total_pages, $page + 2);

                    for ($p = $range_start; $p <= $range_end; $p++): ?>
                        <a class="pg-btn <?php echo ($p === $page) ? 'active' : ''; ?>"
                           href="?<?php echo $qs . $qs_sep; ?>page=<?php echo $p; ?>"><?php echo $p; ?></a>
                    <?php endfor;

                    if ($page < $total_pages): ?>
                        <a class="pg-btn" href="?<?php echo $qs . $qs_sep; ?>page=<?php echo $page+1; ?>">Next ›</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">🕶️</div>
                    <p style="font-weight:600;">No Records Found</p>
                    <p style="font-size:12px; color:var(--text-muted);">
                        <?php echo ($search || $filter_brand || $filter_struct || $filter_gender || $filter_month)
                            ? 'No purchase data matches your current filter.'
                            : 'No frame purchase history available yet.'; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /glass-window -->
        </div><!-- /main-card -->

        <!-- BACK BUTTON -->
        <div class="btn-group">
            <button type="button" class="back-main" onclick="window.location.href='frame_management.php'">
                BACK TO PREVIOUS PAGE
            </button>
        </div>

        <footer class="footer-container">
            <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
        </footer>

    </div><!-- /content-area -->
</div><!-- /main-wrapper -->

<script>
// ─────────────────────────────────────────
// ANALYTICS DATA FROM PHP
// ─────────────────────────────────────────
const brandData    = <?php echo json_encode($js_brand); ?>;
const structData   = <?php echo json_encode($js_struct); ?>;
const genderData   = <?php echo json_encode($js_gender); ?>;
const trendData    = <?php echo json_encode($js_trend); ?>;
const materialData = <?php echo json_encode($js_material); ?>;

// ─────────────────────────────────────────
// CHART DEFAULTS
// ─────────────────────────────────────────
Chart.defaults.color          = '#888';
Chart.defaults.borderColor    = 'rgba(255,255,255,0.05)';
Chart.defaults.font.size      = 11;

const PALETTE = [
    '#f1c40f','#1abc9c','#5dade2','#81C784','#e74c3c',
    '#9b59b6','#e67e22','#2ecc71','#e91e63','#00bcd4'
];

const chartInstances = {};

function makeChart(id, type, labels, values, extras = {}) {
    const ctx = document.getElementById(id);
    if (!ctx) return;
    const chart = new Chart(ctx, {
        type,
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: type === 'line'
                    ? 'rgba(241,196,15,0.10)'
                    : PALETTE,
                borderColor: type === 'line'
                    ? '#f1c40f'
                    : PALETTE,
                borderWidth: type === 'line' ? 2 : 1,
                fill: type === 'line',
                tension: 0.4,
                pointBackgroundColor: '#f1c40f',
                ...extras
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: type !== 'bar',
                    position: 'bottom',
                    labels: { boxWidth: 10, padding: 10, color: '#888', font: { size: 10 } }
                },
                tooltip: {
                    backgroundColor: '#1e2124',
                    borderColor: 'rgba(255,255,255,0.08)',
                    borderWidth: 1,
                    titleColor: '#f1c40f',
                    bodyColor: '#e8e8e8',
                }
            },
            scales: type === 'bar' || type === 'line' ? {
                x: { ticks: { color: '#666', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } },
                y: { ticks: { color: '#666', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true }
            } : {}
        }
    });
    chartInstances[id] = chart;
    return chart;
}

// Build charts
makeChart('chartBrand',
    'bar',
    brandData.map(r => r.brand || 'Unknown'),
    brandData.map(r => parseInt(r.total))
);

makeChart('chartStruct',
    'doughnut',
    structData.map(r => r.structure || 'Unknown'),
    structData.map(r => parseInt(r.total))
);

makeChart('chartGender',
    'pie',
    genderData.map(r => r.gender_cat || 'Unknown'),
    genderData.map(r => parseInt(r.total))
);

makeChart('chartMaterial',
    'doughnut',
    materialData.map(r => r.material || 'Unknown'),
    materialData.map(r => parseInt(r.total))
);

// Trend: dual axis orders + revenue
const trendCtx = document.getElementById('chartTrend');
if (trendCtx && trendData.length > 0) {
    chartInstances['chartTrend'] = new Chart(trendCtx, {
        type: 'bar',
        data: {
            labels: trendData.map(r => r.mon),
            datasets: [
                {
                    label: 'Orders',
                    data: trendData.map(r => parseInt(r.total)),
                    backgroundColor: 'rgba(241,196,15,0.25)',
                    borderColor: '#f1c40f',
                    borderWidth: 1.5,
                    yAxisID: 'yLeft',
                },
                {
                    label: 'Revenue (Rp)',
                    data: trendData.map(r => parseFloat(r.revenue)),
                    type: 'line',
                    backgroundColor: 'rgba(26,188,156,0.08)',
                    borderColor: '#1abc9c',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    pointBackgroundColor: '#1abc9c',
                    yAxisID: 'yRight',
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { boxWidth: 10, padding: 10, color: '#888', font: { size: 10 } }
                },
                tooltip: {
                    backgroundColor: '#1e2124',
                    borderColor: 'rgba(255,255,255,0.08)',
                    borderWidth: 1,
                    titleColor: '#f1c40f',
                    bodyColor: '#e8e8e8',
                    callbacks: {
                        label: ctx => ctx.datasetIndex === 1
                            ? 'Rp ' + parseInt(ctx.raw).toLocaleString('id-ID')
                            : ctx.raw + ' orders'
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#666', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } },
                yLeft:  { position: 'left',  ticks: { color: '#f1c40f', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' }, beginAtZero: true },
                yRight: { position: 'right', ticks: { color: '#1abc9c', font: { size: 10 } }, grid: { display: false }, beginAtZero: true }
            }
        }
    });
}

// ─────────────────────────────────────────
// TOGGLE INDIVIDUAL CHART/ANALYTICS CARDS
// ─────────────────────────────────────────
function toggleCard(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('collapsed');
    if (!el.classList.contains('collapsed')) {
        const canvas = el.querySelector('canvas');
        if (canvas && chartInstances[canvas.id]) {
            setTimeout(() => chartInstances[canvas.id].resize(), 310);
        }
    }
}

// ─────────────────────────────────────────
// TOGGLE ANALYTICS SECTION
// ─────────────────────────────────────────
function toggleSection(id) {
    const el   = document.getElementById(id);
    const icon = document.getElementById('icon-' + id);
    if (!el) return;
    if (el.style.display === 'none') {
        el.style.display = '';
        icon.classList.remove('closed');
    } else {
        el.style.display = 'none';
        icon.classList.add('closed');
    }
}
</script>

</body>
</html>