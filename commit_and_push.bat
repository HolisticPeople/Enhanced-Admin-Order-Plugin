@echo off
echo === Staging changes ===
git add eao-products-tabulator.js enhanced-admin-order-plugin.php

echo.
echo === Committing v5.2.78 ===
git commit -m "v5.2.78 - Fix discounted price display for excluded items with percentage discounts"

echo.
echo === Pushing to dev ===
git push origin HEAD:dev

echo.
echo Done! The v5.2.78 changes should now be on GitHub.
pause
