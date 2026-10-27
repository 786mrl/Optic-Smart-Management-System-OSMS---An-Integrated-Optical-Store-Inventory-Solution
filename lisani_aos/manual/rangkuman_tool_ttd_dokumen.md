# Rangkuman Konsep: Tool Dokumen (Editor TTD, Tempel TTD, Kop, Convert to Text OCR, dan Template Form)

> **Versi 27 Okt 2026.** Dokumen ini direvisi untuk mencocokkan apa yang sudah benar-benar dibangun di
> `document_tools_content.php` (lihat PROJECT_NOTES.md §6 Document Tools untuk detail implementasi terkini).
> Bagian yang sudah SELESAI ditandai ✅, yang masih RENCANA ditandai 🔜, dan pendekatan yang pernah dicoba lalu
> diganti ditandai ❌ (dengan alasan penggantiannya) supaya tidak diulang lagi di sesi berikutnya.

## 1. Ide Utama
Admin sering mengedit dokumen hanya untuk menambah tanda tangan, dan kadang menambah kop perusahaan. Tool ini
mengotomatiskan prosesnya:

1. ✅ Upload dokumen (gambar/foto atau PDF multi-halaman). PDF dikonversi menjadi gambar per halaman (PDF.js, di browser).
2. 🔜 Jika dokumen berupa foto: diluruskan (deskew) dan latarnya dibersihkan — **belum dibangun, lihat bagian 14**.
3. Pola dokumen umum: teks "yang bertanda tangan di bawah ini" (atau teks lain di atas), ruang kosong untuk ttd di
   tengah, lalu nama penandatangan di bawah.
4. ✅ Pengguna **memblok** (gambar kotak) bagian dokumen yang perlu dipindah, lalu **menggeser/resize** kotak itu
   (tahap "Adjust Block" sebelum Apply) sampai pas, baru dipotong jadi layer baru (`cutBlockToLayer()`).
5. ✅ **Guide Box** (kotak oranye resizable, preset ukuran dalam inci) menunjukkan area ttd. **Guide Lines**
   (garis bantu horizontal/vertikal, bisa lebih dari satu) membantu pensejajaran manual.
6. ✅ Gambar ttd (disiapkan di Editor TTD Digital, bagian 8) ditempatkan mengikuti ukuran/posisi Guide Box.
7. ✅ Kop (header/footer) ditempel sebagai layer penuh-lebar, dengan opsi "Apply to all pages" yang menyinkronkan
   posisi di semua halaman (geser satu, semua ikut lewat `letterheadSyncId`).
8. ✅ Untuk mengubah isi dokumen hasil foto/scan jadi bisa diedit: **Convert to Text (OCR) → export sebagai file
   .docx** — lihat bagian 6 untuk penjelasan lengkap kenapa pendekatannya berubah dari rencana awal.
9. 🔜 Untuk dokumen ekspor-impor yang berulang: template HTML/CSS + form — **belum dibangun sama sekali, masih
   rencana murni**, lihat bagian 7.
10. ✅ Hasil "Save" (bukan OCR) diekspor sebagai **satu file PDF** (semua halaman digabung via FPDF).

## 2. Apakah Memungkinkan?
**Ya.** Status tiap kebutuhan (diperbarui dari rencana awal):

| Kebutuhan | Teknik | Status |
|---|---|---|
| PDF ke gambar | PDF.js (browser) | ✅ selesai |
| Luruskan foto (deskew) | OpenCV.js atau 4 titik sudut manual | 🔜 belum dibangun |
| Bersihkan latar foto | Adaptive threshold / koreksi kontras | 🔜 belum dibangun |
| Crop dokumen (adjustable, bisa digeser) | Canvas/Fabric.js, handle resize | 🔜 belum dibangun di Document Editor (sudah ada versi lain khusus di Signature Editor, lihat bagian 8) |
| OCR (baca teks + koordinat) | Tesseract.js (`eng+ind`) | ✅ selesai, dipakai jalur export DOCX |
| Edit ttd (kontras, transparan, hapus bagian) | Canvas + JS (threshold, alpha, kuas) | ✅ selesai (Signature Editor) |
| Blok area & pindah | Rectangle select → layer Fabric.js draggable | ✅ selesai |
| Kotak bantuan (Guide Box) + Guide Lines | Fabric.js overlay resizable/draggable | ✅ selesai |
| Tempel ttd dan kop | Layer gambar via Fabric.js, sync semua halaman | ✅ selesai |
| Ekspor PDF | FPDF (server, `optic_pos/fpdf/fpdf.php`) | ✅ selesai |
| Ekspor DOCX dari hasil OCR | OOXML manual + JSZip, murni di browser | ✅ selesai |
| Template HTML/CSS + form berulang | PHP + MariaDB, Dompdf/mPDF | 🔜 belum dibangun |

## 3. Cara Kerja Teknis (Intinya) — ✅ sesuai rencana awal, sudah berjalan
- Dokumen berupa gambar tidak punya "teks" yang bisa dipindah. Yang dilakukan: **cut and move piksel**:
  1. Pengguna menggambar kotak area (drag di canvas).
  2. Kotak jadi object Fabric adjustable ("pending block") — bisa digeser/resize dulu sebelum di-"Apply".
  3. Setelah Apply, area dipotong jadi layer `fabric.Image` baru yang draggable.
  4. Bekas area ditutup dengan warna hasil sampling piksel tetangga (`cutBlockToLayer()`).
- Guide Box menentukan ukuran & posisi target tanda tangan; "Insert Signature" otomatis menyesuaikan skala ttd
  ke ukuran Guide Box saat itu.
- Document Editor pada dasarnya **editor layer** (Fabric.js): dokumen sebagai background image, lalu
  block/guide box/guide line/ttd/kop sebagai object-object di atasnya, per halaman punya state Fabric sendiri.

## 4. Dokumen Hasil Foto (Miring, Latar Tidak Putih) — 🔜 BELUM DIBANGUN
Ini bagian yang **sekarang jadi prioritas berikutnya** (lihat bagian 14 untuk rencana detail terbaru). Status
tiap sub-kebutuhan:
- **Luruskan (deskew)**: belum ada. Tidak ada tombol/alat apa pun di Document Editor untuk ini saat ini.
- **Bersihkan latar**: belum ada filter "mode dokumen" apa pun.
- **Crop yang bisa diatur dan digeser**: belum ada di Document Editor (beda dari Crop di Signature Editor, yang
  sudah ada tapi khusus untuk gambar tanda tangan di kanvas 600×220 — bukan untuk dokumen multi-halaman).

Rencana teknis asli (perspective transform OpenCV.js atau 4 titik manual, adaptive threshold) **masih berlaku**
sebagai opsi teknik, belum ada yang dicoba/gagal di bagian ini — beda dari OCR (bagian 6) yang sudah sempat
dicoba dan gagal.

## 5. Kop Perusahaan (Header dan Footer) — ✅ SELESAI
Sesuai rencana awal, dengan penyempurnaan:
- Disimpan sebagai PNG (header + footer terpisah) di **Letterhead Library** (tabel `letterhead_library`).
- Ditempel full-width, pinned ke atas/bawah halaman.
- **"Apply to all pages"** (default checked): tersinkron ke semua halaman via `letterheadSyncId` — geser/resize
  satu salinan, semua salinan di halaman lain ikut berubah posisinya (termasuk lewat arrow-key nudge).
- Kop bersifat opsional per dokumen, bisa dipakai berkali-kali di satu dokumen.

## 6. Edit Teks Dokumen Hasil Foto — ❌ PENDEKATAN LAMA GAGAL TOTAL, DIGANTI OCR→DOCX
**Pendekatan awal yang direncanakan (§6.b versi lama): "gambar sebagai dasar, teks diedit di atasnya posisi
asli, tetap jadi gambar/PDF di akhir"** — ini **sudah dicoba dan gagal total**, sehingga **ditinggalkan
sepenuhnya**. Pendekatan yang dipakai sekarang (dan sudah berjalan) **beda arah tujuannya**: bukan edit-in-place
di atas gambar, melainkan **konversi ke file .docx yang bisa diedit bebas di Word**.

**Fitur yang berjalan sekarang — "Convert to Text (OCR)" (grup ribbon "Convert to Text", tombol `docOcrBtn`):**

1. **Exclude Zone**: pengguna menggambar sendiri area yang mau dikecualikan dari OCR (misalnya area
   letterhead/logo/stempel) — bisa lebih dari satu per halaman, opsi "Apply to all pages" (pola sync sama
   seperti Letterhead).
2. Saat convert dijalankan: tiap halaman dirender khusus untuk OCR (`renderPageForOcr()`) — area Exclude Zone
   diputihkan dulu (teks di dalamnya tidak ikut terbaca Tesseract) **tapi** area itu tetap di-crop dari bitmap
   asli dan disisipkan balik sebagai **floating image** di posisi aslinya di file .docx akhir.
3. OCR jalan dengan Tesseract.js (`eng+ind`), murni di browser.
4. Hasil OCR direkonstruksi **per baris** (bukan per-paragraf — paragraf hasil Tesseract sering menggabung
   banyak baris berbeda jadi satu bbox raksasa pada dokumen rapat seperti surat/kontrak, ini salah satu
   penyebab kegagalan percobaan sebelumnya).
5. Tiap baris jadi **paragraf alur teks biasa** (`<w:p>`, BUKAN floating textbox — sempat dicoba floating
   per-baris supaya presisi posisi x/y, tapi dibalik ke alur biasa karena pengguna ingin mudah diedit
   langsung klik-ketik, bukan harus klik kotak dulu).
6. Properti yang dipertahankan dari OCR: perataan (`w:jc`, heuristik dua-syarat: menjorok jauh dari margin
   umum halaman DAN margin kiri≈kanan baris itu sendiri → dianggap center), bold (mayoritas kata di baris itu
   bold), ukuran font (dari tinggi baris, dikonversi px→half-point via `DOC_DPI=150`, clamp 6–72pt).
7. File .docx dibuat **murni di browser** (OOXML manual + JSZip, tanpa PHPWord/Composer di server) dan langsung
   didownload — tidak dikirim ke server sama sekali (beda dari "Save" biasa yang ke
   `ajax/save_edited_document.php`).

**Kenapa pendekatan lama gagal (dicatat supaya tidak diulang):**
- Edit-in-place di atas gambar (tutup teks lama dengan warna latar, tampilkan kotak teks baru persis di posisi
  OCR) tidak cocok dengan granularitas kotak OCR yang dipakai — hasil akhirnya kacau, terutama pada dokumen satu
  kolom yang baris-barisnya rapat.
- Tujuan akhir pengguna sebenarnya bukan "gambar yang terlihat sudah diedit", tapi **dokumen yang benar-benar
  bisa diedit bebas** — makanya diganti total ke jalur export .docx di atas, yang hasilnya memang file teks asli
  (bukan gambar berlapis teks).

**Keterbatasan yang masih berlaku:**
- Belum pernah dites di LibreOffice/Google Docs (namespace `wps` murni ekstensi Microsoft 2010).
- Tulisan tangan, stempel, dan tabel rumit tetap tidak terbaca baik oleh OCR — dikecualikan manual lewat
  Exclude Zone kalau mengganggu, atau dibiarkan ikut terbaca jadi teks "sampah" yang diedit manual belakangan.
- Semua halaman diproses berurutan (bukan paralel); belum ada pengujian batas performa untuk dokumen sangat
  panjang/padat teks.
- Export .docx ini **terpisah** dari "Save" biasa (yang tetap menghasilkan PDF) — satu dokumen bisa di-Save
  sebagai PDF (dengan ttd/kop) DAN di-convert terpisah jadi .docx (teks hasil OCR), tapi keduanya bukan hasil
  gabungan dalam satu file.

## 7. Dokumen Berulang (Ekspor-Impor): Template HTML/CSS + Form — 🔜 BELUM DIBANGUN
Tidak berubah dari rencana awal — **ini satu-satunya bagian besar rencana awal yang sampai sekarang belum
disentuh sama sekali**:

**Alur**
1. Buat template sekali per jenis dokumen (HTML/CSS), dengan bagian yang berubah ditandai sebagai kolom isian
   (nama buyer, tanggal, nomor, daftar barang, jumlah, harga, dan sebagainya).
2. Program menampilkan form sesuai template; pengguna hanya mengisi data.
3. Data masuk ke posisi yang sudah ditentukan; ttd dan kop ditempel dari pustaka (bisa reuse
   `ajax/list_signatures.php`/`list_letterheads.php` yang sudah ada).
4. Ekspor ke PDF (Dompdf/mPDF, atau reuse pola FPDF yang sudah dipakai `save_edited_document.php`/invoice).

**Keuntungan**: layout konsisten, PDF teksnya tetap bisa diseleksi/dicari (bukan gambar), total/tanggal/nomor
dokumen bisa otomatis, data buyer & barang tersimpan dan tinggal dipilih, riwayat dokumen bisa diduplikasi.

## 8. Editor TTD Digital — ✅ SELESAI (Signature Library + Editor, digabung 21 Okt 2026)
Mengubah ttd dari foto apa pun menjadi gambar ttd digital siap pakai. Fitur paling sering dipakai, dan paling
awal selesai dibangun.

- **Memperjelas ttd**: slider **Ink Contrast** (menggelapkan warna + menebalkan opacity piksel tinta).
- **Latar transparan**: slider **Remove White Background** (threshold, dengan tombol -5/+5), disimpan PNG.
- **Menghapus bagian yang mengganggu**: **Erase Part** (kuas, radius diatur, history undo stack maks 30 langkah)
  dan **Crop** (drag kotak awal, lalu 8 handle resize + drag-dari-tengah untuk geser, Undo Crop 1 langkah).
- **Pelengkap**: Zoom (50–1000%, murni tampilan, tidak mengubah resolusi pixel asli), simpan ke pustaka
  (`signature_library`, dikelompokkan per Company Name, field `is_main` untuk tandai ttd utama).
- **Teknologi**: HTML Canvas + JavaScript murni di browser, tanpa server untuk pemrosesan gambarnya.

> Catatan untuk fitur Crop yang direncanakan di Document Editor (bagian 14 di bawah): Crop di Signature Editor
> ini **tidak dipakai ulang langsung** — kanvasnya kecil (600×220, khusus gambar ttd), sedangkan Document Editor
> perlu crop di atas halaman dokumen penuh resolusi tinggi dengan sistem Fabric.js yang berbeda. Pola interaksinya
> (8 handle resize + drag) tetap jadi referensi yang bagus untuk ditiru.

## 9. Pilihan Teknologi — ✅ Opsi A (sepenuhnya di browser) yang dipakai
- HTML Canvas + **Fabric.js 5.3.0** (layer, drag, resize) untuk Document Editor.
- **PDF.js 3.4.120** untuk render PDF multi-halaman ke gambar, murni di browser.
- **Tesseract.js 5.0.4** untuk OCR (`eng+ind`).
- **JSZip 3.10.1** untuk membangun file .docx di browser.
- **FPDF** (PHP, server-side, reuse dari `optic_pos/fpdf/fpdf.php`) untuk menggabungkan halaman jadi satu PDF
  saat "Save".
- Semua library cdnjs dimuat dengan `document.write` berpenjaga (cek `typeof x === 'undefined'`) + dibungkus
  try/catch saat dipakai, karena cdnjs bisa diblokir jaringan pengguna (lihat PROJECT_NOTES.md §3 gotcha #8).

## 10. Tahapan Pengerjaan (status terkini)
- **Tahap 1–2 (Upload gambar/PDF)**: ✅ selesai.
- **Tahap 3 (Koreksi foto)**: 🔜 belum dibangun — lihat bagian 14.
- **Tahap 4–5 (Seleksi blok, pindah)**: ✅ selesai (dengan tambahan tahap "Adjust Block" sebelum Apply).
- **Tahap 6 (Guide Box)**: ✅ selesai, plus Guide Lines (fitur tambahan di luar rencana awal).
- **Tahap 7 (Editor ttd digital)**: ✅ selesai.
- **Tahap 8 (Tempel tanda tangan)**: ✅ selesai, bisa dipakai berkali-kali per dokumen/halaman.
- **Tahap 9 (Kop perusahaan)**: ✅ selesai, termasuk sync ke semua halaman.
- **Tahap 10 (Edit teks OCR in-place)**: ❌ diganti total — lihat bagian 6.
  - **Tahap 10b (Convert to Text → export DOCX)**: ✅ selesai, menggantikan Tahap 10.
- **Tahap 11 (Template dokumen + form)**: 🔜 belum dibangun.
- **Tahap 12 (Ekspor)**: ✅ selesai untuk PDF (via "Save"); DOCX via jalur terpisah (Tahap 10b).
- **Tahap 13 (Penyempurnaan)**: Undo sudah ada (per halaman, snapshot-based, limit 20). Riwayat dokumen sudah
  ada (`edited_documents`). Deteksi otomatis tepi kertas: belum.
- **Tahap 14 (BARU, 27 Okt 2026)**: 🔜 Koreksi foto miring + bersihkan latar + Crop adjustable — lihat bagian 14.

## 11. Risiko dan Catatan (masih berlaku)
- **Foto berkualitas rendah**: bayangan kuat/lipatan/cahaya tidak merata masih akan menyulitkan Tahap 14
  (bersihkan latar) begitu dibangun.
- **Resolusi**: konversi PDF/working canvas dibatasi `MAX_DOC_WIDTH = 1400px`; cukup untuk layar tapi perlu
  dicek lagi kecukupannya untuk cetak begitu Tahap 14 (crop + koreksi) ditambahkan.
- **PDF akhir berupa gambar**: hasil "Save" tetap gambar yang di-flatten (teks tidak bisa diseleksi) — ini
  disengaja (beda tujuan dari jalur OCR→DOCX). Simpan juga dokumen asli kalau perlu.
- **Keamanan**: gambar ttd adalah data sensitif — lihat bagian 13 untuk aturan penyimpanan yang sudah ditetapkan.
- **Kerapian**: pastikan nama dan teks atas tetap sejajar setelah dipindah (sudah dibantu Guide Lines).

## 12. Rekomendasi Langkah Berikutnya (diperbarui)
Rekomendasi asli (mulai dari Editor ttd digital, lalu template+form) **sebagian sudah dijalankan** — Editor ttd
digital sudah selesai duluan sesuai rencana. Prioritas berikutnya yang dipilih pengguna (27 Okt 2026) **bukan**
template+form (Tahap 11), melainkan **Tahap 14: koreksi foto miring, bersihkan latar, dan crop adjustable** —
lihat bagian 14 untuk rencana detailnya. Template+form (Tahap 11) tetap jadi kandidat kuat setelah itu, karena
nilai bisnisnya besar dan sampai sekarang belum tersentuh sama sekali.

## 13. Aturan Penyimpanan — ✅ SUDAH DITETAPKAN (sinkron dengan PROJECT_NOTES.md §2–§6)
Semua data yang dibahas di dokumen ini mengikuti pola penyimpanan proyek `lisani_aos` yang sudah berjalan:

- **Dokumen asli yang diunggah** (gambar/foto/PDF ke Document Editor): **tidak disimpan ke disk**. Diproses
  sepenuhnya di sisi klien (browser, working canvas per halaman); hanya hasil akhir (PNG hasil flatten tiap
  halaman) yang dikirim ke server saat "Save".
- **Hasil dokumen (PDF) dan riwayat dokumen**: tabel `edited_documents` (kolom `signature_ids`/`letterhead_ids`
  sebagai VARCHAR CSV tanpa FK, `page_count`, `file_ext` default `'pdf'`). File PDF fisik tersimpan di `storage/`
  mengikuti pola folder proyek (lihat `AOS_STORAGE_BASE` di PROJECT_NOTES.md §2); FPDF menggabungkan semua
  halaman jadi satu file per penyimpanan.
- **Gambar tanda tangan (data sensitif)**: `storage/signature/{company_name_slug}/{origin_slug}/{file}`, tabel
  `signature_library`. Hapus = pindah ke `storage/recycle/…` (bukan dihapus permanen), lalu folder asli yang
  jadi kosong ikut dibersihkan (berhenti di folder yang masih ada isinya, tidak pernah menghapus
  `storage/signature` sendiri). Akses: sama seperti seluruh extended mode — di balik triple-click login +
  sesi `lisani_aos` sendiri (`$_SESSION['app'] === 'lisani_aos'`), aksi Edit lewat password pola bebas, Delete
  lewat password pola (b) (`verify_password.php` + `_require_reverify.php`, jendela 120 detik).
- **Pustaka kop (header/footer)**: `storage/letterhead/{company_slug}/{origin_slug}/`, tabel
  `letterhead_library`. Pola hapus/akses sama seperti Signature Library di atas.
- **Template dokumen dan data buyer/barang** (Tahap 11, belum dibangun): direncanakan ikut database
  `lisani_aos_db` yang sama (`$lisani_conn`, mysqli), bukan tabel/DB terpisah — menyusul pola semua modul lain
  di proyek ini (lihat PROJECT_NOTES.md §4).
- **Data di database (riwayat, penomoran)**: tidak ada penomoran otomatis khusus Document Tools saat ini (beda
  dari modul Sales yang punya `sequence_number` per customer) — tiap `edited_documents` berdiri sendiri per
  nama+tanggal+activity title yang diinput manual.

**Ringkasan prinsip**: dokumen MENTAH tidak pernah menyentuh server (privasi/keamanan, sesuai pertimbangan Opsi
A di bagian 9); yang tersimpan hanya HASIL AKHIR (PDF) + pustaka ttd/kop yang memang untuk dipakai ulang. Semua
hapus = soft-delete via `recycle/`, bukan hard-delete, konsisten dengan aturan modul lain di `lisani_aos`
(PROJECT_NOTES.md §5.10).

## 14. RENCANA BARU (27 Okt 2026): Koreksi Foto Miring + Bersihkan Latar + Crop Adjustable → Tetap Output PDF
Fitur ini ditambahkan ke Document Editor, dijalankan **sebelum** tahap Block/Guide Box/dst (idealnya langsung
setelah upload, per halaman), supaya dokumen hasil foto jadi rapi dulu sebelum diedit lebih lanjut. Output akhir
tetap digabung jadi **satu file PDF** lewat jalur "Save" yang sudah ada (FPDF) — fitur ini tidak mengubah format
ekspor, hanya menambah tahap persiapan sebelum flatten.

**Lingkup (3 sub-fitur, saling terpisah tapi dipakai berurutan):**

1. **Luruskan foto (deskew)** — opsi teknik: 4 titik sudut manual (diseret pengguna ke tiap pojok kertas) lalu
   perspective transform, ATAU tombol putar halus untuk kemiringan kecil. Manual-4-titik lebih mudah
   diimplementasi lebih dulu (deteksi tepi otomatis via OpenCV.js bisa menyusul sebagai Tahap 2).
2. **Bersihkan latar** — filter "mode dokumen" (adaptive threshold / koreksi kontras-pencahayaan) supaya kertas
   jadi putih merata dan bayangan berkurang. Harus bisa dinyalakan/dimatikan (opsional), karena filter bisa
   mengubah tampilan dokumen yang sebenarnya sudah bagus.
3. **Crop (bisa diatur ukurannya dan digeser)** — beda dari Crop di Signature Editor (lihat catatan di bagian
   8): ini bekerja di atas halaman dokumen Document Editor yang sudah pakai Fabric.js, jadi polanya mengikuti
   pola "pending block" yang sudah ada (`enterPendingAdjustMode()`/Apply/Cancel) — rect Fabric dengan handle
   resize bawaan, DIKONFIRMASI (Apply) baru benar-benar memotong `workingCanvas` halaman itu.

**Urutan pemakaian yang disarankan**: Luruskan → Crop → Bersihkan Latar → (lanjut ke alur lama: Block/Guide
Box/Insert Signature/Insert Letterhead/Save PDF). Crop ditaruh setelah Luruskan supaya area pilihan crop tidak
perlu dihitung ulang kalau perspektifnya berubah duluan.

**Belum diputuskan (perlu dibahas saat implementasi):**
- Apakah 3 sub-fitur ini jadi grup ribbon baru sendiri ("Photo Correction" atau serupa), atau masuk ke alur
  upload langsung (modal/step tersendiri sebelum kanvas utama muncul)?
- Apakah Crop di sini berlaku untuk SEMUA halaman sekaligus (seperti "Apply to all pages" di Letterhead/Exclude
  Zone), atau murni per halaman (karena posisi kertas di tiap foto biasanya beda-beda, "Apply to all" mungkin
  tidak relevan untuk Crop/Luruskan — hanya relevan untuk Bersihkan Latar sebagai default on/off)?
- Deskew otomatis (deteksi tepi kertas via OpenCV.js) — mulai dari manual dulu (4 titik), otomatis menyusul.