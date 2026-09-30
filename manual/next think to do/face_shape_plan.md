# Perencanaan Perbaikan Face Shape Scan (optic_pos)

## 1. Latar belakang

- Scan bentuk wajah di `invoice.php` hampir selalu menghasilkan **ROUND**.
- Dugaan awal: masalah ada di pencitraan 2D, sehingga perlu beralih ke 3D.
- Hasil membaca kode: pipeline **sudah memakai MediaPipe FaceMesh dengan koordinat `z`** (`dist3D`, `angle3D`). Nilai `z` adalah perkiraan model dari satu foto depan, bukan foto multi-sudut. Input tampak depan memang cukup untuk menentukan bentuk wajah, jadi beralih ke 3D kemungkinan besar bukan solusinya.

## 2. Dugaan penyebab "selalu ROUND"

Semua ini masih **dugaan dari membaca kode**, belum dibuktikan dengan data.

1. **Tinggi wajah** memakai `estimateForehead` (tebakan dari jarak mata-alis x 1.65, dicampur landmark 9). Titik dahi atas landmark 10 ada di `LM` tapi tidak dipakai. Jika dahi terhitung terlalu rendah, H/W kecil dan `calcRound` langsung mendapat +4 (`fr < 1.2`). Tinggi hanya memakai sumbu Y sedangkan lebar memakai 3D, sehingga rasio berubah saat kepala mendongak atau menunduk.
2. **Poin "gratis" untuk ROUND.** `cheekRatio` dan `jawRatio` dibagi lebar 234-454, nilainya cenderung tinggi untuk hampir semua orang, sehingga syarat `cheek >= 0.85` dan `jaw >= 0.72` sering terpenuhi otomatis (+3.5).
3. **Skor tidak dinormalisasi antar bentuk.** Bentuk dengan banyak syarat longgar mudah menang atas bentuk dengan syarat sempit.

## 3. Yang dibuat pada tahap ini

**`face_shape_lab.html`** — halaman riset terpisah (file baru, `invoice.php` tidak disentuh).

- Unggah banyak foto sekaligus, beri label bentuk wajah yang diyakini per foto.
- Logika analisis **disalin apa adanya** dari `analyzeFaceShape` dan fungsi `calc*`, dengan indeks landmark yang sama, sehingga hasilnya sebanding dengan sistem asli.
- Metrik tambahan **H/W(10)**: tinggi wajah memakai landmark 10, untuk membandingkan dengan estimasi dahi yang sekarang.
- Kolom **Pose** menandai foto miring (yaw < 0.75) yang bisa merusak pengukuran.
- **Ringkasan**: distribusi prediksi (untuk melihat bias ke ROUND), akurasi pada foto berlabel, dan rata-rata metrik per bentuk.
- **Ekspor CSV**: metrik + label, bisa dipakai sebagai dataset latihan nanti.
- Foto diproses di browser, tidak diunggah ke server. Membutuhkan internet (skrip MediaPipe dari CDN jsdelivr, sama seperti `invoice.php`).

Cara pakai: taruh file di folder project (XAMPP/Termux), buka lewat `http://localhost/...`, jangan lewat `file://` agar kamera/skrip tidak terblokir.

## 4. Classifier hasil latihan (rencana lanjutan)

**Konsep.** Metrik yang sudah dihitung (H/W, dahi, pipi, rahang, dagu, sudut dagu, dst.) dipakai sebagai fitur. Model kecil (kNN, logistic regression, atau decision tree) belajar dari foto berlabel, menggantikan threshold yang ditulis tangan.

**Kebutuhan data.** Minimal sekitar 15-20 foto per bentuk untuk uji awal, idealnya 30-50 per bentuk (7 bentuk = 200-350 foto), seimbang antar kelas, dilabel konsisten (idealnya oleh 2 orang, foto yang diperdebatkan dibuang).

**Efek dan akurasi.** Tidak ada angka yang jujur bisa dijanjikan sebelum ada data. Ekspektasi realistis:
- Bias sistematis seperti "selalu ROUND" biasanya hilang, karena model belajar batas antar bentuk dari data.
- Batas atas akurasi ditentukan oleh **label yang subjektif**: banyak wajah berada di antara dua bentuk. Karena itu tampilan **top-2** lebih jujur daripada satu jawaban.
- Akurasi harus diukur dengan cross-validation pada data yang sama dan dibandingkan dengan baseline sistem aturan dari halaman lab.

**Kombinasi dengan sistem sekarang (hybrid).**
- Pipeline pengukuran (FaceMesh, `analyzeFaceShape`, buffer beberapa frame, `mostFrequent`) tetap dipakai.
- Hanya tahap penilaian yang diganti atau digabung: skor akhir = campuran probabilitas classifier dan persentase sistem aturan, atau sistem aturan sebagai cadangan saat classifier tidak yakin.
- Model diekspor sebagai JSON (bobot atau titik referensi kNN) dan berjalan di browser, tidak butuh server ML, aman untuk Termux.
- Pilihan manual (`openManualFaceShape`) tetap ada sebagai koreksi. Koreksi kasir bisa dicatat sebagai data latihan baru.

**Catatan privasi.** Foto pelanggan sebagai data latihan perlu persetujuan. Cukup simpan metrik dan label, bukan fotonya.

## 5. Tahapan

| Tahap | Kegiatan | Hasil | Menyentuh invoice.php? |
|---|---|---|---|
| 0 | Buat halaman lab (selesai) | `face_shape_lab.html` | Tidak |
| 1 | Riset: uji 5-10 foto per bentuk (foto depan, kepala lurus, dahi dan rahang terlihat), catat distribusi prediksi dan rata-rata metrik | Bukti apakah bias ke ROUND dan dari metrik mana | Tidak |
| 2 | Perbaikan cepat sistem aturan sesuai temuan tahap 1 (misalnya `estimateForehead` / `faceHeight` / threshold `calc*`) | Bias berkurang | Ya, hanya blok terkait |
| 3 | Kumpulkan dataset lebih besar dan beri label via lab, ekspor CSV | Dataset berlabel | Tidak |
| 4 | Latih classifier offline, evaluasi dengan cross-validation, bandingkan dengan baseline aturan | Keputusan: classifier layak atau tidak | Tidak |
| 5 | Integrasi hybrid + tampilan top-2 | Sistem baru dengan cadangan aturan | Ya, hanya blok penilaian |
| 6 | Catat koreksi manual sebagai data latihan baru | Perbaikan berkelanjutan | Ya, kecil |

Setiap perubahan pada `invoice.php` dilakukan bedah-minimal: hanya blok yang dimodifikasi, file dikirim utuh siap salin.

## 6. Keputusan yang perlu diambil

- Setelah tahap 1: cukup perbaiki sistem aturan (tahap 2), atau lanjut ke classifier (tahap 3-5)?
- Apakah 7 bentuk dipertahankan, atau digabung (misalnya DIAMOND dan TRIANGLE) agar data lebih mudah dikumpulkan?
