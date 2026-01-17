@echo off
echo Menjalankan proses backup ke GitHub...
git add .
set /p msg="Masukkan pesan update (lalu tekan Enter): "
git commit -m "%msg%"
git push origin main
echo.
echo Proses selesai! Data Anda sudah aman di GitHub.
pause