<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
ensure_schema();

$token = (string) ($_GET['t'] ?? '');
$note = null;

if (preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    $stmt = db()->prepare("SELECT title, content, updated_at FROM notes WHERE share_token = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$token]);
    $note = $stmt->fetch() ?: null;
}

if (!$note) {
    http_response_code(404);
}
$settings = db()->query("SELECT app_name FROM settings WHERE id=1")->fetch() ?: [];
$appName = $settings['app_name'] ?? 'Memoir';

render('pages/share.tpl', [
    'note' => $note,
    'appName' => $appName,
]);
