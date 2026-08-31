<?php
require __DIR__ . '/bootstrap.php';
if (auth_user()) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass = $_POST['password'] ?? '';
    $stmt = db()->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($pass, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        header('Location: index.php'); exit;
    }
    $error = 'Email or password is incorrect.';
}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — Memoir</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css"></head>
<body class="auth-page"><main class="auth-card">
<div class="auth-mark">M</div><h1>Welcome to Memoir</h1><p>Your notes, quietly kept on your own server.</p>
<?php if(isset($_GET['installed'])): ?><div class="notice success">Installation complete. Sign in to continue.</div><?php endif ?>
<?php if($error): ?><div class="notice error"><?=e($error)?></div><?php endif ?>
<form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>">
<label>Email</label><input type="email" name="email" autocomplete="username" required>
<label>Password</label><input type="password" name="password" autocomplete="current-password" required>
<button class="primary-btn" type="submit">Sign in</button></form>
<div class="auth-foot">Memoir · Self-hosted personal notes</div>
</main></body></html>
