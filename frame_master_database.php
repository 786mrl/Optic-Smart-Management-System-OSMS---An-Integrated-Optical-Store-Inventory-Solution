<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    $role = $_SESSION['role'] ?? 'staff';

    // 1. Load & Normalize Color Mapping
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

    // configuration for input search data rules
    if ($show_data) {
        $cmd = $filter_command;
        $parts = explode('.', $cmd);
        $cmd_type = $parts[0];

        // SMART FILTER LOGIC (Brand, Material, Shape, Structure, Size)
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
            // all or all.new
            $age = isset($parts[1]) ? trim($parts[1]) : null;
            $where_clause = $age ? " WHERE stock_age = '$age'" : "";
            $title_display = $age ? "Stock Filter: " . strtoupper($age) : "Showing All Main Data";

        } else {
            // General Search
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
    <title>Master Database</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .filter-section { display: flex; gap: 15px; align-items: flex-end; background: #2e3133; padding: 25px; border-radius: 15px; margin-bottom: 30px; }
        .cmd-group { flex-grow: 1; }
        .cmd-input { width: 100%; padding: 12px; background: transparent; border: none; border-bottom: 2px solid #00ff88; color: #00ff88; font-family: 'Courier New', monospace; font-size: 1.1em; outline: none; }
        .btn-display { background: #00ff88; color: #1a1c1d; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .master-table-container { background: #2e3133; border-radius: 12px; padding: 15px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
        th { background: #25282a; color: #00ff88; padding: 12px; text-align: left; border-bottom: 2px solid #444; }
        td { padding: 10px; border-bottom: 1px solid #3d4043; }
        .ufc-col { font-family: 'Courier New', monospace; font-weight: bold; color: #fff; }
        .age-dot { height: 12px; width: 12px; border-radius: 50%; display: inline-block; }
        .dot-new { background: #2ecc71; } .dot-old { background: #f1c40f; } .dot-veryold { background: #e74c3c; }
        .color-alias { font-size: 0.8em; color: #888; display: block; }
    </style>
</head>
<body class="main-wrapper">

    <div style="padding: 20px;">
        <h1>MASTER DATABASE</h1>
        <p style="color: #00ff88;"><?= $title_display ?></p>

        <form method="GET" action="" class="filter-section">
            <div class="cmd-group">
                <label style="font-size: 0.8em; color: #888; margin-bottom: 5px; display: block;">COMMAND INPUT</label>
                <input type="text" name="cmd" class="cmd-input" value="<?= htmlspecialchars($filter_command) ?>" autocomplete="off" placeholder="Example: brand.takeyama.available">
            </div>
            <button type="submit" name="display" value="true" class="btn-display">DISPLAY DATA</button>
            <button type="button" onclick="showHelp()" style="background: #3498db; color: white; border: none; padding: 12px; border-radius: 8px; cursor: pointer; margin-left: 10px;">
                <i class="fas fa-question-circle"></i>
            </button>
        </form>

        <div class="master-table-container">
            <?php if ($show_data && $result): ?>
                <?php if (mysqli_num_rows($result) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th><th>UFC</th><th>Color</th><th>Material</th><th>Shape</th><th>Structure</th><th>Size</th>
                                <?php if($role == 'admin'): ?><th>Buy Price</th><?php endif; ?>
                                <th>Sell Price</th><th>Secret Code</th><th>Stock</th><th>Age</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; while($row = mysqli_fetch_assoc($result)): 
                                $code = strtoupper(trim($row['color_code']));
                                $name = $color_map[$code] ?? $code;
                            ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td class="ufc-col"><?= $row['ufc'] ?></td>
                                <td><strong><?= $name ?></strong><span class="color-alias"><?= $code ?></span></td>
                                <td><?= $row['material'] ?></td>
                                <td><?= $row['lens_shape'] ?></td>
                                <td><?= $row['structure'] ?></td>
                                <td><?= $row['size_range'] ?></td>
                                <?php if($role == 'admin'): ?><td><?= number_format($row['buy_price'],0,',','.') ?></td><?php endif; ?>
                                <td><?= number_format($row['sell_price'],0,',','.') ?></td>
                                <td style="color: #e74c3c; font-family: monospace;"><?= $row['price_secret_code'] ?></td>
                                <td style="text-align: center; color: #00ff88; font-weight: bold;"><?= $row['stock'] ?></td>
                                <td style="text-align: center;">
                                    <span class="age-dot dot-<?= str_replace(' ', '', $row['stock_age']) ?>"></span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 50px; color: #e74c3c;">
                        <h2>⚠️ NO DATA FOUND</h2>
                        <button onclick="window.location.href='frame_master_database.php'">Reset Filter</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showHelp() {
            Swal.fire({
                title: '<span style="color: #00ff88">SEARCH GUIDE</span>',
                html: `<div style="text-align: left; color: #eee; font-size: 0.9em;">
                    <p>Format: <b>category.value.extra</b></p>
                    <hr>
                    <li><b>all</b> : All data</li>
                    <li><b>brand.[name]</b> : e.g., brand.takeyama</li>
                    <li><b>shape.[type]</b> : e.g., shape.square</li>
                    <li><b>size.[value]</b> : e.g., size.50-18</li>
                    <hr>
                    <p><b>Extras:</b> .available (stock > 0), .new, .old, .very old</p>
                </div>`,
                background: '#2e3133',
                confirmButtonColor: '#00ff88'
            });
        }
    </script>
</body>
</html>