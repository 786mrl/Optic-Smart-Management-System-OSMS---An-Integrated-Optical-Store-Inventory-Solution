# Konsep & Aturan Operasional Sistem Rekomendasi Lensa

Dokumen ini berisi spesifikasi teknis dan algoritma penskoran untuk sistem rekomendasi lensa berbasis JSON pada aplikasi manajemen optik.

---

## 1. Parameter Input Sistem

### A. Data Resep Pasien (Rx) — *Filter Mutlak*
* **SPH, CYL, ADD**: Memvalidasi apakah ukuran pasien masuk dalam rentang `limits` lensa di data JSON. Jika ukuran resep pasien berada di luar jangkauan `limits`, produk lensa tersebut otomatis digugurkan dari daftar rekomendasi.

### B. Input Kebutuhan Pasien (Customer Needs)
* **Digital Usage (Durasi Layar/Gadget)**:
  * `Low`: < 2 jam / hari
  * `Medium`: 2 - 5 jam / hari
  * `High`: > 5 jam / hari
* **Visual Activity (Lingkungan Utama)**:
  * `Indoor`: Dominan dalam ruangan.
  * `Outdoor`: Dominan luar ruangan.
  * `Both`: Seimbang antara luar & dalam ruangan.
* **Gejala Khusus (Symptoms - Multi Select)**:
  * Silau saat mengemudi malam (*Night Driving / Anti-Glare*).
  * Mata mudah lelah / sensitif cahaya.

### C. Master Tier & Bobot Fitur Lensa (Setting UI Admin)
* **Primary (100 Poin)**: Fitur spesialis / kebutuhan mutlak (*Night Drive Coating*, *High Index 1.67* untuk minus tinggi).
* **Secondary - Level 1 (30 Poin)**: Fitur standar / ekonomis (misal: *Blueray Standard*, *Photochromic Standard*).
* **Secondary - Level 2 (35 Poin)**: Fitur kualitas menengah / OEM Factory (misal: *Blueray Premium*, *Photochromic Premium*).
* **Secondary - Level 3 (40 Poin)**: Fitur kualitas tertinggi / material khusus (misal: *BlueGard*, *BlueChromic / Extra Dark*).
* **Bonus (10 Poin)**: Lapisan pelindung ekstra (*Super Hydrophobic*, *Anti-Static*, *Smudge-Resistant*).
* **Base Features (5 Poin)**: Lapisan dasar standar (*UV Protection*, *AR Coating* standar).

---

## 2. Tiga Mode Sistem Penskoran (Scoring Modes)

---

### MODE 1: Mode Kebutuhan Pasien (Customer-Centric)
> **Tujuan**: Berfokus 100% pada kesehatan, kenyamanan, dan kesesuaian fungsi lensa dengan gaya hidup serta gejala pasien.

#### Aturan Logika (Logic Rules):
1. **Skor Fitur Dasar**: Poin awal dihitung berdasarkan ketersediaan tag fitur pada JSON yang sesuai dengan bobot Tier dari UI (Primary 100, Secondary Lvl 1-3, Bonus 10, Base 5).
2. **Aturan Digital Usage**:
   * `Low (<2h)` $\rightarrow$ Mentrigger *Base Features* & *Secondary Lvl 1* (Blueray Standard).
   * `Medium (2-5h)` $\rightarrow$ Bonus poin untuk *Secondary Lvl 1* & *Lvl 2* (Blueray Standard / Premium).
   * `High (>5h)` $\rightarrow$ Bonus poin maksimal untuk *Secondary Lvl 3* (BlueGard) & *Lvl 2* (Blueray Premium).
3. **Aturan Visual Activity**:
   * `Indoor` $\rightarrow$ Mengutamakan *Clear Lens* + Anti Radiasi. Fitur *Photochromic* tidak mendapat bonus.
   * `Outdoor` $\rightarrow$ Mentrigger bonus poin untuk fitur *Photochromic* (Level 1/2/3).
   * `Both` $\rightarrow$ Mentrigger bonus poin untuk lensa kombinasi (*BlueChromic* / Photochromic + Blueray).
4. **Aturan Gejala (Night Drive)**: Jika gejala "Silau Malam" dicenang, lensa dengan *Night Drive Coating* (`Primary`) mendapat bonus **+150 Poin**.
5. **Otomatisasi High Index (Trigger Resep Tinggi)**:
   * Jika **SPH $\ge$ -5.00** atau **CYL $\ge$ -2.00**, lensa dengan *High Index (1.67)* otomatis mendapat tambahan **+150 Poin** untuk mendongkrak ke peringkat teratas.

---

### MODE 2: Mode Optimasi Bisnis (Business-Driven)
> **Tujuan**: Berfokus pada efisiensi stok opname, profitabilitas toko, dan pencapaian target omzet, tanpa melanggar batasan aman resep pasien (`limits`).

#### Atribut Data Tambahan di JSON:
* `cogs` (HPP / Modal)
* `selling` (Harga Jual)
* `stock_qty` (Jumlah Stok Toko)
* `is_slow_moving` (Status Stok Lama / Slow Moving)

#### Aturan Logika (Logic Rules):
1. **Margin Keuntungan (Nominal Rp)**: Tambahan **+1 Poin** untuk setiap kelipatan keuntungan Rp 5.000.
2. **Margin Keuntungan (%)**: Margin > 60% mendapat **+30 Poin**, Margin > 40% mendapat **+15 Poin**.
3. **Pembersihan Stok (Clearance)**: Lensa dengan status `is_slow_moving = true` mendapat bonus besar **+100 Poin**.
4. **Ketersediaan Stok**: Lensa yang `stock_qty > 0` (Ready Stock Toko) mendapat **+50 Poin** dibanding lensa Order Lab / PO.
5. **Upselling (High-Ticket Item)**: Lensa dengan Harga Jual $\ge$ Rp 500.000 mendapat **+30 Poin**.

---

### MODE 3: Mode Smart Hybrid (Balanced Mode)
> **Tujuan**: Menyeimbangkan secara adil antara kepuasan medis pasien dan keuntungan finansial toko secara otomatis.

#### Formula Kalkulasi:
$$\text{Total Skor Hybrid} = (70\% \times \text{Skor Kebutuhan Pasien}) + (30\% \times \text{Skor Bisnis})$$

#### Aturan Logika (Logic Rules):
* Rekomendasi dijamin aman dan nyaman untuk kesehatan mata pasien.
* Jika ada beberapa pilihan lensa yang fungsinya mirip/setara untuk kebutuhan pasien, sistem secara otomatis akan mendorong lensa yang **profitnya lebih tinggi** atau **stoknya sedang menumpuk di toko** ke urutan Peringkat #1.

---

## 3. Diagram Alur Evaluasi Sistem