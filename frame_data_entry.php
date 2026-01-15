<?php
session_start();
include 'db_config.php';
include 'config_helper.php';
include 'phpqrcode/qrlib.php'; 

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

$role = $_SESSION['role'] ?? 'staff';

function loadJson($file) {
    return json_decode(file_get_contents("data_json/$file"), true);
}

if (isset($_POST['submit_frame'])) {
    $brand = strtoupper($_POST['brand']);
    $f_code = !empty($_POST['frame_code']) ? $_POST['frame_code'] : "lz-786";
    $f_size = !empty($_POST['frame_size']) ? $_POST['frame_size'] : "00-00-786";
    
    // color
    if ($_POST['has_color_code'] == 'no') {
        $colors = loadJson('colors.json');
        $input_color = strtolower($_POST['color_name']);
        if (!isset($colors[$input_color])) {
            $next_col = "col." . (count($colors) + 1);
            $colors[$input_color] = $next_col;
            file_put_contents("data_json/colors.json", json_encode($colors));
        }
        $color_code = $colors[$input_color];
    } else {
        $color_code = $_POST['color_manual_code'];
    }

    // ufc (unique frame code)
    $ufc = str_replace(' ', '', "$brand-$f_code-$f_size-$color_code");

    // stock, default 1
    $input_stock = !empty($_POST['total_frame']) ? (int)$_POST['total_frame'] : 1;

    // price & secret selling price code
    $buy_price = ($role === 'admin') ? (float)$_POST['buy_price'] : 0;
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
            if ($temp_sell >= $val) {
                $secret_code .= $char;
                $temp_sell -= $val;
            }
        }
        $secret_code .= str_pad(($temp_sell / 1000), 2, "0", STR_PAD_LEFT);
    }

    // query: insert or update stoce also overwrite
    $stmt = $conn->prepare("INSERT INTO frame_staging 
        (ufc, brand, frame_code, frame_size, color_code, material, lens_shape, structure, size_range, buy_price, sell_price, price_secret_code, stock) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE 
        brand=VALUES(brand), 
        frame_code=VALUES(frame_code), 
        frame_size=VALUES(frame_size), 
        color_code=VALUES(color_code), 
        material=VALUES(material), 
        lens_shape=VALUES(lens_shape), 
        structure=VALUES(structure), 
        size_range=VALUES(size_range), 
        buy_price=VALUES(buy_price), 
        sell_price=VALUES(sell_price), 
        price_secret_code=VALUES(price_secret_code), 
        stock=stock+VALUES(stock)");
    
    $stmt->bind_param("sssssssssddsi", $ufc, $brand, $f_code, $f_size, $color_code, $_POST['material'], $_POST['lens_shape'], $_POST['structure'], $_POST['size_range'], $buy_price, $sell_price, $secret_code, $input_stock);
    
    if ($stmt->execute()) {
        if (!file_exists('qrcodes')) mkdir('qrcodes');
        QRcode::png($ufc, "qrcodes/$ufc.png", QR_ECLEVEL_L, 4);
        $success = "Data Saved Successfully! UFC: $ufc | Total Stock Added: $input_stock";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frame Entry - <?php echo htmlspecialchars($STORE_NAME); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header class="main-header">
        <div class="header-content">
            <div class="brand-info">
                <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" class="brand-logo">
                <h1 class="brand-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                <p class="store-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p> 
            </div>            
            <a href="logout.php" class="logout-button">Logout</a>
        </div>
    </header>

    <div class="header-section">
        <h1>FRAME DATA ENTRY</h1>
        <p>Logged in as: <?php echo strtoupper($role); ?></p>
    </div>

    <?php if(isset($success)): ?>
        <div class="alert-success" style="text-align:center; color: green; margin-bottom: 20px;">
            <strong><?php echo $success; ?></strong>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST">
            <div class="input-box">
                <label>Frame Brand</label>
                <input type="text" name="brand" required placeholder="e.g. RAYBAN">
            </div>

            <div class="input-box">
                <label>Frame Code</label>
                <input type="text" name="frame_code" placeholder="lz-786">
            </div>

            <div class="input-box">
                <label>Frame Size</label>
                <input type="text" name="frame_size" placeholder="00-00-786">
            </div>

            <div class="input-box">
                <label>Has Color Code?</label>
                <select name="has_color_code" id="color_opt" onchange="toggleColor()">
                    <option value="no">No (Auto-Generate)</option>
                    <option value="yes">Yes (Manual Input)</option>
                </select>
            </div>

            <div id="col_name_box" class="input-box">
                <label>Color Name (Black, Gold, etc)</label>
                <input type="text" name="color_name">
            </div>

            <div id="col_manual_box" class="input-box hidden">
                <label>Manual Color Code</label>
                <input type="text" name="color_manual_code">
            </div>

            <div class="input-box">
                <label>Material</label>
                <select name="material">
                    <?php foreach(loadJson('materials.json') as $m) echo "<option value='$m'>$m</option>"; ?>
                </select>
            </div>

            <div class="input-box">
                <label>Lens Shape</label>
                <select name="lens_shape">
                    <?php foreach(loadJson('shapes.json') as $s) echo "<option value='$s'>$s</option>"; ?>
                </select>
            </div>

            <div class="input-box">
                <label>Frame Structure</label>
                <select name="structure">
                    <option value="full-rim">Full Rim</option>
                    <option value="semi-rimless">Semi Rimless</option>
                    <option value="rimless">Rimless</option>
                </select>
            </div>

            <div class="input-box">
                <label>Size Range</label>
                <select name="size_range">
                    <option value="small">Small</option>
                    <option value="medium">Medium</option>
                    <option value="large">Large</option>
                </select>
            </div>

            <div class="input-box">
                <label>Total Frame (Stock)</label>
                <input type="number" name="total_frame" value="1" min="1" required style="background: #2c3e50; color: white; border: 1px solid #72ad46;">
            </div>

            <?php if ($role === 'admin'): ?>
            <div class="input-box">
                <label>Cost Price (IDR)</label>
                <input type="number" name="buy_price" id="buy_price" oninput="calculatePrice()">
                <div class="price-display" id="sell_display">Selling Price: IDR 0</div>
            </div>
            <?php endif; ?>

            <button type="submit" name="submit_frame" class="inv_btn_red">SAVE DATA</button>
            <button onclick="window.location.href='manage_settings.php'" class="inv_btn_blue">UPDATE SETTINGS</button>
            <p style="margin-top: 40px;"><a href="index.php" class="link-back">Back to Main Menu</a></p>
        </form>
    </div>

    <script>
    function toggleColor() {
        var opt = document.getElementById('color_opt').value;
        document.getElementById('col_name_box').classList.toggle('hidden', opt === 'yes');
        document.getElementById('col_manual_box').classList.toggle('hidden', opt === 'no');
    }

    function calculatePrice() {
        var buy = document.getElementById('buy_price').value;
        var sell = 0;
        if (buy < 20000) sell = buy * 4.2;
        else if (buy <= 55000) sell = buy * 4.5;
        else if (buy <= 65000) sell = buy * 4.8;
        else if (buy <= 90000) sell = buy * 5.0;
        else sell = buy * 6.0;

        sell = Math.ceil(sell / 5000) * 5000;
        document.getElementById('sell_display').innerText = "Selling Price: IDR " + sell.toLocaleString();
    }
    </script>

    <footer>
        <p><?php echo $COPYRIGHT_FOOTER; ?></p>
    </footer>

</body>
</html>