@echo off
cd ..
echo Current Directory: %cd%
echo.
echo --- PULLING LATEST DATA FROM GITHUB ---
git pull origin main
echo.
echo Data Sync Completed!
pause