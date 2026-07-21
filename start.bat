@echo off
chcp 65001 >nul
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
  echo PHP не найден.
  echo Скачайте его с windows.php.net, распакуйте и добавьте папку в PATH.
  echo Либо просто откройте index.html в браузере: всё заработает, кроме формы.
  pause
  exit /b 1
)

echo Сайт:   http://localhost:8000
echo Заявки: http://localhost:8000/api/admin.php   (пароль по умолчанию: change-me)
echo Остановить - Ctrl+C
echo.

php -S localhost:8000
pause
