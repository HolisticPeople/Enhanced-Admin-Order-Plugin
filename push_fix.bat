@echo off
echo === Checking local commits ===
git log --oneline -3

echo.
echo === Checking remote dev commits ===
git fetch origin
git log origin/dev --oneline -3

echo.
echo === Checking if we're ahead ===
git status

echo.
echo === Force pushing to dev ===
git push origin HEAD:dev --force-with-lease

echo.
echo Done! Check if the push succeeded above.
pause
