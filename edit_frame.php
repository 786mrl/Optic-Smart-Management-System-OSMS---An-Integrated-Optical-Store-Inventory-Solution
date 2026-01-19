<?php
session_start();
include 'db_config.php';
include 'config_helper.php';
include 'phpqrcode/qrlib.php'; 

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

$role = $_SESSION['role'] ?? 'staff';
$old_ufc = $_GET['ufc'] ?? '';

if (empty($old_ufc)) { header("Location: frame_management.php"); exit(); }

// Ambil data lama
$stmt_get = $conn->prepare("SELECT * FROM frame_staging WHERE ufc = ?");
$stmt_get->bind_param("s", $old_ufc);
$stmt_get->execute();
$current_data = $stmt_get->get_result()->fetch_assoc();

if (!$current_data) { die("Data not found!"); }

function loadJson($file) {
    $path = "data_json/$file";
    return file_exists($path) ? json_decode(file_get_contents($path), true) : [];
}

if (isset($_POST['update_frame'])) {
    $brand = strtoupper($_POST['brand']);
    $f_code = !empty($_POST['frame_code']) ? $_POST['frame_code'] : "lz-786";
    $f_size = !empty($_POST['frame_size']) ? $_POST['frame_size'] : "00-00-786";
    
    // Logic Color
    if ($_POST['has_color_code'] == 'no') {
        $colors = loadJson('colors.json');
        $input_color = strtolower($_POST['color_name'] ?? '');
        if (!isset($colors[$input_color])) {
            $next_col = "col." . (count($colors) + 1);
            $colors[$input_color] = $next_col;
            file_put_contents("data_json/colors.json", json_encode($colors));
        }
        $color_code = $colors[$input_color];
    } else {
        $color_code = $_POST['color_manual_code'];
    }

    // Generate New UFC
    $new_ufc = str_replace(' ', '', "$brand-$f_code-$f_size-$color_code");

    $input_stock = (int)$_POST['total_frame'];
    $buy_price = ($role === 'admin') ? (float)$_POST['buy_price'] : (float)$current_data['buy_price'];
    $stock_age = $_POST['stock_age'] ?? 'new';

    // Re-calculate Price
    $sell_price = 0;
    $secret_code = "";
    if ($buy_price > 0) {
        $rules = loadJson('price_rules.json');
        foreach ($rules['margins'] as $m) {
            if ($buy_price <= $m['max']) {
                $sell_price = $buy_price + ($buy_price * ($m['percent'] / 100));
                break;
            }
        }
        $sell_price = ceil($sell_price / 5000) * 5000;
        $temp_sell = $sell_price;
        $secret_code = "LZ";
        $map = $rules['secret_map'];
        arsort($map); 
        foreach ($map as $char => $val) {
            if ($temp_sell >= $val) { $secret_code .= $char; $temp_sell -= $val; }
        }
        $secret_code .= str_pad(($temp_sell / 1000), 2, "0", STR_PAD_LEFT);
    }

    $conn->begin_transaction();
    try {
        if ($new_ufc !== $old_ufc) {
            // Jika komponen kunci berubah, hapus yang lama (UFC baru akan dibuat)
            $del = $conn->prepare("DELETE FROM frame_staging WHERE ufc = ?");
            $del->bind_param("s", $old_ufc);
            $del->execute();
            
            // Hapus file QR lama jika ada
            if (file_exists("qrcodes/$old_ufc.png")) unlink("qrcodes/$old_ufc.png");
        }

        $stmt = $conn->prepare("INSERT INTO frame_staging 
            (ufc, brand, frame_code, frame_size, color_code, material, lens_shape, structure, size_range, buy_price, sell_price, price_secret_code, stock, stock_age) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            brand=VALUES(brand), frame_code=VALUES(frame_code), frame_size=VALUES(frame_size), color_code=VALUES(color_code), 
            material=VALUES(material), lens_shape=VALUES(lens_shape), structure=VALUES(structure), size_range=VALUES(size_range), 
            buy_price=VALUES(buy_price), sell_price=VALUES(sell_price), price_secret_code=VALUES(price_secret_code), 
            stock=VALUES(stock), stock_age=VALUES(stock_age)");
        
        $stmt->bind_param("sssssssssddsis", $new_ufc, $brand, $f_code, $f_size, $color_code, $_POST['material'], 
                          $_POST['lens_shape'], $_POST['structure'], $_POST['size_range'], $buy_price, $sell_price, 
                          $secret_code, $input_stock, $stock_age);
        $stmt->execute();

        if (!file_exists('qrcodes')) mkdir('qrcodes');
        QRcode::png($new_ufc, "qrcodes/$new_ufc.png", QR_ECLEVEL_L, 4);

        $conn->commit();
        $_SESSION['success_msg'] = "Data Updated Successfully! UFC: $new_ufc";
        header("Location: frame_management.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Frame - <?php echo $old_ufc; ?></title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="main-wrapper">
        <div class="content-area" style="flex-direction: column">
            <div class="main-card" style="margin: 20px auto; width: 100%;">
                <h2>EDIT FRAME DATA</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="input-group">
                            <label>Frame Brand</label>
                            <input type="text" name="brand" required value="<?php echo $current_data['brand']; ?>" style="text-transform: uppercase;">
                        </div>
                        <div class="input-group">
                            <label>Frame Code</label>
                            <input type="text" name="frame_code" value="<?php echo $current_data['frame_code']; ?>" style="text-transform: uppercase;">
                        </div>
                        <div class="input-group">
                            <label>Frame Size</label>
                            <input type="text" name="frame_size" value="<?php echo $current_data['frame_size']; ?>">
                        </div>

                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">Manual Color Code?</label>
                            <input type="hidden" name="has_color_code" id="has_color_code_input" value="yes">
                            <div id="color_opt" class="selection-wrapper">
                                <button value="no" type="button" class="neu-btn" onclick="toggleNeu(this, 'has_color_code_input', true)"><span>NO</span><div class="led"></div></button>
                                <button value="yes" type="button" class="neu-btn active" onclick="toggleNeu(this, 'has_color_code_input', true)"><span>YES</span><div class="led"></div></button>
                            </div>
                        </div>

                        <div id="col_name_box" class="input-group hidden">
                            <label>Frame Color Name</label>
                            <input type="text" name="color_name" placeholder="BLACK GOLD">
                        </div>
                        <div id="col_manual_box" class="input-group">
                            <label>Color Code</label>
                            <input type="text" name="color_manual_code" value="<?php echo $current_data['color_code']; ?>" style="text-transform: uppercase;">
                        </div>

                        <div class="input-group">
                            <label>Material</label>
                            <select name="material">
                                <?php foreach(loadJson('materials.json') as $m): ?>
                                    <option value="<?php echo $m; ?>" <?php if($m==$current_data['material']) echo 'selected'; ?>><?php echo $m; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="input-group">
                            <label>Lens Shape</label>
                            <select name="lens_shape">
                                <?php foreach(loadJson('shapes.json') as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php if($s==$current_data['lens_shape']) echo 'selected'; ?>><?php echo $s; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">STRUCTURE</label>
                            <input type="hidden" name="structure" id="frame_structure_input" value="<?php echo $current_data['structure']; ?>">
                            <div class="selection-wrapper">
                                <?php $structs = ['full-rim', 'semi-rimless', 'rimless']; 
                                foreach($structs as $st): ?>
                                    <button value="<?php echo $st; ?>" type="button" class="neu-btn <?php echo ($current_data['structure']==$st)?'active':''; ?>" onclick="toggleNeu(this, 'frame_structure_input')">
                                        <span><?php echo strtoupper(str_replace('-', ' ', $st)); ?></span><div class="led"></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">SIZE RANGE</label>
                            <input type="hidden" name="size_range" id="frame_size_range_input" value="<?php echo $current_data['size_range']; ?>">
                            <div class="selection-wrapper">
                                <?php $sizes = ['small', 'medium', 'large']; 
                                foreach($sizes as $sz): ?>
                                    <button value="<?php echo $sz; ?>" type="button" class="neu-btn <?php echo ($current_data['size_range']==$sz)?'active':''; ?>" onclick="toggleNeu(this, 'frame_size_range_input')">
                                        <span><?php echo strtoupper($sz); ?></span><div class="led"></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="input-group">
                            <label>Total Frame (Stock)</label>
                            <input type="number" name="total_frame" value="<?php echo $current_data['stock']; ?>" required>
                        </div>

                        <div class="input-group">
                            <label style="width: 100%; text-align: center;">STOCK AGE</label>
                            <input type="hidden" name="stock_age" id="stock_age_input" value="<?php echo $current_data['stock_age']; ?>">
                            <div class="selection-wrapper">
                                <?php $ages = ['very old', 'old', 'new']; 
                                foreach($ages as $ag): ?>
                                    <button value="<?php echo $ag; ?>" type="button" class="neu-btn <?php echo ($current_data['stock_age']==$ag)?'active':''; ?>" onclick="toggleNeu(this, 'stock_age_input')">
                                        <span><?php echo strtoupper($ag); ?></span><div class="led"></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($role === 'admin'): ?>
                            <div class="input-group">
                                <label>Cost Price (IDR)</label>
                                <input type="password" id="buy_price" name="buy_price" value="<?php echo $current_data['buy_price']; ?>" oninput="calculatePrice()" autocomplete="off">
                            </div>
                            <div class="submit-main" id="sell_display">Selling Price: IDR <?php echo number_format($current_data['sell_price'], 0, ',', '.'); ?></div>
                        <?php endif; ?>

                        <div class="btn-group">
                            <button type="submit" name="update_frame" class="submit-main" style="background: #007bff;">UPDATE DATA</button>
                            <button type="button" class="back-main" onclick="window.location.href='frame_management.php'">CANCEL</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const priceRules = <?php echo file_get_contents("data_json/price_rules.json"); ?>;
        const margins = priceRules.margins;

        function calculatePrice() {
            let buy = parseFloat(document.getElementById('buy_price').value);
            let sell = 0;
            if (!isNaN(buy) && buy > 0) {
                let rule = margins.find(m => buy <= m.max) || margins[margins.length - 1];
                sell = Math.ceil((buy + (buy * (rule.percent / 100))) / 5000) * 5000;
            }
            document.getElementById('sell_display').innerText = "Selling Price: IDR " + sell.toLocaleString('id-ID');
        }

        function toggleNeu(el, hiddenInputId, isColorToggle = false) {
            const val = el.value;
            const parent = el.parentElement;
            parent.querySelectorAll('.neu-btn').forEach(b => b.classList.remove('active'));
            el.classList.add('active');
            document.getElementById(hiddenInputId).value = val;

            if (isColorToggle) {
                document.getElementById('col_name_box').classList.toggle('hidden', val === 'yes');
                document.getElementById('col_manual_box').classList.toggle('hidden', val === 'no');
            }
        }
    </script>
</body>
</html>