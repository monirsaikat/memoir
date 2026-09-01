<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$configPath = $base . '/config.php';
$lockPath = $base . '/storage/installed.lock';
$version = trim((string) @file_get_contents($base . '/VERSION')) ?: '1.0.0';

if (is_file($configPath)) {
    header('Location: ../login.php');
    exit;
}

ini_set('session.use_strict_mode', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
header_remove('X-Powered-By');

function installer_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installer_value(string $key, string $default = ''): string
{
    return installer_h($_POST[$key] ?? $default);
}

function installer_default_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/install/index.php');
    $path = rtrim(dirname(dirname($script)), '/.');
    return ($https ? 'https' : 'http') . '://' . ($host ?: 'localhost') . ($path ? '/' . ltrim($path, '/') : '');
}

$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'Fileinfo extension' => extension_loaded('fileinfo'),
    'Mbstring extension' => extension_loaded('mbstring'),
    'DOM extension' => extension_loaded('dom'),
    'storage is writable' => is_dir($base . '/storage') && is_writable($base . '/storage'),
    'uploads is writable' => is_dir($base . '/uploads') && is_writable($base . '/uploads'),
    'application root is writable' => is_writable($base),
];
$requirementsMet = !in_array(false, $checks, true);
$errors = [];
$notice = is_file($lockPath) && !is_file($configPath)
    ? 'A leftover install lock was found without a configuration file. You can safely complete setup again.'
    : '';

if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_csrf'] ?? '');
    if (!$token || !hash_equals((string) $_SESSION['installer_csrf'], $token)) {
        $errors[] = 'Your installer session expired. Refresh the page and try again.';
    }
    if (!$requirementsMet) {
        $errors[] = 'Resolve the failed server checks before installing Memoir.';
    }

    $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $port = filter_var($_POST['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $name = trim((string) ($_POST['db_name'] ?? 'memoir'));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
    $appName = trim((string) ($_POST['app_name'] ?? 'Memoir')) ?: 'Memoir';
    $timezone = trim((string) ($_POST['timezone'] ?? 'UTC')) ?: 'UTC';
    $adminName = trim((string) ($_POST['admin_name'] ?? 'Owner')) ?: 'Owner';
    $adminEmail = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $smtpHost = trim((string) ($_POST['smtp_host'] ?? ''));
    $smtpPort = filter_var($_POST['smtp_port'] ?? 587, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $smtpSecurity = (string) ($_POST['smtp_security'] ?? 'tls');
    $smtpUser = trim((string) ($_POST['smtp_user'] ?? ''));
    $smtpPass = (string) ($_POST['smtp_pass'] ?? '');
    $smtpFrom = trim((string) ($_POST['smtp_from'] ?? $adminEmail));

    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)) $errors[] = 'Enter a valid database host.';
    if ($port === false) $errors[] = 'Database port must be between 1 and 65535.';
    if (!preg_match('/^[A-Za-z0-9_$-]+$/', $name)) $errors[] = 'Database name contains unsupported characters.';
    if ($dbUser === '') $errors[] = 'Database user is required.';
    if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)) $errors[] = 'Enter a complete HTTP or HTTPS application URL.';
    if (mb_strlen($appName) > 120) $errors[] = 'App name must be 120 characters or fewer.';
    if (!in_array($timezone, timezone_identifiers_list(), true)) $errors[] = 'Enter a valid PHP timezone, such as UTC or Asia/Dhaka.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid owner email address.';
    if (strlen($adminPass) < 12) $errors[] = 'Owner password must contain at least 12 characters.';
    if ($smtpPort === false) $errors[] = 'SMTP port must be between 1 and 65535.';
    if (!in_array($smtpSecurity, ['tls', 'ssl', 'none'], true)) $errors[] = 'Choose a valid SMTP security option.';
    if ($smtpFrom !== '' && !filter_var($smtpFrom, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid SMTP from address.';

    if (!$errors) {
        $createdConfig = false;
        $createdLock = false;
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ];
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $dbUser, $dbPass, $options);
            try {
                $quotedDb = '`' . str_replace('`', '``', $name) . '`';
                $server->exec("CREATE DATABASE IF NOT EXISTS {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable) {
                // Shared hosts usually require the database to be created in cPanel first.
            }

            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $dbUser, $dbPass, $options);
            $schema = [
                "CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, reset_token VARCHAR(64) NULL, reset_expires DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS folders (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, icon VARCHAR(80) NOT NULL DEFAULT 'fa-folder', color VARCHAR(20) NOT NULL DEFAULT '#8B7CF6', sort_order INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS notes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, folder_id INT UNSIGNED NULL, title VARCHAR(255) NOT NULL DEFAULT 'Untitled note', content LONGTEXT NOT NULL, color VARCHAR(20) NOT NULL DEFAULT '#FFFFFF', tags VARCHAR(500) NOT NULL DEFAULT '', icon VARCHAR(80) NOT NULL DEFAULT 'fa-note-sticky', is_pinned TINYINT(1) NOT NULL DEFAULT 0, deleted_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(folder_id), INDEX(updated_at), INDEX(deleted_at), FULLTEXT ft_search (title, content, tags), CONSTRAINT fk_notes_folder FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1, app_name VARCHAR(120) NOT NULL DEFAULT 'Memoir', smtp_host VARCHAR(190) NULL, smtp_port INT NOT NULL DEFAULT 587, smtp_security VARCHAR(20) NOT NULL DEFAULT 'tls', smtp_user VARCHAR(190) NULL, smtp_pass TEXT NULL, smtp_from VARCHAR(190) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ];
            foreach ($schema as $sql) $pdo->exec($sql);

            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                throw new RuntimeException('This database already contains a Memoir owner. Use a new database or restore its existing config.php.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)')->execute([$adminName, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
            $pdo->prepare('INSERT INTO settings(id,app_name,smtp_host,smtp_port,smtp_security,smtp_user,smtp_pass,smtp_from) VALUES(1,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE app_name=VALUES(app_name), smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port), smtp_security=VALUES(smtp_security), smtp_user=VALUES(smtp_user), smtp_pass=VALUES(smtp_pass), smtp_from=VALUES(smtp_from)')->execute([$appName, $smtpHost ?: null, (int) $smtpPort, $smtpSecurity, $smtpUser ?: null, $smtpPass ?: null, $smtpFrom ?: null]);
            $pdo->exec("INSERT INTO folders(name,icon,color,sort_order) VALUES ('Personal','fa-user','#7C6CF3',1),('Ideas','fa-lightbulb','#E7A93D',2),('Work','fa-briefcase','#4E9A7C',3)");
            $config = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'app' => ['name' => $appName, 'url' => $appUrl, 'timezone' => $timezone],
                'db' => ['host' => $host, 'port' => (string) $port, 'name' => $name, 'user' => $dbUser, 'pass' => $dbPass, 'charset' => 'utf8mb4'],
            ], true) . ";\n";
            $tempConfig = $base . '/config.php.tmp-' . bin2hex(random_bytes(6));
            if (file_put_contents($tempConfig, $config, LOCK_EX) === false || !rename($tempConfig, $configPath)) {
                @unlink($tempConfig);
                throw new RuntimeException('Could not create config.php. Check the application folder permissions.');
            }
            $createdConfig = true;
            @chmod($configPath, 0640);
            if (file_put_contents($lockPath, date(DATE_ATOM) . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Could not create the installer lock in storage/.');
            }
            $createdLock = true;
            $pdo->commit();
            unset($_SESSION['installer_csrf']);
            header('Location: ../login.php?installed=1');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if ($createdConfig) @unlink($configPath);
            if ($createdLock) @unlink($lockPath);
            $errors[] = 'Installation failed: ' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>Install Memoir</title>
<link rel="icon" type="image/png" href="../assets/img/favicon.png">
<style>
:root {
    --bg: #f3f0e9;
    --card: #fff;
    --ink: #20221e;
    --muted: #72756d;
    --line: #dfdbd1;
    --accent: #6f4cf5;
    --good: #237653;
    --bad: #a63f42;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    min-height: 100vh;
    background: radial-gradient(circle at 50% -12rem, #fff 0, transparent 42rem), var(--bg);
    color: var(--ink);
    font: 14px/1.5 Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.shell {
    width: min(100% - 32px, 960px);
    margin: 42px auto;
}

/* Brand row above the card */
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0 0 22px;
}
.brand img { width: 46px; height: 46px; object-fit: contain; }
.brand strong { display: block; font-size: 17px; }
.brand span { color: var(--muted); font-size: 12px; }

.card {
    overflow: hidden;
    background: rgba(255, 255, 255, .96);
    border: 1px solid var(--line);
    border-radius: 24px;
    box-shadow: 0 24px 70px rgba(42, 35, 20, .08);
}

/* Intro header */
.intro {
    padding: 30px 32px 24px;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    gap: 20px;
}
.eyebrow {
    text-transform: uppercase;
    letter-spacing: .13em;
    color: var(--accent);
    font-weight: 800;
    font-size: 10px;
}
.intro h1 {
    margin: 5px 0 7px;
    font-size: 30px;
    line-height: 1.15;
    letter-spacing: -.045em;
}
.intro p {
    margin: 0;
    color: var(--muted);
    max-width: 570px;
}
.version {
    align-self: flex-start;
    border: 1px solid var(--line);
    border-radius: 999px;
    padding: 6px 10px;
    color: var(--muted);
    font-size: 11px;
    white-space: nowrap;
}

/* Two-column body: server checks + form */
.body {
    display: grid;
    grid-template-columns: 250px minmax(0, 1fr);
}
.checks {
    background: #faf9f6;
    border-right: 1px solid var(--line);
    padding: 25px 22px;
}
.checks h2,
.form h2 {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .09em;
    margin: 0 0 14px;
}
.check {
    display: flex;
    gap: 9px;
    align-items: flex-start;
    padding: 8px 0;
    color: var(--muted);
    font-size: 12px;
}
.check b {
    width: 19px;
    height: 19px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    font-size: 11px;
    flex: 0 0 auto;
}
.check.ok b { background: #e6f5ed; color: var(--good); }
.check.bad { color: var(--bad); }
.check.bad b { background: #fdebec; }
.checks small {
    display: block;
    color: var(--muted);
    margin-top: 18px;
}

/* Form fields */
.form { padding: 28px 32px 32px; }
.section { margin-bottom: 25px; }
.grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}
.full { grid-column: 1 / -1; }

label {
    display: block;
    font-size: 11px;
    font-weight: 750;
    margin: 0 0 6px;
}
input,
select {
    width: 100%;
    height: 43px;
    border: 1px solid var(--line);
    border-radius: 11px;
    padding: 0 12px;
    background: #fff;
    color: var(--ink);
    font: inherit;
    outline: 0;
}
input:focus,
select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(111, 76, 245, .1);
}
.hint {
    display: block;
    color: var(--muted);
    font-size: 10px;
    margin-top: 5px;
}

/* Alerts */
.alert {
    border-radius: 11px;
    padding: 11px 13px;
    margin-bottom: 16px;
    font-size: 12px;
}
.alert.error { background: #fff0f0; color: #8e3437; border: 1px solid #f0c7c7; }
.alert.notice { background: #fff8dd; color: #785f14; border: 1px solid #eadb9b; }

/* Submit row */
.submit {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding-top: 4px;
}
.submit span { color: var(--muted); font-size: 11px; }
.btn {
    border: 0;
    border-radius: 11px;
    background: #22241f;
    color: #fff;
    padding: 12px 18px;
    font-weight: 750;
}
.btn:disabled { opacity: .45; cursor: not-allowed; }

@media (max-width: 760px) {
    .shell { margin: 20px auto; }
    .intro { padding: 24px; }
    .body { grid-template-columns: 1fr; }
    .checks { border-right: 0; border-bottom: 1px solid var(--line); }
    .form { padding: 24px; }
    .grid { grid-template-columns: 1fr; }
    .full { grid-column: auto; }
    .version { display: none; }
}
</style>
</head>
<body>

<main class="shell">
    <div class="brand">
        <img src="../assets/img/memoir-logo.png" alt="Memoir logo">
        <div>
            <strong>Memoir</strong>
            <span>Private notes on your own server</span>
        </div>
    </div>

    <section class="card">
        <header class="intro">
            <div>
                <div class="eyebrow">Quick setup</div>
                <h1>Make this space yours.</h1>
                <p>Connect a database, create the owner account, and Memoir will handle the tables and configuration.</p>
            </div>
            <span class="version">Version <?= installer_h($version) ?></span>
        </header>

        <div class="body">
            <aside class="checks">
                <h2>Server checks</h2>
                <?php foreach ($checks as $label => $ok): ?>
                <div class="check <?= $ok ? 'ok' : 'bad' ?>">
                    <b><?= $ok ? '✓' : '!' ?></b>
                    <span><?= installer_h($label) ?></span>
                </div>
                <?php endforeach ?>
                <small>On cPanel, enable missing PHP extensions in Select PHP Version. Use 755 for folders in most setups.</small>
            </aside>

            <div class="form">
                <?php if ($notice): ?>
                <div class="alert notice"><?= installer_h($notice) ?></div>
                <?php endif ?>

                <?php foreach ($errors as $error): ?>
                <div class="alert error"><?= installer_h($error) ?></div>
                <?php endforeach ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= installer_h($_SESSION['installer_csrf']) ?>">

                    <div class="section">
                        <h2>1 · Database</h2>
                        <div class="grid">
                            <div>
                                <label for="db_host">Host</label>
                                <input id="db_host" name="db_host" value="<?= installer_value('db_host', 'localhost') ?>" required>
                            </div>
                            <div>
                                <label for="db_port">Port</label>
                                <input id="db_port" name="db_port" inputmode="numeric" value="<?= installer_value('db_port', '3306') ?>" required>
                            </div>
                            <div>
                                <label for="db_name">Database name</label>
                                <input id="db_name" name="db_name" value="<?= installer_value('db_name', 'memoir') ?>" required>
                            </div>
                            <div>
                                <label for="db_user">Database user</label>
                                <input id="db_user" name="db_user" value="<?= installer_value('db_user') ?>" required>
                            </div>
                            <div class="full">
                                <label for="db_pass">Database password</label>
                                <input id="db_pass" type="password" name="db_pass" autocomplete="new-password">
                                <span class="hint">Use the complete cPanel-prefixed database and username.</span>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2>2 · Site</h2>
                        <div class="grid">
                            <div>
                                <label for="app_name">App name</label>
                                <input id="app_name" name="app_name" maxlength="120" value="<?= installer_value('app_name', 'Memoir') ?>">
                            </div>
                            <div>
                                <label for="timezone">Timezone</label>
                                <input id="timezone" name="timezone" value="<?= installer_value('timezone', 'UTC') ?>">
                            </div>
                            <div class="full">
                                <label for="app_url">Application URL</label>
                                <input id="app_url" type="url" name="app_url" value="<?= installer_value('app_url', installer_default_url()) ?>" required>
                                <span class="hint">Do not include /install at the end.</span>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2>3 · Owner account</h2>
                        <div class="grid">
                            <div>
                                <label for="admin_name">Your name</label>
                                <input id="admin_name" name="admin_name" maxlength="120" value="<?= installer_value('admin_name') ?>">
                            </div>
                            <div>
                                <label for="admin_email">Email</label>
                                <input id="admin_email" type="email" name="admin_email" autocomplete="username" value="<?= installer_value('admin_email') ?>" required>
                            </div>
                            <div class="full">
                                <label for="admin_pass">Password</label>
                                <input id="admin_pass" type="password" name="admin_pass" minlength="12" autocomplete="new-password" required>
                                <span class="hint">Use at least 12 characters and a password you do not reuse elsewhere.</span>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2>4 · Email (optional)</h2>
                        <div class="grid">
                            <div>
                                <label for="smtp_host">SMTP host</label>
                                <input id="smtp_host" name="smtp_host" value="<?= installer_value('smtp_host') ?>" placeholder="mail.example.com">
                            </div>
                            <div>
                                <label for="smtp_port">SMTP port</label>
                                <input id="smtp_port" name="smtp_port" inputmode="numeric" value="<?= installer_value('smtp_port', '587') ?>">
                            </div>
                            <div>
                                <label for="smtp_security">Security</label>
                                <select id="smtp_security" name="smtp_security">
                                    <option value="tls">TLS</option>
                                    <option value="ssl">SSL</option>
                                    <option value="none">None</option>
                                </select>
                            </div>
                            <div>
                                <label for="smtp_user">SMTP username</label>
                                <input id="smtp_user" name="smtp_user" value="<?= installer_value('smtp_user') ?>">
                            </div>
                            <div>
                                <label for="smtp_pass">SMTP password</label>
                                <input id="smtp_pass" type="password" name="smtp_pass" autocomplete="new-password">
                            </div>
                            <div>
                                <label for="smtp_from">From email</label>
                                <input id="smtp_from" type="email" name="smtp_from" value="<?= installer_value('smtp_from') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="submit">
                        <span>No data leaves your server during setup.</span>
                        <button class="btn" type="submit" <?= $requirementsMet ? '' : 'disabled' ?>>Install Memoir →</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>

</body>
</html>
