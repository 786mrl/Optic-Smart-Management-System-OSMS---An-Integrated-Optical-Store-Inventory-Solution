@echo off
cd ..
echo Current Directory: %cd%
echo.
echo --- STARTING GITHUB BACKUP PROCESS ---

echo Fetching latest changes from GitHub...
git fetch origin

echo Pulling and merging remote changes...
git pull origin main --no-edit

git add .
set /p msg="Enter update message: "
git commit -m "%msg%"

echo Pushing to GitHub...
git push origin main

if errorlevel 1 (
    echo.
    echo Push failed. Trying pull again then push...
    git pull origin main --no-edit
    git push origin main
)

echo.
echo Backup Completed Successfully!
pause