<?php
require __DIR__ . '/config.php';

security_headers();
$nonce = base64_encode(random_bytes(12));
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; "
     . "script-src 'nonce-{$nonce}'; img-src 'self' data:; form-action 'self'");

$https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true,
    'secure' => $https, 'samesite' => 'Strict',
]);
session_start();

$pdo = db();
$ip  = client_ip();
$e   = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

/* ---------- Выход ---------- */
if (isset($_GET['logout'])) {
    $_SESSION = []; session_destroy();
    header('Location: admin.php'); exit;
}

/* ---------- Вход ---------- */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM logins WHERE ip = ? AND created > datetime('now','-15 minutes')");
    $q->execute([$ip]);

    if ((int)$q->fetchColumn() >= LOGIN_LIMIT) {
        $error = 'Слишком много попыток. Подождите 15 минут.';
    } elseif (password_verify($_POST['password'], ADMIN_HASH)) {
        $pdo->prepare("DELETE FROM logins WHERE ip = ?")->execute([$ip]);
        session_regenerate_id(true);
        $_SESSION['auth']  = true;
        $_SESSION['token'] = bin2hex(random_bytes(16));
        header('Location: admin.php'); exit;
    } else {
        $pdo->prepare("INSERT INTO logins (ip) VALUES (?)")->execute([$ip]);
        $error = 'Неверный пароль';
        usleep(700000);
    }
}

$authed = !empty($_SESSION['auth']);
$tab    = $_GET['tab'] ?? 'leads';
if (!in_array($tab, ['leads', 'albums', 'new'], true)) $tab = 'leads';
$notice = '';

/* ---------- Действия ---------- */
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($_SESSION['token'] ?? '', $_POST['token'] ?? '')) {
        http_response_code(403); exit('Недействительный запрос');
    }
    $act = $_POST['action'];
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'lead_read') {
        $pdo->prepare("UPDATE leads SET is_new = 0 WHERE id = ?")->execute([$id]);
        header('Location: admin.php?tab=leads'); exit;
    }
    if ($act === 'lead_delete') {
        $pdo->prepare("DELETE FROM leads WHERE id = ?")->execute([$id]);
        header('Location: admin.php?tab=leads'); exit;
    }
    if ($act === 'album_rename') {
        $t = trim((string)($_POST['title'] ?? ''));
        if ($t !== '') $pdo->prepare("UPDATE albums SET title = ? WHERE id = ?")->execute([mb_substr($t,0,80), $id]);
        header('Location: admin.php?tab=albums'); exit;
    }
    if ($act === 'album_delete') {
        // Сначала стираем файлы с диска, потом записи
        $q = $pdo->prepare("SELECT file FROM photos WHERE album_id = ?");
        $q->execute([$id]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $file) {
            @unlink(UPLOAD_DIR . '/' . basename($file));
        }
        $pdo->prepare("DELETE FROM photos WHERE album_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM albums WHERE id = ?")->execute([$id]);
        header('Location: admin.php?tab=albums'); exit;
    }
    if ($act === 'photo_delete') {
        $q = $pdo->prepare("SELECT file FROM photos WHERE id = ?");
        $q->execute([$id]);
        $file = $q->fetchColumn();
        if ($file) @unlink(UPLOAD_DIR . '/' . basename($file));
        $pdo->prepare("DELETE FROM photos WHERE id = ?")->execute([$id]);
        header('Location: admin.php?tab=albums'); exit;
    }
    if ($act === 'album_create') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            $notice = 'Укажите название сета';
            $tab = 'new';
        } else {
            $pdo->prepare("INSERT INTO albums (title, sort) VALUES (?, (SELECT IFNULL(MAX(sort),0)+1 FROM albums))")
                ->execute([mb_substr($title, 0, 80)]);
            $albumId = (int)$pdo->lastInsertId();

            $saved = 0; $errs = [];
            if (!empty($_FILES['photos']['name'][0])) {
                $count = count($_FILES['photos']['name']);
                for ($i = 0; $i < $count && $i < 60; $i++) {
                    $one = [
                        'name'     => $_FILES['photos']['name'][$i],
                        'type'     => $_FILES['photos']['type'][$i],
                        'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                        'error'    => $_FILES['photos']['error'][$i],
                        'size'     => $_FILES['photos']['size'][$i],
                    ];
                    $err = '';
                    $file = save_upload($one, $err);
                    if ($file) {
                        $pdo->prepare("INSERT INTO photos (album_id, file, sort) VALUES (?, ?, ?)")
                            ->execute([$albumId, $file, $i]);
                        $saved++;
                    } else {
                        $errs[] = $err;
                    }
                }
            }
            $_SESSION['flash'] = "Сет «{$title}» создан, загружено фотографий: {$saved}"
                . ($errs ? '. Пропущено: ' . count($errs) . ' (' . $e(implode('; ', array_unique($errs))) . ')' : '');
            header('Location: admin.php?tab=albums'); exit;
        }
    }
    if ($act === 'album_add') {
        $saved = 0;
        if (!empty($_FILES['photos']['name'][0])) {
            $count = count($_FILES['photos']['name']);
            $max = (int)$pdo->query("SELECT IFNULL(MAX(sort),0) FROM photos WHERE album_id = " . $id)->fetchColumn();
            for ($i = 0; $i < $count && $i < 60; $i++) {
                $one = [
                    'name'     => $_FILES['photos']['name'][$i],
                    'type'     => $_FILES['photos']['type'][$i],
                    'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                    'error'    => $_FILES['photos']['error'][$i],
                    'size'     => $_FILES['photos']['size'][$i],
                ];
                $err = '';
                $file = save_upload($one, $err);
                if ($file) {
                    $pdo->prepare("INSERT INTO photos (album_id, file, sort) VALUES (?, ?, ?)")
                        ->execute([$id, $file, ++$max]);
                    $saved++;
                }
            }
        }
        $_SESSION['flash'] = "Добавлено фотографий: {$saved}";
        header('Location: admin.php?tab=albums'); exit;
    }
}

/* ---------- Выгрузка в CSV ---------- */
if ($authed && isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Дата', 'Имя', 'Связь', 'Сообщение']);
    foreach ($pdo->query("SELECT created,name,contact,message FROM leads ORDER BY id DESC") as $r) {
        fputcsv($out, [$r['created'], $r['name'], $r['contact'], $r['message']]);
    }
    exit;
}

/* ---------- Данные для вывода ---------- */
$leads = $albums = [];
$newCount = 0;
if ($authed) {
    $leads    = $pdo->query("SELECT * FROM leads ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $newCount = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE is_new = 1")->fetchColumn();
    $albums   = $pdo->query("SELECT * FROM albums ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($albums as &$a) {
        $q = $pdo->prepare("SELECT id, file FROM photos WHERE album_id = ? ORDER BY sort, id");
        $q->execute([$a['id']]);
        $a['photos'] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($a);
}
if (!empty($_SESSION['flash'])) { $notice = $_SESSION['flash']; unset($_SESSION['flash']); }
$token = $authed ? $_SESSION['token'] : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Панель управления</title>
<style>
:root{
  --bg:#0a0a0a; --panel:#161616; --card:#1c1c1c;
  --text:#f2f2f2; --muted:#8f8f8f; --dim:#5a5a5a;
  --accent:#f2cf4a; --line:rgba(242,242,242,.14); --danger:#e0736e;
}
*{box-sizing:border-box}
body{
  margin:0;background:var(--bg);color:var(--text);
  font-family:"Montserrat",system-ui,-apple-system,"Segoe UI",sans-serif;
  font-weight:400;line-height:1.6;
}
.shell{max-width:960px;margin:0 auto;padding:26px 20px 90px}

/* Верхние кнопки */
.topbar{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin-bottom:38px}
.tbtn{
  border:1px solid var(--line);background:none;color:var(--text);
  padding:13px 26px;font:inherit;font-size:11px;font-weight:600;
  letter-spacing:.18em;text-transform:uppercase;text-decoration:none;
  cursor:pointer;transition:.25s;display:inline-block;
}
.tbtn:hover{border-color:var(--accent);color:var(--accent)}

h1.title{
  text-align:center;margin:0 0 34px;
  font-size:clamp(20px,3.6vw,30px);font-weight:700;
  letter-spacing:.2em;text-transform:uppercase;
}

/* Вкладки */
.tabs{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--line);margin-bottom:34px}
.tab{
  padding:18px 8px;text-align:center;text-decoration:none;
  font-size:12px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;
  color:var(--text);background:none;transition:.25s;
}
.tab:hover{color:var(--accent)}
.tab.on{background:var(--accent);color:#111}
.tab .n{
  display:inline-block;margin-left:8px;padding:1px 7px;border-radius:9px;
  background:var(--accent);color:#111;font-size:10px;
}
.tab.on .n{background:#111;color:var(--accent)}
@media(max-width:560px){.tab{font-size:10px;letter-spacing:.08em;padding:15px 4px}}

h2.sec{
  margin:0 0 22px;font-size:13px;font-weight:700;
  letter-spacing:.2em;text-transform:uppercase;color:var(--text);
  padding-bottom:14px;border-bottom:1px solid var(--line);
}

.flash{
  background:rgba(242,207,74,.1);border:1px solid rgba(242,207,74,.35);
  color:var(--accent);padding:14px 18px;border-radius:10px;margin-bottom:24px;font-size:13px;
}

/* Карточки */
.card{background:var(--card);border-radius:12px;padding:22px 24px;margin-bottom:16px}
.card-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
.who{font-size:14px;line-height:1.9}
.who b{color:var(--muted);font-weight:400}
.icons{display:flex;gap:10px;flex-shrink:0}
.icons button{
  background:none;border:0;padding:7px;cursor:pointer;color:var(--muted);
  line-height:0;border-radius:8px;transition:.22s;
}
.icons button:hover{color:var(--accent);background:rgba(255,255,255,.06)}
.icons button.del:hover{color:var(--danger)}
.card .msg{
  margin:16px 0 0;padding-top:16px;border-top:1px solid var(--line);
  white-space:pre-wrap;word-break:break-word;font-size:14px;
}
.when{font-size:11px;letter-spacing:.1em;color:var(--dim);margin-top:12px}
.card.unread{box-shadow:inset 3px 0 0 var(--accent)}

/* Альбомы */
.thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:10px;margin-top:18px}
.thumb{position:relative;aspect-ratio:1;border-radius:8px;overflow:hidden;background:#111}
.thumb img{width:100%;height:100%;object-fit:cover;display:block}
.thumb button{
  position:absolute;top:5px;right:5px;width:24px;height:24px;
  border:0;border-radius:6px;background:rgba(0,0,0,.7);color:#fff;
  cursor:pointer;line-height:0;display:grid;place-items:center;padding:0;
}
.thumb button:hover{background:var(--danger)}
.album-head{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}
.album-head input[type=text]{
  background:#111;border:1px solid var(--line);border-radius:9px;
  padding:11px 15px;color:var(--text);font:inherit;font-size:14px;min-width:220px;
}
.album-head input:focus{outline:none;border-color:var(--accent)}
.inline{display:flex;gap:9px;align-items:center;flex-wrap:wrap}
.mini{
  background:none;border:1px solid var(--line);border-radius:8px;color:var(--muted);
  font:inherit;font-size:10px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;
  padding:9px 14px;cursor:pointer;transition:.22s;
}
.mini:hover{color:var(--accent);border-color:var(--accent)}
.mini.del:hover{color:var(--danger);border-color:var(--danger)}

/* Форма нового сета */
.form label{display:block;font-size:11px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:var(--muted);margin:0 0 10px}
.form input[type=text]{
  width:100%;background:#111;border:1px solid var(--line);border-radius:11px;
  padding:16px 18px;color:var(--text);font:inherit;font-size:15px;margin-bottom:26px;
}
.form input[type=text]:focus{outline:none;border-color:var(--accent)}
.drop{
  border:1px dashed var(--line);border-radius:12px;padding:34px 20px;text-align:center;
  color:var(--muted);font-size:13px;margin-bottom:12px;transition:.25s;cursor:pointer;display:block;
}
.drop:hover{border-color:var(--accent);color:var(--accent)}
.drop input{display:none}
.hint{font-size:12px;color:var(--dim);margin:0 0 26px}
.submit{
  width:100%;background:var(--accent);color:#111;border:0;border-radius:11px;padding:18px;
  font:inherit;font-size:12px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;cursor:pointer;
}
.submit:hover{filter:brightness(1.08)}
.empty{color:var(--dim);text-align:center;padding:56px 0;font-size:14px}

/* Вход */
.login{max-width:330px;margin:16vh auto;text-align:center}
.login input{
  width:100%;background:#111;border:1px solid var(--line);border-radius:11px;
  padding:16px 18px;color:var(--text);font:inherit;margin:24px 0 14px;font-size:15px;
}
.login input:focus{outline:none;border-color:var(--accent)}
.login .submit{margin-top:0}
.err{color:var(--danger);font-size:13px;margin-top:16px}
</style>
</head>
<body>

<?php if (!$authed): ?>
  <div class="login">
    <h1 class="title">Панель управления</h1>
    <form method="post">
      <input type="password" name="password" placeholder="Пароль" autofocus required>
      <button class="submit" type="submit">Войти</button>
    </form>
    <?php if ($error): ?><p class="err"><?= $e($error) ?></p><?php endif; ?>
  </div>

<?php else: ?>
<div class="shell">

  <div class="topbar">
    <a class="tbtn" href="../index.html">&larr; Вернуться на сайт</a>
    <a class="tbtn" href="?logout=1">Выйти из панели</a>
  </div>

  <h1 class="title">Панель управления</h1>

  <nav class="tabs">
    <a class="tab <?= $tab==='leads'?'on':'' ?>" href="?tab=leads">Заявки<?php if($newCount): ?><span class="n"><?= $newCount ?></span><?php endif; ?></a>
    <a class="tab <?= $tab==='albums'?'on':'' ?>" href="?tab=albums">Альбомы</a>
    <a class="tab <?= $tab==='new'?'on':'' ?>" href="?tab=new">Новый сет</a>
  </nav>

  <?php if ($notice): ?><div class="flash"><?= $notice ?></div><?php endif; ?>

  <?php /* ================= ЗАЯВКИ ================= */ ?>
  <?php if ($tab === 'leads'): ?>
    <h2 class="sec">Заявки на съёмку</h2>

    <?php if (!$leads): ?>
      <p class="empty">Заявок пока нет. Они появятся здесь сразу после отправки формы на сайте.</p>
    <?php else: ?>
      <div class="inline" style="justify-content:flex-end;margin-bottom:18px">
        <a class="mini" href="?csv=1">Скачать CSV</a>
      </div>
    <?php endif; ?>

    <?php foreach ($leads as $l): ?>
      <div class="card <?= $l['is_new'] ? 'unread' : '' ?>">
        <div class="card-top">
          <div class="who">
            <b>Имя:</b> <?= $e($l['name']) ?><br>
            <b>Контакты:</b> <?= $e($l['contact']) ?>
          </div>
          <div class="icons">
            <?php if ($l['is_new']): ?>
            <form method="post">
              <input type="hidden" name="token" value="<?= $e($token) ?>">
              <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <button name="action" value="lead_read" type="submit" title="Отметить прочитанным">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                  <path d="M3 8.5 12 14l9-5.5"/><rect x="3" y="5" width="18" height="14" rx="2"/>
                </svg>
              </button>
            </form>
            <?php endif; ?>
            <form method="post" data-confirm="Удалить заявку без возможности восстановления?">
              <input type="hidden" name="token" value="<?= $e($token) ?>">
              <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <button class="del" name="action" value="lead_delete" type="submit" title="Удалить">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                  <path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/>
                </svg>
              </button>
            </form>
          </div>
        </div>
        <p class="msg"><?= $e($l['message']) ?></p>
        <div class="when"><?= $e($l['created']) ?><?= $l['is_new'] ? ' · новая' : '' ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ================= АЛЬБОМЫ ================= */ ?>
  <?php if ($tab === 'albums'): ?>
    <h2 class="sec">Альбомы на сайте</h2>

    <?php if (!$albums): ?>
      <p class="empty">Альбомов пока нет. Создайте первый во вкладке «Новый сет».</p>
    <?php endif; ?>

    <?php foreach ($albums as $a): ?>
      <div class="card">
        <div class="album-head">
          <form method="post" class="inline">
            <input type="hidden" name="token" value="<?= $e($token) ?>">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <input type="text" name="title" value="<?= $e($a['title']) ?>" maxlength="80">
            <button class="mini" name="action" value="album_rename" type="submit">Переименовать</button>
          </form>
          <div class="inline">
            <span style="font-size:11px;letter-spacing:.14em;color:var(--dim)"><?= count($a['photos']) ?> кадров</span>
            <form method="post" enctype="multipart/form-data" class="inline">
              <input type="hidden" name="token" value="<?= $e($token) ?>">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <label class="mini" style="cursor:pointer">
                Добавить фото
                <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" style="display:none" data-autosubmit>
              </label>
              <button name="action" value="album_add" type="submit" style="display:none"></button>
            </form>
            <form method="post" data-confirm="Удалить альбом целиком вместе со всеми фотографиями?">
              <input type="hidden" name="token" value="<?= $e($token) ?>">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="mini del" name="action" value="album_delete" type="submit">Удалить сет</button>
            </form>
          </div>
        </div>

        <?php if ($a['photos']): ?>
        <div class="thumbs">
          <?php foreach ($a['photos'] as $p): ?>
            <div class="thumb">
              <img src="../<?= UPLOAD_URL ?>/<?= $e($p['file']) ?>" alt="">
              <form method="post" data-confirm="Удалить этот кадр?">
                <input type="hidden" name="token" value="<?= $e($token) ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button name="action" value="photo_delete" type="submit" title="Удалить кадр">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                    <path d="M5 5l14 14M19 5L5 19"/>
                  </svg>
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php /* ================= НОВЫЙ СЕТ ================= */ ?>
  <?php if ($tab === 'new'): ?>
    <h2 class="sec">Новый сет</h2>
    <form method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="token" value="<?= $e($token) ?>">

      <label for="t">Название сета</label>
      <input id="t" type="text" name="title" placeholder="Например: Художественная съёмка" maxlength="80" required>

      <label>Фотографии</label>
      <label class="drop" id="drop">
        <span id="dropText">Выберите файлы или перетащите их сюда</span>
        <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" id="files">
      </label>
      <p class="hint">
        JPEG, PNG или WebP, до <?= round(MAX_UPLOAD / 1048576) ?> МБ каждый, не больше 60 за раз.
        Первая фотография станет обложкой альбома.
      </p>

      <button class="submit" name="action" value="album_create" type="submit">Создать сет</button>
    </form>
  <?php endif; ?>

</div>
<?php endif; ?>

<script nonce="<?= $e($nonce) ?>">
// Подтверждение перед удалением
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    if (!confirm(f.dataset.confirm)) ev.preventDefault();
  });
});

// Выбрали файлы для существующего альбома — отправляем сразу
document.querySelectorAll('[data-autosubmit]').forEach(function (inp) {
  inp.addEventListener('change', function () {
    if (!inp.files.length) return;
    var form = inp.closest('form');
    form.querySelector('button[value="album_add"]').click();
  });
});

// Показываем, сколько файлов выбрано
var files = document.getElementById('files');
if (files) {
  files.addEventListener('change', function () {
    document.getElementById('dropText').textContent =
      files.files.length ? 'Выбрано файлов: ' + files.files.length
                         : 'Выберите файлы или перетащите их сюда';
  });
}
</script>

</body>
</html>
