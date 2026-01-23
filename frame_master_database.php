<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    $role = $_SESSION['role'] ?? 'staff';

    // 1. Load & Normalize Color Mapping (Original Logic)
    $color_map = [];
    $json_path = "data_json/colors.json";
    if (file_exists($json_path)) {
        $raw_json = json_decode(file_get_contents($json_path), true);
        if (is_array($raw_json)) {
            foreach ($raw_json as $colorName => $colorCode) {
                $color_map[strtoupper(trim($colorCode))] = strtoupper(trim($colorName));
            }
        }
    }

    $filter_command = isset($_GET['cmd']) ? strtolower(trim($_GET['cmd'])) : 'all';
    $show_data = isset($_GET['display']); 

    $result = null;
    $where_clause = "";
    $title_display = "Please click Display to load data";

    // configuration for input command rules to search data
    if ($show_data) {
        $cmd = $filter_command;
        $parts = explode('.', $cmd);
        $cmd_type = $parts[0];

        if (in_array($cmd_type, ['brand', 'material', 'shape', 'structure', 'size'])) {
            $val_main = mysqli_real_escape_string($conn, trim($parts[1] ?? ''));
            $extra_sql = "";
            $labels = [];

            for ($i = 2; $i < count($parts); $i++) {
                $p = trim($parts[$i]);
                if ($p === 'available') {
                    $extra_sql .= " AND stock > 0";
                    $labels[] = "AVAILABLE";
                } elseif (in_array($p, ['new', 'old', 'very old'])) {
                    $extra_sql .= " AND stock_age = '$p'";
                    $labels[] = strtoupper($p);
                }
            }

            $column_map = [
                'brand'     => 'brand',
                'material'  => 'material',
                'shape'     => 'lens_shape',
                'structure' => 'structure',
                'size'      => 'size_range'
            ];
            
            $col = $column_map[$cmd_type];
            $operator = ($cmd_type === 'structure' || $cmd_type === 'size') ? "= '$val_main'" : "LIKE '%$val_main%'";
            $where_clause = " WHERE $col $operator" . $extra_sql;
            $title_display = strtoupper($cmd_type) . ": " . strtoupper($val_main) . ($labels ? " (" . implode(" & ", $labels) . ")" : "");

        } elseif ($cmd_type === 'all') {
            $age = isset($parts[1]) ? trim($parts[1]) : null;
            $where_clause = $age ? " WHERE stock_age = '$age'" : "";
            $title_display = $age ? "Stock Filter: " . strtoupper($age) : "Showing All Main Data";

        } else {
            $search = mysqli_real_escape_string($conn, $cmd);
            $where_clause = " WHERE brand LIKE '%$search%' OR ufc LIKE '%$search%' OR lens_shape LIKE '%$search%'";
            $title_display = "Search Results: " . strtoupper($search);
        }

        $query = "SELECT * FROM frames_main" . $where_clause . " ORDER BY ufc ASC";
        $result = mysqli_query($conn, $query);
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Database - Cyber View</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #0f1113;
            --card-bg: #16181b;
            --accent: linear-gradient(135deg, #00d4ff 0%, #0055ff 100%);
            --accent-solid: #00d4ff;
            --shadow-dark: #08090a;
            --shadow-light: #1f2226;
            --text-main: #ffffff;
            --text-muted: #808b96;
            --success: #2ecc71;
            --warning: #f1c40f;
            --danger: #e74c3c;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0; padding: 40px 20px;
            display: flex; justify-content: center;
        }

        .container { width: 100%; max-width: 1200px; }

        /* --- HEADER --- */
        .header-area { margin-bottom: 30px; }
        .header-area h1 { font-weight: 800; font-size: 24px; letter-spacing: 2px; margin: 0; }
        .status-text { color: var(--accent-solid); font-size: 13px; font-weight: 600; text-transform: uppercase; margin-top: 5px; }

        /* --- INPUT BAR --- */
        .input-bar-container {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 25px;
            box-shadow: 15px 15px 35px var(--shadow-dark), -10px -10px 30px var(--shadow-light);
            display: flex; gap: 12px; margin-bottom: 40px;
            align-items: center; border: 1px solid rgba(255,255,255,0.02);
        }

        .cmd-input {
            flex: 1; background: var(--bg-color); border: 1px solid rgba(255,255,255,0.05);
            padding: 15px 20px; border-radius: 15px; color: var(--accent-solid);
            font-family: 'Courier New', monospace; outline: none;
            box-shadow: inset 6px 6px 12px var(--shadow-dark);
            text-transform: uppercase;
        }

        .btn-display {
            background: var(--accent); border: none; padding: 0 30px; height: 50px;
            border-radius: 15px; color: white; font-weight: 800; cursor: pointer;
            box-shadow: 0 5px 15px rgba(0, 85, 255, 0.3); transition: 0.3s;
        }

        .btn-help {
            background: var(--card-bg); border: none; width: 50px; height: 50px;
            border-radius: 15px; color: var(--accent-solid); cursor: pointer;
            box-shadow: 4px 4px 10px var(--shadow-dark), -2px -2px 8px var(--shadow-light);
        }

        /* --- TABLE AREA --- */
        .table-responsive {
            background: var(--card-bg); border-radius: 30px; padding: 10px;
            box-shadow: 20px 20px 60px var(--shadow-dark); overflow-x: auto;
        }

        table { width: 100%; border-collapse: separate; border-spacing: 0 10px; min-width: 1000px; }
        th { padding: 15px 20px; font-size: 11px; color: var(--text-muted); text-transform: uppercase; text-align: left; }
        td { padding: 18px 20px; background: #1a1d21; font-size: 13px; white-space: nowrap; }

        tr td:first-child { border-radius: 15px 0 0 15px; border-left: 3px solid var(--accent-solid); }
        tr td:last-child { border-radius: 0 15px 15px 0; }

        .ufc-badge { font-family: 'Courier New', monospace; font-weight: 800; color: #fff; }
        .color-code { font-size: 11px; color: var(--text-muted); }
        
        /* AGE DOTS */
        .age-dot { height: 20px; width: 20px; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px currentColor; }
        .dot-new { color: var(--success); background: currentColor; }
        .dot-old { color: var(--warning); background: currentColor; }
        .dot-veryold { color: var(--danger); background: currentColor; }

        /* EMPTY STATE */
        .empty-state {
            text-align: center; padding: 80px; background: var(--card-bg);
            border-radius: 30px; box-shadow: 20px 20px 60px var(--shadow-dark);
        }
        .price-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .price-value {
            display: none; /* Hidden by default */
        }
        .price-hidden {
            color: var(--text-muted);
            font-style: italic;
            font-size: 11px;
        }
        .btn-reveal {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--accent-solid);
            padding: 4px 8px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 10px;
            transition: 0.2s;
        }
        .btn-reveal:hover {
            background: var(--accent-solid);
            color: white;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header-area">
        <h1>MASTER DATABASE</h1>
        <div class="status-text"><?= $title_display ?></div>
    </div>

    <form method="GET" action="" class="input-bar-container">
        <input type="text" name="cmd" class="cmd-input" value="<?= htmlspecialchars($filter_command) ?>" placeholder="e.g: brand.takeyama.available">
        <button type="submit" name="display" value="true" class="btn-display">DISPLAY DATA</button>
        <button type="button" class="btn-help" onclick="showHelp()"><i class="fas fa-question"></i></button>
    </form>

    <?php if ($show_data && $result): ?>
        <?php if (mysqli_num_rows($result) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>No</th><th>Brand</th><th>UFC</th><th>Color Details</th><th>Material</th><th>Shape</th>
                            <th>Size</th><?php if($role == 'admin'): ?><th>Buy</th><?php endif; ?>
                            <th>Sell</th><th>Secret</th><th>Stock</th><th>Age</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; while($row = mysqli_fetch_assoc($result)): 
                            $code = strtoupper(trim($row['color_code']));
                            $name = $color_map[$code] ?? $code;
                        ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td style="font-weight: 800; color: var(--accent-solid); letter-spacing: 1px;">
                                <?= strtoupper($row['brand']) ?>
                            </td>
                            <td class="ufc-badge">
                                <div class="price-box">
                                    <span class="price-hidden" style="font-family: sans-serif;"></span>
                                    <span class="price-value"><?= $row['ufc'] ?></span>
                                    <button type="button" class="btn-reveal" onclick="revealPrice(this)"><i class="fas fa-eye"></i></button>
                                </div>
                            </td>
                            <td>
                                <span><?= $name ?></span>
                            </td>
                            <td><?= $row['material'] ?></td>
                            <td><?= $row['lens_shape'] ?></td>
                            <td><?= $row['size_range'] ?></td>
                            <?php if($role == 'admin'): ?>
                                <td style="color: var(--success);">
                                    <div class="price-box">
                                        <span class="price-hidden"></span>
                                        <span class="price-value">IDR <?= number_format($row['buy_price'],0,',','.') ?></span>
                                        <button type="button" class="btn-reveal" onclick="revealPrice(this)"><i class="fas fa-eye"></i></button>
                                    </div>
                                </td>
                            <?php endif; ?>

                            <td style="font-weight: 700;">
                                <div class="price-box">
                                    <span class="price-hidden"></span>
                                    <span class="price-value">IDR <?= number_format($row['sell_price'],0,',','.') ?></span>
                                    <button type="button" class="btn-reveal" onclick="revealPrice(this)"><i class="fas fa-eye"></i></button>
                                </div>
                            </td>
                            <td style="color: var(--danger); font-size: 14px; font-weight: bold; font-family: monospace;"><?= $row['price_secret_code'] ?></td>
                            <td style="text-align: center;"><strong><?= $row['stock'] ?></strong></td>
                            <td style="text-align: center;">
                                <span class="age-dot dot-<?= str_replace(' ', '', $row['stock_age']) ?>" title="<?= strtoupper($row['stock_age']) ?>"></span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-database" style="font-size: 50px; opacity: 0.2; margin-bottom: 20px;"></i>
                <h2 style="color: var(--danger);">⚠️ NO DATA FOUND</h2>
                <p style="color: var(--text-muted);">No frames matched the given command.</p>
                <button class="btn-display" onclick="window.location.href='?'" style="margin-top: 20px;">RESET FILTER</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    function showHelp() {
        Swal.fire({
            title: '<span style="color: #00d4ff">SEARCH COMMAND GUIDE</span>',
            html: `
                <div style="text-align: left; color: #ccc; font-size: 14px; line-height: 1.6;">
                    <p>Format: <b>category.value.extra</b></p>
                    <hr style="border: 0; border-top: 1px solid #333; margin: 10px 0;">
                    • <b>all</b> : Show all data<br>
                    • <b>brand.takeyama</b> : Filter by brand<br>
                    • <b>shape.square</b> : Filter by lens shape<br>
                    • <b>size.50-18</b> : Filter by specific size<br>
                    <hr style="border: 0; border-top: 1px solid #333; margin: 10px 0;">
                    <b>Extras:</b><br>
                    • <b>.available</b> : Stock > 0 only<br>
                    • <b>.new / .old / .very old</b> : Filter by stock age
                </div>
            `,
            background: '#16181b',
            confirmButtonColor: '#0055ff',
            confirmButtonText: 'UNDERSTOOD'
        });
    }

    function revealPrice(btn) {
        const box = btn.parentElement;
        const hiddenText = box.querySelector('.price-hidden');
        const valueText = box.querySelector('.price-value');
        const icon = btn.querySelector('i');

        if (valueText.style.display === 'none' || valueText.style.display === '') {
            valueText.style.display = 'inline';
            hiddenText.style.display = 'none';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
            btn.style.opacity = '0.5'; // Mark as revealed
        } else {
            valueText.style.display = 'none';
            hiddenText.style.display = 'inline';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
            btn.style.opacity = '1';
        }
    }
</script>

</body>
</html>