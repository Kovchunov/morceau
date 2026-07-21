<?php
require __DIR__ . '/config.php';

security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

try {
    $pdo = db();
    $out = [];
    foreach ($pdo->query("SELECT id, title FROM albums ORDER BY sort, id") as $a) {
        $q = $pdo->prepare("SELECT file FROM photos WHERE album_id = ? ORDER BY sort, id");
        $q->execute([$a['id']]);
        $files = array_map(
            function ($f) { return UPLOAD_URL . '/' . $f; },
            $q->fetchAll(PDO::FETCH_COLUMN)
        );
        $out[] = ['title' => $a['title'], 'photos' => $files];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('albums read failed: ' . $e->getMessage());
    echo json_encode([]);
}
