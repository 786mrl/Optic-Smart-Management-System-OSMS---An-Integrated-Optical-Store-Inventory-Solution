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
            padding:6px; width:calc(100% + 16px); max-width:calc(100% + 16px);
            margin:0 -8px 20px; box-sizing:border-box;
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
        .reading-block.add  { border-left:3px solid #60a5fa; }

        .reading-label {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.8px; margin-bottom:14px;
            display:flex; align-items:center; gap:8px;
        }
        .reading-label.dua  { color:#34d399; }
        .reading-label.tiga { color:#fb923c; }
        .reading-label.add  { color:#60a5fa; }

        .rx-input.axis-add[readonly] {
            opacity:.55; cursor:not-allowed; background:#0a0c0f;
        }
        .add-result {
            margin-top:10px; font-size:13px; font-weight:700;
            text-align:center; letter-spacing:.3px;
        }

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

        .btn-view-result {
            width:100%; padding:13px; border:none;
            background:#60a5fa; color:#0a0f0a; border-radius:8px;
            cursor:pointer; font-size:14px; font-weight:800; letter-spacing:.5px;
            text-transform:uppercase; transition:all .2s; margin-top:4px;
        }
        .btn-view-result:hover  { background:#3b82f6; box-shadow:0 0 0 3px #60a5fa30; }
        .btn-view-result:active { transform:translateY(1px); }

        /* ── ADD Power card (inside result-card) ────────────────────────── */
        .add-card {
            background:#13151a; border:1px solid #252830; border-radius:10px;
            padding:14px; margin-top:14px;
        }
        .add-card-title {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.6px; color:#60a5fa; margin-bottom:10px;
        }
        .add-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .add-grid .add-result:empty::before { content:'—'; color:#374151; }

        /* ── Result card ─────────────────────────────────────────────────── */
        .result-card {
            background:#1a1d22; border:1px solid #252830; border-radius:12px;
            padding:6px; width:100%; max-width:100%; margin:0 auto;
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
            .card, .result-card { padding:6px; }
        }
        /* Scoped override: perkecil gap kiri-kanan main-wrapper khusus halaman ini */
        body > .main-wrapper, body > .main-wrapper > .content-area {
            padding-left:4px !important; padding-right:4px !important; box-sizing:border-box;
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
    <div class="content-area" style="flex-direction:column;">

        <!-- Header -->
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
                    <span class="line-v"></span><span class="line-v"></span>
                </span>
                2-Line Focus
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="tel" inputmode="tel" id="od_dua_power" class="rx-input dua"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>AXIS (°)</label>
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
                    <input type="tel" inputmode="tel" id="od_tiga_power" class="rx-input tiga"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>AXIS (°)</label>
                    <input type="number" id="od_tiga_axis" class="rx-input axis-t"
                           placeholder="0" min="0" max="180"
                           onfocus="this.select()" oninput="autoFillDuaAxis('od')">
                    <span class="field-hint">0 – 180°</span>
                </div>
            </div>
        </div>

        <!-- Add -->
        <div class="reading-block add">
            <div class="reading-label add">
                <span class="lines-icon" style="color:#60a5fa; font-weight:900;">+</span>
                Add
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="tel" inputmode="tel" id="od_add_power" class="rx-input add"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>AXIS (°)</label>
                    <input type="number" id="od_add_axis" class="rx-input axis-add"
                           placeholder="0" readonly tabindex="-1">
                    <span class="field-hint">same as 3-Line Focus</span>
                </div>
            </div>
        </div>

        <button type="button" class="btn-view-result" onclick="goToResult()">✓ View Result</button>

    </div><!-- end panel-od -->

    <!-- OS -->
    <div class="eye-panel" id="panel-os">

        <div class="reading-block dua">
            <div class="reading-label dua">
                <span class="lines-icon" style="color:#34d399;">
                    <span class="line-v"></span><span class="line-v"></span>
                </span>
                2-Line Focus
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="tel" inputmode="tel" id="os_dua_power" class="rx-input dua"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>AXIS (°)</label>
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
                    <input type="tel" inputmode="tel" id="os_tiga_power" class="rx-input tiga"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>AXIS (°)</label>
                    <input type="number" id="os_tiga_axis" class="rx-input axis-t"
                           placeholder="0" min="0" max="180"
                           onfocus="this.select()" oninput="autoFillDuaAxis('os')">
                    <span class="field-hint">0 – 180°</span>
                </div>
            </div>
        </div>

        <!-- Add -->
        <div class="reading-block add">
            <div class="reading-label add">
                <span class="lines-icon" style="color:#60a5fa; font-weight:900;">+</span>
                Add
            </div>
            <div class="input-row">
                <div class="field">
                    <label>Power</label>
                    <input type="tel" inputmode="tel" id="os_add_power" class="rx-input add"
                           placeholder="0.00" onfocus="this.select()" oninput="liveCalc()">
                    <span class="field-hint">read power scale</span>
                </div>
                <div class="field">
                    <label>AXIS (°)</label>
                    <input type="number" id="os_add_axis" class="rx-input axis-add"
                           placeholder="0" readonly tabindex="-1">
                    <span class="field-hint">same as 3-Line Focus</span>
                </div>
            </div>
        </div>

        <button type="button" class="btn-view-result" onclick="goToResult()">✓ View Result</button>

    </div><!-- end panel-os -->

    <button class="btn-reset" onclick="resetAll()">↺ Reset</button>
</div>

<!-- Hasil -->
<div class="result-card" id="result-card">
    <div class="result-title">✓ Results</div>

    <div class="result-grid">
        <!-- Hasil -->
        <div>
            <div class="notation-box" id="notation-box"></div>
        </div>

        <!-- Transposisi -->
        <div class="trans-card">
            <div class="trans-title">⇄ Transposition</div>
            <div class="notation-box" id="notation-trans"></div>
        </div>
    </div>

    <!-- ADD Power -->
    <div class="add-card">
        <div class="add-card-title">+ ADD Power</div>
        <div class="add-grid">
            <div class="add-result" id="od_add_result"></div>
            <div class="add-result" id="os_add_result"></div>
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
    if (!el) return;
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
    nb.innerHTML = buildRow('OD', od) + buildRow('OS', os);

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
    nt.innerHTML = buildRow('OD', { sph: odT.sph, cyl: odT.cyl, axis: odT.axis, se: od.se })
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

function updateAddResult(eye) {
    var tigaVal = parseVal(document.getElementById(eye + '_tiga_power').value);
    var addVal  = parseVal(document.getElementById(eye + '_add_power').value);
    var resEl   = document.getElementById(eye + '_add_result');
    if (!resEl) return;
    if (tigaVal === null || addVal === null) { resEl.textContent = ''; resEl.className = 'add-result'; return; }
    var diff = roundQ(addVal - tigaVal);
    resEl.textContent = 'ADD ' + fmtRx(diff);
    resEl.className = 'add-result ' + valCls(diff);
}

function liveCalc() {
    ['od','os'].forEach(function(eye) {
        var tigaAxisEl = document.getElementById(eye + '_tiga_axis');
        var addAxisEl  = document.getElementById(eye + '_add_axis');
        if (addAxisEl) addAxisEl.value = tigaAxisEl.value;
        updateAddResult(eye);
    });
    var hasAny = ['od_tiga_power','od_dua_power','os_tiga_power','os_dua_power'].some(function(id) {
        return document.getElementById(id).value.trim() !== '';
    });
    if (hasAny) calculate();
}

// ── Tutup keyboard & scroll ke Result Card ────────────────────────────────────
function goToResult() {
    if (document.activeElement && typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
    }
    var resultCard = document.getElementById('result-card');
    setTimeout(function() {
        resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 150);
}

function resetAll() {
    ['od_tiga_power','od_tiga_axis','od_dua_power','od_dua_axis','od_add_power','od_add_axis',
     'os_tiga_power','os_tiga_axis','os_dua_power','os_dua_axis','os_add_power','os_add_axis'].forEach(function(id) {
        document.getElementById(id).value = '';
    });
    ['od_add_result','os_add_result'].forEach(function(id) {
        var el = document.getElementById(id);
        el.textContent = '';
        el.className = 'add-result';
    });
    document.getElementById('result-card').classList.remove('show');
}
</script>

                </div><!-- end config-window -->

            </div><!-- end content-area -->

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

        </div><!-- end main-wrapper -->
        <div class="logo-backdrop" id="logoBackdrop" ondblclick="zoomOutLogo(document.getElementById('storeLogo'))"></div>
        
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