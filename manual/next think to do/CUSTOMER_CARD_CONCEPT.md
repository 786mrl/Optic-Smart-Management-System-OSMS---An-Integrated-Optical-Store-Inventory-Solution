# Konsep Kartu Customer Digital (optic_pos)

Status: **konsep, belum ada implementasi**
Prinsip utama: **gratis, tanpa server berbayar, tanpa pihak ketiga selain Google Drive**

---

## 1. Tujuan

Setiap customer mendapat kartu fisik berisi **satu QR code unik**. Ketika QR
dipindai, customer langsung membuka PDF miliknya di Google Drive.

## 2. Isi PDF Customer

| # | Isi | Sumber |
|---|-----|--------|
| 1 | Identitas lengkap | DB optic_pos_db |
| 2 | Hasil pemeriksaan terakhir | tabel pemeriksaan |
| 3 | Jenis lensa dan frame yang dibeli | customer_orders |
| 4 | Riwayat ukuran (jika periksa lebih dari satu kali), tabel dan grafik tren | tabel pemeriksaan |
| 5 | Gejala/keluhan, solusi lensa yang sesuai, dan penjelasannya | modul rekomendasi (sedang dibangun) |
| 6 | Garansi, perawatan, dan ketentuan lainnya | konten statis (sudah disiapkan) |
| 7 | Informasi tambahan (lihat bawah) | |

### Usulan informasi tambahan
- Tanggal berakhirnya garansi (dihitung dari tanggal pembelian)
- Jadwal pemeriksaan ulang yang disarankan (mis. 6 atau 12 bulan)
- Kontak toko: alamat, jam operasional, WhatsApp, lokasi peta
- Prosedur klaim garansi dan servis: syarat dan dokumen yang dibawa
- Detail lensa: indeks, jenis lapisan (blueray, photochromic, dll)
- Detail frame: merek, model, ukuran, warna
- Panduan masa adaptasi lensa baru
- Tanda-tanda yang mengharuskan pemeriksaan segera
- Poin loyalitas, promo, atau program rujukan (opsional)
- Pernyataan bahwa dokumen ini bukan diagnosis medis
- Tanggal pembaruan dokumen terakhir

### Keputusan yang sudah diambil
Ukuran pada PDF customer ditampilkan dengan **format umum** (mis. -1.00,
-0.25), bukan notasi internal (-100, -25). Konversi dilakukan hanya saat
PDF dibuat; data di database tetap memakai notasi internal.

### Standar bahasa (wajib)
Seluruh isi PDF **formal**: Bahasa Indonesia baku, sapaan "Bapak/Ibu"
atau "Anda", tanpa bahasa gaul, tanpa singkatan tidak baku, dan tanpa
emoji. Format tanggal dan mata uang konsisten (mis. 30 September 2026,
Rp 1.500.000).

---

## 3. Rancangan yang Dipilih: ID Drive Dibuat Lebih Dahulu

### Masalah yang dihindari
Isi QR code tidak bisa diubah setelah dicetak, dan link Google Drive
biasanya baru dibuat setelah file diunggah. Sehingga QR yang dicetak lebih
dahulu tidak bisa "diisi" link Drive belakangan.

### Solusi
Google Drive dapat **membuat ID file terlebih dahulu** (fitur `generateIds`
pada Drive API, tersedia gratis lewat Google Apps Script). File PDF kemudian
diunggah **dengan ID yang sudah ditentukan tersebut**. Dengan cara ini:

1. Buat ID Drive dalam jumlah besar (mis. 500) sebelum kartu dicetak.
2. Setiap ID dijadikan link `https://drive.google.com/file/d/<ID>/view`
   dan dicetak sebagai **QR unik** pada satu kartu.
3. Saat customer membeli, PDF dibuat lalu diunggah dengan ID yang sama
   dengan QR di kartunya.
4. Saat ada pemeriksaan/pembelian baru, file **diperbarui dengan ID yang
   sama**, sehingga link di QR tidak pernah berubah.

Hasilnya: hanya satu QR per kartu, langsung membuka PDF, tanpa Google Sheet,
tanpa GitHub, tanpa halaman perantara. Kamera bawaan HP dapat membacanya.

### Alur toko saat customer membeli (kartu baru)
```
Kasir scan QR di kartu baru
   -> ID Drive dari link QR disimpan di kolom customer (optic_pos_db)
   -> optic_pos membuat PDF (FPDF)
   -> PDF dikirim ke Apps Script milik toko
   -> Apps Script membuat/memperbarui file di Drive dengan ID tersebut
```

### Alur saat customer lama belanja atau periksa lagi
QR bersifat **per customer**, bukan per invoice/transaksi. ID Drive dan
link-nya tetap sama seumur kartu. Jadi cukup:
```
optic_pos membuat ulang PDF dari data terbaru
   -> PDF dikirim ke Apps Script milik toko
   -> Apps Script MENIMPA file lama di ID yang sama (bukan file baru)
```
Tidak perlu kartu baru maupun QR baru untuk transaksi berikutnya.

### Catatan penting
- **Pengunggahan tidak bisa lewat drag-and-drop biasa** di Drive karena
  Drive akan membuat ID baru. Diperlukan Apps Script (atau Drive API)
  yang menentukan ID saat membuat file.
- **Keamanan**: ID Drive berupa teks acak panjang, sehingga tidak bisa
  ditebak. Pengaturan berbagi: "siapa saja yang memiliki link". Siapa pun
  yang memegang kartu atau foto kartu dapat membuka PDF. Perlindungan
  tambahan (mis. password PDF) sudah dipikirkan terpisah.
- **Mencabut akses**: bila kartu hilang, hentikan berbagi file tersebut;
  customer diberi kartu baru dengan QR baru.
- **Kartu yang belum terpakai** akan menampilkan halaman "tidak ditemukan"
  bila dipindai, karena filenya belum dibuat.
- **Perlu diuji lebih dahulu**: pembuatan file dengan ID yang sudah
  ditentukan melalui Apps Script. Lakukan uji kecil sebelum mencetak kartu
  dalam jumlah besar.

### Alternatif (jika uji di atas gagal)
QR berisi link ke layanan pengalih gratis atau halaman perantara yang
meneruskan ke link Drive yang diisi belakangan. Ini menambah pihak ketiga
dan tidak diprioritaskan.

---

## 4. Tahapan Pengerjaan

**Tahap 0: Keputusan**
- [x] Format notasi ukuran di PDF: format umum (-1.00)
- [ ] Daftar akhir isi PDF
- [ ] Kode versi kartu dan nomor kartu yang tercetak (lihat bagian 5)
- [ ] Jumlah kartu yang dicetak pada gelombang pertama
- [ ] Metode perlindungan tambahan (password PDF, dll)

**Tahap 1: Uji kelayakan (kecil)**
- [ ] Apps Script: buat beberapa ID dengan `generateIds`
- [ ] Unggah PDF percobaan dengan ID yang ditentukan
- [ ] Atur berbagi "siapa saja dengan link"
- [ ] Buka link dari HP lain, lalu perbarui file dan cek link tetap sama

**Tahap 2: Data**
- [ ] Rencana kolom di tabel customer untuk menyimpan ID Drive kartu
- [ ] Folder Drive khusus untuk seluruh PDF

**Tahap 3: Pembuat PDF di optic_pos**
- [ ] Template PDF formal (FPDF, sudah digunakan pada customer_history.php)
- [ ] Ambil data identitas, pemeriksaan, pembelian, riwayat ukuran
- [ ] Sambungkan modul gejala dan solusi lensa setelah selesai
- [ ] Halaman garansi dan perawatan

**Tahap 4: Pengiriman PDF ke Drive**
- [ ] Endpoint Apps Script: menerima PDF dan ID, membuat/memperbarui file
- [ ] Pengamanan endpoint (kunci rahasia)
- [ ] Panggilan dari optic_pos ke endpoint tersebut

**Tahap 5: Kartu fisik**
- [ ] Buat ID dan QR dalam jumlah besar, ekspor untuk dicetak
- [ ] Desain kartu dan uji keterbacaan QR pada HP Android dan iPhone
- [ ] Cetak

**Tahap 6: Alur kasir**
- [ ] Halaman di optic_pos untuk memindai QR kartu dan mengaitkannya ke customer
- [ ] Prosedur pembaruan PDF pada pemeriksaan/pembelian berikutnya

**Tahap 7: Pengujian dan operasional**
- [ ] Uji dari awal sampai akhir dengan customer percobaan
- [ ] Prosedur kartu hilang
- [ ] Pencadangan folder Drive
- [ ] Pemberitahuan penyimpanan data kepada customer (data kesehatan)

---

## 5. Risiko, Cadangan, dan Migrasi

### Risiko yang diterima pada tahap pertama
Tautan Drive dapat berubah perilakunya bila Google mengubah kebijakan,
atau akun bermasalah. Risiko ini **diterima** untuk tahap pertama.

### Kode versi kartu (wajib)
- Cetak kode versi kecil pada kartu (mis. `V1`) dan simpan di database
  (kolom versi kartu, bersama ID Drive).
- Fungsinya: menjadi acuan kartu mana yang masih berlaku, dan kartu mana
  yang perlu diganti saat ada perubahan sistem.
- Cetak juga **nomor kartu yang mudah dibaca** agar staf tetap dapat
  mencari customer jika QR bermasalah.
- Cetak gelombang pertama dalam jumlah kecil (50 sampai 100 kartu).

### Rencana B (jika pembuatan ID di muka tidak bisa dilakukan)
QR berisi alamat **Apps Script web app** yang stabil, disertai ID unik
(`...exec?id=XXXX`). Script mencari ID tersebut pada Google Sheet yang
**privat**, lalu mengarahkan ke link Drive yang sesungguhnya. Tetap gratis
dan tetap hanya produk Google. Konsekuensinya: ada satu langkah perantara
dan Sheet kembali dipakai. Selain itu, script dapat diubah kapan saja
untuk mengarahkan ke tujuan baru (termasuk domain pribadi).

### Migrasi ke domain pribadi di kemudian hari
- QR yang **langsung berisi link Drive** tidak dapat dialihkan, karena
  Drive tidak bisa diatur untuk meneruskan ke alamat lain.
- Kartu lama tetap berfungsi selama PDF di Drive terus diperbarui.
  Kartu baru memakai QR domain, dan kode versi membedakan keduanya
  (mis. `V1` = Drive, `V2` = domain). Kartu `V1` dapat diganti saat
  customer datang untuk pemeriksaan ulang.
- Jika Rencana B dipakai, atau QR dicetak dengan alamat domain sejak awal,
  pengalihan dapat dilakukan kapan saja tanpa mengganti kartu.
- Opsi paling aman: membeli domain **sebelum** kartu dicetak, sehingga QR
  berisi alamat domain (mis. `kartu.namatoko.com/ID`) yang untuk sementara
  diteruskan ke Drive, lalu dapat dialihkan ke server sendiri kelak.
  Ini berbayar (biaya domain tahunan), sehingga keputusannya ada pada Anda.

---

## 6. Batasan
- Google Drive dan Apps Script gratis tetapi memiliki kuota harian; untuk
  skala toko optik umumnya mencukupi.
- PDF bersifat statis dan harus dibuat ulang ketika ada data baru.
- Aplikasi lokal (XAMPP/Termux) tidak dapat diakses customer dari luar;
  PDF di Drive berfungsi sebagai jembatannya.
