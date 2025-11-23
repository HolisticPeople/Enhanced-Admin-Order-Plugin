@echo off
echo === Quick Push Script ===
echo This script will commit and push the current changes
echo.

:: Check if there are staged changes
git diff --cached --quiet
if %errorlevel% equ 0 (
    echo No staged changes found. Staging all modified files...
    git add -A
)

:: Commit
set /p commit_msg="Enter commit message (or press Enter for default): "
if "%commit_msg%"=="" (
    :: Get current version from enhanced-admin-order-plugin.php
    for /f "tokens=3" %%a in ('findstr /C:"* Version:" enhanced-admin-order-plugin.php') do set VERSION=%%a
    set commit_msg=v!VERSION! - Auto-commit
)

git commit -m "%commit_msg%"

:: Push
echo.
echo Pushing to dev...
git push origin HEAD:dev

echo.
echo Done!
pause
