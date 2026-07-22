@echo off
echo Membuat ZIP update...

IF EXIST "C:\xampp\htdocs\optic_pos.zip" (
    del "C:\xampp\htdocs\optic_pos.zip"
    echo ZIP lama dihapus
)

powershell Compress-Archive -Path "C:\xampp\htdocs\optic_pos" -DestinationPath "C:\xampp\htdocs\optic_pos.zip"
echo ✅ ZIP baru siap di htdocs!
pause