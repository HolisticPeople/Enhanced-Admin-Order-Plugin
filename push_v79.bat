@echo off
echo === Committing and pushing version 5.2.79 ===
git add enhanced-admin-order-plugin.php
git commit -m "v5.2.79 - Test auto-deploy workflow"
git push origin HEAD:dev
echo.
echo Done! Version 5.2.79 should now be on GitHub and deploying to staging.
pause
