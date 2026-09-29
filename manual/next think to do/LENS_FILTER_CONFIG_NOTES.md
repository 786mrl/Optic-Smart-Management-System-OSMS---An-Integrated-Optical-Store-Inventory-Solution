# Lens Recommendation Filter — Configurable UI Project

Tracking file untuk pekerjaan memindahkan logika filter/scoring rekomendasi lensa
di `invoice.php` dari hardcoded PHP ke pengaturan yang bisa diubah lewat UI.

## Status: Perencanaan (belum ada kode ditulis)

## Alur Kerja End-to-End (invoice.php, blok lr_* ~line 1745-2250)

0. Rx aktif dipilih: ORIGINAL atau MODIFIED (toggle lens_modification). Kedua set
   di-build (lr_buildSet) supaya toggle YES/NO di UI langsung ganti kartu tanpa reload.
1. Data pasien dibaca: age, visual_habit (1/2/3), digital_usage (1/2/3), teks gabungan
   symptoms + exam_notes (lowercase), need_distance/intermediate/near (hanya usia>=39).
2. Metrik Rx dihitung: maxSph, maxCyl, maxAdd, SE per mata = |sph| + |cyl|/2,
   maxSE = SE terbesar dua mata.
3. Flag: isHighPow (SE>=4 atau CYL>=2), isVeryHighPow (SE>=6 atau CYL>=3),
   isPresbyopia (maxAdd>=0.75 && age>=39).
4. Flag gejala dari regex atas teks (glare, headlight glare, eye strain, headache,
   diabetes, hypertension, dry eye, driving, impact).
5. Katalog dibaca dari data_json/lense_prices.json (stock + lab).
6. Jika presbyopia -> lr_presbyDesign() menentukan presbyType (3 lapis prioritas);
   far_only -> farOnlySV (dialihkan ke SINGLE VISION).
7. Loop tiap lensa: Category Gate -> Rx Fit -> Hard Filter 2 -> lr_score().
8. Sort (score desc, stock sebelum lab, harga asc) + 3 tahap dedup.
9. Special notes (peringatan DM/HT/resep sangat tinggi/desain presbyopia/gejala).
10. Pembagian tampilan: per tipe lensa (SV/Kryptok/Progressive/Flattop), tab harga
    (Recommended top 5, Budget <=600rb, Mid 600rb-1jt, Premium >1jt), dan penanda
    "design match" (lr_meetsDesign + $lr_designFeatureMap).

Bagian hardcoded lain yang belum dibahas untuk config: $lr_designFeatureMap
(presbyType -> fitur desain yang dianggap cocok), teks special notes, batas harga tab.

## Ringkasan Sistem Saat Ini (as of 2026-09-22)

File: `invoice.php` (fungsi-fungsi `lr_*`), data lensa: `data_json/lense_prices.json`

Alur:
1. **STEP 1 — Rx Fit Check** (`lr_rxFits()`): cek SPH/CYL/ADD pasien vs limit per lensa
   di JSON. Logika pembacaan limit (aturan 0/0, plano-only, dst) hardcoded.
2. **Category Gate** (`lr_catAllowed()`): presbyopia → PROGRESSIVE/KRYPTOK/FLATTOP,
   non-presbyopia → SINGLE VISION. Hardcoded.
3. **Hard Filter 2**: skip lensa HIGH INDEX/HIGH POWER RX jika maxSE < 3.0 && maxCyl < 3.0.
   Hardcoded.
4. **STEP 2 — Scoring** (`lr_score()`): bobot poin per fitur lensa (BLUE LIGHT BLOCKING,
   PHOTOCHROMIC, dll) dikalikan kondisi pasien (digital usage, visual habit, gejala teks).
   Semua bobot angka + regex keyword gejala hardcoded.
5. **Presbyopia Design** (`lr_presbyDesign()`): prioritas vision-need eksplisit → keyword
   gejala → habit/digital usage untuk tentukan tipe desain progresif. Hardcoded.
6. **STEP 3**: sort (score desc → stock before lab → price asc) + 3 tahap dedup.

## Rencana: Apa yang Dipindah ke UI Config

Diurutkan dari paling sederhana ke paling kompleks:

1. **Ambang batas numerik** — isHighPow, isVeryHighPow, presbyopia ADD/age threshold,
   hard filter high-index SE<3.0, price bucket boundaries (600rb / 1jt)
2. **Bobot skor per fitur** — semua angka di switch-case `lr_score()`, bobot desain
   progresif (40/25/15/5 dst)
3. **Keyword/regex deteksi gejala** — glare, eye strain, dry eye, driving, dll
4. **Category gate** — mapping presbyopia/non-presbyopia ke kategori lensa
5. **Priority order presbyopia design logic**

## Pendekatan Teknis (diusulkan)

- Tabel baru `lens_filter_config` (key-value, pola sama seperti tabel `settings`
  yang sudah dipakai untuk lens lead time)
- Halaman admin baru `lens_filter_settings.php` — card UI per kategori config di atas
- `invoice.php` baca config dari tabel ini alih-alih array/angka hardcoded

## Keputusan Rais (2026-09-22)

- STEP 1 (Rx fit), Category Gate, Hard Filter 2, dan STEP 3 (sort/dedup): **tetap hardcoded**, sudah OK.
- STEP 2 (scoring) dan Presbyopia Design Logic: **mau dibuat configurable dari UI**.
- Visual habit & digital usage TIDAK jadi config terpisah — keduanya adalah variabel
  kondisi yang dipakai di dalam rules STEP 2 & presbyopia design, bukan step tersendiri.
- Sepakat bangun konsep dulu sampai jelas, sebelum mulai coding.

## Klarifikasi Aturan Limit JSON (sudah dijelaskan ke Rais)

- SPH: `sph_from=0 & sph_to=0` → TIDAK ADA batasan SPH (bukan "harus 0.00")
  Contoh: LENTICULAR HMC/SHMC
- CYL: `cyl_from=0 & cyl_to=0` → HANYA menerima CYL 0 (plano only)
  `cyl_to != 0` → menerima CYL dari 0 s/d abs(cyl_to)/100
  Contoh: BLUERAY cyl_from=-25, cyl_to=-200 → menerima -0.25 s/d -2.00

## Contoh Hard Filter 2 (sudah dijelaskan ke Rais)

LENTICULAR HMC/SHMC: sph_from=0/sph_to=0 (tak dibatasi) tapi fitur HIGH POWER RX.
Tanpa Hard Filter 2, pasien resep tipis (mis. SPH -0.50) akan lolos STEP 1 dan
lensa lenticular (khusus resep tinggi) ikut muncul. Hard Filter 2 = skip
HIGH INDEX 1.67 / HIGH POWER RX kalau maxSE<3.0 && maxCyl<3.0.

## STEP 2 — Breakdown Lengkap (untuk desain config)

### 2A. Skor desain progresif (khusus presbyopia, ambil skor TERTINGGI yang match, bukan dijumlah)
presbyType (all_distance/dynamic/far_near/near) × fitur lensa (ALL-DISTANCE PROGRESSIVE,
FAR & NEAR OPTIMIZED, DYNAMIC DISTANCE, NEAR-OPTIMIZED, ENHANCED NEAR VISION) → tabel poin
40/25/15/5 dst (lihat kode `$designScores` di invoice.php ~line 1916-1925).
Plus bonus KRYPTOK/FLATTOP: +10 jika age≥65, +2 jika <65.

### 2B. Skor fitur × gaya hidup/gejala (semua lensa, dijumlah semua yang match)
17 fitur, masing-masing punya 1-4 baris kondisi→poin (kondisi berbasis digital_usage,
visual_habit, symptom flags, rx threshold/maxSE). Daftar lengkap poin per fitur ada di
riwayat chat 2026-09-22 (BLUE LIGHT BLOCKING, PHOTOCHROMIC, NIGHT DRIVE COATING,
HIGH INDEX 1.67, HIGH-INDEX UV400 PROTECTION, HIGH POWER RX, IMPACT-RESISTANT,
SUPER HYDROPHOBIC, HYDROPHOBIC, SMUDGE-RESISTANT, ANTI-STATIC,
SCRATCH-RESISTANT COATING, ANTI-REFLECTIVE (AR) COATING, UV PROTECTION).

### Usulan skema config (draft, belum final)
```
lens_scoring_rules
- id
- feature_name
- condition_type   (digital_usage | visual_habit | symptom_flag | rx_threshold | age)
- condition_operator (=, >=, <)
- condition_value
- extra_condition  (opsional, untuk kondisi gabungan AND)
- points           (boleh negatif)
- active
```
UI: 1 halaman per fitur dengan list baris kondisi+poin (tambah/hapus/edit),
+ tombol "Tambah Fitur Baru".

### Pertanyaan terbuka (belum dijawab Rais)
1. Fitur baru ke depan — selalu kondisi tunggal→poin, atau perlu kondisi gabungan
   (AND 2+ variabel, seperti "eye strain & digital≥2")?
2. 2A (ambil skor tertinggi yang match) logikanya beda dari 2B (skor dijumlah) —
   digabung 1 UI atau dipisah 2 halaman config?

## Presbyopia Design Logic — Breakdown Lengkap (untuk desain config)

Fungsi `lr_presbyDesign()`, 3 lapis prioritas (berhenti di lapis pertama yang match):

**Lapis 1 — vision-need eksplisit** (toggle DISTANCE/INTERMEDIATE/NEAR, usia≥39):
tabel mapping 8 kombinasi (D/I/N: true/false) → 5 kemungkinan hasil
(all_distance/dynamic/far_near/near/far_only). Kombinasi tetap → cocok jadi
1 tabel dropdown sederhana di UI (8 baris, tiap baris pilih hasil).

**Lapis 2 — keyword dari gejala/catatan** (kalau lapis 1 kosong):
- baca/membaca/jahit/sewing/close.?work/near.?work → near
- mengemudi/bawa.?mobil/driving/berkendara → far_near

**Lapis 3 — fallback habit/digital** (kalau lapis 2 juga kosong):
- habit==3 ATAU (digital≥2 & habit≥2) → all_distance
- habit==2 → far_near
- default → far_near

Lapis 2 & 3 bisa jadi list regex/aturan yang ditambah/edit dari UI, sama pola
dengan keyword gejala di STEP 2. **Catatan penting**: hasil presbyType dari sini
dipakai lagi di STEP 2A (skor desain progresif) — kedua config saling terhubung,
perlu didesain agar saling merujuk jelas di UI.

## Rombakan Scoring — Konsep Baru (disepakati 2026-09-29)

Rais TIDAK mau alur kerja diubah sama sekali. Yang direstruktur HANYA isi
scoring fitur utama (2B lama) di dalam lr_score(); STEP 2A (desain progresif),
Rx fit, category gate, hard filter 2, sort, dedup 3-lapis — SEMUA TETAP SAMA.

### #1 Fitur Utama Lensa
- CLEAR LENSE — hanya ukuran + base feature (+ kadang bonus). Ini yang sebelumnya
  disebut "PLAIN". Tidak dapat skor primary/secondary apa pun.
- BLUERAY — 3 level (Level 1 ekonomis / Level 2 pabrikan / Level 3 proteksi maksimal)
- PHOTOCHROMIC — 2 level (Level 1 ekonomis / Level 2 pabrikan)
- NIGHT DRIVE — 1 level saja
- HIGH INDEX — 2 level: 1.67 dan 1.74 (bukan 1.72 — sudah dikoreksi Rais)
  Index 1.50 & 1.56 dianggap index standar/default → diperlakukan sebagai BASE (5),
  bukan fitur utama, tidak dapat skor primary/primary+.

### #2 Tabel Skor (Tier)
- primary = 100 (night drive; high index 1.67)
- primary+ = 150 (high index 1.74)
- secondary = 30 / secondary+ = 35 / secondary++ = 40
  (blueray L1/L2/L3 berurutan; photochromic L1/L2 → secondary/secondary+)
  CLEAR LENSE tidak dapat secondary apa pun.
- base = 5 (AR coating, UV400/UV protection, index 1.50/1.56)
- base+ = 8 (UV420+cut — baru, belum ada di JSON)
- bonus = 10 (super hydrophobic, anti-static, smudge-resistant, dll — fitur lain)

### #3/#7 Symptom → Fitur Utama (many-to-many, symptom bisa trigger banyak fitur)
Belum final — akan diteliti lebih lanjut oleh Rais (contoh: diabetes → PGX + BLUERAY).
Struktur: 1 symptom bisa dikaitkan ke lebih dari 1 fitur utama sekaligus.

**Keputusan baru (2026-09-29): dipecah jadi 2 lapis, bukan regex langsung ke fitur.**
Regex gejala yang sekarang (hasGlare, hasDryEye, dll) langsung memicu fitur —
Rais menilai ini "belum ada manfaatnya", mau diganti jadi 2 tahap:

1. **Symptom bank** (kamus symptom terstruktur) — teks bebas keluhan pasien
   diuraikan dulu jadi daftar symptom yang terdeteksi (via kata kunci/sinonim
   per symptom, bisa ditambah dari UI). Modul ini independen, berpotensi
   dipakai ulang di luar konteks rekomendasi lensa (repo makin besar, sesuai
   niat Rais).
2. **Symptom → fitur** (mapping #3/#7 di atas) — baru dipetakan ke fitur utama,
   pakai daftar symptom terstruktur dari langkah 1, bukan regex mentah lagi.

1 teks keluhan bisa mendeteksi banyak symptom sekaligus; semua symptom yang
terdeteksi ikut masuk ke union kebutuhan fitur (konsisten dengan keputusan #4).

### Alur UI symptom (klarifikasi Rais, 2026-09-29)
- Input symptom di UI pemeriksaan: via KLIK dari daftar symptom yang sudah ada
  (tiap symptom di daftar ini SUDAH terhubung ke solusi fitur lensa).
- Ada juga input TEKS BEBAS untuk gejala yang tidak ada di daftar klik.
  Untuk versi awal, teks bebas ini TIDAK berpengaruh ke scoring sama sekali
  (cuma tercatat).
- Akan dibangun UI BARU: "Symptom Review Queue" — Rais riset teks bebas yang
  masuk, tentukan kemungkinan kondisi + solusi lensanya, lalu MENJADIKANNYA
  opsi klik baru (otomatis muncul di daftar klik + otomatis terhubung ke
  mapping fiturnya). ISI dari symptom bank yang dibahas sebelumnya jadi
  bersumber dari hasil review ini, bukan didefinisikan sekali di awal.
- Ini human-in-the-loop by design (bukan NLP otomatis penuh) — sengaja,
  karena rekomendasi lensa berkaitan kesehatan mata, perlu verifikasi manusia
  sebelum symptom baru ikut memengaruhi rekomendasi ke pasien lain.
### Jawaban Rais atas 4 pertanyaan (2026-09-29)
1. Sumber teks bebas: dari form pemeriksaan, sudah ada di DB — kolom `symptoms`
   di tabel `customer_examinations`, pola `"OTHERS: <teks>"` (sudah ada di kode,
   lihat sekitar line 379-382 & 613 invoice.php) adalah teks bebas yang dimaksud.
   `exam_notes` (kolom sama) ikut jadi konteks tambahan.
2. Bentuk halaman: list/antrean semua catatan `OTHERS:` yang belum diproses.
3. Proses: 1 layar — riset, isi nama symptom + kata kunci sinonim + centang
   fitur solusi, submit sekaligus jadi opsi klik baru + langsung ter-mapping.
4. Deteksi kemiripan symptom: manual dulu untuk versi awal.

### Info Customer yang Ditampilkan di Layar Review (permintaan Rais, read-only)
Semua sudah tersedia di tabel `customer_examinations` yang sama (tidak perlu join):
usia, gender, visual_habit, digital_usage, symptom lain yang sudah ke-klik di
kunjungan yang sama, exam_notes, Rx (r/l sph/cyl/add), need_distance/
intermediate/near (kalau usia≥39). Semua cuma referensi, tidak diedit di layar ini.

### #4 Digital Usage → Level BLUERAY
- DU Low → CLEAR LENSE atau BLUERAY L1
- DU Medium → BLUERAY L2 atau L1
- DU High → BLUERAY L3 atau L2

### #5 Visual Habit → Level PHOTOCHROMIC
- Indoor → tidak butuh PGX; pakai BLUERAY sesuai nilai DU
- Outdoor → PGX L2 atau L1
- Both → PGX + BLUERAY (level blueray tetap ikut nilai DU)

### Keputusan desain penting
- **Kebutuhan fitur = union dari semua sumber** (habit + DU + symptom + SE/CYL),
  bukan exclusive. Contoh: habit=Indoor tetap bisa butuh PGX kalau ada symptom
  yang memicunya.
- **Tidak match kebutuhan → skor 0, BUKAN penalti** (beda dari sistem lama yang
  ada minus/penalti, mis. PHOTOCHROMIC di habit Indoor dulu -12).
- **Stacking dari banyak sumber untuk 1 fitur = CAP per fitur, ambil tier
  TERTINGGI yang match** (opsi 1 dari 3 opsi yang diajukan Claude), BUKAN
  dijumlah semua sumber. Alasan: menjaga hierarki primary>secondary>base>bonus
  tetap valid, predictable, dan aman dari efek tak terduga saat nambah
  fitur/symptom baru dari UI.
- STEP 2A (desain progresif presbyopia) tetap terpisah, tetap jalan di awal
  (langkah 7, sebelum candidate loop) sesuai kebutuhan jauh-dekat pasien —
  TIDAK digabung dengan rombakan #1-#5 di atas.

### Dampak ke lense_prices.json (perlu diupdate Rais SEBELUM coding)
- Tambah level ke nama fitur: BLUE LIGHT BLOCKING LEVEL 1/2/3,
  PHOTOCHROMIC LEVEL 1/2, HIGH INDEX 1.67, HIGH INDEX 1.74, CLEAR LENSE
- Rapikan index 1.50/1.56 ke label base yang konsisten
- Betulkan typo "HIGHT INDEX 1.67" → "HIGH INDEX 1.67" (1 entri, saat ini
  "diselamatkan" karena isHiIdx cek dua ejaan sekaligus)
- Tambah UV420+cut sebagai base+ (baru, belum ada sama sekali di katalog)

## Alur Lengkap Sistem Setelah Digabung (final, 2026-09-29)

Langkah 1-7, 9-11 SAMA PERSIS seperti sistem lama (lihat "Alur Kerja End-to-End"
di atas) — hanya isi langkah 8 (lr_score, sub-bagian 2B) yang berubah.

STEP 3 (sort + dedup 3-lapis) dikonfirmasi TETAP: STOCK vs non-STOCK suppress,
stock vs lab exact-match suppress lab, strip suffix (2)/(3) lalu ambil harga
termurah per grup source+kategori+nama-dasar. Semua ini terjadi SETELAH scoring,
tidak terpengaruh oleh rombakan scoring.

Isi baru langkah 8 / 2B:
1. Hitung kebutuhan fitur utama pasien (union semua sumber: DU, habit, symptom,
   SE/CYL), simpan per fitur beserta sumber kontribusinya.
2. Untuk tiap fitur utama yang dimiliki lensa (dengan levelnya): cocokkan ke
   kebutuhan, ambil tier TERTINGGI yang match dari semua sumber (cap, bukan
   stacking). Tidak match → 0. CLEAR LENSE → tidak dapat skor primary/secondary.
3. Base & bonus dihitung terpisah, selalu ditambahkan kalau lensa punya fiturnya.
4. Total skor (2A progresif + 2B fitur utama) dipakai di STEP 3 sort, tidak berubah.

## Roadmap Final (9 langkah, disepakati 2026-09-29)

1. [ ] Update lense_prices.json — level fitur baru (BLUERAY L1-3, PHOTOCHROMIC L1-2,
       HIGH INDEX 1.67/1.74, CLEAR LENSE), betulkan typo HIGHT INDEX, tambah UV420+cut.
       PRASYARAT semua langkah berikutnya.
2. [ ] Bangun kamus symptom (symptom bank) — daftar symptom terstruktur + kata
       kunci/sinonim per symptom untuk deteksi dari teks keluhan bebas.
3. [ ] Finalisasi mapping symptom → fitur utama (#3/#7), pakai symptom bank
       dari langkah 2, bukan regex mentah.
4. [ ] Finalisasi mapping DU→blueray, habit→photochromic, SE/CYL→high index.
5. [ ] Desain skema tabel config final: main_features, score_tiers, symptom_bank,
       symptom_feature_map, du_blueray_map, habit_photochromic_map, se_highindex_map.
6. [ ] Wireframe halaman settings.php untuk semua tabel config di atas.
7. [ ] Bangun settings.php (CRUD untuk semua tabel config).
8. [ ] Refactor isi lr_score() bagian 2B saja (surgical) — baca dari config.
       2A progresif, lr_rxFits, lr_catAllowed, hard filter 2, sort, dedup 3-lapis
       TETAP HARDCODED, tidak disentuh sama sekali.
9. [ ] Uji dengan kasus pasien nyata — bandingkan hasil sistem baru vs lama.

**Status saat ini: di langkah 1** (menunggu Rais update lense_prices.json).
Symptom Review Queue sudah jelas alurnya (lihat #3/#7 di atas) — masuk sebagai
sub-komponen baru di langkah 2/6/7 (baca dari customer_examinations.symptoms
pola "OTHERS:", 1 layar proses, tampilkan info customer terkait sebagai konteks).

## Catatan Aturan Kerja (dari Rais)

- Surgical edits only — jangan sentuh kode di luar blok yang dimodifikasi
- Identifier/kode/kolom dalam bahasa Inggris, tampilan UI dalam Bahasa Indonesia
- File hasil modifikasi diberikan lengkap (ready to copy-paste)
- Notasi resep tanpa titik desimal (-100 = -1.00)
