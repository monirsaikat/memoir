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
                send_transactional_mail($settings, $user['email'], 'Reset your Memoir password', $body);
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

render('pages/auth/forgot.tpl', [
    'csrf'  => csrf_token(),
    'sent'  => $sent,
    'error' => $error,
]);
