<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (auth_user()) { header('Location: index.php'); exit; }
ensure_schema();

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(false);

    // At most 5 reset requests per 15 minutes per session.
    $attempts = array_values(array_filter(
        $_SESSION['reset_attempts'] ?? [],
        static fn (int $time): bool => $time > time() - 900
    ));
    if (count($attempts) >= 5) {
        http_response_code(429);
        $error = 'Too many reset requests. Wait a while and try again.';
    } else {
        $attempts[] = time();
        $_SESSION['reset_attempts'] = $attempts;

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $stmt = db()->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('UPDATE users SET reset_token = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 45 MINUTE) WHERE id = ?')
                ->execute([hash('sha256', $token), $user['id']]);

            $settings = db()->query('SELECT * FROM settings WHERE id=1')->fetch() ?: [];
            $link = rtrim($config['app']['url'], '/') . '/reset.php?token=' . $token;
            $body = "Hello,\n\n"
                . "A password reset was requested for your Memoir account.\n"
                . "Open this link within 45 minutes to choose a new password:\n\n"
                . $link . "\n\n"
                . "If you did not request this, you can ignore this email — your password is unchanged.\n";
            try {
                smtp_send($settings, $user['email'], 'Reset your Memoir password', $body);
            } catch (Throwable $exception) {
                // Invalidate the token again if the mail could not go out.
                db()->prepare('UPDATE users SET reset_token = NULL, reset_expires = NULL WHERE id = ?')
                    ->execute([$user['id']]);
                $error = $exception->getMessage();
            }
        }
        // The same message regardless of whether the account exists.
        if ($error === '') $sent = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset password — Memoir</title>
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
    <h1>Reset your password</h1>
    <p>Enter your account email and we will send you a reset link.</p>

    <?php if ($sent): ?>
    <div class="notice success">If that address belongs to an account, a reset link is on its way. Check your inbox.</div>
    <?php endif ?>

    <?php if ($error): ?>
    <div class="notice error"><?= e($error) ?></div>
    <?php endif ?>

    <?php if (!$sent): ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" autocomplete="username" required>

        <button class="primary-btn" type="submit">Send reset link</button>
    </form>
    <?php endif ?>

    <div class="auth-foot"><a class="auth-link" href="login.php">Back to sign in</a></div>
</main>

</body>
</html>
