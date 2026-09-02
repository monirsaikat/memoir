<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (auth_user()) { header('Location: index.php'); exit; }
ensure_schema();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$error = '';

function reset_user_for(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = db()->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

$user = reset_user_for($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    verify_csrf(false);
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    if (strlen($password) < 12) {
        $error = 'The new password must contain at least 12 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } else {
        db()->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        unset($_SESSION['login_attempts']);
        header('Location: login.php?reset=1');
        exit;
    }
}

render('pages/auth/reset.tpl', [
    'csrf' => csrf_token(),
    'token' => $token,
    'linkValid' => $user !== null,
    'error' => $error,
]);
