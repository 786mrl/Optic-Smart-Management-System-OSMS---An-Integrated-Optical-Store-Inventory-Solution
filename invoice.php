<?php
    session_start();
    include 'db_config.php';
    include 'config_helper.php';

    if (isset($_POST['save_modification'])) {
        $inv = mysqli_real_escape_string($conn, $_POST['invoice_number']);
        
        // Fetch input data
        $od_sph = mysqli_real_escape_string($conn, $_POST['od_sph']);
        $od_cyl = mysqli_real_escape_string($conn, $_POST['od_cyl']);
        $od_axis = mysqli_real_escape_string($conn, $_POST['od_axis']);
        $od_add = mysqli_real_escape_string($conn, $_POST['od_add']);
        $os_sph = mysqli_real_escape_string($conn, $_POST['os_sph']);
        $os_cyl = mysqli_real_escape_string($conn, $_POST['os_cyl']);
        $os_axis = mysqli_real_escape_string($conn, $_POST['os_axis']);
        $os_add = mysqli_real_escape_string($conn, $_POST['os_add']);
    
        // Save to modification table
        $sql_mod = "INSERT INTO prescription_modifications (invoice_number, od_sph, od_cyl, od_axis, od_add, os_sph, os_cyl, os_axis, os_add) 
                    VALUES ('$inv', '$od_sph', '$od_cyl', '$od_axis', '$od_add', '$os_sph', '$os_cyl', '$os_axis', '$os_add')";
        
        // Update status in customer_examinations table
        $sql_update = "UPDATE customer_examinations SET lens_modification = 1 WHERE invoice_number = '$inv'";
    
        if (mysqli_query($conn, $sql_mod) && mysqli_query($conn, $sql_update)) {
            header("Location: invoice.php?inv=$inv&status=success");
            exit();
        }
    }

    if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }

    // Check 'inv' parameter (manual) or 'code' (from customer_prescription.php)
    $invoice_num = $_GET['inv'] ?? $_GET['code'] ?? '';
    $invoice_num = mysqli_real_escape_string($conn, $invoice_num);

    if (empty($invoice_num) || $invoice_num === '00' || $invoice_num === '000') {
        die("
            <div style='background:#1a1c1d; color:#888; height:100vh; display:flex; align-items:center; justify-content:center; font-family:sans-serif; flex-direction:column;'>
                <h2 style='color:#00ff88;'>NO PURCHASE FOUND</h2>
                <p>Invoice '$invoice_num' is invalid or represents an examination only.</p>
                <a href='customer.php' style='color:#00ff88; text-decoration:none; border:1px solid #00ff88; padding:10px 20px; border-radius:10px;'>Back to List</a>
            </div>
        ");
    }

    // Query customer_examinations using 'invoice_number' column
    $query = "SELECT ce.*, 
            pm.od_sph AS mod_r_sph, pm.od_cyl AS mod_r_cyl, pm.od_axis AS mod_r_ax, pm.od_add AS mod_r_add,
            pm.os_sph AS mod_l_sph, pm.os_cyl AS mod_l_cyl, pm.os_axis AS mod_l_ax, pm.os_add AS mod_l_add
            FROM customer_examinations ce
            LEFT JOIN prescription_modifications pm ON ce.invoice_number = pm.invoice_number
            WHERE ce.invoice_number = '$invoice_num' 
            LIMIT 1";

    $result = mysqli_query($conn, $query);
    $data = mysqli_fetch_assoc($result);

    if (!$data) {
        die("
            <div style='background:#1a1c1d; color:#ff4d4d; height:100vh; display:flex; align-items:center; justify-content:center; font-family:sans-serif;'>
                Invoice data for <b>$invoice_num</b> was not found in the database.
            </div>
        ");
    }

    // ============================================================
    // LENS RECOMMENDATION ENGINE v4
    // Step 1 — check Rx fits limits (HARD FILTER)
    // Step 2 — score by habits / digital / symptoms / design
    // Step 3 — sort: stock first (within same score tier), then lab
    // Step 4 — show top 5, expand button for rest
    // ============================================================

    // ── Active prescription ───────────────────────────────────
    $lr_rMod = ($data['lens_modification'] == 1);

    // lrVal: DB may store as "-900" (shorthand) or "-9.00" (full)
    // If |value| >= 10 it's shorthand × 100 → divide back to diopters
    function lrVal($data, $modKey, $origKey) {
        $raw = ($data['lens_modification'] == 1 && isset($data[$modKey]) && $data[$modKey] !== '')
               ? $data[$modKey] : $data[$origKey];
        $val = floatval(str_replace('+', '', $raw ?? '0'));
        if (abs($val) >= 10) $val /= 100.0;
        return round($val, 2);
    }
    $lr_r_sph = lrVal($data, 'mod_r_sph', 'new_r_sph');
    $lr_r_cyl = lrVal($data, 'mod_r_cyl', 'new_r_cyl');
    $lr_r_add = lrVal($data, 'mod_r_add', 'new_r_add');
    $lr_l_sph = lrVal($data, 'mod_l_sph', 'new_l_sph');
    $lr_l_cyl = lrVal($data, 'mod_l_cyl', 'new_l_cyl');
    $lr_l_add = lrVal($data, 'mod_l_add', 'new_l_add');

    // ── Patient context ───────────────────────────────────────
    $lr_age     = (int)($data['age']           ?? 0);
    $lr_habit   = (int)($data['visual_habit']  ?? 1); // 1=indoor 2=outdoor 3=both
    $lr_digital = (int)($data['digital_usage'] ?? 1); // 1=low 2=moderate 3=high
    $lr_txt     = strtolower(($data['symptoms'] ?? '') . ' ' . ($data['exam_notes'] ?? ''));

    // Vision need (only populated when age >= 39)
    $lr_needDist  = (int)($data['need_distance']     ?? 0);
    $lr_needInter = (int)($data['need_intermediate'] ?? 0);
    $lr_needNear  = (int)($data['need_near']         ?? 0);

    // ── Derived Rx metrics ────────────────────────────────────
    $lr_maxSph = max(abs($lr_r_sph), abs($lr_l_sph));
    $lr_maxCyl = max(abs($lr_r_cyl), abs($lr_l_cyl));
    $lr_maxAdd = max(abs($lr_r_add), abs($lr_l_add));
    $lr_seR    = abs($lr_r_sph) + abs($lr_r_cyl) / 2.0;
    $lr_seL    = abs($lr_l_sph) + abs($lr_l_cyl) / 2.0;
    $lr_maxSE  = max($lr_seR, $lr_seL);

    // ── Power flags ───────────────────────────────────────────
    $lr_isHighPow     = ($lr_maxSE >= 4.0 || $lr_maxCyl >= 2.0);
    $lr_isVeryHighPow = ($lr_maxSE >= 6.0 || $lr_maxCyl >= 3.0);

    // Presbyopia: ADD present AND age ≥ 39
    $lr_isPresbyopia = ($lr_maxAdd >= 0.75 && $lr_age >= 39);

    // ── Symptom flags ─────────────────────────────────────────
    $lr_hasGlare     = (bool)preg_match('/glare|silau/', $lr_txt);
    $lr_hasEyeStrain = (bool)preg_match('/eye.?strain|mata.?lelah|\blelah\b/', $lr_txt);
    $lr_hasHeadache  = (bool)preg_match('/headache|sakit.?kepala/', $lr_txt);
    $lr_hasDM        = (strpos($lr_txt, 'diabetes')     !== false);
    $lr_hasHT        = (strpos($lr_txt, 'hypertension') !== false);
    $lr_hasDryEye    = (bool)preg_match('/dry.?eye|mata.?kering/', $lr_txt);
    $lr_hasDriving   = (bool)preg_match('/bawa.?mobil|mengemudi|driving|berkendara/', $lr_txt);
    $lr_hasImpact    = (bool)preg_match('/olahraga|sport|bentur|impact/', $lr_txt);

    // ── Load catalog ──────────────────────────────────────────
    $lr_catalog  = [];
    $lr_jsonPath = __DIR__ . '/data_json/lense_prices.json';
    if (is_readable($lr_jsonPath)) {
        $raw = @file_get_contents($lr_jsonPath);
        if ($raw !== false) $lr_catalog = json_decode($raw, true) ?? [];
    }

    // ── Presbyopia design type ────────────────────────────────
    // Priority: explicit vision_need > symptom keywords > habits
    // Returns: 'all_distance' | 'dynamic' | 'far_near' | 'near' | 'far_only'
    function lr_presbyDesign($needDist, $needInter, $needNear, $habit, $digital, $txt) {
        $anySet = ($needDist || $needInter || $needNear);
        if ($anySet) {
            if ( $needDist &&  $needInter &&  $needNear) return 'all_distance';
            if ( $needDist &&  $needInter && !$needNear) return 'dynamic';
            if ( $needDist && !$needInter &&  $needNear) return 'far_near';
            if (!$needDist &&  $needInter &&  $needNear) return 'near';
            if (!$needDist && !$needInter &&  $needNear) return 'near';
            if ( $needDist && !$needInter && !$needNear) return 'far_only'; // SV only
            if (!$needDist &&  $needInter && !$needNear) return 'dynamic';
        }
        if (preg_match('/baca|membaca|jahit|sewing|close.?work|near.?work/', $txt)) return 'near';
        if (preg_match('/mengemudi|bawa.?mobil|driving|berkendara/', $txt))          return 'far_near';
        if ($habit == 3 || ($digital >= 2 && $habit >= 2)) return 'all_distance';
        if ($habit == 2) return 'far_near';
        return 'far_near';
    }
    $lr_presbyType  = $lr_isPresbyopia
        ? lr_presbyDesign($lr_needDist, $lr_needInter, $lr_needNear, $lr_habit, $lr_digital, $lr_txt)
        : '';
    $lr_farOnlySV   = ($lr_isPresbyopia && $lr_presbyType === 'far_only');

    // ============================================================
    // STEP 1 — Rx fit check
    // JSON limits are in 1/100 D integers (e.g. -800 = -8.00 D).
    // Prescription values are floats in diopters (e.g. -9.00 D).
    //
    // Special cases for CYL:
    //   cyl_from=0, cyl_to=0  → lens accepts NO cylinder (plano-only)
    //   cyl_from=-25, cyl_to=-200 → lens accepts CYL -0.25 to -2.00
    // ============================================================
    function lr_rxFits($r_sph, $r_cyl, $l_sph, $l_cyl, $r_add, $l_add, $lim) {
        $maxS   = max(abs($r_sph), abs($l_sph));
        $maxC   = max(abs($r_cyl), abs($l_cyl));
        $maxAdd = max(abs($r_add), abs($l_add));

        // ── Sphere check ─────────────────────────────────────
        // sph_to=0 means no restriction
        $limSph = ($lim['sph_to'] != 0) ? abs($lim['sph_to']) / 100.0 : 0.0;
        if ($limSph > 0.0 && $maxS > $limSph) return false;

        // ── Cylinder check ────────────────────────────────────
        // cyl_from=0 AND cyl_to=0 → lens only accepts plano (no CYL at all)
        // cyl_to != 0              → lens accepts CYL up to abs(cyl_to)/100
        if ($lim['cyl_from'] == 0 && $lim['cyl_to'] == 0) {
            // Plano-only lens: reject any cylinder
            if ($maxC > 0.0) return false;
        } elseif ($lim['cyl_to'] != 0) {
            $limCyl = abs($lim['cyl_to']) / 100.0;
            if ($maxC > $limCyl) return false;
        }

        // ── Combined SPH+CYL check ────────────────────────────
        // comb_max=0 means no restriction
        $limComb = ($lim['comb_max'] != 0) ? abs($lim['comb_max']) / 100.0 : 0.0;
        if ($limComb > 0.0 && ($maxS + $maxC) > $limComb) return false;

        // ── ADD range check ───────────────────────────────────
        // add_to=0 means this lens has no ADD requirement
        if ($lim['add_to'] != 0) {
            $limAddMin = abs($lim['add_from']) / 100.0;
            $limAddMax = abs($lim['add_to'])   / 100.0;
            if ($maxAdd < $limAddMin || $maxAdd > $limAddMax) return false;
        }
        return true;
    }

    // ── Lens category gate ────────────────────────────────────
    // Returns true when the lens category is allowed for this patient.
    function lr_catAllowed($category, $isPresby, $farOnlySV) {
        $cat    = strtoupper(trim($category));
        $isSV   = ($cat === 'SINGLE VISION');
        $isProg = in_array($cat, ['PROGRESSIVE', 'KRYPTOK', 'FLATTOP']);
        if ($farOnlySV) return $isSV;          // presbyopia but only needs far → SV
        if ($isPresby)  return $isProg;         // presbyopia → progressive only
        return $isSV;                           // normal → SV only
    }

    // ============================================================
    // STEP 2 — Relevance score (lifestyle + symptoms + design)
    // Higher = better match. Stock/lab weighting done in step 3.
    // ============================================================
    function lr_score($features, $category, $isPresby, $presbyType, $farOnlySV,
                      $habit, $digital, $maxSE, $maxCyl, $age,
                      $hasGlare, $hasEyeStrain, $hasHeadache, $hasDryEye,
                      $hasDriving, $hasImpact) {
        $s   = 0;
        $cat = strtoupper(trim($category));

        // ── Progressive design match ─────────────────────────
        if ($isPresby && !$farOnlySV) {
            $hasAllDist = in_array('ALL-DISTANCE PROGRESSIVE', $features);
            $hasFarNear = in_array('FAR & NEAR OPTIMIZED LENS', $features);
            $hasDynamic = in_array('DYNAMIC DISTANCE LENS',     $features);
            $hasNearOpt = in_array('NEAR-OPTIMIZED LENS',       $features);
            $hasEnhNear = in_array('ENHANCED NEAR VISION',      $features);

            $designScores = [
                'all_distance' => [$hasAllDist=>40, $hasFarNear=>25, $hasDynamic=>15,
                                   ($hasNearOpt||$hasEnhNear)=>5],
                'dynamic'      => [$hasDynamic=>40, $hasAllDist=>30, $hasFarNear=>20,
                                   ($hasNearOpt||$hasEnhNear)=>5],
                'far_near'     => [$hasFarNear=>40, $hasAllDist=>28, $hasDynamic=>15,
                                   ($hasNearOpt||$hasEnhNear)=>5],
                'near'         => [$hasEnhNear=>40, $hasNearOpt=>35, $hasFarNear=>15,
                                   $hasAllDist=>10],
            ];
            if (isset($designScores[$presbyType])) {
                foreach ($designScores[$presbyType] as $matches => $pts) {
                    if ($matches) { $s += $pts; break; }
                }
            } else {
                if ($hasAllDist)  $s += 30;
                elseif ($hasFarNear) $s += 25;
            }
            if ($cat === 'KRYPTOK' || $cat === 'FLATTOP') $s += ($age >= 65) ? 10 : 2;
        }

        // ── Feature × lifestyle ──────────────────────────────
        foreach ($features as $feat) {
            switch (strtoupper(trim($feat))) {
                case 'BLUE LIGHT BLOCKING':
                    if ($digital == 3)     $s += 28;
                    elseif ($digital == 2) $s += 15;
                    if (($hasEyeStrain || $hasHeadache) && $digital >= 2) $s += 8;
                    if ($hasDryEye && $digital >= 2) $s += 4;
                    break;
                case 'PHOTOCHROMIC':
                    if ($habit == 2)     $s += 22;
                    elseif ($habit == 3) $s += 14;
                    else                 $s -= 12;
                    if ($hasGlare && $habit >= 2) $s += 10;
                    break;
                case 'NIGHT DRIVE COATING':
                    if ($hasDriving)     $s += 20;
                    elseif ($habit >= 2) $s += 8;
                    else                 $s -= 10;
                    break;
                case 'HIGH INDEX 1.67':
                case 'HIGHT INDEX 1.67':
                    if ($maxSE >= 6.0)     $s += 35;
                    elseif ($maxSE >= 4.0) $s += 22;
                    elseif ($maxSE >= 2.0) $s += 10;
                    break;
                case 'HIGH-INDEX UV400 PROTECTION':
                    $s += 4;
                    if ($habit >= 2) $s += 5;
                    break;
                case 'HIGH POWER RX':
                    if ($maxSE >= 8.0)     $s += 35;
                    elseif ($maxSE >= 6.0) $s += 22;
                    elseif ($maxSE >= 4.0) $s += 8;
                    break;
                case 'IMPACT-RESISTANT':
                    if ($hasImpact)      $s += 20;
                    elseif ($habit >= 2) $s += 6;
                    break;
                case 'SUPER HYDROPHOBIC': $s += ($habit >= 2) ? 8 : 2;  break;
                case 'HYDROPHOBIC':       $s += ($habit >= 2) ? 5 : 1;  break;
                case 'SMUDGE-RESISTANT':  $s += 3;  break;
                case 'ANTI-STATIC':       $s += 2;  break;
                case 'SCRATCH-RESISTANT COATING': $s += ($habit >= 2) ? 4 : 1; break;
                case 'ANTI-REFLECTIVE (AR) COATING':
                    if ($hasGlare)                          $s += 12;
                    elseif ($hasEyeStrain || $digital >= 2) $s += 6;
                    else                                    $s += 2;
                    break;
                case 'UV PROTECTION': $s += ($habit >= 2) ? 5 : 0; break;
                // progressive design features scored above — skip here
                case 'ALL-DISTANCE PROGRESSIVE':
                case 'FAR & NEAR OPTIMIZED LENS':
                case 'DYNAMIC DISTANCE LENS':
                case 'NEAR-OPTIMIZED LENS':
                case 'ENHANCED NEAR VISION':
                    break;
            }
        }
        return $s;
    }

    // ============================================================
    // STEP 3 — Build candidate list: fit → score → sort
    // Sort key: stock before lab (within same score band),
    //           then descending score, then ascending price.
    // ============================================================
    $lr_candidates = [];

    foreach ($lr_catalog as $source => $categories) {
        $readiness = ($source === 'stock') ? 'Ready in 2 Days' : 'Lab Order, Ready 7-10 Days';
        foreach ($categories as $category => $types) {

            // Gate: skip categories not allowed for this patient type
            if (!lr_catAllowed($category, $lr_isPresbyopia, $lr_farOnlySV)) continue;

            foreach ($types as $type => $lens) {
                $lim      = $lens['limits']   ?? [];
                $features = $lens['features'] ?? [];
                $selling  = (int)($lens['selling'] ?? 0);
                $lensNote = $lens['limits']['note'] ?? '';

                // HARD FILTER 1: Rx must fit limits
                if (!empty($lim) && !lr_rxFits(
                        $lr_r_sph, $lr_r_cyl, $lr_l_sph, $lr_l_cyl,
                        $lr_r_add, $lr_l_add, $lim)) continue;

                // HARD FILTER 2: skip high-index/lenticular if power too low to benefit
                $isHiIdx = in_array('HIGH INDEX 1.67', $features)
                        || in_array('HIGHT INDEX 1.67', $features)
                        || in_array('HIGH POWER RX',    $features);
                if ($isHiIdx && $lr_maxSE < 3.0 && $lr_maxCyl < 3.0) continue;

                $score = lr_score(
                    $features, $category,
                    $lr_isPresbyopia, $lr_presbyType, $lr_farOnlySV,
                    $lr_habit, $lr_digital,
                    $lr_maxSE, $lr_maxCyl, $lr_age,
                    $lr_hasGlare, $lr_hasEyeStrain, $lr_hasHeadache,
                    $lr_hasDryEye, $lr_hasDriving, $lr_hasImpact
                );

                $lr_candidates[] = [
                    'source'    => $source,         // 'stock' | 'lab'
                    'category'  => $category,
                    'type'      => $type,
                    'selling'   => $selling,
                    'features'  => $features,
                    'note'      => $lensNote,
                    'score'     => $score,
                    'readiness' => $readiness,
                ];
            }
        }
    }

    // Sort: stock first within same score band, then score desc, then price asc
    usort($lr_candidates, function($a, $b) {
        // Primary: score descending
        if ($b['score'] !== $a['score']) return ($b['score'] > $a['score']) ? 1 : -1;
        // Secondary: stock beats lab
        $aStock = ($a['source'] === 'stock') ? 0 : 1;
        $bStock = ($b['source'] === 'stock') ? 0 : 1;
        if ($aStock !== $bStock) return $aStock - $bStock;
        // Tertiary: price ascending
        return $a['selling'] - $b['selling'];
    });

    // ── Special warning notes ─────────────────────────────────
    $lr_specialNotes = [];
    if ($lr_hasDM)
        $lr_specialNotes[] = ['🩸', 'DIABETES — Higher cataract risk. UV protection & blue light blocking lens recommended.'];
    if ($lr_hasHT)
        $lr_specialNotes[] = ['❤️', 'HYPERTENSION — Monitor vision changes regularly.'];
    if ($lr_isVeryHighPow)
        $lr_specialNotes[] = ['⚡', 'Very high prescription (SE ≥ 6.00D) — High Index 1.67 or Lenticular lens strongly advised.'];
    if ($lr_isPresbyopia && $lr_farOnlySV)
        $lr_specialNotes[] = ['👓', 'Distance only needed — SINGLE VISION lens recommended.'];
    elseif ($lr_isPresbyopia && $lr_presbyType === 'all_distance')
        $lr_specialNotes[] = ['👁️', 'Presbyopia: all distances needed — ALL-DISTANCE PROGRESSIVE is the best fit.'];
    elseif ($lr_isPresbyopia && $lr_presbyType === 'dynamic')
        $lr_specialNotes[] = ['🚀', 'Presbyopia: dominant far & intermediate — DYNAMIC DISTANCE LENS is the best fit.'];
    elseif ($lr_isPresbyopia && $lr_presbyType === 'far_near')
        $lr_specialNotes[] = ['👓', 'Presbyopia: dominant far & near — FAR & NEAR OPTIMIZED lens recommended.'];
    elseif ($lr_isPresbyopia && $lr_presbyType === 'near')
        $lr_specialNotes[] = ['📚', 'Presbyopia: dominant near — ENHANCED NEAR VISION / SHORT CORD lens is the best fit.'];
    if ($lr_hasEyeStrain || $lr_hasHeadache)
        $lr_specialNotes[] = ['😣', 'Eye strain / headache complaints — Blue Light Blocking lens may help.'];
    if ($lr_hasDryEye)
        $lr_specialNotes[] = ['💧', 'Dry Eye — Blue Light Blocking & Super Hydrophobic lens reduces screen irritation.'];
    if ($lr_hasDriving)
        $lr_specialNotes[] = ['🚗', 'Frequent driving — Night Drive Coating & Photochromic lens strongly advised.'];

    // Helper: format price
    function lr_fmt_price($v) {
        if ((int)$v <= 0) return '<span style="color:#555;font-size:9.5px;font-style:italic;">Contact Staff</span>';
        return 'Rp&nbsp;' . number_format((int)$v, 0, ',', '.');
    }

    // ── Helper: bucket a list into 4 price tabs ───────────────
    function lr_priceBuckets($list) {
        return [
            'rekomendasi' => ['label'=>'★ REKOMENDASI', 'data'=>$list,  'color'=>'#ffaa00', 'limit'=>5],
            'normal'      => ['label'=>'NORMAL', 'data'=>array_values(array_filter($list, function($c){ return $c['selling'] > 0      && $c['selling'] <= 600000;  })), 'color'=>'#00ff88', 'limit'=>999],
            'sedang'      => ['label'=>'SEDANG', 'data'=>array_values(array_filter($list, function($c){ return $c['selling'] > 600000  && $c['selling'] <= 1000000; })), 'color'=>'#00cfff', 'limit'=>999],
            'tinggi'      => ['label'=>'TINGGI', 'data'=>array_values(array_filter($list, function($c){ return $c['selling'] > 1000000; })),                           'color'=>'#ff8a4d', 'limit'=>999],
        ];
    }

    // ── Design-need match ─────────────────────────────────────
    // Returns true when $cand satisfies the patient's vision need.
    // Kryptok & Flattop are bifocals — they implicitly cover far+near needs.
    function lr_meetsDesign($cand, $wantedFeats, $presbyType) {
        if (empty($wantedFeats) && $presbyType !== 'far_only') return false;
        $cat    = strtoupper(trim($cand['category']));
        $isProg = in_array($cat, array('PROGRESSIVE','KRYPTOK','FLATTOP'));
        if (!$isProg) return false;
        if ($cat === 'KRYPTOK' || $cat === 'FLATTOP') {
            return in_array($presbyType, array('far_near','far_only','all_distance','dynamic'));
        }
        foreach ($wantedFeats as $wf) {
            if (in_array($wf, $cand['features'])) return true;
        }
        return false;
    }

    // ── Vision-need design features ───────────────────────────
    $lr_designFeatureMap = [
        'all_distance' => ['ALL-DISTANCE PROGRESSIVE'],
        'dynamic'      => ['DYNAMIC DISTANCE LENS','ALL-DISTANCE PROGRESSIVE'],
        'far_near'     => ['FAR & NEAR OPTIMIZED LENS','ALL-DISTANCE PROGRESSIVE'],
        'near'         => ['ENHANCED NEAR VISION','NEAR-OPTIMIZED LENS','ALL-DISTANCE PROGRESSIVE'],
        'far_only'     => [],
    ];
    $lr_wantedDesignFeats = ($lr_isPresbyopia && isset($lr_designFeatureMap[$lr_presbyType]))
        ? $lr_designFeatureMap[$lr_presbyType] : [];

    // ── Lens type config ──────────────────────────────────────
    $lr_typeConfig = [
        'sv'          => ['label'=>'SINGLE VISION', 'icon'=>'👓', 'color'=>'#00ff88'],
        'kryptok'     => ['label'=>'KRYPTOK',       'icon'=>'🔵', 'color'=>'#00cfff'],
        'progressive' => ['label'=>'PROGRESSIVE',   'icon'=>'🔭', 'color'=>'#aa88ff'],
        'flattop'     => ['label'=>'FLAT TOP',      'icon'=>'📐', 'color'=>'#ff8a4d'],
    ];

    // ── Split candidates by lens type ─────────────────────────
    $lr_byType = [];
    foreach ($lr_candidates as $c) {
        $cat = strtoupper(trim($c['category']));
        if      ($cat === 'SINGLE VISION') $key = 'sv';
        elseif  ($cat === 'KRYPTOK')       $key = 'kryptok';
        elseif  ($cat === 'PROGRESSIVE')   $key = 'progressive';
        elseif  ($cat === 'FLATTOP')       $key = 'flattop';
        else                               $key = 'sv';
        $lr_byType[$key][] = $c;
    }

    // First active type
    $lr_firstType = '';
    foreach ($lr_typeConfig as $tk => $tc) {
        if (!empty($lr_byType[$tk])) { $lr_firstType = $tk; break; }
    }

    // ── Pre-compute design match per type+price ───────────────
    $lr_hasDesign = array();
    foreach ($lr_typeConfig as $tk => $tc) {
        $lr_hasDesign[$tk] = array();
        $tList = isset($lr_byType[$tk]) ? $lr_byType[$tk] : [];
        $typeHas = false;
        foreach ($tList as $c) {
            if (lr_meetsDesign($c, $lr_wantedDesignFeats, $lr_presbyType)) { $typeHas = true; break; }
        }
        $lr_hasDesign[$tk]['_type'] = $typeHas;
        $buckets = lr_priceBuckets($tList);
        foreach ($buckets as $pk => $pb) {
            $has = false;
            foreach ($pb['data'] as $c) {
                if (lr_meetsDesign($c, $lr_wantedDesignFeats, $lr_presbyType)) { $has = true; break; }
            }
            $lr_hasDesign[$tk][$pk] = $has;
        }
    }


    // Load frame-shape color mapping (optional — fails silently if missing/invalid)
    $frameShapeColors = [];
    $colorJsonPath = __DIR__ . '/data_json/color_shape.json';
    if (is_readable($colorJsonPath)) {
        $raw = @file_get_contents($colorJsonPath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                // Normalize keys to uppercase for consistent JS lookup
                foreach ($decoded as $k => $v) {
                    $frameShapeColors[strtoupper(trim($k))] = $v;
                }
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
        <title>Invoice - <?php echo $data['examination_code']; ?></title>
        <link rel="stylesheet" href="style.css">
        <style  id="mediapipe-styles">
            .mp-wrapper {
                position: relative;
                width: 300px;
                height: 400px;
                margin: 0 auto;
                border-radius: 20px;
                overflow: hidden;
                background: #000;
                -webkit-mask-image: -webkit-radial-gradient(white, black);
            }

            #mp-video {
                width: 100%;
                height: 100%;
                object-fit: cover;
                transform: scaleX(1); /* default: not mirrored (back camera) */
            }
            .mp-wrapper.mirror #mp-video { transform: scaleX(-1); } /* front camera only */

            #mp-canvas {
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                height: 100%;
                transform: scaleX(1); /* default: not mirrored (back camera) */
                pointer-events: none;
            }
            .mp-wrapper.mirror #mp-canvas { transform: scaleX(-1); } /* front camera only */

            .mp-guide {
                position: absolute;
                top: 0; left: 0;
                width: 100%;
                height: 100%;
                z-index: 20;
                pointer-events: none;
                background: rgba(0,0,0,0.35);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
                -webkit-mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                transition: opacity 0.4s;
            }

            .mp-guide::after {
                content: "";
                position: absolute;
                top: 50%; left: 50%;
                transform: translate(-50%, -50%);
                width: 60%;
                height: 90%;
                border: 2px solid #00ff88;
                border-radius: 50% 50% 50% 50% / 45% 45% 55% 55%;
                box-shadow: 0 0 15px rgba(0,255,136,0.5), inset 0 0 10px rgba(0,255,136,0.2);
                transition: border-color 0.3s, box-shadow 0.3s;
            }

            .mp-guide.locked::after {
                border-color: #00cfff;
                box-shadow: 0 0 20px rgba(0,207,255,0.6), inset 0 0 12px rgba(0,207,255,0.2);
            }

            /* Confidence bar */
            .conf-bar-wrap {
                width: 100%;
                height: 6px;
                background: rgba(255,255,255,0.1);
                border-radius: 3px;
                margin-top: 8px;
                overflow: hidden;
            }
            .conf-bar-fill {
                height: 100%;
                border-radius: 3px;
                background: linear-gradient(90deg, #00ff88, #00cfff);
                transition: width 0.4s ease;
            }

            #mp-result {
                min-height: 90px;
                transition: all 0.3s ease;
                border: 1px solid rgba(0,255,136,0.2);
                margin-top: 15px !important;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 15px;
            }

            .shape-badge {
                font-size: 1.4rem;
                font-weight: 800;
                letter-spacing: 2px;
                color: #00ff88;
                text-shadow: 0 0 12px rgba(0,255,136,0.5);
            }

            .metrics-row {
                display: flex;
                gap: 10px;
                margin-top: 8px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .metric-chip {
                font-size: 10px;
                color: #888;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 20px;
                padding: 3px 8px;
            }

            .pd-row {
                display: flex;
                gap: 8px;
                margin-top: 10px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .pd-chip {
                font-size: 11px;
                font-weight: 700;
                color: #00cfff;
                background: rgba(0, 207, 255, 0.08);
                border: 1px solid rgba(0, 207, 255, 0.25);
                border-radius: 20px;
                padding: 4px 10px;
                letter-spacing: 0.5px;
            }
            .pd-chip.total {
                color: #fff;
                background: rgba(0, 207, 255, 0.18);
                border-color: rgba(0, 207, 255, 0.5);
                font-size: 12px;
            }
            .pd-note {
                font-size: 9px;
                color: #444;
                margin-top: 4px;
                text-align: center;
            }

            /* IOC preset buttons */
            .ioc-preset {
                background: var(--bg-color);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 20px;
                color: #555;
                font-size: 9px;
                padding: 4px 10px;
                cursor: pointer;
                font-family: inherit;
                letter-spacing: 0.5px;
                transition: all 0.2s;
            }
            .ioc-preset:hover { color: #888; border-color: rgba(0,255,136,0.2); }
            .ioc-preset.active {
                color: #00ff88;
                border-color: rgba(0,255,136,0.4);
                background: rgba(0,255,136,0.07);
            }

            /* Step indicator */
            .mp-step {
                display: flex;
                align-items: center;
                gap: 5px;
                font-size: 9px;
                letter-spacing: 1px;
                color: #444;
                padding: 4px 8px;
                border-radius: 20px;
                border: 1px solid rgba(255,255,255,0.05);
                background: rgba(255,255,255,0.03);
                transition: all 0.3s;
            }
            .mp-step.active {
                color: #00ff88;
                border-color: rgba(0,255,136,0.3);
                background: rgba(0,255,136,0.06);
            }
            .mp-step.done {
                color: #00cfff;
                border-color: rgba(0,207,255,0.2);
            }
            .mp-step .step-num {
                width: 16px;
                height: 16px;
                border-radius: 50%;
                background: rgba(255,255,255,0.06);
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 700;
                font-size: 9px;
            }
            .mp-step.active .step-num { background: rgba(0,255,136,0.2); }
            .step-arrow { color: #333; font-size: 14px; line-height: 1; }

            /* Loading state */
            .mp-loading {
                display: flex;
                align-items: center;
                gap: 8px;
                color: var(--text-muted);
                font-size: 0.75rem;
            }
            .spinner {
                width: 14px; height: 14px;
                border: 2px solid rgba(0,255,136,0.2);
                border-top-color: #00ff88;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
            }
            @keyframes spin { to { transform: rotate(360deg); } }

            @media (max-width: 600px) {
                .mp-wrapper { width: 100%; height: 350px; }
            }
            .invoice-body { padding: 20px; max-width: 800px; margin: auto; }
            .neumorph-card {
                background: var(--bg-color);
                padding: 30px;
                border-radius: 25px;
                box-shadow: 20px 20px 60px var(--shadow-dark), -20px -20px 60px var(--shadow-light);
            }
            .info-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-top: 20px;
            }
            .read-only-box {
                background: var(--bg-color);
                padding: 12px 15px;
                border-radius: 12px;
                color: var(--accent-color);
                box-shadow: inset 4px 4px 8px var(--shadow-dark), inset -4px -4px 8px var(--shadow-light);
                min-height: 45px;
                display: flex;
                align-items: center;
                font-weight: 600;
            }
            label { color: var(--text-muted); font-size: 0.8rem; margin-left: 5px; margin-bottom: 5px; display: block; }
            .full { grid-column: span 2; }

            /* Table Neumorphic Style */
            .prescription-container {
                background: var(--bg-color);
                padding: 30px;
                border-radius: 25px;
                box-shadow: 8px 8px 16px var(--shadow-dark), -8px -8px 16px var(--shadow-light);
                margin-top: 20px;
                border: 1px solid rgba(255,255,255,0.05);
            }

            .selection-wrapper {
                display: flex;
                gap: 15px;
                justify-content: center; /* Button centered within the container */
            }

            .prescription-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 15px 10px; /* Provides spacing between cells */
            }

            .prescription-table th {
                color: var(--text-muted);
                font-size: 0.7rem;
                letter-spacing: 1px;
                text-transform: uppercase;
                padding-bottom: 10px;
            }

            .prescription-table td {
                padding: 0;
            }

            .input-table-neu {
                width: 100%;
                background: var(--bg-color);
                border: none;
                padding: 15px 5px;
                border-radius: 15px;
                color: var(--text-main);
                text-align: center;
                font-weight: 700;
                font-size: 1rem;
                /* Characteristic Neumorphic inset (concave) effect */
                box-shadow: inset 6px 6px 12px var(--shadow-dark), 
                            inset -6px -6px 12px var(--shadow-light);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .input-table-neu:not([readonly]) {
                color: #00ff88; /* Neon green for contrast during editing */
                text-shadow: 0 0 8px rgba(0, 255, 136, 0.3);
            }

            .input-table-neu:focus {
                outline: none;
                color: var(--accent-color);
            }

            .eye-indicator {
                width: 45px;
                height: 45px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                background: var(--bg-color);
                font-weight: 800;
                color: var(--accent-color);
                box-shadow: 4px 4px 8px var(--shadow-dark), -4px -4px 8px var(--shadow-light);
                border: 1px solid rgba(255,255,255,0.05);
            }

            .eye-label {
                vertical-align: middle;
            }

            /* Scan Line Animation */
            .scan-line {
                position: absolute;
                width: 100%;
                height: 4px;
                background: rgba(0, 255, 136, 0.5);
                box-shadow: 0 0 15px #00ff88;
                top: 0;
                left: 0;
                z-index: 10;
                display: none;
                animation: scanMove 2s linear infinite;
            }

            @keyframes scanMove {
                0% { top: 0; }
                100% { top: 100%; }
            }

            .video-wrapper {
                position: relative;
                width: 300px; /* Standard mobile size to prevent overload */
                height: 400px; /* Portrait ratio is better for face tracking on mobile */
                margin: 0 auto;
                overflow: hidden;
                border-radius: 20px;
                background: #000;
                -webkit-mask-image: -webkit-radial-gradient(white, black); /* Fix for rounded corner bugs in Safari/iOS */
            }

            /* Video container must fill the wrapper */
            #video-container {
                width: 100%;
                height: 100%;
                position: absolute;
                top: 0;
                left: 0;
            }

            /* Face Guide mobile fix: Centered and smaller oval adjustment */
            .face-guide {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 20;
                pointer-events: none;
                background: rgba(0, 0, 0, 0.4);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                
                /* Center cutout reduced to 30% width and 45% height */
                -webkit-mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
            }

            /* Green Outline: Matching the mask size above */
            .face-guide::after {
                content: "";
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                /* Size matched to mask dimensions (50% x 65%) */
                width: 60%; 
                height: 90%;
                border: 2px solid #00ff88;
                border-radius: 50% 50% 50% 50% / 45% 45% 55% 55%;
                box-shadow: 0 0 15px rgba(0, 255, 136, 0.5), inset 0 0 10px rgba(0, 255, 136, 0.2);
            }
            
            /* Small instruction message above the video */
            .scan-instruction {
                font-size: 11px;
                color: var(--text-muted);
                margin-bottom: 8px;
                text-transform: uppercase;
            }

            #face-result {
                min-height: 80px;
                transition: all 0.3s ease;
                border: 1px solid rgba(0, 255, 136, 0.2);
                margin-top: 20px !important;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }

            #overlay {
                max-width: 100%;
                height: auto;
                border-radius: 20px;
                box-shadow: 10px 10px 20px var(--shadow-dark);
            }

            #video {
                width: 100%;
                height: 100%;
                object-fit: cover; /* Ensures video fills the container without distortion */
                transform: scaleX(-1); /* Mirror front camera */
            }

            /* --- Global Adjustments for Mobile --- */
            @media (max-width: 600px) {
                .invoice-body {
                    padding: 10px;
                }

                /* Change grid to single column on mobile */
                .info-grid {
                    grid-template-columns: 1fr; 
                    gap: 15px;
                }

                .info-grid div.full {
                    grid-column: span 1;
                }

                /* Reduce card padding for more screen space */
                .neumorph-card, .main-card, .prescription-container {
                    padding: 15px;
                    border-radius: 15px;
                }

                /* Prescription Table Adjustments (Critical) */
                .prescription-table {
                    border-spacing: 5px 8px;
                }

                .prescription-table th {
                    font-size: 0.6rem;
                }

                .input-table-neu {
                    padding: 10px 2px;
                    font-size: 0.85rem;
                    border-radius: 10px;
                }

                .eye-indicator {
                    width: 35px;
                    height: 35px;
                    font-size: 0.9rem;
                }

                /* Video Scanner Adjustments */
                .video-wrapper {
                    width: 100%;      /* Follows the mobile screen width */
                    height: 380px;    /* Sufficient height for face positioning */
                    position: relative;
                }

                #video, #overlay {
                    width: 100% !important;
                    height: auto !important;
                }

                .face-guide {
                    -webkit-mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                    mask-image: radial-gradient(ellipse 30% 45% at 50% 50%, transparent 95%, black 100%);
                }

                .face-guide::after {
                    width: 60%;
                    height: 90%;
                    border: 2px solid #00ff88;
                    border-radius: 50%;
                }

                /* Optimize buttons for touch targets */
                .neu-btn {
                    padding: 12px 10px;
                    font-size: 0.8rem;
                    flex: 1; /* Buttons share space equally */
                }

                .selection-wrapper {
                    flex-wrap: nowrap; /* Keep aligned horizontally */
                }

                .btn-action {
                    width: 100%;
                    padding: 15px;
                }
            }

            /* Enable horizontal scroll for very small screens (e.g., iPhone SE) */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin-top: 10px;
            }

            @keyframes pulse {
                0% { opacity: 0.6; }
                50% { opacity: 1; }
                100% { opacity: 0.6; }
            }

            /* Per-check position indicators */
            #pose-checks { display: flex; }
            .pose-check {
                font-size: 10px;
                letter-spacing: 0.5px;
                padding: 3px 9px;
                border-radius: 20px;
                background: rgba(255,255,255,0.04);
                border: 1px solid rgba(255,255,255,0.08);
                color: #666;
                transition: all 0.25s;
            }
            .pose-check.ok {
                color: #00ff88;
                border-color: rgba(0,255,136,0.35);
                background: rgba(0,255,136,0.07);
            }
            .pose-check.bad {
                color: #ff8a4d;
                border-color: rgba(255,138,77,0.35);
                background: rgba(255,138,77,0.07);
            }
            #autocap-hint { animation: blink 1s linear infinite; }

            /* ==========================================================
               FULLSCREEN CAMERA MODE (fix #6)
               ========================================================== */
            body.fullscreen-cam-active { overflow: hidden; }
            body.fullscreen-cam-active #mp-scan-card {
                position: fixed !important;
                inset: 0 !important;
                z-index: 9999 !important;
                width: 100vw !important;
                /* Use dynamic viewport height so mobile address bar doesn't cut off bottom content.
                   Falls back to 100vh on browsers that don't support dvh. */
                height: 100vh !important;
                height: 100dvh !important;
                max-width: none !important;
                margin: 0 !important;
                /* Extra bottom padding ensures the RESCAN / button row stays fully visible
                   even with iOS safe-area / home indicator */
                padding: 56px 12px calc(32px + env(safe-area-inset-bottom, 0px)) 12px !important;
                background: #0a0a0a !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                overflow-y: auto !important;
                overscroll-behavior: contain;
                -webkit-overflow-scrolling: touch;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
            }
            body.fullscreen-cam-active #mp-scan-card > .prescription-container {
                width: 100%;
                max-width: 520px;
                background: transparent;
                box-shadow: none;
                padding: 10px;
                /* Let content determine height; don't let the flex container squeeze the buttons */
                flex-shrink: 0;
            }
            body.fullscreen-cam-active .mp-wrapper {
                width: min(92vw, 440px);
                /* Shrink the video frame so there's room for buttons below without scrolling-to-cut */
                height: min(58vh, 520px);
            }
            /* Button row inside fullscreen scan: guarantee it keeps natural height and margin */
            body.fullscreen-cam-active .selection-wrapper {
                flex-shrink: 0;
                padding-bottom: 8px;
            }

            /* Back button overlay — only visible in fullscreen */
            #mp-back-btn {
                display: none;
                position: fixed;
                top: 12px;
                left: 12px;
                z-index: 10000;
                background: rgba(0,0,0,0.7);
                border: 1px solid rgba(0,255,136,0.4);
                color: #00ff88;
                padding: 9px 14px;
                border-radius: 22px;
                font-size: 0.8rem;
                font-weight: 700;
                letter-spacing: 1px;
                cursor: pointer;
                font-family: inherit;
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            }
            #mp-back-btn:active { transform: scale(0.96); }
            body.fullscreen-cam-active #mp-back-btn { display: inline-flex; align-items: center; gap: 6px; }

            @media (max-width: 600px) {
                body.fullscreen-cam-active #mp-scan-card {
                    padding: 52px 6px calc(28px + env(safe-area-inset-bottom, 0px)) 6px !important;
                }
                body.fullscreen-cam-active .mp-wrapper { width: 96vw; height: 52vh; }
            }

            /* =================================================================
               MOBILE LAYOUT HARDENING — prevents content from overflowing the
               outer container on phones. Added last so it wins the cascade.
               ================================================================= */

            /* Global box-sizing so padding never adds to declared widths */
            *, *::before, *::after { box-sizing: border-box; }

            /* Kill any horizontal scroll at the root level */
            html, body {
                max-width: 100%;
                overflow-x: hidden;
            }

            /* Images/canvas/video should never push the layout wider than their parent */
            img, video, canvas, svg { max-width: 100%; height: auto; }

            /* Read-only boxes sometimes hold long IDs or addresses — let them wrap */
            .read-only-box {
                word-break: break-word;
                overflow-wrap: anywhere;
                min-width: 0;
            }

            /* Generic container overflow guard */
            .main-wrapper, .content-area, .main-card, .neumorph-card,
            .prescription-container, .info-grid, .full {
                max-width: 100%;
                min-width: 0;
            }

            /* Make the prescription table adapt on narrow screens */
            .prescription-table { width: 100%; table-layout: fixed; }
            .prescription-table td, .prescription-table th {
                word-break: break-word;
                overflow-wrap: anywhere;
            }
            .input-table-neu { width: 100%; min-width: 0; }

            /* Chips/metrics rows wrap instead of overflow */
            .metrics-row, .pd-row {
                flex-wrap: wrap;
                max-width: 100%;
            }
            .metric-chip, .pd-chip { max-width: 100%; }

            /* The scan-result card sometimes had width:90% pushing beyond the parent */
            #mp-result, #mp-frame-rec { width: 100%; max-width: 100%; }
            #mp-result > div, #mp-frame-rec > div { max-width: 100%; }

            /* Selection-wrapper was set to nowrap on mobile — force wrap so 3+ buttons
               (START/RESCAN, SWITCH, CAPTURE, RESET) never spill out of the container */
            .selection-wrapper { flex-wrap: wrap; }

            /* --- Mobile-specific sizing --- */
            @media (max-width: 600px) {
                /* Soften the big neumorphic shadow so it doesn't create apparent overflow */
                .neumorph-card, .main-card, .prescription-container {
                    box-shadow: 6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
                    padding: 14px;
                }
                .invoice-body { padding: 8px; }

                /* Reduce nested-container padding (prescription inside prescription) */
                .prescription-container .prescription-container { padding: 10px; margin-top: 10px; }

                /* Tighten the prescription table so all 5 columns fit */
                .prescription-table { border-spacing: 3px 6px; }
                .prescription-table th { font-size: 0.55rem; padding-bottom: 4px; }
                .input-table-neu {
                    padding: 8px 2px;
                    font-size: 0.75rem;
                    box-shadow: inset 3px 3px 6px var(--shadow-dark),
                                inset -3px -3px 6px var(--shadow-light);
                }
                .eye-indicator { width: 30px; height: 30px; font-size: 0.8rem; }

                /* Buttons: allow wrapping, let them grow but never exceed the row */
                .selection-wrapper { flex-wrap: wrap !important; gap: 6px; }
                .neu-btn {
                    flex: 1 1 calc(50% - 6px);
                    min-width: 0;
                    padding: 10px 8px;
                    font-size: 0.72rem;
                    white-space: nowrap;
                }

                /* Buttons below the card (PRINT, BACK TO PREVIOUS PAGE) */
                .btn-action, .back-main { width: 100%; max-width: 100%; }
                .btn-group { width: 100%; padding: 0; }

                /* Face-scan card: keep the video inside the card */
                .mp-wrapper { width: 100%; height: 340px; }

                /* IOC preset buttons — wrap nicely on small screens */
                .ioc-preset { font-size: 8px; padding: 3px 7px; }

                /* PD calibration header won't let the IOC label overflow */
                #cal-header { gap: 8px; }
                #cal-header > div:first-child { min-width: 0; flex: 1; }
                #cal-active-label { white-space: nowrap; }

                /* Header / brand section */
                .header-container { padding: 10px; }
                .company-name { font-size: 1rem; }
                .company-address { font-size: 0.7rem; }
                .logout-btn { padding: 6px 12px; font-size: 0.7rem; }

                /* Result box typography a notch smaller so the badge fits */
                .shape-badge { font-size: 1.2rem; letter-spacing: 1.5px; }
            }

            /* Extra-narrow (iPhone SE-class ~360px) */
            @media (max-width: 380px) {
                .invoice-body { padding: 6px; }
                .neumorph-card, .main-card, .prescription-container { padding: 10px; }
                .prescription-table th { font-size: 0.5rem; }
                .input-table-neu { font-size: 0.7rem; padding: 6px 1px; }
                .eye-indicator { width: 26px; height: 26px; font-size: 0.7rem; }
                .neu-btn { flex: 1 1 100%; }
            }
        </style>
    </head>

    <body style="background: var(--bg-color);">
        <div class="main-wrapper">
            <div class="content-area" style="flex-direction: column">
                <div class="header-container" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">
                    <button class="logout-btn" onclick="window.location.href='logout.php';">
                        <span>Logout</span>
                    </button>
            
                    <div class="brand-section">
                        <div class="logo-box">
                            <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>" alt="Brand Logo" style="height: 40px;">
                        </div>
                        <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                        <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
                    </div>
                </div>
                
                <div class="main-card" style="
                margin-left: auto; 
                margin-right: auto; 
                width: 100%;">
                    <h2>INVOICE</h2>
            
                    <div class="info-grid">
                        <div>
                            <label>EXAMINATION CODE</label>
                            <div class="read-only-box"><?php echo $data['examination_code']; ?></div>
                        </div>

                        <div>
                            <label>DATE</label>
                            <div class="read-only-box"><?php echo date('d/m/Y', strtotime($data['examination_date'])); ?></div>
                        </div>

                        <div class="full">
                            <label>CUSTOMER NAME</label>
                            <div class="read-only-box"><?php echo strtoupper($data['customer_name']); ?></div>

                        </div>

                        <div>
                            <label>AGE</label>
                            <div class="read-only-box"><?php echo $data['age']; ?> YEARS</div>

                        </div>

                        <div>
                            <label>GENDER</label>
                            <div class="read-only-box"><?php echo $data['gender']; ?></div>

                        </div>

                        <div class="full">
                            <label>SYMPTOMS</label>
                            <div class="read-only-box" style="height: auto;"><?php echo $data['symptoms']; ?></div>

                        </div>

                        <div class="full">
                            <label>EXAM NOTES</label>
                            <div class="read-only-box" style="height: auto; min-height: 80px;"><?php echo $data['exam_notes'] ?: '-'; ?></div>
                        </div>

                        <div class="full">
                            <div class="prescription-container">
                                <label>PRESCRIPTION MODIFICATION</label>

                                <div class="selection-wrapper">
                                    <button type="button" class="neu-btn active" id="mod-no">
                                        <div class="led"></div> NO
                                    </button>
                                    <button type="button" class="neu-btn" id="mod-yes">
                                        <div class="led"></div> YES (MODIFY)
                                    </button>
                                </div>

                                <form method="POST" class="full">
                                    <input type="hidden" name="invoice_number" value="<?php echo $data['invoice_number']; ?>">
                                    
                                    <div class="prescription-container">
                                        <h3 style="color: var(--accent-color); font-size: 0.85rem; margin-bottom: 20px; text-align: center; opacity: 0.8;">
                                            — MEASUREMENT —
                                        </h3>
                                        <div class="table-responsive">
                                            <table class="prescription-table">
                                                <thead>
                                                    <tr>
                                                        <th>SIDE</th>
                                                        <th>SPH</th>
                                                        <th>CYL</th>
                                                        <th>AXIS</th>
                                                        <th>ADD</th>
                                                    </tr>
                                                </thead>
            
                                                <tbody>
                                                    <tr>
                                                        <td class="eye-label"><div class="eye-indicator">R</div></td>
                                                        <td><input type="text" name="od_sph" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_sph']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_sph'] ?? $data['new_r_sph']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_sph'] : $data['new_r_sph']; ?>" readonly></td>
                                                        
                                                        <td><input type="text" name="od_cyl" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_cyl']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_cyl'] ?? $data['new_r_cyl']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_cyl'] : $data['new_r_cyl']; ?>" readonly></td>
                                                        
                                                        <td><input type="text" name="od_axis" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_ax']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_ax'] ?? $data['new_r_ax']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_ax'] : $data['new_r_ax']; ?>" readonly></td>
                                                        
                                                        <td><input type="text" name="od_add" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_r_add']; ?>" 
                                                            data-modified="<?php echo $data['mod_r_add'] ?? $data['new_r_add']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_r_add'] : $data['new_r_add']; ?>" readonly></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="eye-label"><div class="eye-indicator">L</div></td>
                                                        <td><input type="text" name="os_sph" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_sph']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_sph'] ?? $data['new_l_sph']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_sph'] : $data['new_l_sph']; ?>" readonly></td>
                                                        <td><input type="text" name="os_cyl" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_cyl']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_cyl'] ?? $data['new_l_cyl']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_cyl'] : $data['new_l_cyl']; ?>" readonly></td>
                                                        <td><input type="text" name="os_axis" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_ax']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_ax'] ?? $data['new_l_ax']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_ax'] : $data['new_l_ax']; ?>" readonly></td>
                                                        <td><input type="text" name="os_add" class="input-table-neu mod-field" 
                                                            data-original="<?php echo $data['new_l_add']; ?>" 
                                                            data-modified="<?php echo $data['mod_l_add'] ?? $data['new_l_add']; ?>"
                                                            value="<?php echo ($data['lens_modification'] == 1) ? $data['mod_l_add'] : $data['new_l_add']; ?>" readonly></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
        
                                    <div id="save-btn-container" style="display: none; margin-top: 30px; text-align: center;">
                                        <button type="submit" name="save_modification" class="btn-action" style="width: 100%; max-width: 400px; border-radius: 50px;">
                                            CONFIRM & SAVE MODIFICATION
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="full" id="mp-scan-card">
                            <!-- Back button (only visible in fullscreen scan mode) -->
                            <button type="button" id="mp-back-btn" onclick="exitFullscreenCam()">← BACK</button>
                            <div class="prescription-container" style="text-align: center;">
                                <label>FACE SHAPE ANALYSIS</label>

                                <!-- STEP INDICATOR (hidden initially — only shown inside fullscreen scan) -->
                                <div id="mp-steps" style="display:none;justify-content:center;gap:6px;margin-bottom:14px;">
                                    <div class="mp-step active" id="step1-ind">
                                        <span class="step-num">1</span> CAMERA
                                    </div>
                                    <div class="step-arrow">›</div>
                                    <div class="mp-step" id="step2-ind">
                                        <span class="step-num">2</span> CAPTURE
                                    </div>
                                    <div class="step-arrow">›</div>
                                    <div class="mp-step" id="step3-ind">
                                        <span class="step-num">3</span> ANALYZE
                                    </div>
                                </div>

                                <!-- PD CALIBRATION: IOC ratio only (collapsible) -->
                                <div id="cal-box" style="display:none; margin-bottom:14px; border:1px solid rgba(0,255,136,0.15); border-radius:14px; overflow:hidden;">
                                    <div id="cal-header" onclick="toggleCalBody()" style="background:rgba(0,255,136,0.05); padding:9px 14px; display:flex; align-items:center; justify-content:space-between; cursor:pointer; user-select:none;">
                                        <div>
                                            <div style="font-size:0.6rem; color:#00ff88; letter-spacing:1px;">📐 PD CALIBRATION — IOC RATIO</div>
                                            <div style="font-size:9px; color:#555; margin-top:2px;">Tap to adjust IOC reference (advanced)</div>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <span id="cal-active-label" style="color:#00ff88; font-size:10px; font-weight:700;">IOC 95mm</span>
                                            <span id="cal-chevron" style="color:#00ff88; font-size:11px; transition:transform 0.25s; display:inline-block;">▼</span>
                                        </div>
                                    </div>
                                    <div id="cal-body" style="padding:12px 14px; display:none;">
                                        <div style="font-size:10px; color:#777; margin-bottom:10px; line-height:1.6; text-align:left;">
                                            PD is calculated from the ratio of <b style="color:#00cfff;">inter-pupil distance</b> ÷ <b style="color:#00cfff;">inter-outer-canthus distance (IOC)</b>.<br>
                                            Because both are measured in the same pixel space, the result is not affected by camera distance or zoom.
                                        </div>
                                        <div style="display:flex; align-items:center; gap:10px; justify-content:center; flex-wrap:wrap;">
                                            <div style="text-align:left;">
                                                <div style="font-size:9px; color:#555; letter-spacing:0.5px; margin-bottom:4px;">IOC REFERENCE (mm)</div>
                                                <div style="display:flex; align-items:center; gap:6px;">
                                                    <button type="button" onclick="adjustIOC(-0.5)" style="width:28px; height:28px; background:var(--bg-color); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#aaa; font-size:16px; cursor:pointer; line-height:1;">−</button>
                                                    <input type="number" id="cal-ioc-ref" value="95" min="85" max="110" step="0.5"
                                                        style="width:68px; background:var(--bg-color); border:1px solid rgba(0,255,136,0.3); border-radius:10px; color:#00ff88; padding:6px 4px; font-size:16px; font-weight:800; text-align:center;">
                                                    <button type="button" onclick="adjustIOC(0.5)" style="width:28px; height:28px; background:var(--bg-color); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#aaa; font-size:16px; cursor:pointer; line-height:1;">+</button>
                                                    <span style="font-size:11px; color:#555;">mm</span>
                                                </div>
                                            </div>
                                            <button type="button" onclick="applyAutoCal()" style="padding:7px 14px; font-size:10px; font-weight:700; letter-spacing:0.8px; background:rgba(0,255,136,0.08); border:1px solid rgba(0,255,136,0.35); border-radius:10px; color:#00ff88; cursor:pointer; font-family:inherit; flex:0 0 auto; white-space:nowrap; align-self:flex-end; height:32px; line-height:1;">
                                                ✓ APPLY
                                            </button>
                                        </div>
                                        <div id="cal-auto-status" style="font-size:10px; color:#00ff88; margin-top:8px; text-align:center;">✓ Active — IOC ref: 95mm</div>
                                        <!-- Quick reference -->
                                        <div style="margin-top:10px; display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                                            <div style="font-size:9px; color:#444; letter-spacing:0.5px; width:100%; text-align:center; margin-bottom:3px;">QUICK REFERENCE</div>
                                            <button type="button" onclick="setIOCPreset(90)" class="ioc-preset">Small · 90mm</button>
                                            <button type="button" onclick="setIOCPreset(93)" class="ioc-preset">Female · 93mm</button>
                                            <button type="button" onclick="setIOCPreset(95)" class="ioc-preset active">Average · 95mm</button>
                                            <button type="button" onclick="setIOCPreset(98)" class="ioc-preset">Male · 98mm</button>
                                            <button type="button" onclick="setIOCPreset(102)" class="ioc-preset">Large · 102mm</button>
                                        </div>

                                        <!-- AUTO-CAPTURE DURATION -->
                                        <div style="margin-top:14px; padding-top:10px; border-top:1px dashed rgba(255,255,255,0.06);">
                                            <div style="font-size:9px; color:#555; letter-spacing:0.5px; text-align:center; margin-bottom:6px;">AUTO-CAPTURE DURATION</div>
                                            <div style="display:flex; align-items:center; gap:6px; justify-content:center; flex-wrap:wrap;">
                                                <button type="button" onclick="setAutoCapSeconds(parseFloat(document.getElementById('autocap-sec-input').value||3)-1)" style="width:28px; height:28px; background:var(--bg-color); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#aaa; font-size:16px; cursor:pointer; line-height:1;">−</button>
                                                <input type="number" id="autocap-sec-input" value="3" min="1" max="15" step="1"
                                                    oninput="setAutoCapSeconds(this.value)"
                                                    style="width:58px; background:var(--bg-color); border:1px solid rgba(0,255,136,0.3); border-radius:10px; color:#00ff88; padding:6px 4px; font-size:15px; font-weight:800; text-align:center;">
                                                <button type="button" onclick="setAutoCapSeconds(parseFloat(document.getElementById('autocap-sec-input').value||3)+1)" style="width:28px; height:28px; background:var(--bg-color); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#aaa; font-size:16px; cursor:pointer; line-height:1;">+</button>
                                                <span style="font-size:11px; color:#555;">seconds</span>
                                            </div>
                                            <div style="font-size:9px; color:#444; text-align:center; margin-top:5px;">Range 1–15s · Default 3s</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- LIVE CAMERA VIEW (hidden initially — shown when camera starts) -->
                                <div id="mp-live-view" style="display:none;">
                                    <p class="scan-instruction" id="mp-instruction">Position your face inside the green outline</p>
                                    <div class="mp-wrapper">
                                        <video id="mp-video" autoplay muted playsinline></video>
                                        <canvas id="mp-canvas"></canvas>
                                        <div class="mp-guide" id="mp-guide"></div>
                                        <!-- Pose quality indicators -->
                                        <div id="mp-pose-indicator" style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);z-index:30;display:none;">
                                            <div id="pose-warn" style="background:rgba(255,100,0,0.85);color:#fff;font-size:10px;padding:4px 10px;border-radius:20px;letter-spacing:1px;"></div>
                                        </div>
                                    </div>
                                    <!-- SUB-CHECK INDICATORS -->
                                    <div id="pose-checks" style="display:none;margin-top:10px;gap:6px;justify-content:center;flex-wrap:wrap;">
                                        <span class="pose-check" id="chk-center">◎ Centering</span>
                                        <span class="pose-check" id="chk-distance">↔ Distance</span>
                                        <span class="pose-check" id="chk-tilt">⟲ Tilt</span>
                                        <span class="pose-check" id="chk-yaw">↻ Rotation</span>
                                    </div>

                                    <!-- AUTO-CAPTURE COUNTDOWN -->
                                    <div id="autocap-hint" style="display:none;margin-top:8px;font-size:11px;color:#00ff88;letter-spacing:1px;">
                                        Hold still… auto-capture in <span id="autocap-sec">3</span>
                                    </div>

                                    <!-- BRIGHTNESS / POSE QUALITY BAR -->
                                    <div id="quality-bar-wrap" style="display:none;margin-top:8px;">
                                        <div style="font-size:9px;color:#555;letter-spacing:1px;margin-bottom:3px;">POSITION QUALITY</div>
                                        <div style="width:100%;height:5px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden;">
                                            <div id="quality-bar-fill" style="height:100%;border-radius:3px;background:linear-gradient(90deg,#ff4d4d,#ffaa00,#00ff88);width:0%;transition:width 0.3s;"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- CAPTURED PHOTO VIEW (hidden initially) -->
                                <div id="mp-captured-view" style="display:none;">
                                    <p class="scan-instruction" style="color:#00cfff;">📸 Photo captured — ready to analyze</p>
                                    <div class="mp-wrapper" style="position:relative;">
                                        <canvas id="mp-photo-canvas" style="width:100%;height:100%;object-fit:cover;border-radius:20px;"></canvas>
                                        <!-- Retake overlay button -->
                                        <button type="button" id="mp-retake-overlay" style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.6);border:1px solid #555;color:#fff;border-radius:20px;padding:5px 12px;font-size:10px;z-index:30;cursor:pointer;">
                                            ↩ RETAKE
                                        </button>
                                    </div>
                                </div>

                                <!-- RESULT BOX (hidden initially — shown after analysis / BACK) -->
                                <div id="mp-result" class="read-only-box" style="display:none; color: #00ff88; flex-direction:column; min-height:90px; padding:15px; margin-top:15px;">
                                    <span style="color:var(--text-muted);font-size:0.75rem">Press START CAMERA to begin</span>
                                </div>

                                <!-- FRAME RECOMMENDATION BOX (hidden until result) -->
                                <div id="mp-frame-rec" style="display:none; margin-top:12px; padding:14px 16px; background:rgba(255,170,0,0.06); border:1px solid rgba(255,170,0,0.2); border-radius:14px; text-align:left;">
                                    <div style="font-size:0.6rem;color:#ffaa00;letter-spacing:1px;margin-bottom:8px;text-align:center;">✦ EYEGLASS FRAME RECOMMENDATION</div>
                                    <div id="frame-rec-content"></div>
                                </div>

                                <!-- BUTTON ROW -->
                                <div class="selection-wrapper" style="margin-top: 15px; flex-wrap:wrap; gap:8px;">
                                    <button type="button" class="neu-btn" id="mp-start-btn">
                                        <div class="led"></div> START CAMERA
                                    </button>
                                    <button type="button" class="neu-btn" id="mp-switch-btn" style="display:none;">
                                        <div class="led"></div> SWITCH CAM
                                    </button>
                                    <button type="button" class="neu-btn" id="mp-capture-btn" style="display:none; border-color:rgba(0,255,136,0.4);">
                                        <div class="led" style="background:#00ff88;box-shadow:0 0 6px #00ff88;"></div> 📸 CAPTURE
                                    </button>
                                    <button type="button" class="neu-btn" id="mp-reset-btn" style="display:none;">
                                        <div class="led"></div> RESTART SCAN
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- ============================================================
                             LENS RECOMMENDED — appears AFTER Face Shape Analysis
                             ============================================================ -->
                        <div class="full" id="lens-rec-wrap">
                            <div class="prescription-container" style="border:1px solid rgba(255,170,0,0.18);">

                                <!-- ── HEADER (click to open/close) ─────────────────── -->
                                <div onclick="lrToggle()" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <span style="font-size:1.25rem;">🔬</span>
                                        <div>
                                            <div style="font-size:0.7rem;letter-spacing:2px;color:#ffaa00;font-weight:700;">LENS RECOMMENDED</div>
                                            <div style="font-size:8.5px;color:#555;margin-top:1px;letter-spacing:0.5px;">Berdasarkan ukuran · kebiasaan · keluhan</div>
                                        </div>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <span style="font-size:8px;background:rgba(255,170,0,0.1);color:#ffaa00;border:1px solid rgba(255,170,0,0.28);border-radius:20px;padding:3px 9px;letter-spacing:0.5px;">
                                            <?php
                                            if (!$lr_isPresbyopia) {
                                                echo 'SINGLE VISION';
                                            } elseif ($lr_farOnlySV) {
                                                echo 'PRESBYOPIA → SV (DISTANCE)';
                                            } else {
                                                $lblMap = ['all_distance'=>'ALL-DISTANCE','dynamic'=>'DYNAMIC','far_near'=>'FAR & NEAR','near'=>'NEAR-OPTIMIZED'];
                                                echo 'PRESBYOPIA → ' . ($lblMap[$lr_presbyType] ?? strtoupper($lr_presbyType));
                                            }
                                            ?>
                                        </span>
                                        <span style="font-size:8px;background:rgba(0,255,136,0.07);color:#00ff88;border:1px solid rgba(0,255,136,0.2);border-radius:20px;padding:3px 9px;letter-spacing:0.5px;">
                                            <?php echo count($lr_candidates); ?> lens matched
                                        </span>
                                        <span id="lr-chev" style="color:#ffaa00;font-size:11px;display:inline-block;transition:transform 0.3s;">▼</span>
                                    </div>
                                </div>

                                <!-- ── COLLAPSIBLE BODY ──────────────────────────────── -->
                                <div id="lr-body" style="display:none;margin-top:16px;">

                                    <!-- Context chips -->
                                    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:14px;">
                                        <?php
                                        $lr_habitLbl  = ['','INDOOR','OUTDOOR','INDOOR & OUTDOOR'][$lr_habit] ?? 'INDOOR';
                                        $lr_digitalLbl = ['','LOW SCREEN USE','MODERATE SCREEN (2-5 HRS)','HIGH SCREEN USE (>5 HRS)'][$lr_digital] ?? '';

                                        // Vision need label
                                        $lr_vnParts = [];
                                        if ($lr_needDist)  $lr_vnParts[] = 'DISTANCE';
                                        if ($lr_needInter) $lr_vnParts[] = 'INTERMEDIATE';
                                        if ($lr_needNear)  $lr_vnParts[] = 'NEAR';
                                        $lr_vnLabel = !empty($lr_vnParts) ? implode(' + ', $lr_vnParts) : '';

                                        // Presbyopia design label
                                        $lr_presbyLabelMap = [
                                            'all_distance' => 'ALL-DISTANCE PROG',
                                            'dynamic'      => 'DYNAMIC (FAR+INTER)',
                                            'far_near'     => 'FAR & NEAR PROG',
                                            'near'         => 'NEAR-OPTIMIZED',
                                            'far_only'     => 'DISTANCE ONLY (SV)',
                                        ];

                                        $lr_ctx = [
                                            ['🎂', 'AGE '.$lr_age.' YRS',  '#00cfff'],
                                            ['👁️', $lr_habitLbl,           '#00ff88'],
                                            ['💻', $lr_digitalLbl,          '#aa88ff'],
                                        ];
                                        if ($lr_vnLabel) $lr_ctx[] = ['📏', 'NEEDS: '.$lr_vnLabel, '#00ccff'];
                                        if ($lr_isVeryHighPow) $lr_ctx[] = ['⚡', 'VERY HIGH POWER', '#ff4d4d'];
                                        elseif ($lr_isHighPow) $lr_ctx[] = ['📈', 'HIGH POWER',      '#ff8a4d'];
                                        if ($lr_isPresbyopia) {
                                            $presbyLbl = $lr_farOnlySV
                                                ? 'SINGLE VISION (DISTANCE)'
                                                : ('PRESBYOPIA → ' . ($lr_presbyLabelMap[$lr_presbyType] ?? strtoupper($lr_presbyType)));
                                            $lr_ctx[] = ['📖', $presbyLbl, '#ffcc00'];
                                        }
                                        if ($lr_hasGlare)      $lr_ctx[] = ['☀️', 'LIGHT SENSITIVE',      '#ffaa00'];
                                        if ($lr_hasEyeStrain || $lr_hasHeadache) $lr_ctx[] = ['😣','EYE STRAIN / HEADACHE','#ff6699'];
                                        if ($lr_hasDM)         $lr_ctx[] = ['🩸', 'DIABETES',             '#ff6655'];
                                        if ($lr_hasHT)         $lr_ctx[] = ['❤️', 'HYPERTENSION',         '#ff6655'];
                                        if ($lr_hasDryEye)     $lr_ctx[] = ['💧', 'DRY EYE',              '#66ccff'];
                                        foreach ($lr_ctx as $c):
                                        ?>
                                        <span style="display:inline-flex;align-items:center;gap:3px;font-size:8px;color:<?php echo $c[2]; ?>;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:20px;padding:3px 8px;letter-spacing:0.4px;">
                                            <?php echo $c[0]; ?> <?php echo $c[1]; ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Power summary bar -->
                                    <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);border-radius:12px;padding:10px 14px;margin-bottom:14px;">
                                        <div style="font-size:7.5px;color:#444;letter-spacing:1px;margin-bottom:8px;">ACTIVE RX <?php echo $lr_rMod ? '(MODIFIED)' : '(ORIGINAL)'; ?></div>
                                        <div style="display:flex;flex-wrap:wrap;gap:14px;justify-content:space-around;">
                                        <?php
                                        $lr_pw = [
                                            ['R SPH', $lr_r_sph], ['R CYL', $lr_r_cyl], ['R ADD', $lr_r_add],
                                            ['L SPH', $lr_l_sph], ['L CYL', $lr_l_cyl], ['L ADD', $lr_l_add],
                                            ['MAX SE', $lr_maxSE],
                                        ];
                                        foreach ($lr_pw as $pw):
                                            $val = number_format($pw[1], 2, '.', '');
                                            $zero = abs($pw[1]) < 0.01;
                                        ?>
                                        <div style="text-align:center;">
                                            <div style="font-size:7px;color:#444;letter-spacing:0.5px;margin-bottom:2px;"><?php echo $pw[0]; ?></div>
                                            <div style="font-size:10px;font-weight:700;color:<?php echo $zero ? '#2e2e2e' : '#ccc'; ?>;font-family:monospace;"><?php echo $val; ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Special warning notes -->
                                    <?php foreach ($lr_specialNotes as $sn): ?>
                                    <div style="display:flex;align-items:flex-start;gap:8px;font-size:9.5px;color:#ffcc00;background:rgba(255,204,0,0.05);border:1px solid rgba(255,204,0,0.15);border-radius:10px;padding:8px 11px;margin-bottom:8px;">
                                        <span><?php echo $sn[0]; ?></span>
                                        <span><?php echo htmlspecialchars($sn[1]); ?></span>
                                    </div>
                                    <?php endforeach; ?>

                                    <!-- ── 2-LEVEL TAB SYSTEM ────────────────────────── -->
                                    <?php
                                    $lr_featureIcons = [
                                        'UV PROTECTION'                  => '🌞',
                                        'HIGH-INDEX UV400 PROTECTION'    => '🛡️',
                                        'ANTI-REFLECTIVE (AR) COATING'   => '💡',
                                        'SCRATCH-RESISTANT COATING'      => '🪨',
                                        'SMUDGE-RESISTANT'               => '🧼',
                                        'HYDROPHOBIC'                    => '💧',
                                        'SUPER HYDROPHOBIC'              => '💦',
                                        'ANTI-STATIC'                    => '⚡',
                                        'BLUE LIGHT BLOCKING'            => '💙',
                                        'PHOTOCHROMIC'                   => '🌅',
                                        'NIGHT DRIVE COATING'            => '🚗',
                                        'HIGH INDEX 1.67'                => '💎',
                                        'HIGHT INDEX 1.67'               => '💎',
                                        'HIGH POWER RX'                  => '🔬',
                                        'IMPACT-RESISTANT'               => '🛡️',
                                        'FAR & NEAR OPTIMIZED LENS'      => '📐',
                                        'ALL-DISTANCE PROGRESSIVE'       => '🔭',
                                        'DYNAMIC DISTANCE LENS'          => '🚀',
                                        'NEAR-OPTIMIZED LENS'            => '📚',
                                        'ENHANCED NEAR VISION'           => '🔎',
                                    ];
                                    $lr_rankStyle = [
                                        0 => ['★ #1', '#ffaa00', 'rgba(255,170,0,0.12)', 'rgba(255,170,0,0.40)'],
                                        1 => ['★ #2', '#c0c0c0', 'rgba(180,180,180,0.08)', 'rgba(180,180,180,0.28)'],
                                        2 => ['★ #3', '#cd7f32', 'rgba(180,120,60,0.08)',  'rgba(180,120,60,0.28)'],
                                    ];
                                    ?>

                                    <?php if (empty($lr_candidates)): ?>
                                    <div style="font-size:11px;color:#555;text-align:center;padding:20px;">
                                        No matching lenses found in the catalog.
                                    </div>
                                    <?php else: ?>

                                    <!-- ── LEVEL 1: Lens-type tabs ──────────────────── -->
                                    <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px;">
                                    <?php foreach ($lr_typeConfig as $typeKey => $typeCfg):
                                        $typeList = isset($lr_byType[$typeKey]) ? $lr_byType[$typeKey] : [];
                                        if (empty($typeList)) continue;
                                        $isFirstType  = ($typeKey === $lr_firstType);
                                        $tColor       = $typeCfg['color'];
                                        $typeHasDot   = !empty($lr_wantedDesignFeats) && !empty($lr_hasDesign[$typeKey]['_type']);
                                    ?>
                                    <button type="button"
                                            id="lr-type-btn-<?php echo $typeKey; ?>"
                                            onclick="lrTypeSwitch('<?php echo $typeKey; ?>')"
                                            style="flex:1;min-width:0;padding:8px 5px;border-radius:12px;
                                                   border:1px solid <?php echo $isFirstType ? $tColor : 'rgba(255,255,255,0.08)'; ?>;
                                                   background:<?php echo $isFirstType ? 'rgba(255,255,255,0.07)' : 'rgba(255,255,255,0.02)'; ?>;
                                                   color:<?php echo $tColor; ?>;font-size:9px;font-weight:700;
                                                   letter-spacing:0.5px;cursor:pointer;font-family:inherit;
                                                   transition:all 0.2s;line-height:1.4;text-align:center;position:relative;">
                                        <?php if ($typeHasDot): ?>
                                        <span style="position:absolute;top:4px;right:5px;width:7px;height:7px;border-radius:50%;background:#00ff88;box-shadow:0 0 5px #00ff88;display:inline-block;" title="Has lens matching vision need"></span>
                                        <?php endif; ?>
                                        <?php echo $typeCfg['icon'].' '.$typeCfg['label']; ?><br>
                                        <span style="font-size:8px;opacity:0.65;"><?php echo count($typeList); ?> lens</span>
                                    </button>
                                    <?php endforeach; ?>
                                    </div>

                                    <!-- ── LEVEL 2: Per-type price panes ────────────── -->
                                    <?php foreach ($lr_typeConfig as $typeKey => $typeCfg):
                                        $typeList = isset($lr_byType[$typeKey]) ? $lr_byType[$typeKey] : [];
                                        if (empty($typeList)) continue;
                                        $isFirstType  = ($typeKey === $lr_firstType);
                                        $tColor       = $typeCfg['color'];
                                        $priceBuckets = lr_priceBuckets($typeList);
                                        // Default active price tab for this type
                                        $defPriceTab  = 'rekomendasi';
                                    ?>
                                    <div id="lr-type-pane-<?php echo $typeKey; ?>"
                                         style="display:<?php echo $isFirstType ? 'block' : 'none'; ?>;">

                                        <!-- Price tab bar -->
                                        <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px;padding:8px;background:rgba(255,255,255,0.02);border-radius:12px;border:1px solid rgba(255,255,255,0.05);">
                                        <?php foreach ($priceBuckets as $priceKey => $priceBucket):
                                            $isActivePrice = ($priceKey === $defPriceTab);
                                            $pColor = $priceBucket['color'];
                                            $pCount = count($priceBucket['data']);
                                            $pHint  = ['normal'=>'≤600rb','sedang'=>'600rb–1jt','tinggi'=>'>1jt'][$priceKey] ?? '';
                                            $priceDot = !empty($lr_wantedDesignFeats)
                                                && isset($lr_hasDesign[$typeKey][$priceKey])
                                                && $lr_hasDesign[$typeKey][$priceKey];
                                        ?>
                                        <button type="button"
                                                id="lr-price-btn-<?php echo $typeKey; ?>-<?php echo $priceKey; ?>"
                                                onclick="lrPriceSwitch('<?php echo $typeKey; ?>','<?php echo $priceKey; ?>')"
                                                style="flex:1;min-width:0;padding:6px 4px;border-radius:10px;
                                                       border:1px solid <?php echo $isActivePrice ? $pColor : 'rgba(255,255,255,0.07)'; ?>;
                                                       background:<?php echo $isActivePrice ? 'rgba(255,255,255,0.06)' : 'transparent'; ?>;
                                                       color:<?php echo $pColor; ?>;font-size:8.5px;font-weight:700;
                                                       letter-spacing:0.4px;cursor:pointer;font-family:inherit;
                                                       transition:all 0.2s;line-height:1.3;text-align:center;position:relative;">
                                            <?php if ($priceDot): ?>
                                            <span style="position:absolute;top:3px;right:4px;width:6px;height:6px;border-radius:50%;background:#00ff88;box-shadow:0 0 4px #00ff88;display:inline-block;" title="Has lens matching vision need"></span>
                                            <?php endif; ?>
                                            <?php echo $priceBucket['label']; ?><br>
                                            <span style="font-size:7.5px;opacity:0.65;"><?php echo $pCount; ?> lens<?php echo $pHint ? ' · '.$pHint : ''; ?></span>
                                        </button>
                                        <?php endforeach; ?>
                                        </div>

                                        <!-- Price tab panes -->
                                        <?php foreach ($priceBuckets as $priceKey => $priceBucket):
                                            $isActivePrice = ($priceKey === $defPriceTab);
                                            $list     = $priceBucket['data'];
                                            $limit    = $priceBucket['limit'];
                                            $total    = count($list);
                                            $showExp  = ($total > $limit);
                                            $pColor   = $priceBucket['color'];
                                            $paneId   = 'lr-ppane-'.$typeKey.'-'.$priceKey;
                                        ?>
                                        <div id="<?php echo $paneId; ?>"
                                             style="display:<?php echo $isActivePrice ? 'block' : 'none'; ?>;">

                                            <?php if (empty($list)): ?>
                                            <div style="font-size:11px;color:#444;text-align:center;padding:16px 10px;background:rgba(255,255,255,0.02);border-radius:10px;border:1px dashed rgba(255,255,255,0.05);">
                                                <?php
                                                $emptyMsg = [
                                                    'normal' => 'No lenses in this price range (≤ Rp 600,000)',
                                                    'sedang' => 'No lenses in this price range (Rp 600,000 – Rp 1,000,000)',
                                                    'tinggi' => 'No lenses in this price range (> Rp 1,000,000)',
                                                ];
                                                echo isset($emptyMsg[$priceKey]) ? $emptyMsg[$priceKey] : 'No lenses available.';
                                                ?>
                                            </div>
                                            <?php else: ?>

                                            <div style="display:flex;flex-direction:column;gap:7px;">
                                            <?php foreach ($list as $i => $cand):
                                                if ($priceKey === 'rekomendasi') {
                                                    $rs = isset($lr_rankStyle[$i]) ? $lr_rankStyle[$i] : ['#'.($i+1), '#00ff88', 'rgba(0,255,136,0.05)', 'rgba(0,255,136,0.15)'];
                                                    list($rankLbl, $rankColor, $bg, $bd) = $rs;
                                                } else {
                                                    $rankLbl   = '#'.($i+1);
                                                    $rankColor = $pColor;
                                                    $bg        = 'rgba(255,255,255,0.02)';
                                                    $bd        = 'rgba(255,255,255,0.07)';
                                                }
                                                $srcColor  = ($cand['source'] === 'stock') ? '#00ff88' : '#ff8a4d';
                                                $srcBg     = ($cand['source'] === 'stock') ? 'rgba(0,255,136,0.10)' : 'rgba(255,138,77,0.10)';
                                                $srcBd     = ($cand['source'] === 'stock') ? 'rgba(0,255,136,0.25)' : 'rgba(255,138,77,0.25)';
                                                $srcLabel  = ($cand['source'] === 'stock') ? 'STOCK' : 'LAB';
                                                $uid       = $typeKey.'-'.$priceKey.'-'.$i;
                                                $hidden    = ($i >= $limit) ? 'display:none;' : '';
                                            ?>
                                            <div id="lr-card-<?php echo $uid; ?>"
                                                 style="<?php echo $hidden; ?>border:1px solid <?php echo $bd; ?>;border-radius:12px;overflow:hidden;background:<?php echo $bg; ?>;">

                                                <!-- Collapsed row -->
                                                <div onclick="lrCardToggle('<?php echo $uid; ?>')"
                                                     style="display:flex;align-items:center;gap:10px;padding:11px 13px;cursor:pointer;">
                                                    <span style="display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:24px;background:<?php echo $bd; ?>;border-radius:20px;font-size:9px;font-weight:800;color:<?php echo $rankColor; ?>;letter-spacing:0.5px;flex-shrink:0;"><?php echo $rankLbl; ?></span>
                                                    <div style="flex:1;min-width:0;">
                                                        <div style="font-size:11px;font-weight:700;color:<?php echo $rankColor; ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                            <?php echo htmlspecialchars($cand['category']); ?> — <?php echo htmlspecialchars($cand['type']); ?>
                                                            <?php
                                                            // Inline design match — no function call
                                                            $_cat = strtoupper(trim($cand['category']));
                                                            $_isProg = in_array($_cat, array('PROGRESSIVE','KRYPTOK','FLATTOP'));
                                                            $_match = false;
                                                            if ($_isProg && $lr_isPresbyopia) {
                                                                if ($_cat === 'KRYPTOK' || $_cat === 'FLATTOP') {
                                                                    $_match = in_array($lr_presbyType, array('far_near','far_only','all_distance','dynamic'));
                                                                } else {
                                                                    foreach ($lr_wantedDesignFeats as $_wf) {
                                                                        if (in_array($_wf, $cand['features'])) { $_match = true; break; }
                                                                    }
                                                                }
                                                            }
                                                            if ($_match):
                                                            ?>
                                                            <span style="display:inline-block;font-size:7.5px;font-weight:800;background:rgba(0,255,136,0.15);color:#00ff88;border:1px solid rgba(0,255,136,0.4);border-radius:20px;padding:1px 6px;margin-left:5px;letter-spacing:0.5px;vertical-align:middle;">✓ MATCHES NEED</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div style="display:flex;align-items:center;gap:5px;margin-top:3px;">
                                                            <span style="font-size:7.5px;font-weight:700;color:<?php echo $srcColor; ?>;background:<?php echo $srcBg; ?>;border:1px solid <?php echo $srcBd; ?>;border-radius:20px;padding:1px 7px;letter-spacing:0.5px;"><?php echo $srcLabel; ?></span>
                                                            <span style="font-size:8px;color:#555;">⏱ <?php echo htmlspecialchars($cand['readiness']); ?></span>
                                                        </div>
                                                    </div>
                                                    <div style="font-size:11px;font-weight:700;color:<?php echo $rankColor; ?>;font-family:monospace;text-align:right;flex-shrink:0;">
                                                        <?php echo lr_fmt_price($cand['selling']); ?>
                                                    </div>
                                                    <span id="lr-chev-<?php echo $uid; ?>" style="color:#555;font-size:11px;flex-shrink:0;transition:transform 0.25s;display:inline-block;">▼</span>
                                                </div>

                                                <!-- Detail panel -->
                                                <div id="lr-detail-<?php echo $uid; ?>" style="display:none;padding:0 13px 13px 13px;border-top:1px solid <?php echo $bd; ?>;">
                                                    <div style="font-size:7.5px;color:#444;letter-spacing:1px;margin:10px 0 7px;">LENS FEATURES</div>
                                                    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px;">
                                                        <?php foreach ($cand['features'] as $feat):
                                                            $ficon = isset($lr_featureIcons[strtoupper(trim($feat))]) ? $lr_featureIcons[strtoupper(trim($feat))] : '•';
                                                        ?>
                                                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:9px;color:#ddd;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.10);border-radius:20px;padding:4px 10px;letter-spacing:0.3px;">
                                                            <?php echo $ficon; ?> <?php echo htmlspecialchars($feat); ?>
                                                        </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:8px 12px;">
                                                        <span style="font-size:1rem;"><?php echo ($cand['source'] === 'stock') ? '🏪' : '🔧'; ?></span>
                                                        <div>
                                                            <div style="font-size:10px;font-weight:700;color:<?php echo $srcColor; ?>;">
                                                                <?php echo ($cand['source'] === 'stock') ? 'In Stock' : 'Lab Order (Custom)'; ?>
                                                            </div>
                                                            <div style="font-size:9px;color:#555;margin-top:1px;"><?php echo htmlspecialchars($cand['readiness']); ?></div>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($cand['note'])): ?>
                                                    <div style="margin-top:8px;font-size:9px;color:#666;font-style:italic;padding-left:4px;">
                                                        📋 <?php echo htmlspecialchars($cand['note']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>

                                            </div><!-- /card -->
                                            <?php endforeach; ?>
                                            </div><!-- /card-list -->

                                            <?php if ($showExp): ?>
                                            <div style="text-align:center;margin-top:10px;">
                                                <button type="button"
                                                        id="lr-expand-<?php echo $typeKey.'-'.$priceKey; ?>"
                                                        onclick="lrExpandAll('<?php echo $typeKey; ?>','<?php echo $priceKey; ?>',<?php echo $limit; ?>,<?php echo $total; ?>)"
                                                        style="background:rgba(255,170,0,0.07);border:1px solid rgba(255,170,0,0.25);color:#ffaa00;font-size:10px;font-weight:700;letter-spacing:1px;padding:8px 22px;border-radius:20px;cursor:pointer;font-family:inherit;transition:all 0.2s;">
                                                    ▼ SHOW ALL <?php echo $total; ?> LENSES
                                                </button>
                                            </div>
                                            <?php endif; ?>

                                            <?php endif; // empty list ?>
                                        </div><!-- /price-pane -->
                                        <?php endforeach; // price buckets ?>

                                    </div><!-- /type-pane -->
                                    <?php endforeach; // type tabs ?>

                                    <?php endif; // empty candidates ?>

                                    <!-- Disclaimer -->
                                    <div style="margin-top:12px;font-size:8px;color:#2e2e2e;font-style:italic;border-top:1px solid rgba(255,255,255,0.04);padding-top:10px;">
                                        * Order based on prescription fit, lifestyle habits, and symptoms. Final choice subject to customer preference and budget.
                                    </div>

                                </div><!-- /lr-body -->
                            </div>
                        </div>
                        <!-- END LENS RECOMMENDATION -->

                    </div>

                    <div style="margin-top: 40px; text-align: center;">
                        <button onclick="window.print()" class="btn-action">PRINT INVOICE</button>
                    </div>
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="back-main" onclick="window.location.href='customer.php'">BACK TO PREVIOUS PAGE</button>
            </div>
        
            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>
        </div>

        <script>
            const modYes = document.getElementById('mod-yes');
            const modNo = document.getElementById('mod-no');
            const fields = document.querySelectorAll('.mod-field');
            const saveContainer = document.getElementById('save-btn-container');
            // faceapi removed — using MediaPipe now

            const formatZeroValue = (e) => {
                let val = e.target.value.trim();
                if (val === "0" || val === "00") {
                    e.target.value = "0.00";
                }
            };

            modYes.onclick = () => {
                modYes.classList.add('active');
                modNo.classList.remove('active');
                saveContainer.style.display = 'block';
                
                fields.forEach(f => {
                    // Retrieve value from data-modified attribute
                    f.value = f.getAttribute('data-modified');
                    f.readOnly = false;
                    f.style.color = "#00ff88"; // Neon Green
                    f.style.boxShadow = "inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light)";
                    f.addEventListener('focus', () => f.select());
                    f.addEventListener('blur', formatZeroValue);
                });
            };

            modNo.onclick = () => {
                modNo.classList.add('active');
                modYes.classList.remove('active');
                saveContainer.style.display = 'none';
                
                fields.forEach(f => {
                    // Revert to original value from customer_examinations database
                    f.value = f.getAttribute('data-original');
                    f.readOnly = true;
                    f.style.color = "var(--text-main)";
                    f.style.boxShadow = "inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light)";
                    f.removeEventListener('blur', formatZeroValue);
                });
            };

            // ============================================================
            // LENS RECOMMENDATION — toggle functions
            // ============================================================
            function lrToggle() {
                const body = document.getElementById('lr-body');
                const chev = document.getElementById('lr-chev');
                if (!body) return;
                const open = body.style.display === 'none' || body.style.display === '';
                body.style.display = open ? 'block' : 'none';
                if (chev) chev.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
            }

            // Toggle detail panel inside a card
            function lrCardToggle(uid) {
                const detail = document.getElementById('lr-detail-' + uid);
                const chev   = document.getElementById('lr-chev-'   + uid);
                if (!detail) return;
                const open = detail.style.display === 'none' || detail.style.display === '';
                detail.style.display = open ? 'block' : 'none';
                if (chev) chev.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
            }

            // Switch lens-type tab (level 1)
            function lrTypeSwitch(typeKey) {
                const types  = ['sv','kryptok','progressive','flattop'];
                const colors = { sv:'#00ff88', kryptok:'#00cfff', progressive:'#aa88ff', flattop:'#ff8a4d' };
                types.forEach(function(t) {
                    var pane = document.getElementById('lr-type-pane-' + t);
                    var btn  = document.getElementById('lr-type-btn-'  + t);
                    if (!pane || !btn) return;
                    var active = (t === typeKey);
                    pane.style.display    = active ? 'block' : 'none';
                    btn.style.borderColor = active ? colors[t] : 'rgba(255,255,255,0.08)';
                    btn.style.background  = active ? 'rgba(255,255,255,0.07)' : 'rgba(255,255,255,0.02)';
                });
            }

            // Switch price tab within a type (level 2)
            function lrPriceSwitch(typeKey, priceKey) {
                var priceTabs = ['rekomendasi','normal','sedang','tinggi'];
                var colors    = { rekomendasi:'#ffaa00', normal:'#00ff88', sedang:'#00cfff', tinggi:'#ff8a4d' };
                priceTabs.forEach(function(p) {
                    var pane = document.getElementById('lr-ppane-' + typeKey + '-' + p);
                    var btn  = document.getElementById('lr-price-btn-' + typeKey + '-' + p);
                    if (!pane || !btn) return;
                    var active = (p === priceKey);
                    pane.style.display    = active ? 'block' : 'none';
                    btn.style.borderColor = active ? colors[p] : 'rgba(255,255,255,0.07)';
                    btn.style.background  = active ? 'rgba(255,255,255,0.06)' : 'transparent';
                });
            }

            // Expand hidden cards
            function lrExpandAll(typeKey, priceKey, limit, total) {
                var btnId = 'lr-expand-' + typeKey + '-' + priceKey;
                var btn   = document.getElementById(btnId);
                var expanded = btn && btn.dataset.expanded === '1';
                for (var i = limit; i < total; i++) {
                    var card = document.getElementById('lr-card-' + typeKey + '-' + priceKey + '-' + i);
                    if (card) card.style.display = expanded ? 'none' : 'block';
                }
                if (btn) {
                    btn.dataset.expanded = expanded ? '0' : '1';
                    btn.innerHTML = expanded ? '▼ SHOW ALL ' + total + ' LENSES' : '▲ COLLAPSE';
                }
            }

            window.lrToggle      = lrToggle;
            window.lrCardToggle  = lrCardToggle;
            window.lrTypeSwitch  = lrTypeSwitch;
            window.lrPriceSwitch = lrPriceSwitch;
            window.lrExpandAll   = lrExpandAll;


            window.onload = () => {
                const isModified = <?php echo $data['lens_modification'] == 1 ? 'true' : 'false'; ?>;
                if (isModified) {
                    // Trigger UI as if 'Yes' was clicked
                    modYes.classList.add('active');
                    modNo.classList.remove('active');
                    
                    fields.forEach(f => {
                        f.style.color = "#00ff88"; // Highlights modified data
                        if(f.value === "0") f.value = "0.00";
                        f.readOnly = false; 
                        f.addEventListener('focus', () => f.select());
                    });
                }
            };

            
            (function() {

                // Patient gender from PHP (for gender-aware frame ranking — fix #5)
                const patientGender = "<?php echo strtolower(trim($data['gender'] ?? '')); ?>";

                // Frame-shape color mapping loaded from ./data_json/color_shape.json
                // Keys are uppercase frame-shape names (e.g., "WAYFARER"), values are hex colors.
                const frameShapeColors = <?php echo json_encode($frameShapeColors, JSON_UNESCAPED_UNICODE); ?>;

                // ============================================================
                // LANDMARK INDEX MAP (MediaPipe Face Mesh 468+10 iris points)
                // ============================================================
                const LM = {
                    JAW_LEFT:      234,
                    JAW_RIGHT:     454,
                    JAW_L1:        172,
                    JAW_R1:        397,
                    JAW_L2:        136,
                    JAW_R2:        365,
                    CHIN:          152,
                    CHIN_L:        176,
                    CHIN_R:        400,
                    TEMPLE_L:      162,
                    TEMPLE_R:      389,
                    FOREHEAD_TOP:  10,
                    // Additional forehead landmarks for improved estimation
                    FOREHEAD_L:    103,
                    FOREHEAD_R:    332,
                    FOREHEAD_MID:  9,
                    CHEEK_L:       123,
                    CHEEK_R:       352,
                    CHEEK_L2:      116,
                    CHEEK_R2:      345,
                    BROW_L:        70,
                    BROW_R:        300,
                    BROW_L_INNER:  55,
                    BROW_R_INNER:  285,
                    BROW_L_TOP:    52,   // top of left eyebrow
                    BROW_R_TOP:    282,  // top of right eyebrow
                    EYE_L_OUTER:   33,
                    EYE_R_OUTER:   263,
                    EYE_L_INNER:   133,
                    EYE_R_INNER:   362,
                    EYE_L_TOP:     159,
                    EYE_R_TOP:     386,
                    EYE_L_BOT:     145,
                    EYE_R_BOT:     374,
                    NOSE_TIP:      4,
                    NOSE_ROOT:     6,    // bridge of nose
                    MOUTH_L:       61,
                    MOUTH_R:       291,
                    MOUTH_TOP:     13,
                    MOUTH_BOT:     14,
                    FACE_CENTER:   168,
                };

                // ============================================================
                // DOM ELEMENTS
                // ============================================================
                const video         = document.getElementById('mp-video');
                const canvas        = document.getElementById('mp-canvas');
                const photoCanvas   = document.getElementById('mp-photo-canvas');
                const guide         = document.getElementById('mp-guide');
                const resultBox     = document.getElementById('mp-result');
                const frameRecBox   = document.getElementById('mp-frame-rec');
                const frameRecContent = document.getElementById('frame-rec-content');
                const startBtn      = document.getElementById('mp-start-btn');
                const switchBtn     = document.getElementById('mp-switch-btn');
                const captureBtn    = document.getElementById('mp-capture-btn');
                const resetBtn      = document.getElementById('mp-reset-btn');
                const liveView      = document.getElementById('mp-live-view');
                const capturedView  = document.getElementById('mp-captured-view');
                const retakeOverlay = document.getElementById('mp-retake-overlay');
                const poseIndicator = document.getElementById('mp-pose-indicator');
                const poseWarn      = document.getElementById('pose-warn');
                const qualityWrap   = document.getElementById('quality-bar-wrap');
                const qualityFill   = document.getElementById('quality-bar-fill');
                
                const step1Ind      = document.getElementById('step1-ind');
                const step2Ind      = document.getElementById('step2-ind');
                const step3Ind      = document.getElementById('step3-ind');
                const ctx           = canvas.getContext('2d');
                const pCtx          = photoCanvas.getContext('2d');

                // Wrapper elements used to toggle mirroring per camera
                const mpWrappers    = document.querySelectorAll('.mp-wrapper');
                function applyMirrorState() {
                    const mirrored = facingMode === 'user';
                    mpWrappers.forEach(w => w.classList.toggle('mirror', mirrored));
                }

                // ============================================================
                // STATE
                // ============================================================
                let faceMesh         = null;
                let facingMode       = 'user';
                let isRunning        = false;
                let isCaptured       = false;
                let rafId            = null;
                let lastPD           = null;
                let capturedLM       = null;
                let lastLM       = null;        // last landmark from the live feed
                let pdBuffer         = [];
                let shapeBuffer      = [];
                let metricsBuffer    = [];
                let qualityScore     = 0;
                const PD_BUFFER_SIZE    = 30;
                const SHAPE_BUFFER_SIZE = 15;

                // Auto-capture state — user-configurable hold time (default 3s)
                let autoCapStableSince = 0;
                let autoCapTimerId     = null;
                let AUTO_CAP_HOLD_MS   = 3000;   // default 3 seconds (user-adjustable via #autocap-sec-input)
                function handleAutoCapture(allOk) {
                    const hint = document.getElementById('autocap-hint');
                    const sec  = document.getElementById('autocap-sec');
                    if (!allOk) {
                        autoCapStableSince = 0;
                        hint.style.display = 'none';
                        return;
                    }
                    if (isCaptured) return;
                    const now = performance.now();
                    if (autoCapStableSince === 0) autoCapStableSince = now;
                    const held = now - autoCapStableSince;
                    const remaining = Math.max(0, Math.ceil((AUTO_CAP_HOLD_MS - held) / 1000));
                    hint.style.display = 'block';
                    sec.textContent = remaining;
                    if (held >= AUTO_CAP_HOLD_MS) {
                        hint.style.display = 'none';
                        autoCapStableSince = 0;
                        capturePhoto();
                    }
                }

                // Setter hooked to the duration input — clamped to 1..15 seconds
                function setAutoCapSeconds(val) {
                    const n = parseFloat(val);
                    if (isNaN(n)) return;
                    const clamped = Math.max(1, Math.min(15, n));
                    AUTO_CAP_HOLD_MS = Math.round(clamped * 1000);
                    autoCapStableSince = 0; // restart the countdown with the new duration
                    const inp = document.getElementById('autocap-sec-input');
                    if (inp && parseFloat(inp.value) !== clamped) inp.value = clamped;
                    const initial = document.getElementById('autocap-sec');
                    if (initial) initial.textContent = Math.ceil(clamped);
                }
                window.setAutoCapSeconds = setAutoCapSeconds;

                // Face-width calibration — default 142mm, can be overridden
                let faceRefMM    = 142;   // updated by calibration method
                let calBoxEl     = null;  // assigned after DOM ready

                // ============================================================
                // STEP UI HELPER
                // ============================================================
                function setStep(n) {
                    [step1Ind, step2Ind, step3Ind].forEach((el, i) => {
                        el.classList.toggle('active', i < n);
                        el.classList.toggle('done', i < n - 1);
                    });
                }

                // ============================================================
                // PD CALIBRATION — IOC RATIO
                // PD = (pdPx / iocPx) × iocRefMM
                // No external reference object required.
                // Unaffected by camera distance, zoom, or face size.
                // ============================================================

                // State — iocRefMM can be changed via the UI
                let iocRefMM = 95; // adult average, user-adjustable

                // Preset quick-select
                function setIOCPreset(val) {
                    document.getElementById('cal-ioc-ref').value = val;
                    document.querySelectorAll('.ioc-preset').forEach(b => {
                        b.classList.toggle('active', parseFloat(b.textContent) === val ||
                            b.onclick && b.getAttribute('onclick').includes(val));
                    });
                    applyAutoCal();
                }
                window.setIOCPreset = setIOCPreset;

                // +/− stepper buttons
                function adjustIOC(delta) {
                    const inp = document.getElementById('cal-ioc-ref');
                    const cur = parseFloat(inp.value) || 95;
                    const next = Math.min(110, Math.max(85, +(cur + delta).toFixed(1)));
                    inp.value = next;
                }
                window.adjustIOC = adjustIOC;

                // Apply IOC reference value
                function applyAutoCal() {
                    const val = parseFloat(document.getElementById('cal-ioc-ref').value);
                    const st  = document.getElementById('cal-auto-status');
                    if (isNaN(val) || val < 85 || val > 110) {
                        st.style.color   = '#ff4d4d';
                        st.textContent   = '⚠ Enter a value between 85–110mm';
                        return;
                    }
                    iocRefMM = val;
                    st.style.color   = '#00ff88';
                    st.textContent   = `✓ Active — IOC ref: ${val}mm`;
                    const lbl = document.getElementById('cal-active-label');
                    if (lbl) lbl.textContent = `IOC ${val}mm`;
                    // Update active preset highlight
                    document.querySelectorAll('.ioc-preset').forEach(b => {
                        const match = b.getAttribute('onclick') || '';
                        b.classList.toggle('active', match.includes('(' + val + ')'));
                    });
                }
                window.applyAutoCal = applyAutoCal;

                // ============================================================
                // PD CALIBRATION TOGGLE (fix #1 — collapsible header)
                // ============================================================
                function toggleCalBody() {
                    const b = document.getElementById('cal-body');
                    const c = document.getElementById('cal-chevron');
                    if (!b) return;
                    const open = b.style.display === 'none';
                    b.style.display = open ? 'block' : 'none';
                    if (c) c.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
                }
                window.toggleCalBody = toggleCalBody;

                // ============================================================
                // FULLSCREEN CAMERA MODE (fix #6)
                // ============================================================
                function enterFullscreenCam() {
                    document.body.classList.add('fullscreen-cam-active');
                    // Reset the BACK button label each time we enter scan mode
                    const bb = document.getElementById('mp-back-btn');
                    if (bb) bb.innerHTML = '← BACK';
                    // Scroll the scan card to the top of the fullscreen view
                    const card = document.getElementById('mp-scan-card');
                    if (card) card.scrollTop = 0;
                }
                function exitFullscreenCam() {
                    // Remember whether we had a finished analysis before stopping the camera
                    const hasResult = !!capturedLM;

                    document.body.classList.remove('fullscreen-cam-active');

                    // Stop the stream & processing loop
                    if (video && video.srcObject) {
                        video.srcObject.getTracks().forEach(t => t.stop());
                        video.srcObject = null;
                    }
                    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
                    isRunning = false;
                    autoCapStableSince = 0;

                    // Always hide scan-only chrome (steps, live view, pose indicators, calibration, countdown)
                    const stepsEl = document.getElementById('mp-steps');   if (stepsEl) stepsEl.style.display = 'none';
                    liveView.style.display       = 'none';
                    capturedView.style.display   = 'none';
                    qualityWrap.style.display    = 'none';
                    poseIndicator.style.display  = 'none';
                    const pc = document.getElementById('pose-checks');    if (pc) pc.style.display = 'none';
                    const ah = document.getElementById('autocap-hint');   if (ah) ah.style.display = 'none';
                    const ce = document.getElementById('cal-box');        if (ce) ce.style.display = 'none';

                    // Scan-mode action buttons are no longer relevant outside fullscreen
                    switchBtn.style.display  = 'none';
                    captureBtn.style.display = 'none';
                    resetBtn.style.display   = 'none';
                    startBtn.disabled        = false;

                    if (hasResult) {
                        // Analysis is done — KEEP the result + frame-rec visible on the summary view
                        resultBox.style.display   = 'flex';
                        // (frameRecBox visibility was already set by showFrameRecommendation)
                        startBtn.style.display    = 'inline-block';
                        startBtn.innerHTML        = '<div class="led"></div> RESCAN';
                    } else {
                        // User cancelled before any result — go back to the clean initial state
                        resultBox.style.display   = 'none';
                        frameRecBox.style.display = 'none';
                        resultBox.innerHTML       = '<span style="color:var(--text-muted);font-size:0.75rem">Press START CAMERA to begin</span>';
                        startBtn.style.display    = 'inline-block';
                        startBtn.innerHTML        = '<div class="led"></div> START CAMERA';
                        isCaptured = false;
                        capturedLM = null;
                        setStep(1);
                    }
                }
                window.exitFullscreenCam = exitFullscreenCam;


                function loadMediaPipe() {
                    resultBox.innerHTML = `<div class="mp-loading"><div class="spinner"></div> LOADING MEDIAPIPE...</div>`;
                    const scripts = [
                        'https://cdn.jsdelivr.net/npm/@mediapipe/camera_utils/camera_utils.js',
                        'https://cdn.jsdelivr.net/npm/@mediapipe/drawing_utils/drawing_utils.js',
                        'https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/face_mesh.js'
                    ];
                    function loadNext(index) {
                        if (index >= scripts.length) { initFaceMesh(); return; }
                        const s = document.createElement('script');
                        s.src = scripts[index];
                        s.onload = () => loadNext(index + 1);
                        s.onerror = () => {
                            resultBox.innerHTML = `<b style="color:#ff4d4d">FAILED TO LOAD LIBRARY (${index+1}/3). Check your connection.</b>`;
                            startBtn.disabled  = false;
                            startBtn.innerHTML = '<div class="led"></div> TRY AGAIN';
                        };
                        document.head.appendChild(s);
                    }
                    loadNext(0);
                }

                // ============================================================
                // INIT FACE MESH
                // ============================================================
                function initFaceMesh() {
                    resultBox.innerHTML = `<div class="mp-loading"><div class="spinner"></div> INITIALIZING 3D MODEL...</div>`;
                    faceMesh = new FaceMesh({
                        locateFile: (file) => `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`
                    });
                    faceMesh.setOptions({
                        maxNumFaces: 1,
                        refineLandmarks: true,
                        minDetectionConfidence: 0.65,
                        minTrackingConfidence: 0.65
                    });
                    faceMesh.onResults(onResults);
                    startCamera();
                }

                // ============================================================
                // START CAMERA — RAF loop (iOS-compatible)
                // ============================================================
                function startCamera() {
                    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
                    if (video.srcObject) {
                        video.srcObject.getTracks().forEach(t => t.stop());
                        video.srcObject = null;
                    }
                    const constraints = {
                        video: {
                            facingMode: { ideal: facingMode },
                            width:  { ideal: 1280, max: 1920 },
                            height: { ideal: 720,  max: 1080 },
                            frameRate: { ideal: 30, max: 30 },
                            advanced: [{ focusMode: 'continuous' }]
                        },
                        audio: false
                    };
                    navigator.mediaDevices.getUserMedia(constraints)
                    .then(stream => {
                        video.srcObject = stream;
                        video.setAttribute('playsinline', true);
                        video.setAttribute('muted', true);
                        video.muted = true;
                        const pp = video.play();
                        if (pp !== undefined) pp.catch(() => video.play());
                        video.onloadedmetadata = () => {
                            canvas.width  = video.videoWidth  || 640;
                            canvas.height = video.videoHeight || 480;
                            applyMirrorState(); // mirror only when using front camera
                            isRunning = true;
                            isCaptured = false;
                            // Hide the (now-useless) START CAMERA button once the camera is running
                            startBtn.style.display   = 'none';
                            startBtn.disabled        = false;
                            switchBtn.style.display  = 'inline-block';
                            captureBtn.style.display = 'inline-block';
                            // Reveal the scan UI elements that were hidden on initial load
                            const stepsEl = document.getElementById('mp-steps');
                            if (stepsEl) stepsEl.style.display = 'flex';
                            liveView.style.display  = 'block';
                            resultBox.style.display = 'flex';
                            // Hide any previous analysis result from a prior scan
                            frameRecBox.style.display = 'none';
                            // Enter fullscreen scan view (fix #6)
                            enterFullscreenCam();
                            // Show calibration box (header only — body stays collapsed per fix #1)
                            const calEl = document.getElementById('cal-box');
                            if (calEl) calEl.style.display = 'block';
                            qualityWrap.style.display   = 'block';
                            poseIndicator.style.display = 'block';
                            document.getElementById('pose-checks').style.display = 'flex';
                            resultBox.innerHTML = `<span style="color:var(--text-muted);font-size:0.75rem">Position your face...</span>`;
                            setStep(2);
                            let processing = false;
                            async function rafLoop() {
                                if (!isCaptured && !processing && video.readyState >= 2) {
                                    processing = true;
                                    try { await faceMesh.send({ image: video }); } catch(e) {}
                                    processing = false;
                                }
                                rafId = requestAnimationFrame(rafLoop);
                            }
                            rafLoop();
                        };
                    }).catch(err => {
                        let msg = err.message;
                        if (err.name === 'NotAllowedError')  msg = 'Camera permission denied. Open Settings → allow camera.';
                        if (err.name === 'NotFoundError')    msg = 'Camera not found.';
                        if (err.name === 'NotReadableError') msg = 'Camera is in use by another application.';
                        resultBox.innerHTML = `<b style="color:#ff4d4d">ERROR: ${msg}</b>`;
                        startBtn.disabled  = false;
                        startBtn.innerHTML = '<div class="led"></div> TRY AGAIN';
                    });
                }

                // ============================================================
                // POSE QUALITY DETECTION (yaw / pitch / size) → returns 0–100
                // ============================================================
                function detectPoseQuality(lm) {
                    const nose = lm[LM.NOSE_TIP], eyeL = lm[LM.EYE_L_OUTER], eyeR = lm[LM.EYE_R_OUTER];
                    const distL = Math.abs(nose.x - eyeL.x), distR = Math.abs(nose.x - eyeR.x);
                    const yawRatio = Math.min(distL, distR) / (Math.max(distL, distR) + 0.001);
                    const brow = lm[LM.BROW_L], chin = lm[LM.CHIN];
                    const noseRelY = (nose.y - brow.y) / ((chin.y - brow.y) + 0.001);
                    const pitchScore = 1 - Math.min(1, Math.abs(noseRelY - 0.45) * 4);
                    const W = canvas.width;
                    const faceW = Math.abs(lm[LM.JAW_LEFT].x - lm[LM.JAW_RIGHT].x) * W;
                    const sizeScore = (faceW > 80 && faceW < W * 0.85) ? 1 : 0.3;
                    return Math.round(Math.min(100, (yawRatio * 0.5 + pitchScore * 0.3 + sizeScore * 0.2) * 100));
                }

                // ============================================================
                // PER-AXIS POSE CHECKS — drives the visual sub-indicators
                // Returns { center, distance, tilt, yaw } each as 'ok' | 'bad'.
                // ============================================================
                function detectPoseChecks(lm) {
                    const W = canvas.width, H = canvas.height;
                    const nose   = lm[LM.NOSE_TIP];
                    const eyeL   = lm[LM.EYE_L_OUTER], eyeR = lm[LM.EYE_R_OUTER];
                    const jawL   = lm[LM.JAW_LEFT],    jawR = lm[LM.JAW_RIGHT];

                    // Centering — face center should sit within ~15% of frame center
                    const cx = (jawL.x + jawR.x) / 2;
                    const cy = (lm[LM.FOREHEAD_TOP].y + lm[LM.CHIN].y) / 2;
                    const center = (Math.abs(cx - 0.5) < 0.15 && Math.abs(cy - 0.5) < 0.15) ? 'ok' : 'bad';

                    // Distance — face width should be 35–75% of frame width
                    const faceW = Math.abs(jawL.x - jawR.x);
                    const distance = (faceW >= 0.35 && faceW <= 0.75) ? 'ok' : 'bad';

                    // Tilt (roll) — eye-line should be near horizontal
                    const rollDeg = Math.atan2((eyeR.y - eyeL.y) * H, (eyeR.x - eyeL.x) * W) * 180 / Math.PI;
                    const tilt = Math.abs(rollDeg) < 8 ? 'ok' : 'bad';

                    // Yaw — nose roughly centered between the two outer eyes
                    const distEL = Math.abs(nose.x - eyeL.x);
                    const distER = Math.abs(nose.x - eyeR.x);
                    const yawRatio = Math.min(distEL, distER) / (Math.max(distEL, distER) + 0.001);
                    const yaw = yawRatio > 0.75 ? 'ok' : 'bad';

                    return { center, distance, tilt, yaw };
                }

                // Render pose-check pills in the UI
                const checksWrap = document.getElementById('pose-checks');
                const chkEls = {
                    center:   document.getElementById('chk-center'),
                    distance: document.getElementById('chk-distance'),
                    tilt:     document.getElementById('chk-tilt'),
                    yaw:      document.getElementById('chk-yaw'),
                };
                function renderPoseChecks(checks) {
                    Object.entries(checks).forEach(([key, v]) => {
                        const el = chkEls[key]; if (!el) return;
                        el.classList.remove('ok', 'bad');
                        el.classList.add(v);
                    });
                }

                function getPoseWarning(lm) {
                    const nose = lm[LM.NOSE_TIP], eyeL = lm[LM.EYE_L_OUTER], eyeR = lm[LM.EYE_R_OUTER];
                    const distL = Math.abs(nose.x - eyeL.x), distR = Math.abs(nose.x - eyeR.x);
                    const yaw = Math.min(distL, distR) / (Math.max(distL, distR) + 0.001);
                    if (yaw < 0.65) {
                        // Pick the arrow that matches what the user sees on screen.
                        // Front camera is mirrored; back camera is not.
                        const needRight = distL < distR;          // subject should turn toward their own right
                        const mirrored  = facingMode === 'user';
                        const showRightArrow = mirrored ? needRight : !needRight;
                        return showRightArrow ? '← Turn slightly right' : 'Turn slightly left →';
                    }
                    const W = canvas.width;
                    const faceW = Math.abs(lm[LM.JAW_LEFT].x - lm[LM.JAW_RIGHT].x) * W;
                    if (faceW < 80)    return '↔ Move closer to camera';
                    if (faceW > W * 0.85) return '↔ Move farther from camera';
                    return null;
                }

                // ============================================================
                // LIVE DETECTION CALLBACK
                // ============================================================
                function onResults(results) {
                    if (canvas.width !== video.videoWidth) {
                        canvas.width  = video.videoWidth;
                        canvas.height = video.videoHeight;
                    }
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    if (!results.multiFaceLandmarks || !results.multiFaceLandmarks.length) {
                        shapeBuffer = []; pdBuffer = [];
                        poseWarn.textContent = '⚠ Face not detected';
                        qualityFill.style.width = '0%';
                        resultBox.innerHTML = `<span style="color:var(--text-muted);font-size:0.75rem">Face not detected...</span>`;
                        return;
                    }
                    const lm = results.multiFaceLandmarks[0];
                    lastLM = lm; // stored for use by calibration functions
                    drawMesh(lm, ctx, canvas.width, canvas.height, false);
                    qualityScore = detectPoseQuality(lm);
                    qualityFill.style.width = qualityScore + '%';
                    const warn = getPoseWarning(lm);
                    poseWarn.textContent = warn || '';
                    poseWarn.style.display = warn ? 'block' : 'none';

                    // Per-axis positioning checks + auto-capture countdown
                    const checks = detectPoseChecks(lm);
                    renderPoseChecks(checks);
                    const allOk = Object.values(checks).every(v => v === 'ok') && qualityScore >= 80;
                    handleAutoCapture(allOk);
                    // PD multi-frame averaging
                    const pdNow = measurePD(lm, canvas.width, canvas.height);
                    if (pdNow) { pdBuffer.push(pdNow); if (pdBuffer.length > PD_BUFFER_SIZE) pdBuffer.shift(); lastPD = averagePD(pdBuffer); }
                    // Shape preview
                    const an = analyzeFaceShape(lm, canvas.width, canvas.height);
                    shapeBuffer.push(an.shape);
                    if (shapeBuffer.length > SHAPE_BUFFER_SIZE) shapeBuffer.shift();
                    const liveShape = mostFrequent(shapeBuffer);
                    const pdHtml = lastPD
                        ? `<div class="pd-row"><span class="pd-chip total">PD ${lastPD.total}mm</span><span class="pd-chip">OD ${lastPD.od}mm</span><span class="pd-chip">OS ${lastPD.os}mm</span></div><div class="pd-note">*live preview — press 📸 CAPTURE for accuracy</div>`
                        : `<div class="pd-note" style="margin-top:8px;color:#555;">Waiting for iris detection...</div>`;
                    const qColor = qualityScore > 70 ? '#00ff88' : qualityScore > 40 ? '#ffaa00' : '#ff4d4d';
                    resultBox.innerHTML = `
                        <div style="font-size:0.6rem;color:var(--text-muted);letter-spacing:1px;margin-bottom:4px;">LIVE PREVIEW</div>
                        <div class="shape-badge">${liveShape}</div>
                        <div style="font-size:10px;color:${qColor};margin:4px 0;">Position quality: ${qualityScore}%</div>
                        ${pdHtml}
                        <div style="font-size:10px;color:#444;margin-top:6px;">${qualityScore >= 70 ? '✓ Good position — press 📸 CAPTURE' : '⚠ Fix your position first'}</div>
                    `;
                    guide.classList.toggle('locked', qualityScore >= 70);
                }

                // ============================================================
                // SHARPNESS CHECK — Laplacian variance on a downscaled grayscale frame.
                // Returns a positive number; higher = sharper. Typical blur < 60, sharp > 120.
                // ============================================================
                function measureSharpness(srcCanvas) {
                    const w = 160, h = Math.round(160 * srcCanvas.height / srcCanvas.width);
                    const tmp = document.createElement('canvas');
                    tmp.width = w; tmp.height = h;
                    const tctx = tmp.getContext('2d');
                    tctx.drawImage(srcCanvas, 0, 0, w, h);
                    const img = tctx.getImageData(0, 0, w, h).data;
                    const gray = new Float32Array(w * h);
                    for (let i = 0, j = 0; i < img.length; i += 4, j++) {
                        gray[j] = 0.299 * img[i] + 0.587 * img[i+1] + 0.114 * img[i+2];
                    }
                    let sum = 0, sumSq = 0, n = 0;
                    for (let y = 1; y < h - 1; y++) {
                        for (let x = 1; x < w - 1; x++) {
                            const k = y * w + x;
                            const lap = -4 * gray[k] + gray[k-1] + gray[k+1] + gray[k-w] + gray[k+w];
                            sum   += lap;
                            sumSq += lap * lap;
                            n++;
                        }
                    }
                    const mean = sum / n;
                    return (sumSq / n) - (mean * mean); // variance
                }

                // Retry budget for blurry frames during auto-capture
                let sharpnessRetry = 0;
                const SHARPNESS_MIN    = 70;  // reject below this
                const SHARPNESS_RETRIES = 8;   // max retries before accepting anyway

                // ============================================================
                // CAPTURE PHOTO — step 2 (with blur rejection + auto-analyze)
                // ============================================================
                async function capturePhoto() {
                    if (!isRunning || !video.srcObject) return;

                    // Build a temp canvas from the raw video frame (no mirroring) for sharpness
                    const tmpCanvas = document.createElement('canvas');
                    tmpCanvas.width  = video.videoWidth  || 640;
                    tmpCanvas.height = video.videoHeight || 480;
                    tmpCanvas.getContext('2d').drawImage(video, 0, 0);

                    // Blur check — if too blurry, wait ~150ms and try again
                    const sharp = measureSharpness(tmpCanvas);
                    if (sharp < SHARPNESS_MIN && sharpnessRetry < SHARPNESS_RETRIES) {
                        sharpnessRetry++;
                        const hint = document.getElementById('autocap-hint');
                        if (hint) {
                            hint.style.display = 'block';
                            hint.innerHTML = `⏳ Image blurry, holding for sharp frame… (${sharpnessRetry}/${SHARPNESS_RETRIES})`;
                        }
                        setTimeout(() => { capturePhoto(); }, 150);
                        return;
                    }
                    sharpnessRetry = 0;

                    photoCanvas.width  = tmpCanvas.width;
                    photoCanvas.height = tmpCanvas.height;
                    pCtx.save();
                    if (facingMode === 'user') {
                        pCtx.translate(photoCanvas.width, 0);
                        pCtx.scale(-1, 1);
                    }
                    pCtx.drawImage(tmpCanvas, 0, 0);
                    pCtx.restore();
                    isCaptured = true;
                    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
                    resultBox.innerHTML = `<div class="mp-loading"><div class="spinner"></div> ANALYZING…</div>`;

                    faceMesh.onResults((results) => {
                        capturedLM = (results.multiFaceLandmarks && results.multiFaceLandmarks.length)
                            ? results.multiFaceLandmarks[0] : null;
                        if (capturedLM) drawMesh(capturedLM, photoCanvas.getContext('2d'), photoCanvas.width, photoCanvas.height, facingMode === 'user');
                        liveView.style.display      = 'none';
                        capturedView.style.display  = 'block';
                        captureBtn.style.display    = 'none';
                        switchBtn.style.display     = 'none';
                        resetBtn.style.display      = 'inline-block';
                        qualityWrap.style.display   = 'none';
                        poseIndicator.style.display = 'none';
                        document.getElementById('pose-checks').style.display = 'none';
                        document.getElementById('autocap-hint').style.display = 'none';
                        setStep(3);
                        if (capturedLM) {
                            // Auto-run analysis — no button press needed
                            runAnalysis();
                        } else {
                            resultBox.innerHTML = `<span style="color:#ff4d4d;font-size:0.75rem">Face not detected. Tap RESTART SCAN.</span>`;
                        }
                        faceMesh.onResults(onResults);
                    });
                    await faceMesh.send({ image: tmpCanvas });
                }

                // ============================================================
                // RUN ANALYSIS — step 3 (auto-runs after capture)
                // ============================================================
                function runAnalysis() {
                    if (!capturedLM) { resultBox.innerHTML = `<span style="color:#ff4d4d">No landmarks found. Retry the scan.</span>`; return; }
                    setTimeout(() => {
                        const analysis = analyzeFaceShape(capturedLM, photoCanvas.width, photoCanvas.height);
                        const finalPD  = measurePD(capturedLM, photoCanvas.width, photoCanvas.height);
                        const avgPD    = (finalPD && pdBuffer.length > 5) ? blendPD(finalPD, averagePD(pdBuffer)) : (finalPD || lastPD);
                        displayFinalResult(analysis, avgPD);
                        showFrameRecommendation(analysis.shape);
                        // Analysis complete — make the BACK button indicate the summary is ready
                        const bb = document.getElementById('mp-back-btn');
                        if (bb) bb.innerHTML = '✓ DONE — VIEW SUMMARY';
                    }, 200);
                }

                // ============================================================
                // DRAW MESH — reusable for live canvas & photo canvas
                // ============================================================
                function drawMesh(lm, targetCtx, W, H, mirrored) {
                    targetCtx.save();
                    if (!mirrored) { targetCtx.translate(W, 0); targetCtx.scale(-1, 1); }
                    const keyPoints = [LM.JAW_LEFT,LM.JAW_RIGHT,LM.JAW_L1,LM.JAW_R1,LM.JAW_L2,LM.JAW_R2,
                        LM.CHIN,LM.CHIN_L,LM.CHIN_R,LM.TEMPLE_L,LM.TEMPLE_R,LM.CHEEK_L,LM.CHEEK_R,
                        LM.BROW_L,LM.BROW_R,LM.FOREHEAD_L,LM.FOREHEAD_R,LM.FOREHEAD_MID];
                    keyPoints.forEach(idx => {
                        const p = lm[idx]; if (!p) return;
                        targetCtx.beginPath(); targetCtx.arc(p.x*W, p.y*H, 3, 0, 2*Math.PI);
                        targetCtx.fillStyle = 'rgba(0,255,136,0.7)'; targetCtx.fill();
                    });
                    const jawC = [162,21,54,103,67,109,10,338,297,332,284,251,389,454,323,361,288,397,365,379,378,400,377,152,148,176,149,150,136,172,58,132,93,234,127,162];
                    targetCtx.beginPath();
                    jawC.forEach((idx,i) => { const p=lm[idx]; if(!p) return; i===0?targetCtx.moveTo(p.x*W,p.y*H):targetCtx.lineTo(p.x*W,p.y*H); });
                    targetCtx.strokeStyle='rgba(0,255,136,0.4)'; targetCtx.lineWidth=1.5; targetCtx.stroke();
                    // Improved forehead estimated line
                    const fhEst = estimateForehead(lm, W, H);
                    if (fhEst) {
                        targetCtx.beginPath();
                        targetCtx.moveTo(lm[LM.TEMPLE_L].x*W, fhEst*H);
                        targetCtx.lineTo(lm[LM.TEMPLE_R].x*W, fhEst*H);
                        targetCtx.strokeStyle='rgba(255,170,0,0.5)'; targetCtx.lineWidth=1.5;
                        targetCtx.setLineDash([5,4]); targetCtx.stroke(); targetCtx.setLineDash([]);
                        targetCtx.font='9px monospace'; targetCtx.fillStyle='rgba(255,170,0,0.7)';
                        targetCtx.fillText('FOREHEAD EST.', lm[LM.TEMPLE_L].x*W, fhEst*H-3);
                    }
                    // Iris & PD line
                    const rp=lm[468], lp=lm[473];
                    if (rp && lp) {
                        [rp,lp].forEach(p => {
                            targetCtx.beginPath(); targetCtx.arc(p.x*W,p.y*H,5,0,2*Math.PI);
                            targetCtx.fillStyle='rgba(0,207,255,0.9)'; targetCtx.fill();
                            targetCtx.strokeStyle='rgba(255,255,255,0.6)'; targetCtx.lineWidth=1.5; targetCtx.stroke();
                        });
                        targetCtx.beginPath(); targetCtx.moveTo(rp.x*W,rp.y*H); targetCtx.lineTo(lp.x*W,lp.y*H);
                        targetCtx.strokeStyle='rgba(0,207,255,0.5)'; targetCtx.lineWidth=1;
                        targetCtx.setLineDash([4,4]); targetCtx.stroke(); targetCtx.setLineDash([]);
                    }
                    targetCtx.restore();
                }

                // ============================================================
                // IMPROVED FOREHEAD ESTIMATION
                // Returns the estimated Y-coordinate (normalized 0-1) of forehead top
                // Uses brow-to-eye gap + landmark 9 blend — better than fixed 15% offset
                // ============================================================
                function estimateForehead(lm, W, H) {
                    const browL  = lm[LM.BROW_L_TOP] || lm[LM.BROW_L];
                    const eyeL   = lm[LM.EYE_L_TOP]  || lm[LM.EYE_L_OUTER];
                    const fhMid  = lm[LM.FOREHEAD_MID]; // lm[9] — mid forehead point
                    if (!browL || !eyeL) return null;
                    // Eye-to-brow vertical gap drives forehead height estimate
                    const eyeBrowGap    = Math.abs(browL.y - eyeL.y);
                    const foreheadOffset = eyeBrowGap * 1.65;
                    const browY = Math.min(lm[LM.BROW_L].y, lm[LM.BROW_R] ? lm[LM.BROW_R].y : lm[LM.BROW_L].y);
                    const estY  = browY - foreheadOffset;
                    // Blend with lm[9] if available (lm[9] is a real mesh point on mid forehead)
                    return fhMid ? estY * 0.6 + fhMid.y * 0.4 : estY;
                }

                // ============================================================
                // MEASURE PD — multi-point iris centroid + face-width calibration
                //
                // MediaPipe with refineLandmarks=true produces 10 iris landmarks:
                //   468–472 = right iris (from camera's view = patient's OS)
                //   473–477 = left iris  (from camera's view = patient's OD)
                // A 5-point centroid per iris is far more stable than a single point.
                //
                // Calibration: tragus-to-tragus face width (LM 234–454) ≈ 142mm
                // is the best reference available without a physical object.
                // Further accuracy improvements require a physical reference that
                // MediaPipe can reliably detect (not eyeglasses).
                // ============================================================
                function irisCenter(lm, startIdx, W, H) {
                    // Take 5 iris points (startIdx..startIdx+4), compute centroid
                    let sumX = 0, sumY = 0, count = 0;
                    for (let i = startIdx; i < startIdx + 5; i++) {
                        const p = lm[i];
                        if (!p) continue;
                        sumX += p.x * W;
                        sumY += p.y * H;
                        count++;
                    }
                    if (count === 0) return null;
                    return { x: sumX / count, y: sumY / count };
                }

                function measurePD(lm, W, H) {
                    // Use the centroid of 5 iris points per eye — more stable than a single point
                    const rIris = irisCenter(lm, 468, W, H); // OS (patient's left, camera's right)
                    const lIris = irisCenter(lm, 473, W, H); // OD (patient's right, camera's left)
                    if (!rIris || !lIris) return null;

                    const dx   = lIris.x - rIris.x;
                    const dy   = lIris.y - rIris.y;
                    const pdPx = Math.sqrt(dx*dx + dy*dy);

                    // IOC ratio: inter-outer-canthus distance in pixels as denominator
                    // mmPerPx = iocRefMM / iocPx  →  PD = pdPx × mmPerPx
                    // Advantage: no need to know camera distance or absolute face size.
                    // Because pdPx and iocPx are measured in the same frame, the ratio
                    // stays constant regardless of how close/far the customer is.
                    const iocPx = Math.abs((lm[LM.EYE_R_OUTER].x - lm[LM.EYE_L_OUTER].x) * W);
                    if (iocPx < 5) return null;
                    const mmPerPx = iocRefMM / iocPx;

                    const pdTotal = +(pdPx * mmPerPx).toFixed(1);
                    const nose    = lm[LM.FACE_CENTER];
                    const noseX   = nose.x * W;
                    const pdOD    = +(Math.abs(lIris.x - noseX) * mmPerPx).toFixed(1);
                    const pdOS    = +(Math.abs(rIris.x - noseX) * mmPerPx).toFixed(1);

                    return { total: pdTotal, od: pdOD, os: pdOS };
                }

                function averagePD(buf) {
                    if (!buf.length) return null;
                    // Outlier rejection: drop values > 1 SD from the mean before averaging
                    function filteredAvg(arr) {
                        const mean = arr.reduce((s,v) => s+v, 0) / arr.length;
                        const sd   = Math.sqrt(arr.reduce((s,v) => s+(v-mean)**2, 0) / arr.length);
                        const clean = arr.filter(v => Math.abs(v - mean) <= sd * 1.5);
                        const src   = clean.length >= 3 ? clean : arr; // fallback if too many were dropped
                        return +(src.reduce((s,v) => s+v, 0) / src.length).toFixed(1);
                    }
                    return {
                        total: filteredAvg(buf.map(v => v.total)),
                        od:    filteredAvg(buf.map(v => v.od)),
                        os:    filteredAvg(buf.map(v => v.os)),
                    };
                }

                function blendPD(a, b) {
                    // Captured frame is weighted higher (70%) vs live buffer (30%)
                    return {
                        total: +((a.total*0.7 + b.total*0.3)).toFixed(1),
                        od:    +((a.od   *0.7 + b.od   *0.3)).toFixed(1),
                        os:    +((a.os   *0.7 + b.os   *0.3)).toFixed(1),
                    };
                }

                // ============================================================
                // FACE SHAPE ANALYSIS — improved with forehead estimation + secondary metrics
                // ============================================================
                function analyzeFaceShape(lm, W, H) {
                    function dist3D(a, b) {
                        const dx=(a.x-b.x)*W, dy=(a.y-b.y)*H, dz=(a.z-b.z)*W;
                        return Math.sqrt(dx*dx+dy*dy+dz*dz);
                    }
                    const p = lm;
                    const faceWidth = dist3D(p[LM.JAW_LEFT], p[LM.JAW_RIGHT]);
                    // Improved face height using forehead estimation
                    const fhY = estimateForehead(lm, W, H);
                    const faceHeight = fhY !== null
                        ? Math.abs(p[LM.CHIN].y - fhY) * H
                        : Math.abs(p[LM.CHIN].y - (Math.min(p[LM.BROW_L].y, p[LM.BROW_R].y) - (p[LM.CHIN].y - Math.min(p[LM.BROW_L].y, p[LM.BROW_R].y)) * 0.18)) * H;
                    const foreheadWidth = dist3D(p[LM.TEMPLE_L], p[LM.TEMPLE_R]);
                    const cheekWidth    = dist3D(p[LM.CHEEK_L],  p[LM.CHEEK_R]);
                    const jawWidth      = dist3D(p[LM.JAW_L2],   p[LM.JAW_R2]);
                    const chinWidth     = dist3D(p[LM.CHIN_L],   p[LM.CHIN_R]);
                    function angle3D(center, a, b) {
                        const v1={x:(a.x-center.x)*W,y:(a.y-center.y)*H,z:(a.z-center.z)*W};
                        const v2={x:(b.x-center.x)*W,y:(b.y-center.y)*H,z:(b.z-center.z)*W};
                        const dot=v1.x*v2.x+v1.y*v2.y+v1.z*v2.z;
                        const m1=Math.sqrt(v1.x**2+v1.y**2+v1.z**2), m2=Math.sqrt(v2.x**2+v2.y**2+v2.z**2);
                        if(m1===0||m2===0) return 90;
                        return Math.acos(Math.max(-1,Math.min(1,dot/(m1*m2))))*180/Math.PI;
                    }
                    const chinAngle     = angle3D(p[LM.CHIN], p[LM.CHIN_L], p[LM.CHIN_R]);
                    const upperFaceRatio = foreheadWidth / (cheekWidth + 0.001);
                    const lowerTaper     = chinWidth / (cheekWidth + 0.001);
                    const faceRatio      = faceHeight / (faceWidth + 0.001);
                    const foreheadRatio  = foreheadWidth / (faceWidth + 0.001);
                    const cheekRatio     = cheekWidth / (faceWidth + 0.001);
                    const jawRatio       = jawWidth / (faceWidth + 0.001);
                    const chinRatio      = chinWidth / (jawWidth + 0.001);
                    const jawForeRatio   = jawWidth / (foreheadWidth + 0.001);
                    const scores = {
                        "OVAL":     calcOval(faceRatio, foreheadRatio, cheekRatio, jawRatio, chinAngle, upperFaceRatio, lowerTaper),
                        "ROUND":    calcRound(faceRatio, cheekRatio, jawRatio, chinAngle, lowerTaper),
                        "SQUARE":   calcSquare(faceRatio, jawRatio, chinAngle, chinRatio),
                        "OBLONG":   calcOblong(faceRatio, foreheadRatio, jawRatio, chinRatio),
                        "HEART":    calcHeart(foreheadRatio, jawRatio, chinAngle, jawForeRatio, lowerTaper),
                        "DIAMOND":  calcDiamond(foreheadRatio, cheekRatio, jawRatio, chinAngle, upperFaceRatio),
                        "TRIANGLE": calcTriangle(foreheadRatio, jawRatio, jawForeRatio, lowerTaper)
                    };
                    const totalScore = Object.values(scores).reduce((a,b)=>a+b,0);
                    const percentages = {};
                    for (const [k,v] of Object.entries(scores)) percentages[k] = totalScore>0 ? Math.round((v/totalScore)*100) : 0;
                    const shape      = Object.entries(scores).reduce((a,b)=>b[1]>a[1]?b:a)[0];
                    const confidence = percentages[shape];
                    return { shape, confidence, scores, percentages,
                        metrics: { faceRatio:+faceRatio.toFixed(3), foreheadRatio:+foreheadRatio.toFixed(3), cheekRatio:+cheekRatio.toFixed(3), jawRatio:+jawRatio.toFixed(3), chinRatio:+chinRatio.toFixed(3), chinAngle:+chinAngle.toFixed(1), upperFaceRatio:+upperFaceRatio.toFixed(3), lowerTaper:+lowerTaper.toFixed(3) }
                    };
                }

                // ============================================================
                // SCORING FUNCTIONS — improved with secondary parameters
                // ============================================================
                function calcOval(fr,fore,cheek,jaw,chinA,ufr,lt) {
                    let s=0;
                    if(fr>=1.25&&fr<=1.65) s+=3.0;
                    if(cheek>jaw&&cheek>fore) s+=2.5;
                    if(fore>jaw) s+=1.0;
                    if(jaw>=0.58&&jaw<=0.80) s+=1.5;
                    if(chinA>=65&&chinA<=115) s+=1.0;
                    if(lt>=0.45&&lt<=0.70) s+=1.0;
                    if(ufr>=0.88&&ufr<=1.05) s+=0.5;
                    return s;
                }
                function calcRound(fr,cheek,jaw,chinA,lt) {
                    let s=0;
                    if(fr<1.2) s+=4.0;
                    if(cheek>=0.85) s+=2.0;
                    if(jaw>=0.72) s+=1.5;
                    if(chinA>=125) s+=2.5;
                    if(lt>=0.60) s+=1.5;
                    return s;
                }
                function calcSquare(fr,jaw,chinA,chinR) {
                    let s=0;
                    if(fr>=0.90&&fr<=1.25) s+=2.5;
                    if(jaw>=0.82) s+=4.5;
                    if(chinA>=110) s+=2.5;
                    if(chinR>=0.75) s+=1.5;
                    return s;
                }
                function calcOblong(fr,fore,jaw,chinR) {
                    let s=0;
                    if(fr>=1.60) s+=5.0;
                    if(fore<0.82) s+=1.5;
                    if(jaw<0.72) s+=2.0;
                    if(chinR<0.55) s+=1.5;
                    return s;
                }
                function calcHeart(fore,jaw,chinA,jawFore,lt) {
                    let s=0;
                    if(fore>=0.92) s+=3.0;
                    if(jaw<0.68) s+=3.0;
                    if(chinA<80) s+=3.0;
                    if(jawFore<0.75) s+=1.0;
                    if(lt<0.40) s+=1.5;
                    return s;
                }
                function calcDiamond(fore,cheek,jaw,chinA,ufr) {
                    let s=0;
                    if(cheek>fore&&cheek>jaw) s+=5.0;
                    if(fore<0.82) s+=1.5;
                    if(jaw<0.68) s+=1.5;
                    if(chinA<95) s+=2.0;
                    if(ufr<0.88) s+=1.0;
                    return s;
                }
                function calcTriangle(fore,jaw,jawFore,lt) {
                    let s=0;
                    if(jawFore>=1.12) s+=5.0;
                    if(jaw>=0.85) s+=3.0;
                    if(fore<0.80) s+=2.0;
                    if(lt>=0.65) s+=1.0;
                    return s;
                }

                // ============================================================
                // DISPLAY FINAL RESULT
                // ============================================================
                function displayFinalResult(analysis, pd) {
                    const {shape, confidence: conf, percentages, metrics: m} = analysis;
                    const shapeDesc = {
                        OVAL:'Symmetrical face, slightly longer than wide, with soft lines.',
                        ROUND:'Rounded face, width and height nearly equal, blunt chin.',
                        SQUARE:'Strong wide jaw, square chin, balanced proportions.',
                        OBLONG:'Long and narrow face, forehead and jaw parallel.',
                        HEART:'Wide forehead tapering to a pointed chin.',
                        DIAMOND:'Prominent cheekbones, narrower forehead and jaw.',
                        TRIANGLE:'Jaw wider than forehead, face widens toward the bottom.'
                    };
                    const top3 = Object.entries(percentages).sort((a,b)=>b[1]-a[1]).slice(0,3);
                    const pdSrc = `*IOC ratio method — reference ${iocRefMM}mm`;
                    const pdHtml = pd
                        ? `<div style="margin-top:12px;padding:10px 15px;background:rgba(0,207,255,0.07);border:1px solid rgba(0,207,255,0.2);border-radius:12px;width:100%;max-width:100%;box-sizing:border-box;">
                            <div style="font-size:0.6rem;color:var(--text-muted);letter-spacing:1px;margin-bottom:6px;">PUPILLARY DISTANCE</div>
                            <div class="pd-row" style="margin-top:0;"><span class="pd-chip total">PD Total ${pd.total} mm</span></div>
                            <div class="pd-row" style="margin-top:6px;"><span class="pd-chip">OD (Right) ${pd.od} mm</span><span class="pd-chip">OS (Left) ${pd.os} mm</span></div>
                            <div class="pd-note" style="margin-top:5px;">${pdSrc}</div>
                           </div>`
                        : `<div style="font-size:10px;color:#555;margin-top:8px;">Iris data not available</div>`;

                    // Collapsible analysis-details block — hidden by default, toggled via header tap
                    const detailsHtml = `
                        <div id="analysis-details-wrap" style="width:100%;margin-top:10px;">
                            <div onclick="toggleAnalysisDetails()" style="cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;padding:7px 12px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:10px;">
                                <span style="font-size:0.6rem;color:#888;letter-spacing:1px;">ANALYSIS DETAILS</span>
                                <span id="analysis-details-chev" style="color:#888;font-size:11px;transition:transform 0.25s;display:inline-block;">▼</span>
                            </div>
                            <div id="analysis-details-body" style="display:none;padding:10px 4px 0 4px;">
                                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;max-width:260px;text-align:center;margin-left:auto;margin-right:auto;">${shapeDesc[shape]||''}</div>
                                <div class="conf-bar-wrap" style="width:80%;margin:8px auto 6px auto;"><div class="conf-bar-fill" style="width:${conf}%;"></div></div>
                                <div style="font-size:10px;color:#666;margin-bottom:8px;text-align:center;">${top3.map(([s,p])=>`${s} ${p}%`).join(' · ')}</div>
                                <div class="metrics-row">
                                    <span class="metric-chip">H/W ${m.faceRatio}</span>
                                    <span class="metric-chip">Forehead ${m.foreheadRatio}</span>
                                    <span class="metric-chip">Cheek ${m.cheekRatio}</span>
                                    <span class="metric-chip">Jaw ${m.jawRatio}</span>
                                    <span class="metric-chip">Chin ${m.chinAngle}°</span>
                                    <span class="metric-chip">Taper ${m.lowerTaper}</span>
                                </div>
                            </div>
                        </div>`;

                    resultBox.innerHTML = `
                        <div style="font-size:0.65rem;color:var(--text-muted);letter-spacing:1px;margin-bottom:6px;">ANALYSIS RESULT</div>
                        <div class="shape-badge" style="font-size:1.6rem;">${shape}</div>
                        ${pdHtml}
                        ${detailsHtml}
                    `;
                }

                // Collapsible toggle for the ANALYSIS DETAILS block
                function toggleAnalysisDetails() {
                    const b = document.getElementById('analysis-details-body');
                    const c = document.getElementById('analysis-details-chev');
                    if (!b) return;
                    const open = b.style.display === 'none';
                    b.style.display = open ? 'block' : 'none';
                    if (c) c.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
                }
                window.toggleAnalysisDetails = toggleAnalysisDetails;

                // ============================================================
                // FRAME RECOMMENDATIONS
                // ============================================================
                const frameRec = {
                    OVAL:{emoji:'◉',tagline:'Lucky you — almost any frame suits you.',best:[{s:'Rectangular / Wayfarer',r:'Adds definition to a proportional face',i:'▬'},{s:'Round / Circular',r:'Emphasizes the curve of an oval face',i:'●'},{s:'Cat-Eye / Upswept',r:'Adds expression and character',i:'◣'},{s:'Aviator',r:'Classic, timeless, well-proportioned',i:'▽'}],avoid:[],avoidNote:'No restrictions — every frame works!'},
                    ROUND:{emoji:'●',tagline:'Choose frames that add definition and length.',best:[{s:'Rectangular / Square',r:'Sharpens lines and elongates the look',i:'▬'},{s:'Browline / Club Master',r:'Horizontal line highlights facial structure',i:'⊓'},{s:'Wayfarer',r:'Trapezoid shape balances round faces',i:'⬡'},{s:'Angular / Geometric',r:'Contrasts the round curves, modern look',i:'◻'}],avoid:[{s:'Round / Circular',r:'Reinforces roundness'},{s:'Small Oval',r:'No visual contrast'}],avoidNote:'Avoid round frames and small ovals.'},
                    SQUARE:{emoji:'■',tagline:'Choose frames that soften a strong jawline.',best:[{s:'Round / Circular',r:'Softens a square jaw',i:'●'},{s:'Oval / Soft Rectangle',r:'Balances with gentle curves',i:'◉'},{s:'Semi-Rimless',r:'Visually light, does not thicken facial lines',i:'⌒'},{s:'Aviator / Teardrop',r:'Lower curve softens the jaw',i:'▽'}],avoid:[{s:'Strong Square / Rectangular',r:'Reinforces squareness'},{s:'Geometric angular',r:'Too many sharp angles'}],avoidNote:'Avoid strongly squared frames.'},
                    OBLONG:{emoji:'▬',tagline:'Choose frames that add width.',best:[{s:'Oversized / Wide Frame',r:'Fills the face width, balanced look',i:'▬'},{s:'Decorative Top Bar',r:'Horizontal line emphasizes width',i:'⊤'},{s:'Round',r:'Curves break up the elongated look',i:'●'},{s:'Low Bridge / Deep Lens',r:'Reduces the long proportion',i:'◎'}],avoid:[{s:'Small / Narrow frame',r:'Makes the face look even longer'},{s:'Small Rimless',r:'Provides no balancing effect'}],avoidNote:'Avoid narrow and small frames.'},
                    HEART:{emoji:'♥',tagline:'Choose frames that balance a wide forehead.',best:[{s:'Bottom-Heavy / Pear Shape',r:'Bottom-wide frames balance the forehead',i:'▽'},{s:'Rimless',r:'Minimal visual weight on top',i:'◌'},{s:'Oval / Round',r:'Softens a wide forehead contour',i:'◉'},{s:'Low Set Bridge',r:'Appears wider toward the bottom',i:'⊥'}],avoid:[{s:'Cat-Eye / Top-Heavy',r:'Enlarges an already wide forehead'},{s:'Embellished top rim',r:'Adds visual weight to the forehead'}],avoidNote:'Avoid cat-eye and top detailing.'},
                    DIAMOND:{emoji:'◆',tagline:'Choose frames that highlight the eyes.',best:[{s:'Cat-Eye / Upswept',r:'Balances prominent cheekbones',i:'◣'},{s:'Oval',r:'Softens cheek angles',i:'◉'},{s:'Rimless',r:'Does not add weight to dominant cheeks',i:'◌'},{s:'Browline',r:'Adds definition to the upper face',i:'⊓'}],avoid:[{s:'Narrow/Angular frame',r:'Reinforces cheek width'}],avoidNote:'Avoid frames that are too narrow at the cheeks.'},
                    TRIANGLE:{emoji:'▼',tagline:'Choose frames that widen the upper area.',best:[{s:'Cat-Eye / Top-Heavy',r:'Lifts the visual line, balances the jaw',i:'◣'},{s:'Browline / Club Master',r:'Strengthens a narrow forehead line',i:'⊓'},{s:'Embellished/Decorative top',r:'Draws attention away from the jaw',i:'✦'},{s:'Wide Top Frame',r:'Expands the upper visual area',i:'▬'}],avoid:[{s:'Bottom-heavy frame',r:'Reinforces jaw width'},{s:'Small narrow frame',r:'Does not help balance'}],avoidNote:'Avoid bottom-heavy frames.'},
                };

                // ============================================================
                // GENDER NORMALIZATION + AFFINITY (fix #5)
                // Returns 'female' | 'male' | '' (unknown). Accepts Indonesian + English.
                // ============================================================
                function normalizeGender(g) {
                    if (!g) return '';
                    const v = String(g).toLowerCase().trim();
                    if (['f','female','wanita','perempuan','woman','w','p'].includes(v)) return 'female';
                    if (['m','male','pria','laki-laki','laki laki','man','l'].includes(v)) return 'male';
                    return '';
                }

                // Keyword-based affinity. Higher = better fit for that gender.
                // Applied on top of the base shape-fit order to re-rank recommendations.
                function genderAffinity(frameName, gender) {
                    if (!gender) return 0;
                    const n = frameName.toLowerCase();
                    const femaleBoost = [
                        { kw:'cat-eye',        w:3 },
                        { kw:'cat eye',        w:3 },
                        { kw:'upswept',        w:2 },
                        { kw:'oval',           w:2 },
                        { kw:'round',          w:2 },
                        { kw:'rimless',        w:2 },
                        { kw:'soft',           w:1 },
                        { kw:'decorative',     w:2 },
                        { kw:'embellished',    w:2 }
                    ];
                    const maleBoost = [
                        { kw:'wayfarer',       w:3 },
                        { kw:'browline',       w:3 },
                        { kw:'club master',    w:3 },
                        { kw:'clubmaster',     w:3 },
                        { kw:'aviator',        w:3 },
                        { kw:'rectangular',    w:3 },
                        { kw:'square',         w:2 },
                        { kw:'angular',        w:2 },
                        { kw:'geometric',      w:2 },
                        { kw:'oversized',      w:1 },
                        { kw:'wide',           w:1 },
                        { kw:'top bar',        w:1 }
                    ];
                    const list = gender === 'female' ? femaleBoost : maleBoost;
                    let score = 0;
                    for (const { kw, w } of list) if (n.includes(kw)) score += w;
                    return score;
                }

                // ============================================================
                // FRAME SHAPE → COLOR LOOKUP
                // Matches descriptive frame names (e.g. "Rectangular / Wayfarer")
                // against keys in frameShapeColors ("WAYFARER", "ROUND", ...).
                // A single frame can map to multiple shape-colors — all matches
                // are returned, ordered by where they appear in the name.
                // ============================================================
                function getFrameColors(frameName) {
                    if (!frameShapeColors || !frameName) return [];
                    const nUpper = String(frameName).toUpperCase();
                    // Helper: returns ALL word-boundary match positions of `needle` in nUpper
                    function findWordAll(needle) {
                        const esc = needle.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&');
                        const re = new RegExp('(^|[^A-Z])' + esc + '(?=[^A-Z]|$)', 'g');
                        const out = [];
                        let m;
                        while ((m = re.exec(nUpper)) !== null) {
                            out.push(m.index + m[1].length);
                        }
                        return out;
                    }
                    // Synonym map: each surface form (left) can map to one OR MORE JSON keys (right).
                    // The matcher will add a swatch for every listed target that exists in the JSON.
                    // This lets a name like "Rectangular / Square" show BOTH a Rectangle
                    // swatch (if that key is in the JSON) AND a Square swatch.
                    const synonyms = {
                        'CIRCULAR':      ['ROUND'],
                        'CLUB MASTER':   ['BROWLINE'],
                        'CLUBMASTER':    ['BROWLINE'],
                        'UPSWEPT':       ['CAT-EYE'],
                        'RECTANGULAR':   ['RECTANGLE', 'SQUARE'],
                        'RECTANGLE':     ['RECTANGLE', 'SQUARE'],
                        'TEARDROP':      ['AVIATOR'],
                        'ANGULAR':       ['GEOMETRIC'],
                        'PEAR SHAPE':    ['BUTTERFLY'],
                        'PEAR':          ['BUTTERFLY'],
                        'BOTTOM-HEAVY':  ['BUTTERFLY'],
                        'TOP-HEAVY':     ['CAT-EYE']
                    };
                    // Collect (position, key) hits. De-dup by (pos, key) so the same word
                    // isn't counted twice when it's both a direct match and a synonym.
                    const hits = [];
                    const seen = new Set();
                    function add(key, pos) {
                        if (!(key in frameShapeColors)) return;
                        const sig = pos + ':' + key;
                        if (seen.has(sig)) return;
                        seen.add(sig);
                        hits.push({ key, pos });
                    }
                    // Direct key hits
                    for (const k of Object.keys(frameShapeColors)) {
                        for (const pos of findWordAll(k)) add(k, pos);
                    }
                    // Synonym hits (each synonym can fan out to several target keys)
                    for (const [syn, targets] of Object.entries(synonyms)) {
                        const positions = findWordAll(syn);
                        if (!positions.length) continue;
                        for (const pos of positions) {
                            for (const key of targets) add(key, pos);
                        }
                    }
                    hits.sort((a, b) => a.pos - b.pos);
                    // Final de-dup by key (keep first occurrence) so we never show the
                    // same swatch twice just because the frame name contained the word
                    // more than once.
                    const out = [];
                    const seenKey = new Set();
                    for (const h of hits) {
                        if (seenKey.has(h.key)) continue;
                        seenKey.add(h.key);
                        out.push({ name: h.key, color: frameShapeColors[h.key] });
                    }
                    return out;
                }

                // Render helper: colored swatch pills for a given frame name.
                // size: 'sm' (default — for legend & avoid list) or 'lg' (recommended list)
                function renderColorSwatches(frameName, size) {
                    const colors = getFrameColors(frameName);
                    if (!colors.length) return '';
                    const large = size === 'lg';
                    const dot   = large ? 18 : 11;            // circle diameter
                    const font  = large ? 11 : 9;             // label font-size
                    const padY  = large ? 4 : 2;
                    const padX  = large ? 10 : 7;
                    const gap   = large ? 7 : 4;
                    const marginTop = large ? 8 : 5;
                    return `<div style="display:flex;flex-wrap:wrap;gap:${gap}px;margin-top:${marginTop}px;">`
                        + colors.map(c => `
                            <span style="display:inline-flex;align-items:center;gap:${large?6:4}px;padding:${padY}px ${padX}px ${padY}px ${Math.max(padY,3)}px;border-radius:20px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);font-size:${font}px;letter-spacing:0.5px;color:#ddd;font-weight:${large?700:500};">
                                <span style="display:inline-block;width:${dot}px;height:${dot}px;border-radius:50%;background:${c.color};box-shadow:0 0 ${large?8:4}px ${c.color}99,inset 0 0 0 1px rgba(255,255,255,0.2);flex-shrink:0;"></span>
                                ${c.name}
                            </span>`).join('')
                        + `</div>`;
                }

                function showFrameRecommendation(shape) {
                    const rec = frameRec[shape]; if (!rec) return;
                    const gender = normalizeGender(patientGender);

                    // Re-rank recommendations: base rank (higher = better) + gender affinity
                    const len = rec.best.length;
                    const ranked = rec.best.map((f, idx) => ({
                        ...f,
                        _base:    len - idx,          // preserve original priority
                        _gender:  genderAffinity(f.s, gender),
                        _score:   (len - idx) + genderAffinity(f.s, gender) * 1.5
                    }))
                    .sort((a, b) => b._score - a._score);

                    const genderLabel = gender === 'female' ? '♀ Female' : gender === 'male' ? '♂ Male' : '';
                    const genderNote  = gender
                        ? `<div style="font-size:9px;color:#888;letter-spacing:0.5px;margin-bottom:8px;">Priority tailored for: <span style="color:#00cfff;font-weight:700;">${genderLabel}</span></div>`
                        : '';

                    // Build a compact legend of all frame-shape colors available (collapsible)
                    const legendEntries = Object.entries(frameShapeColors || {});
                    const legendHtml = legendEntries.length
                        ? `<div style="margin-bottom:10px;background:rgba(255,255,255,0.025);border:1px solid rgba(255,255,255,0.06);border-radius:10px;overflow:hidden;">
                             <div onclick="toggleCollapsible('frame-legend')" style="cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">
                                 <span style="font-size:0.55rem;color:#888;letter-spacing:1px;">FRAME SHAPE COLOR GUIDE</span>
                                 <span id="frame-legend-chev" style="color:#888;font-size:11px;transition:transform 0.25s;display:inline-block;">▼</span>
                             </div>
                             <div id="frame-legend-body" style="display:none;padding:0 10px 10px 10px;">
                                 <div style="display:flex;flex-wrap:wrap;gap:5px;">
                                   ${legendEntries.map(([name, color]) => `
                                     <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px 2px 3px;border-radius:20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);font-size:9px;color:#bbb;letter-spacing:0.5px;">
                                       <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};box-shadow:0 0 4px ${color}66,inset 0 0 0 1px rgba(255,255,255,0.15);"></span>
                                       ${name}
                                     </span>`).join('')}
                                 </div>
                             </div>
                           </div>`
                        : '';

                    // Build collapsible "Frames to Avoid" section
                    const avoidHtml = rec.avoid.length
                        ? `<div style="margin-top:6px;background:rgba(255,77,77,0.03);border:1px solid rgba(255,77,77,0.10);border-radius:10px;overflow:hidden;">
                             <div onclick="toggleCollapsible('frame-avoid')" style="cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">
                                 <span style="font-size:0.6rem;color:#ff4d4d;letter-spacing:1px;">✕ FRAMES TO AVOID (${rec.avoid.length})</span>
                                 <span id="frame-avoid-chev" style="color:#ff4d4d;font-size:11px;transition:transform 0.25s;display:inline-block;">▼</span>
                             </div>
                             <div id="frame-avoid-body" style="display:none;padding:0 10px 10px 10px;">
                                 <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                                     ${rec.avoid.map(f => `<div style="display:flex;align-items:flex-start;gap:8px;padding:7px 10px;background:rgba(255,77,77,0.05);border:1px solid rgba(255,77,77,0.12);border-radius:10px;">
                                         <span style="font-size:12px;color:#ff4d4d;">✕</span>
                                         <div style="flex:1;min-width:0;"><div style="font-size:11px;color:#ff4d4d;font-weight:700;">${f.s}</div><div style="font-size:10px;color:#777;margin-top:2px;">${f.r}</div>${renderColorSwatches(f.s)}</div>
                                     </div>`).join('')}
                                 </div>
                                 <div style="font-size:10px;color:#555;font-style:italic;">${rec.avoidNote}</div>
                             </div>
                           </div>`
                        : `<div style="font-size:10px;color:#00ff88;font-style:italic;margin-top:6px;">${rec.avoidNote}</div>`;

                    frameRecContent.innerHTML = `
                        <div style="font-size:12px;color:#ffaa00;font-weight:700;margin-bottom:8px;">${rec.emoji} ${shape} — ${rec.tagline}</div>
                        ${genderNote}
                        ${legendHtml}
                        <div style="font-size:0.6rem;color:var(--text-muted);letter-spacing:1px;margin-bottom:6px;">✓ RECOMMENDED FRAMES (BY PRIORITY)</div>
                        <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:12px;">
                            ${ranked.map((f, i) => {
                                const isTop = i === 0;
                                const bg    = isTop ? 'rgba(255,170,0,0.10)' : 'rgba(0,255,136,0.05)';
                                const bd    = isTop ? 'rgba(255,170,0,0.45)' : 'rgba(0,255,136,0.12)';
                                const color = isTop ? '#ffaa00' : '#00ff88';
                                const rank  = `<span style="display:inline-flex;min-width:18px;height:18px;align-items:center;justify-content:center;border-radius:50%;background:${isTop?'rgba(255,170,0,0.25)':'rgba(0,255,136,0.15)'};color:${color};font-size:9px;font-weight:800;margin-right:4px;">${i+1}</span>`;
                                const badge = isTop
                                    ? `<span style="display:inline-block;background:#ffaa00;color:#111;font-size:8px;font-weight:800;letter-spacing:0.8px;padding:2px 7px;border-radius:10px;margin-left:6px;vertical-align:middle;">★ TOP PICK</span>`
                                    : '';
                                return `<div style="display:flex;align-items:flex-start;gap:8px;padding:9px 11px;background:${bg};border:1px solid ${bd};border-radius:10px;">
                                    <span style="font-size:14px;min-width:20px;text-align:center;">${f.i}</span>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:11px;color:${color};font-weight:700;">${rank}${f.s}${badge}</div>
                                        <div style="font-size:10px;color:#888;margin-top:2px;">${f.r}</div>
                                        ${renderColorSwatches(f.s, 'lg')}
                                    </div>
                                </div>`;
                            }).join('')}
                        </div>
                        ${avoidHtml}
                    `;
                    frameRecBox.style.display = 'block';
                }

                // Generic collapsible toggle used by the legend + avoid sections.
                // Expects an element with id "{prefix}-body" and chevron "{prefix}-chev".
                function toggleCollapsible(prefix) {
                    const body = document.getElementById(prefix + '-body');
                    const chev = document.getElementById(prefix + '-chev');
                    if (!body) return;
                    const open = body.style.display === 'none';
                    body.style.display = open ? 'block' : 'none';
                    if (chev) chev.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
                }
                window.toggleCollapsible = toggleCollapsible;

                // ============================================================
                // RESET
                // ============================================================
                function resetScan() {
                    isCaptured = false; capturedLM = null; pdBuffer = []; shapeBuffer = []; lastPD = null;
                    guide.classList.remove('locked');
                    liveView.style.display      = 'block';
                    capturedView.style.display  = 'none';
                    captureBtn.style.display    = 'inline-block';
                    resetBtn.style.display      = 'none';
                    switchBtn.style.display     = 'inline-block';
                    qualityWrap.style.display   = 'block';
                    poseIndicator.style.display = 'block';
                    frameRecBox.style.display   = 'none';
                    const calEl = document.getElementById('cal-box');
                    if (calEl) calEl.style.display = 'none';
                    // Reset calibration to default
                    iocRefMM = 95;
                    const calLbl = document.getElementById('cal-active-label');
                    if (calLbl) calLbl.textContent = 'IOC 95mm';
                    resultBox.innerHTML = `<span style="color:var(--text-muted);font-size:0.75rem">Position your face...</span>`;
                    document.getElementById('autocap-hint').style.display = 'none';
                    autoCapStableSince = 0;
                    setStep(2);
                    startCamera();
                }

                // ============================================================
                // UTILITY
                // ============================================================
                function mostFrequent(arr) {
                    if (!arr.length) return 'OVAL';
                    const freq = {};
                    arr.forEach(v => freq[v] = (freq[v]||0)+1);
                    return Object.entries(freq).reduce((a,b) => b[1]>a[1]?b:a)[0];
                }

                // ============================================================
                // EVENT HANDLERS
                // ============================================================
                startBtn.onclick   = () => {
                    if (isRunning) return;
                    if (faceMesh) {
                        // MediaPipe already loaded (user pressed RESCAN) — skip the script reload
                        startCamera();
                    } else {
                        loadMediaPipe();
                        startBtn.innerHTML = '<div class="led"></div> LOADING...';
                        startBtn.disabled = true;
                    }
                };
                switchBtn.onclick  = () => { facingMode = facingMode==='user'?'environment':'user'; startCamera(); };
                captureBtn.onclick = () => capturePhoto();
                retakeOverlay.onclick = () => resetScan();
                resetBtn.onclick   = () => resetScan();

            })(); // end IIFE

        </script>
    </body>
</html>