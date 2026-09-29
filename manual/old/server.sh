#!/bin/bash
case "$1" in
  start)
    echo "Memulai server..."
    mysqld_safe &
    sleep 3
    pgrep -f "php-fpm: master" > /dev/null || php-fpm
    apachectl start
    sleep 2
    echo "Fix permission qrcodes..."
    OPTIC_DIR="$PREFIX/share/apache2/default-site/htdocs/optic_pos"
    mkdir -p "$OPTIC_DIR/qrcodes" "$OPTIC_DIR/main_qrcodes"
    chmod -R 755 "$OPTIC_DIR/qrcodes" "$OPTIC_DIR/main_qrcodes"
    echo "✅ Server siap!"
    echo "Warming up..."
    curl -s -o /dev/null http://localhost:8080/optic_pos/login.php
    echo "Buka: http://localhost:8080/optic_pos/login.php"
    ;;
  stop)
    echo "Mematikan server..."
    apachectl stop
    pkill -9 php-fpm
    pkill mysqld_safe
    pkill mariadbd
    echo "✅ Server dimatikan!"
    ;;
  update)
    read -p "Masukkan IP address PC (contoh: 192.168.18.13): " IP_PC
    if ! curl -s --connect-timeout 5 "http://$IP_PC" > /dev/null; then
      echo "❌ Tidak bisa konek ke PC. Pastikan XAMPP aktif."
      exit 1
    fi
    echo "✅ Koneksi OK"
    echo "Backup database..."
    mysqldump -u root optic_pos_db > ~/backup_$(date +%Y%m%d_%H%M%S).sql
    echo "✅ Backup selesai"
    echo "Mematikan server sementara..."
    apachectl stop
    pkill -9 php-fpm
    pkill mysqld_safe; pkill mariadbd
    sleep 2
    echo "Download file terbaru..."
    wget -q http://$IP_PC/optic_pos.zip -O ~/optic_pos_update.zip
    echo "✅ Download selesai"
    echo "Menghapus file lama..."
    rm -rf $PREFIX/share/apache2/default-site/htdocs/optic_pos
    echo "✅ File lama dihapus"
    echo "Mengupdate file..."
    cd $PREFIX/share/apache2/default-site/htdocs
    unzip -o ~/optic_pos_update.zip > /dev/null
    echo "Fix permission qrcodes..."
    mkdir -p optic_pos/qrcodes optic_pos/main_qrcodes
    chmod -R 755 optic_pos/qrcodes optic_pos/main_qrcodes
    echo "Menyalakan database..."
    mysqld_safe &
    echo "Menunggu database siap..."
    for i in $(seq 1 15); do
      if mariadb -u root -e "SELECT 1;" > /dev/null 2>&1; then
        break
      fi
      sleep 1
    done
    if [ -f "optic_pos/database/optic_pos_db.sql" ]; then
      echo "Mengosongkan database lama..."
      mariadb -u root -e "DROP DATABASE IF EXISTS optic_pos_db; CREATE DATABASE optic_pos_db;"
      echo "Import database dari PC..."
      if mariadb -u root optic_pos_db < optic_pos/database/optic_pos_db.sql; then
        echo "✅ Database berhasil diimport"
      else
        echo "❌ Import database GAGAL, cek error di atas"
      fi
    else
      echo "⚠️  File database tidak ditemukan, lewati import"
    fi
    echo "Fix db_config..."
    cat > optic_pos/db_config.php << 'DBEOF'
<?php
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "optic_pos_db";
$socket = "/data/data/com.termux/files/usr/var/run/mysqld.sock";
$conn = new mysqli($servername, $db_username, $db_password, $dbname, 3306, $socket);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8");
function close_db_connection($conn) {
    if ($conn) { $conn->close(); }
}
?>
DBEOF
    echo "Fix config_helper..."
    sed -i 's/isset($conn) && $conn->ping()/isset($conn)/' optic_pos/config_helper.php
    echo "Menjalankan Apache..."
    pgrep -f "php-fpm: master" > /dev/null || php-fpm
    apachectl start
    sleep 2
    echo "✅ Update selesai!"
    echo "Warming up..."
    curl -s -o /dev/null http://localhost:8080/optic_pos/login.php
    echo "Buka: http://localhost:8080/optic_pos/login.php"
    ;;
  status)
    echo "=== Status Server ==="
    if pgrep httpd > /dev/null; then
      echo "✅ Apache (httpd): AKTIF"
    else
      echo "❌ Apache (httpd): MATI"
    fi
    if pgrep -f "php-fpm: master" > /dev/null; then
      echo "✅ PHP-FPM: AKTIF"
    else
      echo "❌ PHP-FPM: MATI"
    fi
    if pgrep mariadbd > /dev/null || pgrep mysqld > /dev/null; then
      echo "✅ MariaDB (mariadbd): AKTIF"
    else
      echo "❌ MariaDB (mariadbd): MATI"
    fi
    echo ""
    echo "IP HP saat ini:"
    ifconfig wlan0 2>/dev/null | grep "inet " || echo "  (tidak bisa deteksi, cek manual di Settings > WiFi)"
    ;;
  backup)
    BACKUP_FILE=~/backup_$(date +%Y%m%d_%H%M%S).sql
    echo "Backup database..."
    mysqldump -u root optic_pos_db > $BACKUP_FILE
    echo "✅ Backup tersimpan: $BACKUP_FILE"
    ls -lh ~/backup_*.sql
    ;;
  restore)
    echo "Daftar backup tersedia:"
    ls ~/backup_*.sql 2>/dev/null || echo "Tidak ada file backup"
    read -p "Masukkan nama file backup (contoh: backup_20260525_083000.sql): " BACKUP_NAME
    if [ ! -f ~/$BACKUP_NAME ]; then
        echo "❌ File tidak ditemukan"
        exit 1
    fi
    read -p "Yakin restore dari $BACKUP_NAME? Data sekarang akan diganti! (y/n): " CONFIRM
    if [ "$CONFIRM" = "y" ]; then
        mariadb -u root optic_pos_db < ~/$BACKUP_NAME
        echo "✅ Restore selesai!"
    else
        echo "Restore dibatalkan."
    fi
    ;;
  files)
    OPTIC_DIR="$PREFIX/share/apache2/default-site/htdocs/optic_pos"
    echo "=== Isi folder optic_pos ==="
    echo "Lokasi: $OPTIC_DIR"
    echo ""
    ls -la "$OPTIC_DIR"
    echo ""
    echo "=== Permission qrcodes & main_qrcodes ==="
    stat -c '%A  %U:%G  %n' "$OPTIC_DIR/qrcodes" 2>/dev/null || echo "qrcodes/ tidak ditemukan"
    stat -c '%A  %U:%G  %n' "$OPTIC_DIR/main_qrcodes" 2>/dev/null || echo "main_qrcodes/ tidak ditemukan"
    ;;
  qrcodes)
    OPTIC_DIR="$PREFIX/share/apache2/default-site/htdocs/optic_pos"
    echo "=== Isi folder qrcodes ==="
    ls -la "$OPTIC_DIR/qrcodes" 2>/dev/null || echo "qrcodes/ tidak ditemukan"
    echo ""
    echo "=== Isi folder main_qrcodes ==="
    ls -la "$OPTIC_DIR/main_qrcodes" 2>/dev/null || echo "main_qrcodes/ tidak ditemukan"
    ;;
  help)
    echo "Penggunaan:"
    echo "  server start    — Menghidupkan server"
    echo "  server stop     — Mematikan server"
    echo "  server update   — Update kode dari PC"
    echo "  server status   — Cek status server (aktif/mati)"
    echo "  server files    — Tampilkan isi folder optic_pos & permission qrcodes"
    echo "  server qrcodes  — Tampilkan isi folder qrcodes & main_qrcodes"
    echo "  server backup   — Backup database"
    echo "  server restore  — Restore database dari backup"
    ;;
  *)
    echo "Penggunaan:"
    echo "  server start    — Menghidupkan server"
    echo "  server stop     — Mematikan server"
    echo "  server update   — Update kode dari PC"
    echo "  server status   — Cek status server (aktif/mati)"
    echo "  server files    — Tampilkan isi folder optic_pos & permission qrcodes"
    echo "  server qrcodes  — Tampilkan isi folder qrcodes & main_qrcodes"
    echo "  server backup   — Backup database"
    echo "  server restore  — Restore database dari backup"
    ;;
esac