<?php
/* ============================================================
   НАСТРОЙКИ. Отредактируйте перед загрузкой на сервер.
   ============================================================ */

// Хеш пароля от админки.
// Сгенерируйте свой: php -r "echo password_hash('вашпароль', PASSWORD_DEFAULT);"
// Значение ниже соответствует паролю "change-me" — обязательно замените.
const ADMIN_HASH = '$2y$10$DTnn2md9o8dYhxBs/LIlV.ao7XZXjAy3fnzzpKoXmWsc2S7STQsOC';

// Файл базы. По умолчанию — выше папки сайта, чтобы его
// нельзя было скачать по прямой ссылке.
const DB_PATH = __DIR__ . '/../../leads.sqlite';

// Куда складывать загруженные фотографии
const UPLOAD_DIR = __DIR__ . '/../img/albums';
// Как этот путь выглядит для браузера
const UPLOAD_URL = 'img/albums';

// Дублировать заявки в Telegram (необязательно).
// Токен у @BotFather, chat_id у @userinfobot. Пусто — не отправлять.
const TG_TOKEN   = '';
const TG_CHAT_ID = '';

// Домен сайта, например 'https://example.ru'.
// Пусто — принимаются запросы только с того же домена (безопаснее всего).
const ALLOW_ORIGIN = '';

// Не больше стольких заявок с одного IP за час.
const RATE_LIMIT = 5;

// Не больше стольких неудачных входов в админку за 15 минут.
const LOGIN_LIMIT = 5;

// Максимальный вес одной фотографии, байт (8 МБ)
const MAX_UPLOAD = 8 * 1024 * 1024;


// На части хостингов не установлено расширение mbstring.
// Подставляем свои реализации, чтобы код работал везде.
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) {
        return preg_match_all('/./us', (string)$s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        preg_match_all('/./us', (string)$s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}

function db() {
    $first = !file_exists(DB_PATH);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON");

    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        name     TEXT NOT NULL,
        contact  TEXT NOT NULL,
        message  TEXT NOT NULL,
        ip       TEXT,
        is_new   INTEGER NOT NULL DEFAULT 1,
        created  TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS logins (
        ip      TEXT NOT NULL,
        created TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS albums (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        title   TEXT NOT NULL,
        sort    INTEGER NOT NULL DEFAULT 0,
        created TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS photos (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        album_id INTEGER NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
        file     TEXT NOT NULL,
        sort     INTEGER NOT NULL DEFAULT 0
    )");

    if ($first) @chmod(DB_PATH, 0600);
    return $pdo;
}

function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Заголовки безопасности — ставятся на каждый ответ */
function security_headers() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header_remove('X-Powered-By');
}

/**
 * Принимает загруженный файл и возвращает имя сохранённого,
 * либо строку с ошибкой в $error.
 *
 * Проверяем не расширение, которое прислал браузер, а само содержимое файла:
 * это единственный надёжный способ не дать залить скрипт под видом картинки.
 */
function save_upload(array $f, &$error) {
    $error = '';

    if (!isset($f['error']) || $f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Файл не загрузился (код ' . ($f['error'] ?? '?') . ')';
        return null;
    }
    if ($f['size'] > MAX_UPLOAD) {
        $error = 'Файл тяжелее ' . round(MAX_UPLOAD / 1048576) . ' МБ';
        return null;
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        $error = 'Некорректная загрузка';
        return null;
    }

    // Определяем настоящий тип по содержимому
    $info = @getimagesize($f['tmp_name']);
    if ($info === false) {
        $error = 'Это не изображение';
        return null;
    }
    $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!isset($allowed[$info[2]])) {
        $error = 'Подходят только JPEG, PNG и WebP';
        return null;
    }

    // Имя придумываем сами — то, что прислал браузер, не используем вообще
    $name = bin2hex(random_bytes(10)) . '.' . $allowed[$info[2]];

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) {
        $error = 'Не удалось создать папку для фотографий';
        return null;
    }
    if (!@move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        $error = 'Не удалось сохранить файл — проверьте права на папку img/albums';
        return null;
    }
    @chmod(UPLOAD_DIR . '/' . $name, 0644);

    return $name;
}
