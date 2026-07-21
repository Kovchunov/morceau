<?php
/* ============================================================
   ПРОВЕРКА ХОСТИНГА
   Откройте /api/check.php в браузере, введите пароль от панели.
   Покажет, всё ли нужное есть на сервере.
   УДАЛИТЕ ЭТОТ ФАЙЛ, как только всё заработает.
   ============================================================ */
require __DIR__ . '/config.php';
security_headers();

session_start();
if (isset($_POST['password']) && password_verify($_POST['password'], ADMIN_HASH)) {
    $_SESSION['check_ok'] = true;
}
$ok = !empty($_SESSION['check_ok']);

$rows = [];
if ($ok) {
    $add = function ($name, $pass, $value, $hint = '') use (&$rows) {
        $rows[] = compact('name', 'pass', 'value', 'hint');
    };

    // Версия PHP
    $add('Версия PHP', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION,
         'Нужна 7.4 или новее. Меняется в панели хостинга.');

    // Расширения
    $add('Расширение pdo_sqlite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'есть' : 'нет',
         'Без него база заявок не заработает. Напишите в поддержку или переведём проект на MySQL.');
    $add('Расширение mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'есть' : 'нет (не критично)',
         'Если нет — используются встроенные замены, всё работает.');
    $add('Функция getimagesize', function_exists('getimagesize'), function_exists('getimagesize') ? 'есть' : 'нет',
         'Нужна для проверки загружаемых фотографий.');
    $add('Расширение curl', function_exists('curl_init'), function_exists('curl_init') ? 'есть' : 'нет (не критично)',
         'Нужно только для дублирования заявок в Telegram.');

    // Запись базы
    $dbDir = dirname(DB_PATH);
    $dbWritable = is_writable($dbDir) || is_writable(DB_PATH);
    $add('Папка для базы', $dbWritable, $dbDir . ($dbWritable ? ' — запись разрешена' : ' — ЗАПИСЬ ЗАПРЕЩЕНА'),
         'Если запись запрещена, укажите в config.php другой путь в DB_PATH.');

    // Реальная попытка создать базу
    $dbWorks = false; $dbErr = '';
    try { db(); $dbWorks = true; } catch (Throwable $e) { $dbErr = $e->getMessage(); }
    $add('Создание базы', $dbWorks, $dbWorks ? 'база готова' : 'ошибка: ' . $dbErr);

    // Папка загрузок
    $up = UPLOAD_DIR;
    if (!is_dir($up)) @mkdir($up, 0755, true);
    $upOk = is_dir($up) && is_writable($up);
    $add('Папка img/albums', $upOk, $upOk ? 'запись разрешена' : 'ЗАПИСЬ ЗАПРЕЩЕНА',
         'Поставьте на папку права 755 через файловый менеджер.');

    // Лимиты загрузки
    $umf = ini_get('upload_max_filesize');
    $pms = ini_get('post_max_size');
    $mfu = ini_get('max_file_uploads');
    $toBytes = function ($v) {
        $v = trim((string)$v); $last = strtolower(substr($v, -1)); $n = (float)$v;
        if ($last === 'g') $n *= 1073741824; elseif ($last === 'm') $n *= 1048576; elseif ($last === 'k') $n *= 1024;
        return $n;
    };
    $add('Максимальный размер файла', $toBytes($umf) >= 2097152, $umf,
         'Меньше 2 МБ — фотографии придётся сильно сжимать.');
    $add('Максимальный размер запроса', $toBytes($pms) >= $toBytes($umf), $pms,
         'Должен быть не меньше размера файла.');
    $add('Файлов за одну загрузку', (int)$mfu >= 20, $mfu,
         'Если мало — загружайте фотографии частями.');

    // HTTPS
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $add('HTTPS', $https, $https ? 'включён' : 'ВЫКЛЮЧЕН',
         'Без него пароль от панели передаётся открытым текстом. Включите сертификат в панели хостинга.');

    // Пароль по умолчанию
    $weak = password_verify('change-me', ADMIN_HASH);
    $add('Пароль панели', !$weak, $weak ? 'СТОИТ ПАРОЛЬ ПО УМОЛЧАНИЮ' : 'изменён',
         'Смените его в config.php до того, как сайт станет публичным.');

    // Домен для формы
    $add('Настройка ALLOW_ORIGIN', true, ALLOW_ORIGIN !== '' ? ALLOW_ORIGIN : 'пусто — только свой домен',
         'Пустое значение подходит, если сайт и папка api на одном домене.');
}

$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

// Генератор хеша для нового пароля
$newHash = '';
if ($ok && !empty($_POST['newpass'])) {
    $newHash = password_hash($_POST['newpass'], PASSWORD_DEFAULT);
}

$fails = 0;
foreach ($rows as $r) if (!$r['pass']) $fails++;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Проверка хостинга</title>
<style>
body{margin:0;background:#0a0a0a;color:#f2f2f2;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-weight:400;line-height:1.6}
.shell{max-width:760px;margin:0 auto;padding:40px 20px 80px}
h1{font-size:20px;letter-spacing:.16em;text-transform:uppercase;margin:0 0 8px}
.sub{color:#8f8f8f;font-size:13px;margin:0 0 30px}
.row{display:flex;gap:14px;align-items:flex-start;padding:15px 0;border-bottom:1px solid rgba(242,242,242,.12)}
.mark{width:22px;flex-shrink:0;font-size:17px;line-height:1.4}
.ok{color:#6ec972}.no{color:#e0736e}
.name{flex:1;min-width:150px}
.val{color:#8f8f8f;font-size:13px;text-align:right;word-break:break-all;max-width:300px}
.hint{font-size:12px;color:#e0a05e;margin-top:5px}
.box{border-radius:11px;padding:16px 18px;margin-bottom:26px;font-size:14px}
.good{background:rgba(110,201,114,.1);border:1px solid rgba(110,201,114,.35);color:#6ec972}
.bad{background:rgba(224,115,110,.1);border:1px solid rgba(224,115,110,.35);color:#e0736e}
.warn{background:rgba(242,207,74,.1);border:1px solid rgba(242,207,74,.35);color:#f2cf4a;margin-top:30px}
input{width:100%;background:#111;border:1px solid rgba(242,242,242,.14);border-radius:11px;padding:15px 17px;color:#f2f2f2;font:inherit;margin:20px 0 12px}
button{width:100%;background:#f2cf4a;color:#111;border:0;border-radius:11px;padding:16px;font:inherit;font-weight:700;letter-spacing:.16em;text-transform:uppercase;cursor:pointer}
.login{max-width:320px;margin:16vh auto;text-align:center}
a{color:#f2cf4a}
</style>
</head>
<body>
<?php if (!$ok): ?>
  <div class="login">
    <h1>Проверка хостинга</h1>
    <form method="post">
      <input type="password" name="password" placeholder="Пароль от панели" autofocus required>
      <button type="submit">Проверить</button>
    </form>
  </div>
<?php else: ?>
  <div class="shell">
    <h1>Проверка хостинга</h1>
    <p class="sub">Сервер: <?= $e($_SERVER['HTTP_HOST'] ?? '') ?></p>

    <?php if ($fails === 0): ?>
      <div class="box good">Всё в порядке — сайт готов к работе.</div>
    <?php else: ?>
      <div class="box bad">Проблем найдено: <?= $fails ?>. Подробности ниже, под каждой строкой написано, что делать.</div>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
      <div class="row">
        <span class="mark <?= $r['pass'] ? 'ok' : 'no' ?>"><?= $r['pass'] ? '✓' : '✕' ?></span>
        <div class="name">
          <?= $e($r['name']) ?>
          <?php if (!$r['pass'] && $r['hint']): ?><div class="hint"><?= $e($r['hint']) ?></div><?php endif; ?>
        </div>
        <div class="val"><?= $e($r['value']) ?></div>
      </div>
    <?php endforeach; ?>

    <h2 style="font-size:14px;letter-spacing:.14em;text-transform:uppercase;margin:38px 0 6px">Новый пароль для панели</h2>
    <p class="sub" style="margin-bottom:0">
      Введите пароль — получите строку, которую нужно вставить в <b>api/config.php</b>
      вместо значения <b>ADMIN_HASH</b>. Сам пароль никуда не отправляется и нигде не сохраняется.
    </p>
    <form method="post">
      <input type="text" name="newpass" placeholder="Придумайте пароль" autocomplete="off">
      <button type="submit">Получить строку</button>
    </form>
    <?php if ($newHash): ?>
      <p class="sub" style="margin:18px 0 6px">Скопируйте строку целиком, вместе с кавычками:</p>
      <div class="box good" style="word-break:break-all;font-family:monospace;font-size:13px">
        const ADMIN_HASH = '<?= $e($newHash) ?>';
      </div>
    <?php endif; ?>

    <div class="box warn">
      Удалите файл <b>api/check.php</b>, как только закончите настройку —
      он показывает сведения о сервере.
    </div>
  </div>
<?php endif; ?>
</body>
</html>
