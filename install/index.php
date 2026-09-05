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

require dirname(__DIR__) . '/app/view.php';

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
                "CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password VARCHAR(255) NOT NULL, role ENUM('owner','collaborator') NOT NULL DEFAULT 'owner', reset_token VARCHAR(64) NULL, reset_expires DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS folders (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, icon VARCHAR(80) NOT NULL DEFAULT 'fa-folder', color VARCHAR(20) NOT NULL DEFAULT '#8B7CF6', sort_order INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS notes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, folder_id INT UNSIGNED NULL, owner_id INT UNSIGNED NULL, title VARCHAR(255) NOT NULL DEFAULT 'Untitled note', content LONGTEXT NOT NULL, color VARCHAR(20) NOT NULL DEFAULT '#FFFFFF', tags VARCHAR(500) NOT NULL DEFAULT '', icon VARCHAR(80) NOT NULL DEFAULT 'fa-note-sticky', is_pinned TINYINT(1) NOT NULL DEFAULT 0, deleted_at DATETIME NULL, share_token VARCHAR(64) NULL, UNIQUE KEY uq_share (share_token), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX(folder_id), INDEX(owner_id), INDEX(updated_at), INDEX(deleted_at), FULLTEXT ft_search (title, content, tags), CONSTRAINT fk_notes_folder FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE SET NULL, CONSTRAINT fk_notes_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS note_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note_id INT UNSIGNED NOT NULL, folder_id INT UNSIGNED NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, color VARCHAR(20) NOT NULL DEFAULT '#FFFFFF', tags VARCHAR(500) NOT NULL DEFAULT '', icon VARCHAR(80) NOT NULL DEFAULT 'fa-note-sticky', is_pinned TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(20) NOT NULL DEFAULT 'autosave', snapshot_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_version_note (note_id, created_at), CONSTRAINT fk_versions_note FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS note_collaborators (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note_id INT UNSIGNED NOT NULL, invited_email VARCHAR(190) NOT NULL, user_id INT UNSIGNED NULL, role VARCHAR(20) NOT NULL DEFAULT 'editor', status ENUM('pending','accepted','revoked') NOT NULL DEFAULT 'pending', invite_token_hash CHAR(64) NULL, invite_expires DATETIME NULL, invited_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, accepted_at DATETIME NULL, UNIQUE KEY uq_note_collaborator (note_id, invited_email), UNIQUE KEY uq_invite_token (invite_token_hash), INDEX idx_collaborator_user (user_id, status), CONSTRAINT fk_collaborators_note FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE, CONSTRAINT fk_collaborators_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS activity_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, note_id INT UNSIGNED NULL, actor_id INT UNSIGNED NULL, action VARCHAR(40) NOT NULL, message VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_activity_note (note_id, created_at), INDEX idx_activity_actor (actor_id, created_at), CONSTRAINT fk_activity_note FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE, CONSTRAINT fk_activity_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS settings (id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1, app_name VARCHAR(120) NOT NULL DEFAULT 'Memoir', mail_provider ENUM('smtp','brevo') NOT NULL DEFAULT 'smtp', smtp_host VARCHAR(190) NULL, smtp_port INT NOT NULL DEFAULT 587, smtp_security VARCHAR(20) NOT NULL DEFAULT 'tls', smtp_user VARCHAR(190) NULL, smtp_pass TEXT NULL, smtp_from VARCHAR(190) NULL, brevo_api_key TEXT NULL, backup_enabled TINYINT(1) NOT NULL DEFAULT 1, backup_interval_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24, backup_keep SMALLINT UNSIGNED NOT NULL DEFAULT 7, backup_last_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
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

// Field values echoed back into the form: what was posted, or the installer defaults.
$form = [
    'db_host' => (string) ($_POST['db_host'] ?? 'localhost'),
    'db_port' => (string) ($_POST['db_port'] ?? '3306'),
    'db_name' => (string) ($_POST['db_name'] ?? 'memoir'),
    'db_user' => (string) ($_POST['db_user'] ?? ''),
    'app_name' => (string) ($_POST['app_name'] ?? 'Memoir'),
    'timezone' => (string) ($_POST['timezone'] ?? 'UTC'),
    'app_url' => (string) ($_POST['app_url'] ?? installer_default_url()),
    'admin_name' => (string) ($_POST['admin_name'] ?? ''),
    'admin_email' => (string) ($_POST['admin_email'] ?? ''),
    'smtp_host' => (string) ($_POST['smtp_host'] ?? ''),
    'smtp_port' => (string) ($_POST['smtp_port'] ?? '587'),
    'smtp_user' => (string) ($_POST['smtp_user'] ?? ''),
    'smtp_from' => (string) ($_POST['smtp_from'] ?? ''),
];

render('pages/install.tpl', [
    'version' => $version,
    'checks' => $checks,
    'requirementsMet' => $requirementsMet,
    'notice' => $notice,
    'errors' => $errors,
    'csrf' => $_SESSION['installer_csrf'],
    'form' => $form,
]);
