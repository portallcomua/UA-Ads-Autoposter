@echo off
cd /d "%~dp0"
echo ============================================
echo  Vstanovlennya komponentiv robota...
echo  Zachekayte 1-2 khvylyny.
echo ============================================
call npm install
if errorlevel 1 (
    echo POMYLKA: npm install ne vdalosya.
    pause
    exit /b 1
)
call npx playwright install chromium
if errorlevel 1 (
    echo POMYLKA: playwright install ne vdalosya.
    pause
    exit /b 1
)
echo.
echo Gotovo! Teper mozhna zakryty ce vikno.
pause