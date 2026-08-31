<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$lock = $base . '/storage/installed.lock';
if (file_exists($lock)) {
    http_response_code(403);
    exit('Memoir is already installed.');
}

$errors = [];
$step = 1;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'Fileinfo' => extension_loaded('fileinfo'),
    'Mbstring' => extension_loaded('mbstring'),
    'storage/ writable' => is_writable($base . '/storage'),
    'uploads/ writable' => is_writable($base . '/uploads'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = 2;
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? 'memoir');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $appName = trim($_POST['app_name'] ?? 'Memoir') ?: 'Memoir';
    $timezone = trim($_POST['timezone'] ?? 'UTC') ?: 'UTC';
    $adminName = trim($_POST['admin_name'] ?? 'Saikat') ?: 'Saikat';
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPass = $_POST['admin_pass'] ?? '';
    $smtpHost = trim($_POST['smtp_host'] ?? '');
    $smtpPort = trim($_POST['smtp_port'] ?? '587');
    $smtpSecurity = trim($_POST['smtp_security'] ?? 'tls');
    $smtpUser = trim($_POST['smtp_user'] ?? '');
    $smtpPass = $_POST['smtp_pass'] ?? '';
    $smtpFrom = trim($_POST['smtp_from'] ?? $adminEmail);

    if (!$user || !$name || !$adminEmail || strlen($adminPass) < 8 || !$appUrl) {
        $errors[] = 'Database name/user, app URL, admin email and a password of at least 8 characters are required.';
    }

    if (!$errors) {
        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            try {
                $server->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`','``',$name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable $ignored) {
                // Shared hosting commonly blocks CREATE DATABASE; connecting below will confirm whether it already exists.
            }

            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $schema = [
                "CREATE TABLE IF NOT EXISTS users (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    email VARCHAR(190) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS folders (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    icon VARCHAR(80) NOT NULL DEFAULT 'fa-folder',
                    color VARCHAR(20) NOT NULL DEFAULT '#8B7CF6',
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS notes (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder_id INT UNSIGNED NULL,
                    title VARCHAR(255) NOT NULL DEFAULT 'Untitled note',
                    content LONGTEXT NOT NULL,
                    color VARCHAR(20) NOT NULL DEFAULT '#FFFFFF',
                    icon VARCHAR(80) NOT NULL DEFAULT 'fa-note-sticky',
                    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX(folder_id),
                    INDEX(updated_at),
                    CONSTRAINT fk_notes_folder FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS settings (
                    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                    app_name VARCHAR(120) NOT NULL DEFAULT 'Memoir',
                    smtp_host VARCHAR(190) NULL,
                    smtp_port INT NOT NULL DEFAULT 587,
                    smtp_security VARCHAR(20) NOT NULL DEFAULT 'tls',
                    smtp_user VARCHAR(190) NULL,
                    smtp_pass TEXT NULL,
                    smtp_from VARCHAR(190) NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];
            foreach ($schema as $sql) $pdo->exec($sql);

            $pdo->prepare("INSERT INTO users(name,email,password) VALUES(?,?,?)")
                ->execute([$adminName, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
            $pdo->prepare("INSERT INTO settings(id,app_name,smtp_host,smtp_port,smtp_security,smtp_user,smtp_pass,smtp_from)
                VALUES(1,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE app_name=VALUES(app_name), smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port), smtp_security=VALUES(smtp_security), smtp_user=VALUES(smtp_user), smtp_pass=VALUES(smtp_pass), smtp_from=VALUES(smtp_from)")
                ->execute([$appName, $smtpHost ?: null, (int)$smtpPort, $smtpSecurity, $smtpUser ?: null, $smtpPass ?: null, $smtpFrom ?: null]);

            $pdo->exec("INSERT INTO folders(name,icon,color,sort_order) VALUES
                ('Personal','fa-user','#7C6CF3',1),
                ('Ideas','fa-lightbulb','#E7A93D',2),
                ('Work','fa-briefcase','#4E9A7C',3)");

            $config = "<?php\nreturn " . var_export([
                'app' => ['name'=>$appName,'url'=>$appUrl,'timezone'=>$timezone],
                'db' => ['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4']
            ], true) . ";\n";
            if (file_put_contents($base . '/config.php', $config) === false) {
                throw new RuntimeException('Could not write config.php');
            }
            file_put_contents($lock, date('c'));
            header('Location: ../login.php?installed=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install Memoir</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f4f1ea;--card:#fff;--ink:#1f211e;--muted:#74756e;--line:#ddd9cf;--accent:#6f5ee8}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#fff 0,transparent 34rem),var(--bg);font-family:"DM Sans",sans-serif;color:var(--ink)}
.wrap{width:min(100% - 32px,860px);margin:52px auto}.brand{display:flex;gap:12px;align-items:center;margin-bottom:24px}.mark{width:42px;height:42px;border:1px solid var(--line);border-radius:13px;display:grid;place-items:center;background:#fff;font-weight:800;color:var(--accent)}
.card{background:rgba(255,255,255,.92);border:1px solid var(--line);border-radius:24px;padding:28px;box-shadow:0 20px 60px rgba(40,34,20,.07)}
h1{margin:0 0 8px;font-size:28px;letter-spacing:-.04em}.sub{color:var(--muted);margin:0 0 26px}.checks{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:24px}.check{padding:12px;border:1px solid var(--line);border-radius:12px;font-size:13px;background:#faf9f6}.ok{color:#287a52}.bad{color:#a34141}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}label{font-size:12px;font-weight:700;display:block;margin-bottom:7px}input,select{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:11px;background:#fff;font:inherit;outline:none}input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(111,94,232,.1)}
.sep{grid-column:1/-1;border-top:1px solid var(--line);margin:8px 0}.btn{margin-top:22px;background:#1f211e;color:#fff;border:0;border-radius:12px;padding:13px 18px;font-weight:700;cursor:pointer}.error{background:#fff0f0;border:1px solid #f0c5c5;padding:12px 14px;border-radius:12px;margin-bottom:16px;color:#8d3030}
@media(max-width:700px){.grid,.checks{grid-template-columns:1fr}.full,.sep{grid-column:auto}.wrap{margin:22px auto}.card{padding:20px}}
</style></head>
<body><div class="wrap">
<div class="brand"><div class="mark">M</div><div><strong>Memoir</strong><div style="color:#777;font-size:13px">Personal notes, on your server.</div></div></div>
<div class="card"><h1>Install Memoir</h1><p class="sub">A few details and your private note space will be ready.</p>
<div class="checks"><?php foreach($checks as $label=>$ok): ?><div class="check <?= $ok?'ok':'bad' ?>"><?= $ok?'✓':'×' ?> <?=h($label)?></div><?php endforeach ?></div>
<?php foreach($errors as $err): ?><div class="error"><?=h($err)?></div><?php endforeach ?>
<form method="post"><div class="grid">
<div><label>Database host</label><input name="db_host" value="<?=h($_POST['db_host']??'localhost')?>" required></div>
<div><label>Database port</label><input name="db_port" value="<?=h($_POST['db_port']??'3306')?>" required></div>
<div><label>Database name</label><input name="db_name" value="<?=h($_POST['db_name']??'memoir')?>" required></div>
<div><label>Database user</label><input name="db_user" value="<?=h($_POST['db_user']??'')?>" required></div>
<div class="full"><label>Database password</label><input type="password" name="db_pass"></div>
<div class="sep"></div>
<div><label>App name</label><input name="app_name" value="<?=h($_POST['app_name']??'Memoir')?>"></div>
<div><label>Timezone</label><input name="timezone" value="<?=h($_POST['timezone']??'Asia/Dhaka')?>"></div>
<div class="full"><label>App URL</label><input name="app_url" placeholder="https://saikat.cyou/notes" value="<?=h($_POST['app_url']??'')?>"></div>
<div class="sep"></div>
<div><label>Your name</label><input name="admin_name" value="<?=h($_POST['admin_name']??'')?>"></div>
<div><label>Login email</label><input type="email" name="admin_email" value="<?=h($_POST['admin_email']??'')?>" required></div>
<div class="full"><label>Login password</label><input type="password" name="admin_pass" minlength="8" required></div>
<div class="sep"></div>
<div><label>SMTP host (optional)</label><input name="smtp_host" placeholder="mail.example.com"></div>
<div><label>SMTP port</label><input name="smtp_port" value="587"></div>
<div><label>SMTP security</label><select name="smtp_security"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select></div>
<div><label>SMTP username</label><input name="smtp_user"></div>
<div><label>SMTP password</label><input type="password" name="smtp_pass"></div>
<div><label>From email</label><input type="email" name="smtp_from"></div>
</div><button class="btn">Install Memoir →</button></form></div></div></body></html>
