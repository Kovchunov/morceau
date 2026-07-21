#!/usr/bin/env bash
# Запуск сайта на своём компьютере: ./start.sh
# Останавливается сочетанием Ctrl+C

cd "$(dirname "$0")" || exit 1

if ! command -v php >/dev/null 2>&1; then
  echo "PHP не найден."
  echo "Установите его — macOS: brew install php, Linux: sudo apt install php-cli php-sqlite3"
  echo "Либо просто откройте index.html в браузере: всё заработает, кроме формы."
  exit 1
fi

PORT=8000
while lsof -i ":$PORT" >/dev/null 2>&1; do PORT=$((PORT+1)); done

echo "Сайт:   http://localhost:$PORT"
echo "Заявки: http://localhost:$PORT/api/admin.php   (пароль по умолчанию: change-me)"
echo "Остановить — Ctrl+C"
echo

php -S "localhost:$PORT"
