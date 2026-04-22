<?php
// Lens Recommendation Simulator - PHP Version
// Dapat dijalankan langsung di server PHP (Apache/Nginx/XAMPP/Laragon)
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lens Recommendation Simulator</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh;
      background: #080c11;
      color: #c9d1d9;
      font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
      padding-bottom: 60px;
    }
    .header {
      background: linear-gradient(135deg, #0d1117 0%, #0d1f2d 100%);
      border-bottom: 1px solid #1e2832;
      padding: 24px 20px 20px;
      text-align: center;
    }
    .header-sub { font-size: 10px; letter-spacing: 4px; color: #3b82f6; margin-bottom: 6px; }
    .header h1 { font-size: 22px; font-weight: 800; color: #7dd3fc; letter-spacing: 1px; }
    .header-eng { font-size: 10px; color: #2e3f52; margin-top: 4px; }
    .container { max-width: 640px; margin: 0 auto; padding: 20px 16px; display: flex; flex-direction: column; gap: 16px; }
    .card { background: #0d1117; border: 1px solid #1e2832; border-radius: 14px; padding: 18px 16px; }
    .card-label { font-size: 9px; color: #3b82f6; letter-spacing: 2px; margin-bottom: 14px; }
    .rx-grid-header { display: grid; grid-template-columns: 60px repeat(3, 1fr); gap: 8px; margin-bottom: 8px; }
    .rx-grid { display: grid; grid-template-columns: 60px repeat(3, 1fr); gap: 8px; margin-bottom: 8px; }
    .col-label { font-size: 9px; color: #3b6; text-align: center; }
    .row-label { font-size: 11px; color: #7dd3fc; font-weight: 700; align-self: center; }
    .field-wrap { display: flex; flex-direction: column; gap: 2px; }
    .field-sublabel { font-size: 9px; color: #556; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
    input[type=text], input[type=number], textarea {
      width: 100%;
      background: #0d1117;
      border: 1px solid #1e2832;
      border-radius: 6px;
      padding: 7px 6px;
      color: #7dd3fc;
      text-align: center;
      font-family: monospace;
      font-size: 13px;
      outline: none;
    }
    textarea { text-align: left; resize: vertical; min-height: 60px; font-size: 11px; }
    .section-label { font-size: 9px; color: #556; letter-spacing: 1px; margin-bottom: 6px; text-transform: uppercase; }
    .btn-group { display: flex; gap: 6px; }
    .btn-toggle {
      flex: 1; padding: 8px 4px; border-radius: 8px; cursor: pointer; font-size: 10px;
      border: 1px solid #1e2832; background: #080c11; color: #556;
      font-family: inherit; transition: all 0.15s;
    }
    .btn-toggle.active-blue { border-color: #3b82f6; background: rgba(59,130,246,0.15); color: #7dd3fc; }
    .btn-toggle.active-green { border-color: #22c55e; background: rgba(34,197,94,0.12); color: #86efac; }
    .symptom-tags { display: flex; flex-wrap: wrap; gap: 5px; }
    .symptom-tag {
      padding: 5px 10px; border-radius: 20px; border: 1px solid #1e2832;
      background: #080c11; color: #445; cursor: pointer; font-size: 10px;
      font-family: inherit; transition: all 0.15s;
    }
    .symptom-tag.active { border-color: #f59e0b; background: rgba(245,158,11,0.12); color: #fcd34d; }
    .btn-run {
      background: linear-gradient(135deg, #1d4ed8, #0ea5e9);
      border: none; border-radius: 12px; padding: 14px;
      color: #fff; font-size: 13px; font-weight: 800; letter-spacing: 2px;
      cursor: pointer; box-shadow: 0 0 20px rgba(14,165,233,0.3);
      font-family: inherit; width: 100%;
    }
    .stats-bar { display: flex; gap: 8px; flex-wrap: wrap; }
    .stat-box {
      flex: 1; min-width: 70px; background: #0d1117; border: 1px solid #1e2832;
      border-radius: 8px; padding: 8px 10px; text-align: center;
    }
    .stat-box-label { font-size: 8px; color: #3b82f6; letter-spacing: 1px; }
    .stat-box-val { font-size: 12px; font-weight: 700; color: #7dd3fc; margin-top: 2px; }
    .warning-box {
      display: flex; gap: 8px; background: rgba(245,158,11,0.07);
      border: 1px solid rgba(245,158,11,0.2); border-radius: 10px;
      padding: 10px 12px; font-size: 11px; color: #fcd34d;
    }
    .result-count { font-size: 9px; color: #2e3f52; letter-spacing: 1px; margin-bottom: 4px; }
    .lens-card { border-radius: 12px; overflow: hidden; }
    .lens-header {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 13px; cursor: pointer;
    }
    .rank-badge {
      min-width: 30px; height: 22px; border-radius: 20px;
      font-size: 9px; font-weight: 800; display: flex;
      align-items: center; justify-content: center;
      letter-spacing: 0.5px; flex-shrink: 0;
    }
    .lens-info { flex: 1; min-width: 0; }
    .lens-name { font-size: 11px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lens-meta { display: flex; gap: 5px; margin-top: 3px; align-items: center; }
    .source-badge { font-size: 8px; border-radius: 20px; padding: 1px 7px; border: 1px solid; }
    .lens-readiness { font-size: 8px; color: #445; }
    .lens-price { font-size: 11px; font-weight: 700; font-family: monospace; flex-shrink: 0; text-align: right; }
    .lens-arrow { color: #445; font-size: 11px; flex-shrink: 0; transition: transform 0.2s; }
    .lens-body { padding: 0 13px 13px; border-top: 1px solid; }
    .feat-label { font-size: 8px; color: #2e3f52; letter-spacing: 1px; margin: 10px 0 6px; }
    .feat-tags { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px; }
    .feat-tag {
      display: inline-flex; align-items: center; gap: 3px;
      font-size: 9px; color: #8ba; background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08); border-radius: 20px; padding: 4px 9px;
    }
    .lens-note { font-size: 9px; color: #556; font-style: italic; padding: 6px 8px; background: rgba(255,255,255,0.02); border-radius: 6px; }
    .lens-score { font-size: 9px; color: #2e3f52; margin-top: 6px; }
    .lens-score span { color: #3b82f6; }
    .no-result { text-align: center; color: #445; padding: 20px; font-size: 12px; }
    #results { display: flex; flex-direction: column; gap: 10px; }
  </style>
</head>
<body>

<div class="header">
  <div class="header-sub">OPTOMETRY TOOLS</div>
  <h1>LENS RECOMMENDATION SIMULATOR</h1>
  <div class="header-eng">Engine v3 — aligned with lense_prices.json</div>
</div>

<div class="container">

  <!-- Prescription Card -->
  <div class="card">
    <div class="card-label">NEW PRESCRIPTION</div>
    <div class="rx-grid-header">
      <div></div>
      <div class="col-label">SPH</div>
      <div class="col-label">CYL</div>
      <div class="col-label">ADD</div>
    </div>
    <div class="rx-grid">
      <div class="row-label">RIGHT (OD)</div>
      <div class="field-wrap"><input type="text" id="rSph" placeholder="0.00" inputmode="decimal"></div>
      <div class="field-wrap"><input type="text" id="rCyl" placeholder="0.00" inputmode="decimal"></div>
      <div class="field-wrap"><input type="text" id="rAdd" placeholder="0.00" inputmode="decimal"></div>
    </div>
    <div class="rx-grid">
      <div class="row-label">LEFT (OS)</div>
      <div class="field-wrap"><input type="text" id="lSph" placeholder="0.00" inputmode="decimal"></div>
      <div class="field-wrap"><input type="text" id="lCyl" placeholder="0.00" inputmode="decimal"></div>
      <div class="field-wrap"><input type="text" id="lAdd" placeholder="0.00" inputmode="decimal"></div>
    </div>
  </div>

  <!-- Patient Profile -->
  <div class="card">
    <div class="card-label">PATIENT PROFILE</div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
      <div class="field-wrap">
        <span class="field-sublabel">Age</span>
        <input type="text" id="age" placeholder="0" inputmode="numeric">
      </div>
      <div></div>
    </div>

    <!-- Visual Habits -->
    <div style="margin-bottom:12px;">
      <div class="section-label">Visual Habits</div>
      <div class="btn-group">
        <button class="btn-toggle active-blue" id="habit-1" onclick="setHabit(1)">Indoor 🏠</button>
        <button class="btn-toggle" id="habit-2" onclick="setHabit(2)">Outdoor 🌤️</button>
        <button class="btn-toggle" id="habit-3" onclick="setHabit(3)">Both 🌍</button>
      </div>
    </div>

    <!-- Digital Usage -->
    <div style="margin-bottom:12px;">
      <div class="section-label">Digital Device Usage</div>
      <div class="btn-group">
        <button class="btn-toggle active-green" id="dig-1" onclick="setDigital(1)">Low (&lt;2h)</button>
        <button class="btn-toggle" id="dig-2" onclick="setDigital(2)">Moderate (2-5h)</button>
        <button class="btn-toggle" id="dig-3" onclick="setDigital(3)">High (&gt;5h)</button>
      </div>
    </div>

    <!-- Symptoms -->
    <div style="margin-bottom:12px;">
      <div class="section-label">Symptoms / Complaints</div>
      <div class="symptom-tags">
        <button class="symptom-tag" id="sym-headache" onclick="toggleSymptom('headache')">headache</button>
        <button class="symptom-tag" id="sym-eye strain" onclick="toggleSymptom('eye strain')">eye strain</button>
        <button class="symptom-tag" id="sym-dry eye" onclick="toggleSymptom('dry eye')">dry eye</button>
        <button class="symptom-tag" id="sym-glare" onclick="toggleSymptom('glare')">glare</button>
        <button class="symptom-tag" id="sym-driving" onclick="toggleSymptom('driving')">driving</button>
        <button class="symptom-tag" id="sym-sport" onclick="toggleSymptom('sport')">sport/impact</button>
      </div>
    </div>

    <!-- Notes -->
    <div>
      <div class="section-label">Additional Notes</div>
      <textarea id="notes" placeholder="e.g.: sering baca, driving malam, olahraga..."></textarea>
    </div>
  </div>

  <!-- Run Button -->
  <button class="btn-run" onclick="generate()">✦ GENERATE LENS RECOMMENDATION ✦</button>

  <!-- Results -->
  <div id="results"></div>

</div>

<script>
// ── Full lens catalog ──────────────────────────────────────────
const CATALOG_RAW = <?php echo json_encode(json_decode('{"stock":{"SINGLE VISION":{"BLUERAY":{"cost":25000,"selling":150000,"features":["UV PROTECTION","ANTI-REFLECTIVE (AR) COATING","SCRATCH-RESISTANT COATING","BLUE LIGHT BLOCKING"],"limits":{"sph_from":0,"sph_to":-800,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"PHOTOCHROMIC":{"cost":30000,"selling":150000,"features":["PHOTOCHROMIC","UV PROTECTION","ANTI-REFLECTIVE (AR) COATING","SCRATCH-RESISTANT COATING"],"limits":{"sph_from":0,"sph_to":-800,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"BLUECHROMIC":{"cost":60000,"selling":285000,"features":["PHOTOCHROMIC","UV PROTECTION","ANTI-REFLECTIVE (AR) COATING","BLUE LIGHT BLOCKING","SCRATCH-RESISTANT COATING"],"limits":{"sph_from":0,"sph_to":-800,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"ONE-DRIVE":{"cost":130000,"selling":500000,"features":["HIGH-INDEX UV400 PROTECTION","ANTI-STATIC","SUPER HYDROPHOBIC","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","BLUE LIGHT BLOCKING","PHOTOCHROMIC","NIGHT DRIVE COATING"],"limits":{"sph_from":0,"sph_to":-800,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"HMC":{"cost":18000,"selling":90000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":0,"sph_to":-800,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}}},"KRYPTOK":{"HMC":{"cost":18000,"selling":90000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":"Reading power must not exceed distance SPH. If exceeded, order from lab."}},"PHOTOCHROMIC":{"cost":60000,"selling":250000,"features":["PHOTOCHROMIC","UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":"Reading power must not exceed distance SPH. If exceeded, order from lab."}}},"PROGRESSIVE":{"HMC":{"cost":38000,"selling":160000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"PHOTOCHROMIC":{"cost":60000,"selling":250000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","PHOTOCHROMIC"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"ANTI-BLUE RAY":{"cost":60000,"selling":250000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","BLUE LIGHT BLOCKING"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"BLUECHROMIC":{"cost":120000,"selling":450000,"features":["PHOTOCHROMIC","BLUE LIGHT BLOCKING","SCRATCH-RESISTANT COATING","UV PROTECTION","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":200,"comb_max":0,"note":""}}}},"lab":{"SINGLE VISION":{"HMC":{"cost":96000,"selling":285000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":0,"sph_to":-1100,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":-1100,"note":""}},"SHMC":{"cost":114000,"selling":450000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SUPER HYDROPHOBIC","SMUDGE-RESISTANT","ANTI-STATIC"],"limits":{"sph_from":0,"sph_to":-1100,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":-1100,"note":""}},"LENTICULAR HMC":{"cost":150000,"selling":750000,"features":["HIGH POWER RX","UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":0,"sph_to":0,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"LENTICULAR SHMC":{"cost":180000,"selling":800000,"features":["HIGH POWER RX","UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SUPER HYDROPHOBIC","ANTI-STATIC","SMUDGE-RESISTANT"],"limits":{"sph_from":0,"sph_to":0,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"SUPERBLOCK 1.67":{"cost":330000,"selling":1600000,"features":["HIGH INDEX 1.67","UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":-300,"sph_to":-1400,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":-1400,"note":""}},"BLUGARD (STOCK)":{"cost":60000,"selling":350000,"features":["UV PROTECTION","SMUDGE-RESISTANT","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":0,"sph_to":-600,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"BLUGARD (STOCK) (2)":{"cost":90000,"selling":450000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":0,"sph_to":0,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"BLUGARD (STOCK) (3)":{"cost":110000,"selling":500000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":0,"sph_to":-400,"cyl_from":-225,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"BLUGARD (STOCK) (4)":{"cost":180000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","HIGHT INDEX 1.67"],"limits":{"sph_from":-400,"sph_to":-1200,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"BLUGARD (STOCK) (5)":{"cost":180000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","HIGH INDEX 1.67"],"limits":{"sph_from":-1225,"sph_to":-1500,"cyl_from":0,"cyl_to":0,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"SUPERBLOCK (STOCK)":{"cost":180000,"selling":600000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","HIGH INDEX 1.67"],"limits":{"sph_from":-400,"sph_to":-1200,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"SUPERBLOCK (STOCK) (2)":{"cost":180000,"selling":600000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","HIGH INDEX 1.67"],"limits":{"sph_from":-1225,"sph_to":-1500,"cyl_from":0,"cyl_to":0,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"SUPERBLOCK":{"cost":180000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":850,"sph_to":-950,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":-950,"note":""}},"SUPERBLOCK (STOCK) (3)":{"cost":110000,"selling":300000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":0,"sph_to":-375,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"SUPERBLOCK (STOCK) (4)":{"cost":110000,"selling":300000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":25,"sph_to":400,"cyl_from":0,"cyl_to":0,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"SENSIBLE 6 GREY":{"cost":180000,"selling":850000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC"],"limits":{"sph_from":850,"sph_to":-1000,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":-1000,"note":""}},"SENSIBLE 6 BROWN":{"cost":200000,"selling":1000000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC"],"limits":{"sph_from":850,"sph_to":-100,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":-1000,"note":""}},"BLUECHROMIC (STOCK)":{"cost":180000,"selling":1100000,"features":["HIGH-INDEX UV400 PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC","HIGH INDEX 1.67"],"limits":{"sph_from":-400,"sph_to":-1200,"cyl_from":-25,"cyl_to":-200,"add_from":0,"add_to":0,"comb_max":0,"note":""}},"BLUECHROMIC":{"cost":350000,"selling":2050000,"features":["HIGH-INDEX UV400 PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC"],"limits":{"sph_from":-300,"sph_to":-1400,"cyl_from":-25,"cyl_to":-400,"add_from":0,"add_to":0,"comb_max":-1400,"note":""}},"BLUECHROMIC (STOCK) (2)":{"cost":180000,"selling":1100000,"features":["HIGH-INDEX UV400 PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC"],"limits":{"sph_from":-1225,"sph_to":-1500,"cyl_from":0,"cyl_to":0,"add_from":0,"add_to":0,"comb_max":0,"note":""}}},"KRYPTOK":{"LENTICULAR HMC":{"cost":220000,"selling":1300000,"features":["HIGH POWER RX","UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":700,"sph_to":1700,"cyl_from":-25,"cyl_to":-400,"add_from":200,"add_to":300,"comb_max":1700,"note":""}},"LENTICULAR SHMC":{"cost":240000,"selling":1400000,"features":["HIGH POWER RX","UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC"],"limits":{"sph_from":700,"sph_to":1700,"cyl_from":-25,"cyl_to":-400,"add_from":200,"add_to":300,"comb_max":1700,"note":""}},"HMC":{"cost":110000,"selling":285000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":650,"sph_to":-900,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-900,"note":""}},"SHMC":{"cost":160000,"selling":550000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC"],"limits":{"sph_from":650,"sph_to":-900,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-900,"note":""}},"SUPERBLOCK":{"cost":220000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","HYDROPHOBIC","BLUE LIGHT BLOCKING","ANTI-STATIC","SMUDGE-RESISTANT"],"limits":{"sph_from":650,"sph_to":-900,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-900,"note":""}},"BLUGARD":{"cost":250000,"selling":800000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":-650,"sph_to":-900,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-900,"note":""}},"BLUECHROMIC":{"cost":280000,"selling":1000000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC"],"limits":{"sph_from":650,"sph_to":-500,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-500,"note":""}},"BLUECHROMIC (STOCK)":{"cost":220000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"SENSIBLE 6 GREY":{"cost":200000,"selling":1000000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}}},"FLATTOP":{"HMC":{"cost":140000,"selling":550000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING"],"limits":{"sph_from":650,"sph_to":-500,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-500,"note":""}},"SHMC":{"cost":150000,"selling":600000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","HYDROPHOBIC","ANTI-STATIC","SMUDGE-RESISTANT"],"limits":{"sph_from":50,"sph_to":-500,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-500,"note":""}},"SUPERBLOCK":{"cost":250000,"selling":850000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":650,"sph_to":-600,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-600,"note":""}},"BLUGARD":{"cost":280000,"selling":900000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":650,"sph_to":-600,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-600,"note":""}},"TAFT":{"cost":220000,"selling":1100000,"features":["UV PROTECTION","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","ANTI-STATIC","BLUE LIGHT BLOCKING","IMPACT-RESISTANT"],"limits":{"sph_from":500,"sph_to":-825,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-825,"note":""}},"SENSIBLE 6 BROWN":{"cost":220000,"selling":1200000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC"],"limits":{"sph_from":650,"sph_to":-600,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-600,"note":""}},"SENSIBLE 6 GREY":{"cost":210000,"selling":1100000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","SMUDGE-RESISTANT","ANTI-REFLECTIVE (AR) COATING","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC"],"limits":{"sph_from":650,"sph_to":-600,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-600,"note":""}}},"PROGRESSIVE":{"FLEXI BLUCHROMIC ONE-DRIVE (STOCK)":{"cost":250000,"selling":1100000,"features":["HIGH-INDEX UV400 PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC","NIGHT DRIVE COATING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"BALANCE BLUCHROMIC ONE-DRIVE (STOCK)":{"cost":260000,"selling":1250000,"features":["HIGH-INDEX UV400 PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC","NIGHT DRIVE COATING","ALL-DISTANCE PROGRESSIVE"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"FLEXI BLUCHROMIC ONE-DRIVE":{"cost":330000,"selling":1800000,"features":["HIGH-INDEX UV400 PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC","NIGHT DRIVE COATING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":500,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"BALANCE BLUCHROMIC ONE-DRIVE":{"cost":350000,"selling":1900000,"features":["HIGH-INDEX UV400 PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","PHOTOCHROMIC","NIGHT DRIVE COATING","ALL-DISTANCE PROGRESSIVE"],"limits":{"sph_from":500,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"STANDARD CORD HMC":{"cost":140000,"selling":500000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","DYNAMIC DISTANCE LENS"],"limits":{"sph_from":600,"sph_to":-10,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-1000,"note":""}},"STANDARD CORD SHMC":{"cost":170000,"selling":600000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","DYNAMIC DISTANCE LENS"],"limits":{"sph_from":600,"sph_to":-1000,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-1000,"note":""}},"STANDARD CORD SUPERBLOCK":{"cost":200000,"selling":750000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","DYNAMIC DISTANCE LENS"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-1000,"note":""}},"SHORT CORD HMC":{"cost":180000,"selling":650000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","NEAR-OPTIMIZED LENS"],"limits":{"sph_from":600,"sph_to":-1000,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-1000,"note":""}},"SHORT CORD SHMC":{"cost":200000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","NEAR-OPTIMIZED LENS"],"limits":{"sph_from":600,"sph_to":-1000,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-1000,"note":""}},"SHORT CORD SUPERBLOCK":{"cost":330000,"selling":850000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","NEAR-OPTIMIZED LENS"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"MINICORD HMC":{"cost":180000,"selling":600000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","ENHANCED NEAR VISION"],"limits":{"sph_from":600,"sph_to":-1000,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-1000,"note":""}},"MINICORD SHMC":{"cost":190000,"selling":650000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","ENHANCED NEAR VISION"],"limits":{"sph_from":600,"sph_to":-1000,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-1000,"note":""}},"MINICORD SUPERBLOCK":{"cost":220000,"selling":800000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","ENHANCED NEAR VISION"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"INOCORD PREMIUM":{"cost":250000,"selling":1000000,"features":["UV PROTECTION","ANTI-REFLECTIVE (AR) COATING","SCRATCH-RESISTANT COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"FLEXI CORD HMC":{"cost":220000,"selling":800000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":600,"sph_to":-950,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-950,"note":""}},"FLEXI CORD SHMC":{"cost":240000,"selling":850000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","HYDROPHOBIC","SMUDGE-RESISTANT","ANTI-STATIC","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":600,"sph_to":-950,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-950,"note":""}},"FLEXI CORD PREMIUM":{"cost":250000,"selling":1100000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"FLEXI CORD SENSIBLE 6 GREY":{"cost":280000,"selling":1350000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"BALANCE CORD SHMC":{"cost":200000,"selling":950000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","ALL-DISTANCE PROGRESSIVE"],"limits":{"sph_from":600,"sph_to":-800,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-800,"note":""}},"BALANCE CORD PREMIUM":{"cost":330000,"selling":1250000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","ALL-DISTANCE PROGRESSIVE"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"BALANCE CORD SENSIBLE GREY 6":{"cost":350000,"selling":1500000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC","ALL-DISTANCE PROGRESSIVE"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"BALANCE CORD SENSIBLE BROWN 6":{"cost":380000,"selling":1600000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","PHOTOCHROMIC","ALL-DISTANCE PROGRESSIVE"],"limits":{"sph_from":650,"sph_to":-700,"cyl_from":-25,"cyl_to":-400,"add_from":100,"add_to":300,"comb_max":-700,"note":""}},"STANDARD CORD HMC (STOCK)":{"cost":45000,"selling":300000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","DYNAMIC DISTANCE LENS"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"MINI CORD HMC (STOCK)":{"cost":60000,"selling":350000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","ENHANCED NEAR VISION"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"MINI CORD PREMIUM (STOCK)":{"cost":180000,"selling":650000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING"],"limits":{"sph_from":850,"sph_to":-1100,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"INNOVATIVE HMC (STOCK)":{"cost":160000,"selling":400000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"INOCORD PREMIUM (STOCK)":{"cost":200000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"FLEXI CORD HMC (STOCK)":{"cost":180000,"selling":450000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"FLEXI TAFT SHMC (STOCK)":{"cost":280000,"selling":750000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC","IMPACT-RESISTANT"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"FLEXI BLUTAFT (STOCK)":{"cost":330000,"selling":1100000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","SUPER HYDROPHOBIC","ANTI-STATIC","BLUE LIGHT BLOCKING","IMPACT-RESISTANT","FAR & NEAR OPTIMIZED LENS"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}},"BALANCE CORD SHMC (STOCK)":{"cost":220000,"selling":700000,"features":["UV PROTECTION","SCRATCH-RESISTANT COATING","ANTI-REFLECTIVE (AR) COATING","SMUDGE-RESISTANT","HYDROPHOBIC","ANTI-STATIC"],"limits":{"sph_from":-200,"sph_to":200,"cyl_from":0,"cyl_to":0,"add_from":100,"add_to":300,"comb_max":0,"note":""}}}}}')); ?>;

// ── State ──────────────────────────────────────────────────────
let habit = 1, digital = 1;
let symptoms = [];
let openCards = {};

function setHabit(v) {
  habit = v;
  [1,2,3].forEach(n => {
    const b = document.getElementById('habit-'+n);
    b.className = 'btn-toggle' + (n === v ? ' active-blue' : '');
  });
}

function setDigital(v) {
  digital = v;
  [1,2,3].forEach(n => {
    const b = document.getElementById('dig-'+n);
    b.className = 'btn-toggle' + (n === v ? ' active-green' : '');
  });
}

function toggleSymptom(s) {
  const idx = symptoms.indexOf(s);
  if (idx >= 0) symptoms.splice(idx, 1);
  else symptoms.push(s);
  const btn = document.getElementById('sym-' + s);
  if (btn) btn.className = 'symptom-tag' + (symptoms.includes(s) ? ' active' : '');
}

// ── Engine helpers ─────────────────────────────────────────────
function parsePow(v) {
  const n = parseFloat(String(v).replace('+','')) || 0;
  return Math.abs(n) >= 10 ? n / 100 : n;
}

function fitsLimits(rSph, rCyl, lSph, lCyl, rAdd, lAdd, lim) {
  const maxS = Math.max(Math.abs(rSph), Math.abs(lSph));
  const maxC = Math.max(Math.abs(rCyl), Math.abs(lCyl));
  const maxA = Math.max(Math.abs(rAdd), Math.abs(lAdd));
  if (lim.sph_to !== 0 && maxS > Math.abs(lim.sph_to)) return false;
  if (lim.cyl_to !== 0 && maxC > Math.abs(lim.cyl_to)) return false;
  if (lim.comb_max !== 0 && (maxS + maxC) > Math.abs(lim.comb_max)) return false;
  if (lim.add_to !== 0) {
    const addMin = Math.abs(lim.add_from);
    const addMax = Math.abs(lim.add_to);
    if (maxA < addMin || maxA > addMax) return false;
  }
  return true;
}

function presbyDesign(habit, digital, notes) {
  const t = notes.toLowerCase();
  if (/baca|membaca|jahit|close.?work|near.?work/.test(t)) return 'near';
  if (/mengemudi|bawa.?mobil|driving|berkendara/.test(t)) return 'far_near';
  if (habit === 3 || (digital >= 2 && habit >= 2)) return 'all_distance';
  if (habit === 2) return 'far_near';
  return 'far_near';
}

function scoreFeatures(features, source, category, isPresby, pDesign,
    habit, digital, maxSE, maxCyl, maxAdd, age,
    hasGlare, hasEyeStrain, hasHeadache, hasDryEye, hasDriving, hasImpact) {
  const cat = category.toUpperCase();
  const isSV   = cat === 'SINGLE VISION';
  const isProg = ['PROGRESSIVE','KRYPTOK','FLATTOP'].includes(cat);
  if (isPresby && isSV)   return -9999;
  if (!isPresby && isProg) return -9999;

  let s = 0;
  if (isPresby) {
    const hasAll  = features.includes('ALL-DISTANCE PROGRESSIVE');
    const hasFN   = features.includes('FAR & NEAR OPTIMIZED LENS');
    const hasDyn  = features.includes('DYNAMIC DISTANCE LENS');
    const hasNear = features.includes('NEAR-OPTIMIZED LENS');
    const hasEnN  = features.includes('ENHANCED NEAR VISION');
    if (pDesign === 'all_distance') {
      if (hasAll) s += 40; else if (hasFN) s += 25; else if (hasDyn) s += 15; else if (hasNear||hasEnN) s += 5;
    } else if (pDesign === 'far_near') {
      if (hasFN) s += 40; else if (hasAll) s += 28; else if (hasDyn) s += 15; else if (hasNear||hasEnN) s += 5;
    } else {
      if (hasEnN) s += 40; else if (hasNear) s += 35; else if (hasFN) s += 15; else if (hasAll) s += 10;
    }
    if (cat === 'KRYPTOK' || cat === 'FLATTOP') s += (age >= 65 ? 10 : 2);
  }

  for (const feat of features) {
    switch (feat.toUpperCase().trim()) {
      case 'BLUE LIGHT BLOCKING':
        if (digital === 3) s += 28; else if (digital === 2) s += 15;
        if ((hasEyeStrain || hasHeadache) && digital >= 2) s += 8;
        if (hasDryEye && digital >= 2) s += 4;
        break;
      case 'PHOTOCHROMIC':
        if (habit === 2) s += 22; else if (habit === 3) s += 14; else s -= 12;
        if (hasGlare && habit >= 2) s += 10;
        break;
      case 'NIGHT DRIVE COATING':
        if (hasDriving) s += 20; else if (habit >= 2) s += 8; else s -= 10;
        break;
      case 'HIGH INDEX 1.67':
      case 'HIGHT INDEX 1.67':
        if (maxSE >= 6) s += 35; else if (maxSE >= 4) s += 22; else if (maxSE >= 2) s += 10;
        break;
      case 'HIGH-INDEX UV400 PROTECTION':
        s += 4; if (habit >= 2) s += 5;
        break;
      case 'HIGH POWER RX':
        if (maxSE >= 8) s += 35; else if (maxSE >= 6) s += 22; else if (maxSE >= 4) s += 8;
        break;
      case 'IMPACT-RESISTANT':
        if (hasImpact) s += 20; else if (habit >= 2) s += 6;
        break;
      case 'SUPER HYDROPHOBIC': s += habit >= 2 ? 8 : 2; break;
      case 'HYDROPHOBIC': s += habit >= 2 ? 5 : 1; break;
      case 'SMUDGE-RESISTANT': s += 3; break;
      case 'ANTI-STATIC': s += 2; break;
      case 'SCRATCH-RESISTANT COATING': s += habit >= 2 ? 4 : 1; break;
      case 'ANTI-REFLECTIVE (AR) COATING':
        if (hasGlare) s += 12; else if (hasEyeStrain || digital >= 2) s += 6; else s += 2;
        break;
      case 'UV PROTECTION': s += habit >= 2 ? 5 : 0; break;
    }
  }
  if (source === 'stock' && maxSE < 4 && maxCyl < 2) s += 5;
  if (source === 'lab' && (maxSE >= 5 || maxCyl >= 3)) s += 8;
  return s;
}

function runEngine(p) {
  const rSph = parsePow(p.rSph), rCyl = parsePow(p.rCyl), rAdd = parsePow(p.rAdd);
  const lSph = parsePow(p.lSph), lCyl = parsePow(p.lCyl), lAdd = parsePow(p.lAdd);
  const maxSE  = Math.max(Math.abs(rSph)+Math.abs(rCyl)/2, Math.abs(lSph)+Math.abs(lCyl)/2);
  const maxCyl = Math.max(Math.abs(rCyl), Math.abs(lCyl));
  const maxAdd = Math.max(Math.abs(rAdd), Math.abs(lAdd));
  const age = parseInt(p.age) || 0;
  const hab = parseInt(p.habit) || 1;
  const dig = parseInt(p.digital) || 1;
  const notes = (p.notes||'') + ' ' + (p.symptoms||'');
  const txt = notes.toLowerCase();

  const isPresby = maxAdd >= 0.75 && age >= 39;
  const pDesign  = isPresby ? presbyDesign(hab, dig, notes) : '';
  const hasGlare     = /glare|silau/.test(txt);
  const hasEyeStrain = /eye.?strain|mata.?lelah|\blelah\b/.test(txt);
  const hasHeadache  = /headache|sakit.?kepala/.test(txt);
  const hasDryEye    = /dry.?eye|mata.?kering/.test(txt);
  const hasDriving   = /mengemudi|bawa.?mobil|driving|berkendara/.test(txt);
  const hasImpact    = /olahraga|sport|bentur|impact/.test(txt);

  let anyStockFits = false;
  for (const [cat, types] of Object.entries(CATALOG_RAW.stock||{})) {
    for (const [, lens] of Object.entries(types)) {
      if (fitsLimits(rSph,rCyl,lSph,lCyl,rAdd,lAdd, lens.limits)) { anyStockFits = true; break; }
    }
    if (anyStockFits) break;
  }

  const candidates = [];
  for (const [source, cats] of Object.entries(CATALOG_RAW)) {
    const readiness = source === 'stock' ? 'Siap 1-2 Hari' : 'Gosok Lab, 7-10 Hari';
    for (const [category, types] of Object.entries(cats)) {
      for (const [type, lens] of Object.entries(types)) {
        if (source === 'lab' && anyStockFits) continue;
        const feats = lens.features || [];
        const isHiIdx = feats.some(f => ['HIGH INDEX 1.67','HIGHT INDEX 1.67','HIGH POWER RX'].includes(f.toUpperCase()));
        if (isHiIdx && maxSE < 3 && maxCyl < 3) continue;
        if (!fitsLimits(rSph,rCyl,lSph,lCyl,rAdd,lAdd, lens.limits)) continue;
        let score = scoreFeatures(feats, source, category, isPresby, pDesign,
          hab, dig, maxSE, maxCyl, maxAdd, age,
          hasGlare, hasEyeStrain, hasHeadache, hasDryEye, hasDriving, hasImpact);
        if (score <= -9999) continue;
        if (lens.selling > 0) score -= lens.selling / 600000;
        candidates.push({ source, category, type, selling: lens.selling, features: feats,
          note: lens.limits.note||'', score, readiness });
      }
    }
  }
  candidates.sort((a,b) => b.score - a.score);
  return { candidates, isPresby, pDesign, maxSE, maxCyl, maxAdd, anyStockFits };
}

// ── Feature icons ──────────────────────────────────────────────
const FEAT_ICON = {
  'UV PROTECTION':'🌞','HIGH-INDEX UV400 PROTECTION':'🛡️',
  'ANTI-REFLECTIVE (AR) COATING':'💡','SCRATCH-RESISTANT COATING':'🪨',
  'SMUDGE-RESISTANT':'🧼','HYDROPHOBIC':'💧','SUPER HYDROPHOBIC':'💦',
  'ANTI-STATIC':'⚡','BLUE LIGHT BLOCKING':'💙','PHOTOCHROMIC':'🌅',
  'NIGHT DRIVE COATING':'🚗','HIGH INDEX 1.67':'💎','HIGHT INDEX 1.67':'💎',
  'HIGH POWER RX':'🔬','IMPACT-RESISTANT':'🛡️',
  'FAR & NEAR OPTIMIZED LENS':'📐','ALL-DISTANCE PROGRESSIVE':'🔭',
  'DYNAMIC DISTANCE LENS':'🚀','NEAR-OPTIMIZED LENS':'📚','ENHANCED NEAR VISION':'🔎',
};

function fmt(v) {
  if (!v || v <= 0) return '-';
  return 'Rp ' + v.toLocaleString('id-ID');
}

const RANK_COLORS = ['#f4b223','#a0aec0','#cd7f32'];
const RANK_BG    = ['rgba(244,178,35,0.08)','rgba(160,174,192,0.06)','rgba(205,127,50,0.06)'];
const RANK_BD    = ['rgba(244,178,35,0.35)','rgba(160,174,192,0.25)','rgba(205,127,50,0.25)'];
const RANK_LBL   = ['★ #1','★ #2','★ #3'];

function toggleCard(i) {
  openCards[i] = !openCards[i];
  renderCard(i);
}

function renderCard(i) {
  const el = document.getElementById('card-body-' + i);
  const arrow = document.getElementById('card-arrow-' + i);
  if (!el) return;
  el.style.display = openCards[i] ? 'block' : 'none';
  if (arrow) arrow.style.transform = openCards[i] ? 'rotate(180deg)' : 'none';
}

// ── Generate ───────────────────────────────────────────────────
function generate() {
  const rSph = document.getElementById('rSph').value;
  const rCyl = document.getElementById('rCyl').value;
  const rAdd = document.getElementById('rAdd').value;
  const lSph = document.getElementById('lSph').value;
  const lCyl = document.getElementById('lCyl').value;
  const lAdd = document.getElementById('lAdd').value;
  const age  = document.getElementById('age').value;
  const notes = document.getElementById('notes').value;

  const allZero = ['rSph','rCyl','lSph','lCyl'].every(k => {
    const v = document.getElementById(k).value;
    return !parseFloat(v);
  });
  if (allZero) {
    document.getElementById('results').innerHTML =
      '<div class="card" style="text-align:center;color:#f59e0b;">⚠️ Masukkan minimal satu nilai resep terlebih dahulu.</div>';
    return;
  }

  const p = { rSph, rCyl, rAdd, lSph, lCyl, lAdd, age,
    habit, digital, notes, symptoms: symptoms.join(' ') };

  const result = runEngine(p);
  openCards = {};
  renderResults(result, p);
}

function renderResults(result, p) {
  const container = document.getElementById('results');
  let html = '';

  // Stats bar
  html += '<div class="stats-bar">';
  [
    ['MAX SE', result.maxSE.toFixed(2)+'D'],
    ['MAX CYL', result.maxCyl.toFixed(2)+'D'],
    ['MAX ADD', result.maxAdd.toFixed(2)+'D'],
    ['TYPE', result.isPresby ? 'PRESBIOPI' : 'SINGLE VISION'],
    ['SOURCE', result.anyStockFits ? 'STOCK' : 'LAB'],
  ].forEach(([l,v]) => {
    html += `<div class="stat-box"><div class="stat-box-label">${l}</div><div class="stat-box-val">${v}</div></div>`;
  });
  html += '</div>';

  // Warnings
  const txt = (p.notes + ' ' + p.symptoms).toLowerCase();
  const warnings = [];
  if (result.isPresby) {
    if (result.pDesign === 'all_distance') warnings.push(['🔭','Presbiopi — butuh semua jarak. Progressive ALL-DISTANCE direkomendasikan.']);
    else if (result.pDesign === 'far_near') warnings.push(['📐','Presbiopi — dominan jauh & dekat. FAR & NEAR OPTIMIZED direkomendasikan.']);
    else warnings.push(['📚','Presbiopi — dominan dekat. ENHANCED NEAR VISION / SHORT CORD direkomendasikan.']);
  }
  if (result.maxSE >= 6) warnings.push(['⚡','Ukuran sangat tinggi (SE ≥ 6.00D) — High Index 1.67 atau Lenticular sangat dianjurkan.']);
  if (/headache|sakit.?kepala|mata.?lelah/.test(txt)) warnings.push(['😣','Keluhan mata lelah / sakit kepala — Blue Light Blocking membantu.']);
  if (/dry.?eye|mata.?kering/.test(txt)) warnings.push(['💧','Dry Eye — Super Hydrophobic + Blue Light Blocking mengurangi iritasi.']);
  if (/driving|berkendara|mengemudi/.test(txt)) warnings.push(['🚗','Sering berkendara — Night Drive Coating & Photochromic dianjurkan.']);

  warnings.forEach(([icon, msg]) => {
    html += `<div class="warning-box"><span>${icon}</span><span>${msg}</span></div>`;
  });

  // Result count
  html += `<div class="result-count">✦ ${result.candidates.length} LENSA COCOK — DIURUTKAN DARI PALING SESUAI</div>`;

  if (result.candidates.length === 0) {
    html += '<div class="no-result">Tidak ada lensa yang cocok dengan ukuran ini.</div>';
  }

  result.candidates.slice(0, 15).forEach((c, i) => {
    const col = RANK_COLORS[i] || '#00c896';
    const bg  = RANK_BG[i]    || 'rgba(0,200,150,0.04)';
    const bd  = RANK_BD[i]    || 'rgba(0,200,150,0.15)';
    const lbl = RANK_LBL[i]  || `#${i+1}`;
    const srcColor = c.source === 'stock' ? '#22c55e' : '#f97316';
    const srcRgb   = c.source === 'stock' ? '34,197,94' : '249,115,22';

    const featTags = c.features.map(f => {
      const icon = FEAT_ICON[f.toUpperCase()] || '•';
      return `<span class="feat-tag">${icon} ${f}</span>`;
    }).join('');

    const noteHtml = c.note
      ? `<div class="lens-note">📋 ${c.note}</div>` : '';

    html += `
    <div class="lens-card" style="border:1px solid ${bd};background:${bg};">
      <div class="lens-header" onclick="toggleCard(${i})">
        <span class="rank-badge" style="background:${bd};color:${col};">${lbl}</span>
        <div class="lens-info">
          <div class="lens-name" style="color:${col};">${c.category} — ${c.type}</div>
          <div class="lens-meta">
            <span class="source-badge" style="color:${srcColor};background:rgba(${srcRgb},0.1);border-color:rgba(${srcRgb},0.3);">${c.source.toUpperCase()}</span>
            <span class="lens-readiness">⏱ ${c.readiness}</span>
          </div>
        </div>
        <div class="lens-price" style="color:${col};">${fmt(c.selling)}</div>
        <span class="lens-arrow" id="card-arrow-${i}">▼</span>
      </div>
      <div class="lens-body" id="card-body-${i}" style="display:none;border-top-color:${bd};">
        <div class="feat-label">FITUR LENSA</div>
        <div class="feat-tags">${featTags}</div>
        ${noteHtml}
        <div class="lens-score">Score: <span>${c.score.toFixed(2)}</span></div>
      </div>
    </div>`;
  });

  container.innerHTML = html;
}
</script>
</body>
</html>