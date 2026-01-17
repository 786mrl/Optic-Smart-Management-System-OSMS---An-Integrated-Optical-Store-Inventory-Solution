@echo off
cd ..
echo Current Directory: %cd%
echo.
echo --- STARTING GITHUB BACKUP PROCESS ---
git add .
set /p msg="Enter update message: "
git commit -m "%msg%"
git push origin main
echo.
echo Backup Completed Successfully!
pause