<?php
// lensmeter.php — Manual Lensometer Calculator
session_start();
include 'db_config.php';
include 'config_helper.php';
include 'auth_check.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lensometer Calculator</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Base ────────────────────────────────────────────────────────── */
        body { background:#0f1115; color:#c9cdd4; font-family:'Segoe UI',sans-serif; margin:0; }

        /* ── Page header ─────────────────────────────────────────────────── */
        .page-header { text-align:center; margin-bottom:28px; }
        .page-header h2 { margin:0 0 6px; font-size:22px; font-weight:800; color:#e5e7eb; text-transform:uppercase; letter-spacing:1px; }
        .page-header p  { margin:0; color:#6b7280; font-size:12px; }

        /* ── Eye tab buttons ──────────────────────────────────────────────── */
        .eye-tabs { display:flex; gap:12px; justify-content:center; margin-bottom:24px; }
        .eye-tab {
            padding:10px 32px; border-radius:8px; border:1px solid #2e3138;
            background:#1a1d22; color:#6b7280; font-weight:700; font-size:13px;
            cursor:pointer; transition:all .2s; letter-spacing:.3px;
        }
        .eye-tab:hover { border-color:#374151; color:#c9cdd4; }
        .eye-tab.od.active { background:#1a1d22; border-color:#00ff88; color:#00ff88; box-shadow:0 0 0 1px #00ff8833; }
        .eye-tab.os.active { background:#1a1d22; border-color:#00ff88; color:#00ff88; box-shadow:0 0 0 1px #00ff8833; }

        /* ── Main card ───────────────────────────────────────────────────── */
        .card {
            background:#1a1d22; border:1px solid #252830; border-radius:12px;
            padding:24px; width:100%; max-width:100%; margin:0 auto 20px; box-sizing:border-box;
        }
        .section-title {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.8px; color:#4b5563; margin:0 0 16px;
        }

        /* ── Eye panel toggle ────────────────────────────────────────────── */
        .eye-panel { display:none; }
        .eye-panel.active { display:block; animation:slideDown .2s ease-out; }
        @keyframes slideDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }

        /* ── Reading blocks ──────────────────────────────────────────────── */
        .reading-block {
            background:#13151a; border:1px solid #252830; border-radius:10px;
            padding:16px; margin-bottom:12px;
        }
        .reading-block.dua  { border-left:3px solid #34d399; }
        .reading-block.tiga { border-left:3px solid #fb923c; }

        .reading-label {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.8px; margin-bottom:14px;
            display:flex; align-items:center; gap:8px;
        }
        .reading-label.dua  { color:#34d399; }
        .reading-label.tiga { color:#fb923c; }

        /* Line icons */
        .lines-icon { display:inline-flex; align-items:center; gap:2px; }
        .line-h { width:14px; height:2px; background:currentColor; border-radius:1px; }
        .line-v { width:2px; height:13px; background:currentColor; border-radius:1px; }

        /* ── Input grid ──────────────────────────────────────────────────── */
        .input-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .field { display:flex; flex-direction:column; gap:5px; }
        .field label {
            display:block; font-size:10px; font-weight:600;
            text-transform:uppercase; letter-spacing:.6px; color:#4b5563;
        }

        /* ── Inputs ──────────────────────────────────────────────────────── */
        .rx-input {
            width:100%; background:#0d0f12; border:1px solid #252830;
            border-radius:6px; color:#e5e7eb; font-size:16px; font-weight:700;
            padding:10px 8px; outline:none; text-align:center;
            transition:border-color .2s, box-shadow .2s; box-sizing:border-box;
        }
        .rx-input:focus        { border-color:#00ff88; box-shadow:0 0 0 2px #00ff8820; color:#fff; }
        .rx-input.dua:focus    { border-color:#34d399; box-shadow:0 0 0 2px #34d39920; }
        .rx-input.tiga:focus   { border-color:#fb923c; box-shadow:0 0 0 2px #fb923c20; }
        .rx-input.axis-d:focus { border-color:#34d39966; }
        .rx-input.axis-t:focus { border-color:#fb923c66; }

        .field-hint { font-size:10px; color:#374151; font-style:italic; text-align:center; }

        /* ── Divider ─────────────────────────────────────────────────────── */
        .card-divider { border:none; border-top:1px solid #252830; margin:16px 0; }

        /* ── Buttons ─────────────────────────────────────────────────────── */
        .btn-reset {
            width:100%; padding:13px; border:none;
            background:#00ff88; color:#0a0f0a; border-radius:8px;
            cursor:pointer; font-size:14px; font-weight:800; letter-spacing:.5px;
            text-transform:uppercase; transition:all .2s; margin-top:4px;
        }
        .btn-reset:hover  { background:#00e07a; box-shadow:0 0 0 3px #00ff8830; }
        .btn-reset:active { transform:translateY(1px); }

        /* ── Result card ─────────────────────────────────────────────────── */
        .result-card {
            background:#1a1d22; border:1px solid #252830; border-radius:12px;
            padding:24px; width:100%; max-width:100%; margin:0 auto;
            display:none; box-sizing:border-box;
        }
        .result-card.show { display:block; animation:slideDown .2s ease-out; }

        .result-title {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.8px; color:#00ff88; margin-bottom:16px;
            display:flex; align-items:center; gap:8px;
        }
        .result-title::after { content:''; flex:1; height:1px; background:#252830; }

        /* ── Result grid ─────────────────────────────────────────────────── */
        .result-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:500px){ .result-grid { grid-template-columns:1fr; } }

        /* ── Tables ──────────────────────────────────────────────────────── */
        .result-table, .trans-table { width:100%; border-collapse:collapse; }
        .result-table th, .trans-table th {
            font-size:9px; font-weight:700; text-transform:uppercase;
            letter-spacing:.6px; color:#374151; padding:5px 6px;
            text-align:center; border-bottom:1px solid #252830;
        }
        .result-table th:first-child, .trans-table th:first-child { text-align:left; }
        .result-table td, .trans-table td {
            padding:10px 6px; text-align:center;
            font-size:14px; font-weight:700; border-bottom:1px solid #13151a;
        }
        .result-table td:first-child, .trans-table td:first-child {
            text-align:left; font-size:11px; font-weight:800; letter-spacing:.8px;
        }
        .td-od { color:#60a5fa; }
        .td-os { color:#a78bfa; }
        .val-pos  { color:#00ff88; }
        .val-neg  { color:#f87171; }
        .val-zero { color:#374151; }
        .val-axis { color:#a78bfa; }
        .val-se   { color:#2dd4bf; font-size:12px; }

        /* ── Transposition card ────────────────────────────────────────────── */
        .trans-card {
            background:#13151a; border:1px solid #252830; border-radius:10px; padding:16px;
        }
        .trans-title {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.7px; color:#f59e0b; margin-bottom:12px;
            display:flex; align-items:center; gap:6px;
        }
        .trans-title::after { content:''; flex:1; height:1px; background:#252830; }

        /* ── Notation box ────────────────────────────────────────────────── */
        .notation-box {
            background:#13151a; border:1px solid #252830; border-radius:8px;
            padding:12px 14px; margin-top:12px; line-height:2;
        }
        .notation-box .label {
            font-size:9px; font-weight:700; text-transform:uppercase;
            letter-spacing:.6px; color:#374151; margin-bottom:6px;
        }
        .notation-row { display:flex; align-items:center; gap:5px; flex-wrap:wrap; margin-bottom:2px; }
        .n-eye  { font-size:11px; font-weight:800; letter-spacing:.8px; min-width:24px; }
        .n-eye.od { color:#60a5fa; }
        .n-eye.os { color:#a78bfa; }
        .n-val  { font-size:14px; font-weight:700; }
        .n-sep  { color:#374151; font-size:12px; }
        .n-ax   { color:#a78bfa; font-size:12px; }
        .n-sph-only { color:#6b7280; font-size:11px; font-style:italic; }
        .n-se   { color:#2dd4bf; font-size:11px; margin-left:6px; }

        /* ── Warn box ────────────────────────────────────────────────────── */
        .warn-box {
            background:#2a1a0e; border:1px solid #78350f; border-radius:8px;
            padding:10px 14px; font-size:11px; color:#fbbf24;
            margin-top:12px; display:none; line-height:1.7;
        }
        .warn-box.show { display:block; }

        /* ── Mobile ──────────────────────────────────────────────────────── */
        @media(max-width:600px){
            .eye-tabs { gap:8px; }
            .eye-tab  { padding:10px 16px; font-size:12px; flex:1; }
            .card, .result-card { padding:16px; }
        }
    </style>
</head>
<body>
<div class="main-wrapper">
    <div class="content-area" style="flex-direction:column;">

        <!-- Header -->
        <div class="header-container" style="margin:0 auto;width:100%;">
            <button class="logout-btn" onclick="window.location.href='logout.php';"><span>Logout</span></button>
            <div class="brand-section">
                <div class="logo-box">
                    <img src="<?php echo htmlspecialchars($BRAND_IMAGE_PATH); ?>?t=<?php echo time(); ?>" alt="Brand Logo" style="height:40px;">
                </div>
                <h1 class="company-name"><?php echo htmlspecialchars($STORE_NAME); ?></h1>
                <p class="company-address"><?php echo htmlspecialchars($STORE_ADDRESS); ?></p>
            </div>
        </div>

        <div class="config-window">

            <div class="page-header">
                <h2>👁 Lensometer Calculator</h2>
                <p>Enter lensometer readings &rarr; SPH / CYL / AXIS calculated automatically</p>
            </div>

<div class="card">
    <p class="section-title">Lensometer Readings</p>

    <!-- Eye tabs -->
    <div class="eye-tabs">
        <div class="eye-tab od active" onclick="switchEye('od')">OD &nbsp;·&nbsp; Right</div>
        <div class="eye-tab os"        onclick="switchEye('os')">OS &nbsp;·&nbsp; Left</div>
    </div>

    <!-- OD -->
    <div class="eye-panel active" id="panel-od">

        <!-- 2 garis -->
        <div class="reading-block dua">
            <div class="reading-label dua">
                <span class="lines-icon" style="color:#34d399;">
                    <span class="line-h"></span>&nbsp;<span class="line-h"></span>
                </span>
                2-Line Focus
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="text" id="od_dua_power" class="rx-input dua"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>Sudut (°)</label>
                    <input type="number" id="od_dua_axis" class="rx-input axis-d"
                           placeholder="0" min="0" max="180"
                           onfocus="this.select()" oninput="autoFillTigaAxis('od')">
                    <span class="field-hint">0 – 180°</span>
                </div>
            </div>
        </div>

        <!-- 3 garis -->
        <div class="reading-block tiga">
            <div class="reading-label tiga">
                <span class="lines-icon" style="color:#fb923c;">
                    <span class="line-v"></span><span class="line-v"></span><span class="line-v"></span>
                </span>
                3-Line Focus
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="text" id="od_tiga_power" class="rx-input tiga"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>Sudut (°)</label>
                    <input type="number" id="od_tiga_axis" class="rx-input axis-t"
                           placeholder="0" min="0" max="180"
                           onfocus="this.select()" oninput="autoFillDuaAxis('od')">
                    <span class="field-hint">0 – 180°</span>
                </div>
            </div>
        </div>

    </div><!-- end panel-od -->

    <!-- OS -->
    <div class="eye-panel" id="panel-os">

        <div class="reading-block dua">
            <div class="reading-label dua">
                <span class="lines-icon" style="color:#34d399;">
                    <span class="line-h"></span>&nbsp;<span class="line-h"></span>
                </span>
                2-Line Focus
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="text" id="os_dua_power" class="rx-input dua"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>Sudut (°)</label>
                    <input type="number" id="os_dua_axis" class="rx-input axis-d"
                           placeholder="0" min="0" max="180"
                           onfocus="this.select()" oninput="autoFillTigaAxis('os')">
                    <span class="field-hint">0 – 180°</span>
                </div>
            </div>
        </div>

        <div class="reading-block tiga">
            <div class="reading-label tiga">
                <span class="lines-icon" style="color:#fb923c;">
                    <span class="line-v"></span><span class="line-v"></span><span class="line-v"></span>
                </span>
                3-Line Focus
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="text" id="os_tiga_power" class="rx-input tiga"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>Sudut (°)</label>
                    <input type="number" id="os_tiga_axis" class="rx-input axis-t"
                           placeholder="0" min="0" max="180"
                           onfocus="this.select()" oninput="autoFillDuaAxis('os')">
                    <span class="field-hint">0 – 180°</span>
                </div>
            </div>
        </div>

    </div><!-- end panel-os -->

    <button class="btn-reset" onclick="resetAll()">↺ Reset</button>
</div>

<!-- Hasil -->
<div class="result-card" id="result-card">
    <div class="result-title">✓ Results</div>

    <div class="result-grid">
        <!-- Hasil -->
        <div>
            <table class="result-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>SPH</th>
                        <th>CYL</th>
                        <th>AXIS</th>
                        <th>SE</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="td-od">OD</td>
                        <td id="r_od_sph">—</td>
                        <td id="r_od_cyl">—</td>
                        <td id="r_od_axis">—</td>
                        <td id="r_od_se">—</td>
                    </tr>
                    <tr>
                        <td class="td-os">OS</td>
                        <td id="r_os_sph">—</td>
                        <td id="r_os_cyl">—</td>
                        <td id="r_os_axis">—</td>
                        <td id="r_os_se">—</td>
                    </tr>
                </tbody>
            </table>
            <div class="notation-box" id="notation-box" style="margin-top:12px;"></div>
        </div>

        <!-- Transposisi -->
        <div class="trans-card">
            <div class="trans-title">⇄ Transposition</div>
            <table class="trans-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>SPH</th>
                        <th>CYL</th>
                        <th>AXIS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="td-od">OD</td>
                        <td id="t_od_sph">—</td>
                        <td id="t_od_cyl">—</td>
                        <td id="t_od_axis">—</td>
                    </tr>
                    <tr>
                        <td class="td-os">OS</td>
                        <td id="t_os_sph">—</td>
                        <td id="t_os_cyl">—</td>
                        <td id="t_os_axis">—</td>
                    </tr>
                </tbody>
            </table>
            <div class="notation-box" id="notation-trans" style="margin-top:12px;"></div>
        </div>
    </div>

    <div class="warn-box" id="warn-box"></div>
</div>

<script>
// ── Auto-fill axis — bidirectional ────────────────────────────────────────────
// Input axis on either side → the other fills automatically (always 90° apart)
function pairAxis(val) {
    return val > 90 ? val - 90 : val + 90;
}
function autoFillDuaAxis(eye) {
    var tigaEl = document.getElementById(eye + '_tiga_axis');
    var duaEl  = document.getElementById(eye + '_dua_axis');
    var val    = parseInt(tigaEl.value);
    if (isNaN(val) || tigaEl.value.trim() === '') {
        duaEl.value = '';
    } else {
        duaEl.value = pairAxis(val);
    }
    liveCalc();
}
function autoFillTigaAxis(eye) {
    var duaEl  = document.getElementById(eye + '_dua_axis');
    var tigaEl = document.getElementById(eye + '_tiga_axis');
    var val    = parseInt(duaEl.value);
    if (isNaN(val) || duaEl.value.trim() === '') {
        tigaEl.value = '';
    } else {
        tigaEl.value = pairAxis(val);
    }
    liveCalc();
}

// ── Tab switching ────────────────────────────────────────────────────────────
function switchEye(eye) {
    ['od','os'].forEach(function(e) {
        document.getElementById('panel-' + e).classList.toggle('active', e === eye);
        document.querySelectorAll('.eye-tab.' + e)[0].classList.toggle('active', e === eye);
    });
}

// ── Parse ────────────────────────────────────────────────────────────────────
// Mendukung shortcut tanpa titik desimal:
//   -200  → -2.00      200  → +2.00
//   -125  → -1.25      75   → +0.75
// Aturan: jika tidak ada titik dan |nilai| >= 25 (kelipatan 25) → bagi 100
function parseVal(s) {
    s = String(s).trim();
    if (!s || s === '-' || s.toLowerCase() === 'pl') return null;
    var n = parseFloat(s);
    if (isNaN(n)) return null;
    // Jika input tidak mengandung titik desimal DAN nilainya kelipatan 25
    // dan |n| >= 25 → anggap format shortcut (sudah dalam 1/100 dioptri)
    if (s.indexOf('.') === -1 && Math.abs(n) >= 25 && Math.round(n) % 25 === 0) {
        n = n / 100;
    }
    return n;
}
function roundQ(n) { return Math.round(n * 4) / 4; }
function fmtRx(n) {
    if (n === 0) return '0.00';
    return (n > 0 ? '+' : '') + n.toFixed(2);
}
function valCls(n) {
    return n > 0 ? 'val-pos' : (n < 0 ? 'val-neg' : 'val-zero');
}

// ── Kalkulasi inti ───────────────────────────────────────────────────────────
// Input:
//   tigaPower, tigaAxis = power & axis when 3-line focus (vertical meridian)
//   duaPower,  duaAxis  = power & axis when 2-line focus (horizontal meridian)
//
// Prinsip:
//   - 3-line focus at angle X  → CYL meridian (CYL axis = X)
//   - 2-line focus at angle Y  → SPH meridian (Y should be X ± 90°)
//   - SPH  = power at 2-line focus
//   - CYL  = tigaPower − duaPower  (konvensi minus cyl → selalu ≤ 0)
//   - AXIS = angle at 3-line focus (tigaAxis)
//
// Catatan: jika tigaPower > duaPower (tidak lazim), sistem tetap hitung
// dengan peringatan — bisa terjadi jika lensa plus tinggi.

function calcEye(tigaPower, tigaAxis, duaPower, duaAxis) {
    var tp = parseVal(tigaPower);
    var dp = parseVal(duaPower);
    var ta = parseInt(tigaAxis) || 0;

    // Jika keduanya kosong
    if (tp === null && dp === null) return null;

    // Jika hanya satu yang diisi → sferis murni
    if (tp === null) { tp = dp; ta = 0; }
    if (dp === null) { dp = tp; }

    tp = roundQ(tp);
    dp = roundQ(dp);

    var sph, cyl, axis;

    if (Math.abs(tp - dp) < 0.01) {
        // Sferis murni
        sph  = dp;
        cyl  = 0;
        axis = 0;
    } else {
        // SPH  = power at 2-line focus (horizontal meridian)
        // CYL  = power 3-line − power 2-line (can be + or −)
        // AXIS = angle at 3-line focus
        sph  = dp;
        cyl  = roundQ(tp - dp);
        axis = ta;
    }

    var se = Math.round((sph + cyl / 2) * 100) / 100;

    return { sph: sph, cyl: cyl, axis: axis, se: se };
}

// ── Render hasil ─────────────────────────────────────────────────────────────
function setCell(id, text, cls) {
    var el = document.getElementById(id);
    el.textContent = text;
    el.className   = cls || '';
}

function calculate() {
    var od = calcEye(
        document.getElementById('od_tiga_power').value,
        document.getElementById('od_tiga_axis').value,
        document.getElementById('od_dua_power').value,
        document.getElementById('od_dua_axis').value
    );
    var os = calcEye(
        document.getElementById('os_tiga_power').value,
        document.getElementById('os_tiga_axis').value,
        document.getElementById('os_dua_power').value,
        document.getElementById('os_dua_axis').value
    );

    if (!od && !os) return;
    if (!od) od = { sph:0, cyl:0, axis:0, se:0, warn:'' };
    if (!os) os = { sph:0, cyl:0, axis:0, se:0, warn:'' };

    // OD
    setCell('r_od_sph',  fmtRx(od.sph),  valCls(od.sph));
    setCell('r_od_cyl',  od.cyl === 0 ? 'sph' : fmtRx(od.cyl), od.cyl === 0 ? 'val-zero' : valCls(od.cyl));
    setCell('r_od_axis', od.cyl === 0 ? '—' : od.axis + '°', 'val-axis');
    setCell('r_od_se',   fmtRx(od.se), 'val-se');

    // OS
    setCell('r_os_sph',  fmtRx(os.sph),  valCls(os.sph));
    setCell('r_os_cyl',  os.cyl === 0 ? 'sph' : fmtRx(os.cyl), os.cyl === 0 ? 'val-zero' : valCls(os.cyl));
    setCell('r_os_axis', os.cyl === 0 ? '—' : os.axis + '°', 'val-axis');
    setCell('r_os_se',   fmtRx(os.se), 'val-se');



    // Notation
    var nb = document.getElementById('notation-box');
    nb.innerHTML = '<div class="label">Prescription</div>'
        + buildRow('OD', od) + buildRow('OS', os);

    // Transposition
    var odT = transpose(od);
    var osT = transpose(os);

    setCell('t_od_sph',  fmtRx(odT.sph),  valCls(odT.sph));
    setCell('t_od_cyl',  odT.cyl === 0 ? 'sph' : fmtRx(odT.cyl), odT.cyl === 0 ? 'val-zero' : valCls(odT.cyl));
    setCell('t_od_axis', odT.cyl === 0 ? '—' : odT.axis + '°', 'val-axis');

    setCell('t_os_sph',  fmtRx(osT.sph),  valCls(osT.sph));
    setCell('t_os_cyl',  osT.cyl === 0 ? 'sph' : fmtRx(osT.cyl), osT.cyl === 0 ? 'val-zero' : valCls(osT.cyl));
    setCell('t_os_axis', osT.cyl === 0 ? '—' : osT.axis + '°', 'val-axis');

    // Transposition notation
    var nt = document.getElementById('notation-trans');
    nt.innerHTML = '<div class="label">Transposition</div>'
        + buildRow('OD', { sph: odT.sph, cyl: odT.cyl, axis: odT.axis, se: od.se })
        + buildRow('OS', { sph: osT.sph, cyl: osT.cyl, axis: osT.axis, se: os.se });

    document.getElementById('result-card').classList.add('show');
}

// ── Transposition ───────────────────────────────────────────────────────────────
// SPH baru = SPH + CYL
// CYL baru = -CYL
// AXIS baru = AXIS > 90 ? AXIS - 90 : AXIS + 90
function transpose(e) {
    if (e.cyl === 0) return { sph: e.sph, cyl: 0, axis: 0 };
    var newSph  = roundQ(e.sph + e.cyl);
    var newCyl  = roundQ(-e.cyl);
    var newAxis = e.axis > 90 ? e.axis - 90 : e.axis + 90;
    return { sph: newSph, cyl: newCyl, axis: newAxis };
}

function buildRow(eye, e) {
    var ec = eye.toLowerCase();
    var h  = '<div class="notation-row">';
    h += '<span class="n-eye ' + ec + '">' + eye + '</span>';
    h += '<span class="n-sep">:</span>';
    h += '<span class="n-val ' + valCls(e.sph) + '">' + fmtRx(e.sph) + '</span>';
    if (e.cyl !== 0) {
        h += '<span class="n-sep">/</span>';
        h += '<span class="n-val ' + valCls(e.cyl) + '">' + fmtRx(e.cyl) + '</span>';
        h += '<span class="n-sep">×</span>';
        h += '<span class="n-ax">' + e.axis + '°</span>';
    } else {
        h += '<span class="n-sph-only">sph</span>';
    }
    h += '<span class="n-se">SE ' + fmtRx(e.se) + '</span>';
    h += '</div>';
    return h;
}

function liveCalc() {
    var hasAny = ['od_tiga_power','od_dua_power','os_tiga_power','os_dua_power'].some(function(id) {
        return document.getElementById(id).value.trim() !== '';
    });
    if (hasAny) calculate();
}

function resetAll() {
    ['od_tiga_power','od_tiga_axis','od_dua_power','od_dua_axis',
     'os_tiga_power','os_tiga_axis','os_dua_power','os_dua_axis'].forEach(function(id) {
        document.getElementById(id).value = '';
    });
    document.getElementById('result-card').classList.remove('show');
}
</script>

                </div><!-- end config-window -->

            </div><!-- end content-area -->

            <div class="btn-group">
                <button type="button" class="back-main" onclick="history.back()">&larr; Back to Previous Page</button>
            </div>

            <footer class="footer-container">
                <p class="footer-text"><?php echo $COPYRIGHT_FOOTER; ?></p>
            </footer>

        </div><!-- end main-wrapper -->
</body>
</html>