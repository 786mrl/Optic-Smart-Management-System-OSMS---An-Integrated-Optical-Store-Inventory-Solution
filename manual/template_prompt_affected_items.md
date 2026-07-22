# Template Prompt: Affected-Items Confirmation + Time-Based Lock

Copy-paste blok di bawah ini ke chat baru, isi bagian [ISI DI SINI], lalu lampirkan file
referensi yang diminta.

---

## FILE YANG PERLU DILAMPIRKAN

Lampirkan 4 file ini sebagai referensi (jangan minta AI menebak polanya):
1. `inventory.php` (versi terbaru/sudah fix)
2. `frame_management.php` (versi terbaru/sudah fix)
3. `log_activity.php` (versi terbaru/sudah fix)
4. `check_logged_items.php` (versi terbaru/sudah fix)

---

## PROMPT

```
Konteks: Saya punya sistem optical store PHP + MySQL (XAMPP, tanpa framework).
Saya sudah menerapkan pola "affected-items confirmation (multi-module
accumulation) + time-based lock + skip-modal-if-already-logged" di
inventory.php dan frame_management.php (saya lampirkan keduanya +
log_activity.php + check_logged_items.php sebagai referensi pola yang PERSIS
harus diikuti - jangan diubah strukturnya, jangan reinvent).

Sekarang saya mau menerapkan pola yang SAMA PERSIS (struktur JS, nama fungsi,
CSS modal, cara delete-then-insert exact-match) di file baru:

**Nama file halaman baru:** [ISI: misal lense_management.php]

**Tombol/aksi yang jadi "tracked module" di halaman ini (boleh lebih dari satu):**
- Tombol: [ISI: misal "Lense Data Entry"] 
  → item yang ditrack: [ISI: misal lense_staging, qrcodes [folder], data_json [folder]]
- Tombol: [ISI: misal "Lense Stock Report"]
  → item yang ditrack: [ISI: misal lense_staging, report_cache [folder]]
- (tambah baris sesuai jumlah tombol tracked yang dibutuhkan)

**Aksi yang memicu modal konfirmasi:** Back dan Logout (default, sebutkan kalau ada tambahan lain)

**Lock mechanism (auto-disable tombol):**
- Tombol mana saja yang perlu auto-disable kalau item-nya sudah ke-log di
  activity_log DAN sudah lewat jam blocking:
  [ISI: misal "Lense Data Entry saja" / "Keduanya, independent check masing-masing" / "Tidak perlu"]
- setting_key yang dipakai untuk jam blocking: db_backup_blocking_time
  (REUSE yang sama, JANGAN bikin key baru, dan PASTIKAN spelling-nya PERSIS
  "blocking" bukan "blockiing" - typo ini pernah bikin lock mechanism gagal total)

**PENTING - hal-hal yang WAJIB diperhatikan (pernah jadi bug sebelumnya):**
1. Setiap string di `data-affected-items="..."` HARUS persis sama karakter-nya
   (termasuk spasi dan tanda kurung [ ]) dengan string item yang sama di
   tombol/halaman lain manapun. log_activity.php dan check_logged_items.php
   pakai EXACT MATCH (bukan LIKE), jadi typo satu karakter saja (misal kurung
   "]" jadi ")") akan membuat sistem menganggap itu item yang berbeda dan
   menyebabkan data duplikat/tidak pernah ke-delete/lock tidak terdeteksi.
2. Jangan ubah log_activity.php dan check_logged_items.php sama sekali - kedua
   file itu shared dipakai semua halaman, sudah benar:
   - log_activity.php: exact match, delete-all-dulu-baru-insert-semua.
   - check_logged_items.php: exact match, return item mana yang BELUM ada di
     activity_log, dipakai untuk skip/filter modal (lihat poin 6).
3. Ikuti nama fungsi JS yang sama persis: recordVisitedModule,
   getMergedAffectedItems, confirmAffectedModal, cancelAffectedModal,
   shouldConfirmAffectedItems, getAffectedItems, clearVisitedModules,
   fetchUnloggedItems, proceedWithConfirmation, handleBackClick,
   handleLogoutClick, handleButtonClick, runNavigateAction.
4. Timezone date_default_timezone_set('Asia/Jakarta') di paling atas file.
5. Instruksi standar: jangan ganggu kode/fitur lain di file ini maupun file
   lain, komentar & identifier dalam bahasa Inggris, cukup edit surgical
   (str_replace) bukan rewrite total.
6. Perilaku modal "AFFECTED FILES OR DATABASE": sebelum modal ditampilkan,
   item-item yang sudah dikunjungi (tracked) dicek dulu ke
   check_logged_items.php. Item yang SUDAH ada persis di activity_log tidak
   ditampilkan lagi di modal (tidak perlu dikonfirmasi ulang). Kalau SEMUA
   item tracked ternyata sudah ada di DB, modal tidak muncul sama sekali dan
   aksi (Back/Logout) langsung jalan, tracker langsung di-clear.
```

---

## CATATAN TAMBAHAN (opsional, tempel kalau relevan)

Kalau AI di sesi baru bilang "file tidak ter-upload" padahal sudah Anda
lampirkan, minta dia cek langsung ke `/mnt/user-data/uploads/` via tool -
filenya biasanya tetap ada di disk meski tidak ter-render di preview chat.
