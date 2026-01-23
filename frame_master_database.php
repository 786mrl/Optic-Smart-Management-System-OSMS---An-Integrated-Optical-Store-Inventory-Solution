<?php
session_start();
include 'db_config.php';
include 'config_helper.php';

$role = $_SESSION['role'] ?? 'staff';

// 1. Load & Normalisasi Mapping Warna dari JSON (Balik Key & Value)
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
$title_display = "Silahkan tekan Display untuk memuat data";

if ($show_data) {
    $cmd = $filter_command;
    $parts = explode('.', $cmd);

    if ($parts[0] === 'all' && isset($parts[1])) {
        // all.new, all.old
        $age = trim($parts[1]);
        $where_clause = " WHERE stock_age = '$age'";
        $title_display = "Filter Stok: " . strtoupper($age);
        
    } elseif ($parts[0] === 'brand' && isset($parts[1])) {
        // brand.takeyama, brand.takeyama.new
        $brand_name = mysqli_real_escape_string($conn, trim($parts[1]));
        if (isset($parts[2])) {
            $age = trim($parts[2]);
            $where_clause = " WHERE brand LIKE '%$brand_name%' AND stock_age = '$age'";
            $title_display = "Brand: " . strtoupper($brand_name) . " | Stok: " . strtoupper($age);
        } else {
            $where_clause = " WHERE brand LIKE '%$brand_name%'";
            $title_display = "Brand: " . strtoupper($brand_name);
        }

    } elseif ($parts[0] === 'material' && isset($parts[1])) {
        // material.plastic, material.plastic.old
        $material_type = mysqli_real_escape_string($conn, trim($parts[1]));
        if (isset($parts[2])) {
            $age = trim($parts[2]);
            $where_clause = " WHERE material LIKE '%$material_type%' AND stock_age = '$age'";
            $title_display = "Material: " . strtoupper($material_type) . " | Stok: " . strtoupper($age);
        } else {
            $where_clause = " WHERE material LIKE '%$material_type%'";
            $title_display = "Material: " . strtoupper($material_type);
        }

    } elseif ($parts[0] === 'shape' && isset($parts[1])) {
        // shape.square, shape.square.new
        $shape_type = mysqli_real_escape_string($conn, trim($parts[1]));
        if (isset($parts[2])) {
            $age = trim($parts[2]);
            $where_clause = " WHERE lens_shape LIKE '%$shape_type%' AND stock_age = '$age'";
            $title_display = "Shape: " . strtoupper($shape_type) . " | Stok: " . strtoupper($age);
        } else {
            $where_clause = " WHERE lens_shape LIKE '%$shape_type%'";
            $title_display = "Shape: " . strtoupper($shape_type);
        }

    } elseif ($parts[0] === 'structure' && isset($parts[1])) {
        // Gunakan trim untuk membersihkan spasi
        $structure_type = mysqli_real_escape_string($conn, trim($parts[1]));
        $age_filter = "";
        
        if (isset($parts[2])) {
            $age = trim($parts[2]);
            $age_filter = " AND stock_age = '$age'";
        }

        // MENGGUNAKAN '=' BUKAN 'LIKE' AGAR TERPISAH TEGAS
        // Ini memastikan 'rimless' tidak akan menarik 'semi-rimless'
        $where_clause = " WHERE structure = '$structure_type'" . $age_filter;
        $title_display = "Structure: " . strtoupper($structure_type) . ($age_filter ? " | Stok: " . strtoupper($age) : "");
        
    } elseif ($cmd === 'all') {
        $where_clause = "";
        $title_display = "Menampilkan Semua Data Utama";
    } else {
        // Pencarian Umum (Mencakup semua kolom penting)
        $search = mysqli_real_escape_string($conn, $cmd);
        $where_clause = " WHERE brand LIKE '%$search%' 
                          OR ufc LIKE '%$search%' 
                          OR lens_shape LIKE '%$search%' 
                          OR material LIKE '%$search%' 
                          OR structure LIKE '%$search%'";
        $title_display = "Hasil Pencarian: " . strtoupper($search);
    }

    $query = "SELECT * FROM frames_main" . $where_clause . " ORDER BY ufc ASC";
    $result = mysqli_query($conn, $query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Master Database - Color Mapping</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .filter-section { display: flex; gap: 15px; align-items: flex-end; background: #2e3133; padding: 25px; border-radius: 15px; box-shadow: inset 4px 4px 8px #25282a, inset -4px -4px 8px #373a3c; margin-bottom: 30px; }
        .cmd-group { flex-grow: 1; }
        .cmd-input { width: 100%; padding: 12px; background: transparent; border: none; border-bottom: 2px solid #00ff88; color: #00ff88; font-family: 'Courier New', monospace; font-size: 1.1em; outline: none; }
        .btn-display { background: #00ff88; color: #1a1c1d; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .master-table-container { background: #2e3133; border-radius: 12px; padding: 15px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
        th { background: #25282a; color: #00ff88; padding: 12px; text-align: left; border-bottom: 2px solid #444; }
        td { padding: 10px; border-bottom: 1px solid #3d4043; }
        .no-col { color: #888; text-align: center; width: 40px; }
        .ufc-col { font-family: 'Courier New', monospace; font-weight: bold; color: #fff; }
        .price-secret { color: #e74c3c; font-weight: bold; font-family: 'Courier New', monospace; }
        .age-dot { height: 14px; width: 14px; border-radius: 50%; display: inline-block; box-shadow: 0 0 5px rgba(0,0,0,0.5); }
        .dot-new { background: #2ecc71; }
        .dot-old { background: #f1c40f; }
        .dot-veryold { background: #e74c3c; }
        .color-alias { font-size: 0.8em; color: #888; display: block; font-weight: normal; }
    </style>
</head>
<body class="main-wrapper">

    <div style="padding: 20px;">
        <div class="header-container">
            <h1>MASTER DATABASE</h1>
            <p style="color: #00ff88;"><?= $title_display ?></p>
        </div>

        <form method="GET" action="" class="filter-section">
            <div class="cmd-group">
                <label style="font-size: 0.8em; color: #888; margin-bottom: 5px; display: block;">COMMAND INPUT (all / all.new / all.old)</label>
                <input type="text" name="cmd" class="cmd-input" value="<?= htmlspecialchars($filter_command) ?>" autocomplete="off">
            </div>
            <button type="submit" name="display" value="true" class="btn-display">DISPLAY DATA</button>
        </form>

        <div class="master-table-container">
            <?php if ($show_data && $result): ?>
                <?php if (mysqli_num_rows($result) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th class="no-col">No</th>
                                <th>UFC</th>
                                <th>Color</th>
                                <th>Material</th>
                                <th>Shape</th>
                                <th>Structure</th>
                                <th>Size Range</th>
                                <?php if($role == 'admin'): ?>
                                    <th>Buy Price</th>
                                <?php endif; ?>
                                <th>Sell Price</th>
                                <th>Secret Code</th>
                                <th style="text-align:center">Stock</th>
                                <th style="text-align:center">Age</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            while($row = mysqli_fetch_assoc($result)): 
                                $db_color_code = strtoupper(trim($row['color_code']));
                                $display_color = isset($color_map[$db_color_code]) ? $color_map[$db_color_code] : $db_color_code;
                                $has_alias = (isset($color_map[$db_color_code]));
                            ?>
                            <tr>
                                <td class="no-col"><?= $no++ ?></td>
                                <td class="ufc-col"><?= $row['ufc'] ?></td>
                                <td>
                                    <strong><?= $display_color ?></strong>
                                    <?php if($has_alias): ?>
                                        <span class="color-alias"><?= $db_color_code ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['material'] ?></td>
                                <td><?= $row['lens_shape'] ?></td>
                                <td><?= $row['structure'] ?></td>
                                <td><?= $row['size_range'] ?></td>
                                <?php if($role == 'admin'): ?>
                                    <td><?= number_format($row['buy_price'], 0, ',', '.') ?></td>
                                <?php endif; ?>
                                <td><?= number_format($row['sell_price'], 0, ',', '.') ?></td>
                                <td class="price-secret"><?= $row['price_secret_code'] ?></td>
                                <td style="text-align: center; font-weight: bold; color: #00ff88;"><?= $row['stock'] ?></td>
                                <td style="text-align: center;">
                                    <?php 
                                        $dot_class = str_replace(' ', '', $row['stock_age']);
                                        echo "<span class='age-dot dot-$dot_class' title='".strtoupper($row['stock_age'])."'></span>";
                                    ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 80px 20px; border: 2px dashed #444; border-radius: 12px;">
                        <div style="font-size: 3em; margin-bottom: 10px;">🔍</div>
                        <div style="font-size: 1.2em; color: #e74c3c; font-weight: bold; letter-spacing: 1px;">
                            DATA TIDAK DITEMUKAN
                        </div>
                        <p style="color: #888; margin-top: 10px;">
                            Perintah <strong>"<?= htmlspecialchars($filter_command) ?>"</strong> tidak cocok dengan record manapun.
                        </p>
                        <button onclick="window.location.href='frame_master_database.php'" style="margin-top: 15px; background: #444; color: #fff; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer;">
                            Reset Filter
                        </button>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 60px; color: #666;">
                    <p>Gunakan format <strong>all.[age]</strong> atau <strong>brand.[nama].[age]</strong></p>
                    <p style="font-size: 0.9em;">Contoh: <i>brand.takeyama.new</i></p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="btn-group" style="margin-top: 20px;">
            <button class="back-main" onclick="window.location.href='frame_management.php'">KEMBALI KE MENU</button>
        </div>
    </div>
</body>
</html>