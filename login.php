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

render('pages/auth/login.tpl', [
    'csrf' => csrf_token(),
    'error' => $error,
    'installed' => isset($_GET['installed']),
    'passwordReset' => isset($_GET['reset']),
]);
