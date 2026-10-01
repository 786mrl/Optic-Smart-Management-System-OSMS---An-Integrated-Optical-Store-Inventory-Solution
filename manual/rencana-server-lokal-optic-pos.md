# Rencana Implementasi Server Lokal — optic_pos

**Tujuan:** Membuat web optic_pos (XAMPP) bisa diakses oleh HP, tablet, dan PC lain di toko tanpa bergantung pada koneksi internet dari ISP (Iconnet, IndiHome, dll), menggunakan jaringan WiFi lokal mandiri.

---

## 1. Arsitektur Jaringan

```
[Router WiFi (AP only, tanpa internet)]
         |  (WiFi / LAN)
         |
   +-----+-----+-----+-----+
   |           |           |
[Mini PC]   [HP Kasir]  [Tablet]  [PC lain]
(Server      (Client)    (Client)  (Client)
 XAMPP)
```

- Semua device terhubung ke **router yang sama**.
- Mini PC bertindak sebagai server (menjalankan XAMPP: Apache + MySQL).
- Device lain mengakses web lewat IP address atau hostname mini PC, bukan `localhost`.
- Router **tidak perlu** disambungkan ke internet/modem ISP — cukup jadi Access Point lokal.

---

## 2. Perangkat yang Dibutuhkan

### A. Mini PC (Server)
| Komponen | Minimal | Rekomendasi |
|---|---|---|
| CPU | Intel N100/N150 | Intel N150 / Core i3 |
| RAM | 8GB | 16GB |
| Storage | 128GB SSD | 256GB SSD NVMe |
| OS | Windows 11 | Windows 11 Pro |

**Kisaran harga:** Rp4.000.000 – Rp6.000.000
Contoh: ASUS Mini PC N150 8GB/256GB (~Rp4,5 jt), MSI Cubi 5 12M i3-1215U 8GB/256GB (~Rp5,8–5,9 jt).

Hindari varian "firewall/pfSense grade" (overkill) atau "gaming grade" (boros biaya, GPU tidak terpakai).

### B. Router WiFi (Access Point)
- Router rumahan biasa (TP-Link, Tenda, Mercusys), WiFi AC/WiFi 5 sudah cukup.
- **Kisaran harga:** Rp150.000 – Rp400.000
- Fitur yang perlu dicari:
  - DHCP reservation (untuk IP statis mini PC)
  - Minimal 1 port LAN (agar mini PC bisa pakai kabel, lebih stabil dari WiFi)
- Tidak perlu fitur VPN/QoS/bisnis-grade karena tidak pakai internet.

**Total estimasi biaya awal: ~Rp4,2 – 6,4 juta** (mini PC + router)

---

## 3. Langkah Implementasi

1. **Beli & setup mini PC**
   - Install Windows, install XAMPP, pindahkan/restore project optic_pos + database optic_pos_db.
   - Set Apache & MySQL agar **auto-start saat boot** (service Windows), supaya kalau mati lampu lalu nyala lagi, server otomatis jalan tanpa perlu buka XAMPP manual.

2. **Beli & setup router**
   - Set router sebagai **AP only** — jangan sambungkan ke modem ISP.
   - Aktifkan DHCP reservation, catat MAC address mini PC.

3. **Set IP statis untuk mini PC**
   - Reservasi IP lewat router (misal `192.168.1.100`) agar IP tidak berubah-ubah.

4. **Koneksikan semua device**
   - Mini PC → router (sebaiknya kabel LAN untuk stabilitas).
   - HP, tablet, PC lain → konek WiFi ke router yang sama.
   - Akses web lewat: `http://192.168.1.100/optic_pos`

5. **(Opsional) Setup alamat yang mudah diingat**
   - Rename mini PC jadi nama pendek, misal `OPTICPOS`.
   - Aktifkan mDNS / Bonjour Print Services di Windows.
   - Device lain bisa akses lewat `http://opticpos.local/optic_pos` tanpa perlu hafal IP.
   - Alternatif lebih advanced (kalau butuh lebih rapi): pasang DNS lokal (Pi-hole/dnsmasq) di mini PC agar semua device otomatis resolve nama domain custom.

---

## 4. Catatan Kapasitas

- **Tidak ada batasan jumlah pemakaian dari sisi jaringan** — router rumahan standar sanggup menangani 20–30 device bersamaan, cukup untuk skala toko optik.
- Apache & MySQL default sudah punya limit koneksi simultan (`MaxConnections`, `max_connections`) yang cukup besar (ratusan) untuk skala toko kecil-menengah — baru perlu tuning kalau terasa lambat saat banyak akses bersamaan.

---

## 5. Checklist Belanja

- [ ] Mini PC (CPU N150/i3, RAM 8–16GB, SSD 256GB, Windows 11)
- [ ] Router WiFi AP-only (TP-Link/Tenda/Mercusys, ada DHCP reservation)
- [ ] Kabel LAN (untuk sambungan mini PC ↔ router)
- [ ] UPS/stabilizer kecil (opsional, jaga-jaga mati lampu mendadak agar server tidak corrupt data)

---

*Dokumen ini adalah catatan perencanaan pribadi, bisa diupdate sesuai perkembangan implementasi.*
