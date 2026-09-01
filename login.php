<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (auth_user()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(false);
    $attempts = array_values(array_filter(
        $_SESSION['login_attempts'] ?? [],
        static fn (int $time): bool => $time > time() - 300
    ));
    if (count($attempts) >= 5) {
        http_response_code(429);
        $error = 'Too many sign-in attempts. Wait five minutes and try again.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $stmt = db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            unset($_SESSION['login_attempts']);
            header('Location: index.php');
            exit;
        }
        $attempts[] = time();
        $_SESSION['login_attempts'] = $attempts;
        $error = 'Email or password is incorrect.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sign in — Memoir</title>
    <script>
    (function () {
        try {
            var choice = localStorage.getItem('memoir-theme') || 'system';
            var dark = choice === 'dark' || (choice === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
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
    <h1>Welcome to Memoir</h1>
    <p>Your notes, quietly kept on your own server.</p>

    <?php if (isset($_GET['installed'])): ?>
    <div class="notice success">Installation complete. Sign in to continue.</div>
    <?php endif ?>

    <?php if ($error): ?>
    <div class="notice error"><?= e($error) ?></div>
    <?php endif ?>

    <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" autocomplete="username" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>

        <button class="primary-btn" type="submit">Sign in</button>
    </form>

    <div class="auth-foot">Memoir · Self-hosted personal notes</div>
</main>

</body>
</html>
