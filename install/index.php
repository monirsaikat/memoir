<?php
declare(strict_types=1);
$base = dirname(__DIR__);
$lock = $base . '/storage/installed.lock';
if (file_exists($lock)) { http_response_code(403); exit('Memoir is already installed.'); }
$errors = [];
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$checks = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'Fileinfo' => extension_loaded('fileinfo'),
    'Mbstring' => extension_loaded('mbstring'),
    'storage writable' => is_writable($base . '/storage'),
    'uploads writable' => is_writable($base . '/uploads'),
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $appName = trim($_POST['app_name'] ?? 'Memoir') ?: 'Memoir';
    $timezone = trim($_POST['timezone'] ?? 'UTC') ?: 'UTC';
    $adminName = trim($_POST['admin_name'] ?? '') ?: 'Admin';
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPass = $_POST['admin_pass'] ?? '';
    $smtpHost = trim($_POST['smtp_host'] ?? '');
    $smtpPort = trim($_POST['smtp_port'] ?? '587');
    $smtpSecurity = trim($_POST['smtp_security'] ?? 'tls');
    $smtpUser = trim($_POST['smtp_user'] ?? '');
    $smtpPass = $_POST['smtp_pass'] ?? '';
    $smtpFrom = trim($_POST['smtp_from'] ?? '');
    if (!$user || !$name || !$adminEmail || strlen($adminPass) < 8 || !$appUrl) $errors[] = 'Database name/user, app URL, admin email and a password of at least 8 characters are required.';
    if (!$errors) {
        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
            try { $server->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`','``',$name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $ignored) {}
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
            foreach ([
                "CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS folders (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,icon VARCHAR(80) NOT NULL DEFAULT 'fa-folder',color VARCHAR(20) NOT NULL DEFAULT '#8B7CF6',sort_order INT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS notes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,folder_id INT UNSIGNED NULL,title VARCHAR(255) NOT NULL DEFAULT 'Untitled note',content LONGTEXT NOT NULL,color VARCHAR(20) NOT NULL DEFAULT '#FFFFFF',icon VARCHAR(80) NOT NULL DEFAULT 'fa-note-sticky',is_pinned TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(folder_id),INDEX(updated_at),CONSTRAINT fk_notes_folder FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,app_name VARCHAR(120) NOT NULL DEFAULT 'Memoir',smtp_host VARCHAR(190) NULL,smtp_port INT NOT NULL DEFAULT 587,smtp_security VARCHAR(20) NOT NULL DEFAULT 'tls',smtp_user VARCHAR(190) NULL,smtp_pass TEXT NULL,smtp_from VARCHAR(190) NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ] as $sql) $pdo->exec($sql);
            $pdo->prepare("INSERT INTO users(name,email,password) VALUES(?,?,?)")->execute([$adminName,$adminEmail,password_hash($adminPass,PASSWORD_DEFAULT)]);
            $pdo->prepare("INSERT INTO settings(id,app_name,smtp_host,smtp_port,smtp_security,smtp_user,smtp_pass,smtp_from) VALUES(1,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE app_name=VALUES(app_name),smtp_host=VALUES(smtp_host),smtp_port=VALUES(smtp_port),smtp_security=VALUES(smtp_security),smtp_user=VALUES(smtp_user),smtp_pass=VALUES(smtp_pass),smtp_from=VALUES(smtp_from)")->execute([$appName,$smtpHost?:null,(int)$smtpPort,$smtpSecurity,$smtpUser?:null,$smtpPass?:null,$smtpFrom?:null]);
            $config = "<?php\nreturn " . var_export(['app'=>['name'=>$appName,'url'=>$appUrl,'timezone'=>$timezone],'db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4']], true) . ";\n";
            if (file_put_contents($base . '/config.php', $config) === false) throw new RuntimeException('Could not write config.php');
            file_put_contents($lock, date('c'));
            header('Location: ../login.php?installed=1'); exit;
        } catch (Throwable $e) { $errors[] = 'Installation failed: ' . $e->getMessage(); }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Memoir</title><link rel="icon" type="image/png" href="../assets/img/favicon.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"><style>
:root{--bg:#f4f1ea;--card:#fff;--ink:#1f211e;--muted:#777970;--line:#ded9cf;--accent:#6f5ee8}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:"DM Sans",sans-serif;color:var(--ink);padding:28px}.shell{width:min(100%,760px);margin:auto}.brand{display:flex;align-items:center;gap:10px;margin-bottom:18px}.brand img{width:38px;height:38px;border-radius:11px}.brand b{font-size:16px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:24px}.hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:18px}.hero h1{margin:0;font-size:24px;letter-spacing:-.04em}.hero p{margin:5px 0 0;color:var(--muted);font-size:13px}.checks{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:18px}.check{font-size:11px;padding:6px 8px;border:1px solid var(--line);border-radius:999px;background:#faf9f6}.ok{color:#27734d}.bad{color:#a24242}.error{background:#fff2f2;border:1px solid #efcaca;color:#943d3d;border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:12px}.section{border-top:1px solid #eeeae3;padding-top:18px;margin-top:18px}.section:first-of-type{border-top:0;padding-top:0;margin-top:0}.section h2{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#8b8d85;margin:0 0 12px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}label{font-size:11px;font-weight:700;display:block;margin-bottom:6px}input,select{width:100%;height:40px;padding:0 11px;border:1px solid var(--line);border-radius:9px;background:white;font:inherit;outline:none}input:focus,select:focus{border-color:#bbb4ec;box-shadow:0 0 0 3px rgba(111,94,232,.08)}.actions{display:flex;justify-content:flex-end;margin-top:20px}.btn{background:#22241f;color:white;border:0;border-radius:10px;padding:11px 15px;font-weight:700;cursor:pointer}.hint{font-size:11px;color:#999b94;margin-top:5px}@media(max-width:650px){body{padding:14px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.card{padding:18px}}
</style></head><body><div class="shell"><div class="brand"><img src="../assets/img/memoir-logo.png" alt="Memoir"><div><b>Memoir</b><div style="font-size:11px;color:#8b8d85">Self-hosted personal notes</div></div></div><div class="card"><div class="hero"><div><h1>Install Memoir</h1><p>Connect your database, create the owner account, and finish.</p></div></div><div class="checks"><?php foreach($checks as $label=>$ok): ?><span class="check <?=$ok?'ok':'bad'?>"><?=$ok?'✓':'×'?> <?=h($label)?></span><?php endforeach ?></div><?php foreach($errors as $err): ?><div class="error"><?=h($err)?></div><?php endforeach ?><form method="post"><section class="section"><h2>Database</h2><div class="grid"><div><label>Host</label><input name="db_host" value="<?=h($_POST['db_host']??'localhost')?>" required></div><div><label>Port</label><input name="db_port" value="<?=h($_POST['db_port']??'3306')?>" required></div><div><label>Database name</label><input name="db_name" value="<?=h($_POST['db_name']??'')?>" placeholder="yourcpanel_memoir" required></div><div><label>Database user</label><input name="db_user" value="<?=h($_POST['db_user']??'')?>" placeholder="yourcpanel_user" required></div><div class="full"><label>Database password</label><input type="password" name="db_pass"></div></div></section><section class="section"><h2>Application</h2><div class="grid"><div><label>App name</label><input name="app_name" value="<?=h($_POST['app_name']??'Memoir')?>"></div><div><label>Timezone</label><input name="timezone" value="<?=h($_POST['timezone']??'UTC')?>"></div><div class="full"><label>App URL</label><input name="app_url" placeholder="https://example.com/memoir" value="<?=h($_POST['app_url']??'')?>" required><div class="hint">Nothing personal is pre-filled.</div></div></div></section><section class="section"><h2>Owner account</h2><div class="grid"><div><label>Your name</label><input name="admin_name" value="<?=h($_POST['admin_name']??'')?>" required></div><div><label>Login email</label><input type="email" name="admin_email" value="<?=h($_POST['admin_email']??'')?>" required></div><div class="full"><label>Password</label><input type="password" name="admin_pass" minlength="8" required></div></div></section><section class="section"><h2>Mail — optional</h2><div class="grid"><div><label>SMTP host</label><input name="smtp_host" placeholder="mail.example.com"></div><div><label>SMTP port</label><input name="smtp_port" value="587"></div><div><label>Security</label><select name="smtp_security"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select></div><div><label>SMTP username</label><input name="smtp_user"></div><div><label>SMTP password</label><input type="password" name="smtp_pass"></div><div><label>From email</label><input type="email" name="smtp_from"></div></div></section><div class="actions"><button class="btn">Install Memoir</button></div></form></div></div></body></html>
