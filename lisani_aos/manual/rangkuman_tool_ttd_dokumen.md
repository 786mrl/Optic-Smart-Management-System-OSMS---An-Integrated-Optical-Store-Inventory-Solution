# Rangkuman Konsep: Tool Dokumen (Editor TTD, Tempel TTD, Kop, Edit Teks OCR, dan Template Form)

> **PENGINGAT:** Penyimpanan semua data dalam proyek ini memiliki aturan tersendiri. Pastikan aturan tersebut dipenuhi **sebelum implementasi** (lihat bagian 13).

## 1. Ide Utama
Admin sering mengedit dokumen hanya untuk menambah tanda tangan, dan kadang menambah kop perusahaan. Tool ini mengotomatiskan prosesnya:

1. Upload dokumen (gambar, foto, atau PDF). PDF dikonversi menjadi gambar.
2. Jika dokumen berupa foto: diluruskan dan latarnya dibersihkan (lihat bagian 4).
3. Pola dokumen umum: teks "yang bertanda tangan di bawah ini" (atau teks lain di atas), ruang kosong untuk ttd di tengah, lalu nama penandatangan di bawah.
4. Pengguna **memblok** bagian teks atas dan bagian nama, lalu **memindahkan** posisinya masing-masing sehingga tercipta ruang untuk ttd.
5. **Kotak bantuan (guide box)** menunjukkan area ttd dan menjadi panduan seberapa jauh nama harus digeser.
6. Gambar ttd (disiapkan lebih dulu di editor ttd digital, bagian 8) ditempatkan tepat di antara kedua bagian tersebut.
7. Jika diperlukan, kop (header/footer) ditempel sebagai layer.
8. Jika perlu mengubah isi dokumen hasil foto: teks dikenali dengan OCR lalu diedit di posisi aslinya (bagian 6).
9. Untuk dokumen ekspor-impor yang berulang: gunakan template HTML/CSS dan isi lewat form (bagian 7).
10. Hasil diekspor.

## 2. Apakah Memungkinkan?
**Ya, sangat memungkinkan.** Semua fitur yang dibutuhkan sudah umum dan matang:

| Kebutuhan | Teknik |
|---|---|
| PDF ke gambar | PDF.js (browser) atau Poppler/Imagick (server) |
| Luruskan foto | OpenCV.js (perspective transform) atau 4 titik sudut manual |
| Bersihkan latar foto | Adaptive threshold / koreksi kontras dan pencahayaan |
| OCR (baca teks + koordinat) | Tesseract.js (gratis, mendukung Bahasa Indonesia) |
| Edit ttd (kontras, transparan, hapus bagian) | Canvas + JavaScript (threshold, alpha channel, kuas penghapus) |
| Blok area | Seleksi persegi (rectangle select) di atas canvas |
| Pindah bagian terblok | Potong piksel area terpilih, jadikan layer terpisah, drag |
| Kotak bantuan | Elemen overlay yang bisa digeser dan diubah ukuran |
| Tempel ttd dan kop | Layer gambar PNG transparan |
| Ekspor | Canvas ke PNG/JPG, atau gabung ke PDF |

## 3. Cara Kerja Teknis (Intinya)
- Dokumen berupa gambar tidak punya "teks" yang bisa dipindah. Maka yang dilakukan adalah **cut and move piksel**:
  1. Pengguna memblok area (x, y, lebar, tinggi).
  2. Area itu dipotong menjadi layer baru.
  3. Bekas area diisi warna latar agar tidak terlihat duplikat.
  4. Layer baru dapat di-drag ke posisi baru.
- Jarak/ukuran ttd = tinggi kotak bantuan. Saat kotak diubah ukurannya, bagian nama bisa otomatis ikut bergeser sebesar selisihnya (opsional, sangat membantu).
- Pada dasarnya tool ini adalah **editor layer sederhana**: dokumen sebagai dasar, lalu ttd dan kop sebagai layer yang bisa diatur.

## 4. Dokumen Hasil Foto (Miring, Latar Tidak Putih)
Perlu tahap tambahan di awal, sebelum seleksi blok:

- **Luruskan.** Dua cara: otomatis (deteksi tepi kertas lalu perspective transform dengan OpenCV.js, seperti aplikasi scanner) atau manual (pengguna menyeret 4 titik sudut kertas). Untuk awal, manual lebih mudah dan akurat. Tambahkan juga tombol putar halus untuk kemiringan kecil.
- **Bersihkan latar.** Setelah lurus, terapkan filter "mode dokumen" (adaptive threshold atau koreksi kontras/pencahayaan) agar kertas menjadi putih merata dan bayangan berkurang. Jadikan opsi yang bisa dinyalakan/dimatikan, karena filter dapat mengubah tampilan dokumen.
- **Area bekas pindahan.** Pada foto, warna latar tidak seragam, sehingga mengisi dengan satu warna akan terlihat. Dengan filter di atas, latar menjadi nyaris putih sehingga masalah ini sebagian besar hilang. Cadangannya: ambil sampel warna di sekitar blok.

## 5. Kop Perusahaan (Header dan Footer)
Kop diperlakukan sebagai layer gambar seperti ttd:

- Simpan desain kop sebagai PNG (header dan footer terpisah) di pustaka kop.
- Tempel di atas atau bawah halaman, lalu atur posisi dan ukuran.
- Jika isi dokumen bertabrakan dengan kop, pengguna dapat memindahkan blok isi ke bawah dengan fitur seleksi dan pindah yang sama.
- Untuk dokumen multi-halaman, sediakan opsi "terapkan ke semua halaman".
- Kop bersifat opsional per dokumen (tidak selalu dipakai).

## 6. Edit Teks Dokumen Hasil Foto dengan OCR
Dokumen hasil foto tidak bisa diedit di Word. Ada dua pendekatan:

**a. Rekonstruksi penuh ke Word (tidak disarankan)**
OCR membaca teks lalu layout disusun ulang menjadi .docx. Hasil biasanya hanya mirip, bukan sama persis: font, spasi, tabel, dan posisi sering bergeser, terutama jika foto miring atau buram.

**b. Gambar sebagai dasar, teks diedit di atasnya (disarankan)**
- OCR (Tesseract.js, gratis, jalan di browser, mendukung Bahasa Indonesia) mendeteksi setiap kata/baris beserta koordinatnya.
- Pengguna klik teks yang ingin diubah. Teks lama ditutup dengan warna latar, lalu muncul kotak teks di posisi yang sama dengan ukuran font yang disesuaikan dari tinggi kotak OCR.
- Bagian yang tidak diedit tetap berupa gambar asli, sehingga layout, logo, stempel, dan tabel tetap sama persis.
- Hasil diekspor sebagai PDF atau gambar.
- Selaras dengan fitur seleksi dan pindah blok yang sudah direncanakan.

**Catatan akurasi**
- Foto harus diluruskan dan dibersihkan dulu (Tahap 3); OCR jauh lebih akurat pada gambar lurus dan terang.
- Font asli tidak bisa dikenali sempurna; tool memilih font mirip dan pengguna bisa menggantinya.
- Tulisan tangan, stempel, dan tabel rumit tidak terbaca baik; biasanya dibiarkan sebagai gambar.
- Kekurangan pendekatan b: hasil bukan file Word yang bisa diedit bebas.

## 7. Dokumen Berulang (Ekspor-Impor): Template HTML/CSS + Form
Dokumen yang sama dipakai terus dengan data yang berbeda, sehingga tidak perlu diedit di Word. Karena template berasal dari perusahaan sendiri, dipilih pendekatan **HTML/CSS**.

**Alur**
1. Buat template sekali per jenis dokumen (HTML/CSS), dengan bagian yang berubah ditandai sebagai kolom isian (nama buyer, tanggal, nomor, daftar barang, jumlah, harga, dan sebagainya).
2. Program menampilkan form sesuai template; pengguna hanya mengisi data.
3. Data masuk ke posisi yang sudah ditentukan; ttd dan kop ditempel dari pustaka.
4. Ekspor ke PDF.

**Keuntungan**
- Layout selalu konsisten, tidak bergeser seperti di Word.
- Hasil PDF tajam dan teksnya tetap bisa diseleksi/dicari (bukan gambar).
- Total, tanggal, dan nomor dokumen bisa dihitung/dibuat otomatis (penomoran berurutan).
- Data buyer dan barang tersimpan dan tinggal dipilih; riwayat dokumen bisa diduplikasi.

**Teknologi**
- PHP + MariaDB (database yang sama dengan sistem yang sudah ada) untuk template, data buyer/barang, dan riwayat.
- PDF: Dompdf atau mPDF (gratis), atau cetak ke PDF dari browser.

## 8. Editor TTD Digital (Prioritas: Paling Sering Dipakai)
Mengubah ttd dari sumber apa pun (foto, potongan dokumen) menjadi gambar ttd digital yang siap dipakai. Ini fitur yang paling sering dibutuhkan dan paling mudah dibangun.

**a. Memperjelas ttd**
- Slider kontras, ketajaman, dan kecerahan.
- Mode "ttd tinta": piksel yang lebih terang dari ambang batas menjadi putih, goresan dipertegas sehingga bersih (hitam/biru).
- Foto miring dapat diluruskan dengan fitur koreksi foto (bagian 4).

**b. Latar menjadi transparan**
- Setelah goresan dipertegas, piksel latar dijadikan transparan dan disimpan sebagai PNG.
- Slider ambang batas agar goresan tipis tidak ikut hilang.
- Warna tinta dapat diseragamkan; pratinjau di atas latar putih, abu, dan dokumen contoh.

**c. Menghapus bagian yang mengganggu**
- **Kuas penghapus** (ukuran bisa diatur, ada undo) untuk garis atau teks yang menimpa ttd.
- **Hapus area** (blok persegi) untuk garis atau teks yang jauh dari goresan.
- Batasan: jika garis/teks menimpa persis di atas goresan, bagian yang tertimpa tidak bisa dipulihkan otomatis. Biasanya dihapus dengan kuas lalu goresan dirapikan manual.

**d. Pelengkap**
- Auto-crop agar ukuran PNG pas dengan goresan.
- Ubah ukuran dan putar.
- Simpan ke pustaka ttd, siap ditempel ke dokumen (Tahap 8).

**Teknologi:** HTML Canvas + JavaScript di browser, gratis, tanpa server.

## 9. Pilihan Teknologi
**Opsi A: sepenuhnya di browser (disarankan untuk awal)**
- HTML Canvas + JavaScript (atau Fabric.js / Konva.js untuk layer, drag, resize).
- PDF.js untuk render PDF; OpenCV.js untuk meluruskan foto.
- Kelebihan: cepat, tanpa beban server, dokumen tidak diunggah ke server (lebih aman untuk dokumen perusahaan).
- Cocok jika dibuat sebagai halaman PHP/HTML biasa.

**Opsi B: dengan backend**
- Backend (PHP/Python) untuk menyimpan hasil, riwayat dokumen, pustaka ttd dan kop.
- Konversi PDF di server dengan Imagick atau pdftoppm.

## 10. Tahapan Pengerjaan

**Tahap 1: Upload dan tampil**
- Upload gambar (JPG/PNG) dan tampilkan di canvas; zoom dan geser tampilan.

**Tahap 2: Dukungan PDF**
- Render PDF ke gambar dengan PDF.js; pilih halaman jika multi-halaman.

**Tahap 3: Koreksi foto**
- Luruskan (4 titik sudut manual + putar halus), lalu filter pembersih latar (opsional).

**Tahap 4: Seleksi blok**
- Alat seleksi persegi di atas canvas; dua jenis blok: teks atas dan nama.

**Tahap 5: Pindah blok**
- Potong area menjadi layer, isi bekas dengan warna latar; drag dan tombol panah untuk penyesuaian halus.

**Tahap 6: Kotak bantuan**
- Overlay yang bisa digeser dan diubah ukurannya; opsi nama ikut bergeser otomatis.

**Tahap 7: Editor ttd digital**
- Muat gambar ttd (foto atau potongan dokumen); atur kontras, ketajaman, dan kecerahan.
- Mode "ttd tinta" dan latar transparan dengan slider ambang batas; seragamkan warna tinta.
- Kuas penghapus dan hapus area (dengan undo); pratinjau di atas latar putih/abu/dokumen.
- Auto-crop, ubah ukuran, putar, lalu simpan PNG transparan ke pustaka ttd.

**Tahap 8: Tempel tanda tangan**
- Unggah PNG ttd transparan atau simpan beberapa ttd siap pakai; hapus latar ttd otomatis (opsional); atur posisi dan ukuran.

**Tahap 9: Kop perusahaan**
- Pustaka kop (header/footer PNG), tempel, atur posisi, opsi terapkan ke semua halaman.

**Tahap 10: Edit teks dengan OCR**
- Jalankan OCR (Tesseract.js, Bahasa Indonesia) pada gambar yang sudah diluruskan.
- Tampilkan kotak per kata/baris; klik untuk mengedit.
- Tutup teks lama dengan warna latar, lalu tampilkan kotak teks baru di posisi yang sama dengan ukuran font mengikuti tinggi kotak OCR.
- Pilihan font mirip dan penyesuaian manual (ukuran, tebal, posisi).

**Tahap 11: Template dokumen dan form (HTML/CSS)**
- Buat template tiap jenis dokumen (invoice, packing list, surat jalan, dan sebagainya) dalam HTML/CSS dengan kolom isian bertanda.
- Form isian dibuat otomatis dari kolom template; hitung total dan penomoran otomatis.
- Simpan data buyer/barang yang sering dipakai, riwayat dokumen, dan fitur duplikasi.
- Tempel ttd dan kop dari pustaka, lalu cetak ke PDF (Dompdf/mPDF atau cetak dari browser).

**Tahap 12: Ekspor**
- Simpan sebagai PNG/JPG, atau PDF (gambar dimasukkan ke halaman PDF).

**Tahap 13: Penyempurnaan**
- Undo/redo, riwayat dokumen, perbaikan untuk latar yang sulit, otomatisasi deteksi tepi kertas.

## 11. Risiko dan Catatan
- **Foto berkualitas rendah**: bayangan kuat, lipatan, atau cahaya tidak merata dapat menyulitkan pembersihan latar. Foto yang diambil tegak lurus dan terang memberi hasil jauh lebih baik.
- **Resolusi**: konversi PDF minimal 150-200 DPI agar tetap tajam saat dicetak.
- **PDF akhir berupa gambar**: teks tidak lagi bisa diseleksi atau dicari. Simpan juga dokumen asli.
- **Keamanan**: gambar ttd adalah data sensitif. Batasi akses dan jangan menyimpannya sembarangan.
- **Kerapian**: pastikan nama dan teks atas tetap sejajar setelah dipindah.

## 12. Rekomendasi Langkah Berikutnya
1. Mulai dengan **prototipe satu halaman** (Opsi A): upload gambar, seleksi blok, pindah, tempel ttd, ekspor PNG.
2. Uji dengan 3-5 contoh dokumen asli perusahaan, termasuk satu atau dua hasil foto, untuk melihat variasi pola dan latar.
3. Setelah alur inti nyaman, tambahkan PDF, koreksi foto, kop, edit teks OCR, dan template form.
4. Karena permintaan **edit ttd** paling sering, pertimbangkan memulai dari **Editor ttd digital** (Tahap 7) terlebih dulu, lalu **template + form** (Tahap 11) untuk dokumen ekspor-impor, karena nilainya paling cepat terasa.

## 13. Pengingat: Aturan Penyimpanan
Penyimpanan semua hal yang dibahas di dokumen ini memiliki aturan. Sebelum mulai membangun, pastikan aturannya dipenuhi untuk data berikut:

- Dokumen asli yang diunggah (gambar/foto/PDF)
- Hasil dokumen (PDF/gambar) dan riwayat dokumen
- Gambar tanda tangan (data sensitif)
- Pustaka kop (header/footer)
- Template dokumen dan data buyer/barang
- Data di database (riwayat, penomoran)

**Aturan penyimpanan:** _(isi sesuai ketentuan yang berlaku: lokasi, format, lama simpan, akses, dan sebagainya)_
- ...
