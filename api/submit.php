<?php
require __DIR__ . '/config.php';

security_headers();
header('Content-Type: application/json; charset=utf-8');

// CORS: по умолчанию принимаем только запросы со своего домена.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$self   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
        . ($_SERVER['HTTP_HOST'] ?? '');
$allowed = ALLOW_ORIGIN !== '' ? ALLOW_ORIGIN : $self;
if ($origin !== '' && $origin === $allowed) {
    header('Access-Control-Allow-Origin: ' . $allowed);
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST-запрос', 405);

// Запрос с чужого сайта отклоняем
if ($origin !== '' && $origin !== $allowed) fail('Запрос с постороннего домена', 403);

$raw = file_get_contents('php://input');
if (strlen($raw) > 20000) fail('Слишком большой запрос', 413);
$in = json_decode($raw, true);
if (!is_array($in)) fail('Некорректные данные');

// Ловушка для ботов: человек это поле не видит и не заполнит.
if (!empty($in['website'])) { echo json_encode(['ok' => true]); exit; }

$name    = trim((string)($in['name'] ?? ''));
$contact = trim((string)($in['contact'] ?? ''));
$message = trim((string)($in['message'] ?? ''));

if ($name === '' || $contact === '' || $message === '') fail('Заполнены не все поля');
if (mb_strlen($name) > 80 || mb_strlen($contact) > 120 || mb_strlen($message) > 2000) {
    fail('Слишком длинный текст');
}

// Убираем управляющие символы, чтобы в базу не попал мусор
$clean = function ($s) { return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s); };
$name = $clean($name); $contact = $clean($contact); $message = $clean($message);

$ip = client_ip();

try {
    $pdo = db();

    $q = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE ip = ? AND created > datetime('now','-1 hour')");
    $q->execute([$ip]);
    if ((int)$q->fetchColumn() >= RATE_LIMIT) fail('Слишком много заявок подряд. Попробуйте позже.', 429);

    $pdo->prepare("INSERT INTO leads (name, contact, message, ip) VALUES (?, ?, ?, ?)")
        ->execute([$name, $contact, $message, $ip]);
} catch (Throwable $e) {
    error_log('lead insert failed: ' . $e->getMessage());
    fail('Не удалось сохранить заявку', 500);
}

// Дублируем в Telegram, если настроено. Сбой отправки не ломает заявку.
if (TG_TOKEN !== '' && TG_CHAT_ID !== '' && function_exists('curl_init')) {
    $text = "Новая заявка с сайта\n\nИмя: {$name}\nСвязь: {$contact}\n\n{$message}";
    $ch = curl_init('https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => TG_CHAT_ID, 'text' => $text]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
