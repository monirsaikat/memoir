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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Choose a new password — Memoir</title>
    <script>
    (function () {
        try {
            var choice = localStorage.getItem('memoir-theme') || 'system';
            var darkFlavors = { dark: 1, ocean: 1, midnight: 1 };
            if (choice === 'system') {
                choice = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            if (!/^(light|dark|sepia|ocean|midnight)$/.test(choice)) choice = 'light';
            document.documentElement.dataset.theme = choice;
            document.documentElement.dataset.mode = darkFlavors[choice] ? 'dark' : 'light';
            var accent = localStorage.getItem('memoir-accent');
            if (accent && /^#[0-9a-fA-F]{6}$/.test(accent)) {
                document.documentElement.style.setProperty('--accent', accent);
            }
        } catch (e) {}
    })();
    </script>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="auth-page">

<main class="auth-card">
    <img class="auth-logo" src="assets/img/memoir-logo.png" alt="Memoir">

    <?php if (!$user): ?>
    <h1>Link expired</h1>
    <p>This reset link is invalid or has expired. Reset links work for 45 minutes.</p>
    <div class="auth-foot"><a class="auth-link" href="forgot.php">Request a new link</a></div>

    <?php else: ?>
    <h1>Choose a new password</h1>
    <p>Pick a strong password of at least 12 characters.</p>

    <?php if ($error): ?>
    <div class="notice error"><?= e($error) ?></div>
    <?php endif ?>

    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <label for="password">New password</label>
        <input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required>

        <label for="confirm">Confirm new password</label>
        <input id="confirm" type="password" name="confirm" autocomplete="new-password" required>

        <button class="primary-btn" type="submit">Set new password</button>
    </form>

    <div class="auth-foot"><a class="auth-link" href="login.php">Back to sign in</a></div>
    <?php endif ?>
</main>

</body>
</html>
