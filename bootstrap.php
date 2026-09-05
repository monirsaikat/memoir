<?php
declare(strict_types=1);

define('MEMOIR_VERSION', trim((string) @file_get_contents(__DIR__ . '/VERSION')) ?: '1.0.0');
define('MEMOIR_SCHEMA_VERSION', '2026-09-05-1');

// Template rendering (Smarty, vendored under lib/smarty) and its view helpers.
require_once __DIR__ . '/app/view.php';

ini_set('session.use_strict_mode', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; img-src 'self' data: blob: https:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
header_remove('X-Powered-By');

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/install') === false) {
        header('Location: install/');
        exit;
    }
    return;
}
$config = require $configFile;
$timezone = $config['app']['timezone'] ?? 'UTC';
date_default_timezone_set(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC');

function db(): PDO {
    static $pdo;
    global $config;
    if (!$pdo) {
        $d = $config['db'];
        $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(bool $json = true): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        header('Content-Type: ' . ($json ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8'));
        echo $json
            ? json_encode(['ok' => false, 'message' => 'Session expired. Refresh and try again.'])
            : 'Session expired. Go back, refresh the page, and try again.';
        exit;
    }
}

function auth_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare("SELECT id,email,name,role FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user) $user['role'] = $user['role'] ?: 'owner';
    return $user;
}

// The role a user holds on a specific note: 'owner', 'editor' (an accepted
// collaborator), or null when they have no access at all.
function note_role_for_user(int $noteId, int $userId): ?string {
    $stmt = db()->prepare("SELECT owner_id FROM notes WHERE id = ? LIMIT 1");
    $stmt->execute([$noteId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId === false) return null;
    if ((int) $ownerId === $userId) return 'owner';

    $stmt = db()->prepare(
        "SELECT role FROM note_collaborators WHERE note_id = ? AND user_id = ? AND status = 'accepted' LIMIT 1"
    );
    $stmt->execute([$noteId, $userId]);
    $role = $stmt->fetchColumn();
    return $role !== false ? (string) $role : null;
}

// 404s (rather than 403s) so a collaborator probing note IDs cannot tell
// "doesn't exist" apart from "exists, but you can't see it".
function require_note_access(int $noteId, int $userId, string $need = 'editor'): string {
    $role = note_role_for_user($noteId, $userId);
    if ($role === null || ($need === 'owner' && $role !== 'owner')) {
        json_response(['ok' => false, 'message' => 'Note not found'], 404);
    }
    return $role;
}

// SQL fragment restricting a notes query to what a user can see: notes they
// own, plus notes shared with them as an accepted collaborator. Expects the
// user id bound twice, in the order the two placeholders appear.
function accessible_notes_clause(string $alias = 'n'): string {
    return "($alias.owner_id = ? OR $alias.id IN (SELECT note_id FROM note_collaborators WHERE user_id = ? AND status = 'accepted'))";
}

function log_activity(int $actorId, ?int $noteId, string $action, string $message): void {
    db()->prepare(
        "INSERT INTO activity_log(note_id, actor_id, action, message) VALUES (?, ?, ?, ?)"
    )->execute([$noteId, $actorId, $action, mb_substr($message, 0, 255)]);
}

function require_auth(): array {
    $user = auth_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

// Lightweight in-place migrations for installs created before newer features.
function ensure_schema(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    // PHP processes are short lived on shared hosting, so the static guard only
    // helps within one request. Keep a tiny on-disk marker as well: without it
    // every page view performs a series of SHOW COLUMNS/INDEX queries and a
    // CREATE TABLE check before it can render.
    $marker = __DIR__ . '/storage/.schema-version';
    if (is_file($marker) && trim((string) @file_get_contents($marker)) === MEMOIR_SCHEMA_VERSION) {
        return;
    }

    try {
        if (!db()->query("SHOW COLUMNS FROM notes LIKE 'tags'")->fetch()) {
            db()->exec("ALTER TABLE notes ADD COLUMN tags VARCHAR(500) NOT NULL DEFAULT '' AFTER color");
        }
        if (!db()->query("SHOW COLUMNS FROM users LIKE 'reset_token'")->fetch()) {
            db()->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_expires DATETIME NULL");
        }
        if (!db()->query("SHOW COLUMNS FROM notes LIKE 'deleted_at'")->fetch()) {
            db()->exec("ALTER TABLE notes ADD COLUMN deleted_at DATETIME NULL, ADD INDEX(deleted_at)");
        }
        if (!db()->query("SHOW INDEX FROM notes WHERE Key_name = 'ft_search'")->fetch()) {
            db()->exec("ALTER TABLE notes ADD FULLTEXT ft_search (title, content, tags)");
        }
        if (!db()->query("SHOW COLUMNS FROM notes LIKE 'share_token'")->fetch()) {
            db()->exec("ALTER TABLE notes ADD COLUMN share_token VARCHAR(64) NULL, ADD UNIQUE INDEX uq_share (share_token)");
        }
        db()->exec(
            "CREATE TABLE IF NOT EXISTS note_versions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                note_id INT UNSIGNED NOT NULL,
                folder_id INT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                content LONGTEXT NOT NULL,
                color VARCHAR(20) NOT NULL DEFAULT '#FFFFFF',
                tags VARCHAR(500) NOT NULL DEFAULT '',
                icon VARCHAR(80) NOT NULL DEFAULT 'fa-note-sticky',
                is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(20) NOT NULL DEFAULT 'autosave',
                snapshot_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_version_note (note_id, created_at),
                CONSTRAINT fk_versions_note FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (!db()->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch()) {
            db()->exec("ALTER TABLE users ADD COLUMN role ENUM('owner','collaborator') NOT NULL DEFAULT 'owner'");
        }
        if (!db()->query("SHOW COLUMNS FROM notes LIKE 'owner_id'")->fetch()) {
            db()->exec("ALTER TABLE notes ADD COLUMN owner_id INT UNSIGNED NULL AFTER folder_id, ADD INDEX(owner_id)");
            db()->exec("UPDATE notes SET owner_id = (SELECT MIN(id) FROM users) WHERE owner_id IS NULL");
            db()->exec("ALTER TABLE notes ADD CONSTRAINT fk_notes_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE SET NULL");
        }
        db()->exec(
            "CREATE TABLE IF NOT EXISTS note_collaborators (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                note_id INT UNSIGNED NOT NULL,
                invited_email VARCHAR(190) NOT NULL,
                user_id INT UNSIGNED NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'editor',
                status ENUM('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
                invite_token_hash CHAR(64) NULL,
                invite_expires DATETIME NULL,
                invited_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                accepted_at DATETIME NULL,
                UNIQUE KEY uq_note_collaborator (note_id, invited_email),
                INDEX idx_collaborator_user (user_id, status),
                UNIQUE KEY uq_invite_token (invite_token_hash),
                CONSTRAINT fk_collaborators_note FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE,
                CONSTRAINT fk_collaborators_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS activity_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                note_id INT UNSIGNED NULL,
                actor_id INT UNSIGNED NULL,
                action VARCHAR(40) NOT NULL,
                message VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_note (note_id, created_at),
                INDEX idx_activity_actor (actor_id, created_at),
                CONSTRAINT fk_activity_note FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE,
                CONSTRAINT fk_activity_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $settingColumns = [
            'backup_enabled' => "TINYINT(1) NOT NULL DEFAULT 1",
            'backup_interval_hours' => "SMALLINT UNSIGNED NOT NULL DEFAULT 24",
            'backup_keep' => "SMALLINT UNSIGNED NOT NULL DEFAULT 7",
            'backup_last_at' => "DATETIME NULL",
        ];
        foreach ($settingColumns as $column => $definition) {
            if (!db()->query("SHOW COLUMNS FROM settings LIKE " . db()->quote($column))->fetch()) {
                db()->exec("ALTER TABLE settings ADD COLUMN $column $definition");
            }
        }
        // Trashed notes are purged for good after 30 days.
        db()->exec("DELETE FROM notes WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

        $temporary = $marker . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, MEMOIR_SCHEMA_VERSION . PHP_EOL, LOCK_EX) !== false) {
            @chmod($temporary, 0640);
            if (!@rename($temporary, $marker)) @unlink($temporary);
        }
    } catch (Throwable) {
        // Fresh installs get the columns from the installer schema.
    }
}

function valid_backup_datetime(mixed $value): ?string {
    if (!is_string($value) || $value === '') return null;
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    return $date && $date->format('Y-m-d H:i:s') === $value ? $value : null;
}

// A portable workspace backup intentionally excludes passwords and public
// share tokens. Those credentials must never travel inside an export file.
function workspace_backup_payload(): array {
    $settings = db()->query(
        "SELECT app_name, backup_enabled, backup_interval_hours, backup_keep FROM settings WHERE id = 1"
    )->fetch() ?: [];
    return [
        'format' => 'memoir-workspace',
        'schema_version' => 1,
        'exported_at' => gmdate('c'),
        'app_version' => MEMOIR_VERSION,
        'settings' => $settings,
        'folders' => db()->query(
            "SELECT id, name, icon, color, sort_order, created_at, updated_at FROM folders ORDER BY sort_order, id"
        )->fetchAll(),
        'notes' => db()->query(
            "SELECT id, folder_id, title, content, color, tags, icon, is_pinned, deleted_at, created_at, updated_at FROM notes ORDER BY id"
        )->fetchAll(),
        'versions' => db()->query(
            "SELECT id, note_id, folder_id, title, content, color, tags, icon, is_pinned, source, snapshot_hash, created_at FROM note_versions ORDER BY id"
        )->fetchAll(),
    ];
}

function write_workspace_backup(string $reason = 'automatic'): string {
    $directory = __DIR__ . '/storage/backups';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the backup directory.');
    }
    $stamp = gmdate('Ymd-His');
    $safeReason = preg_replace('/[^a-z0-9-]+/i', '-', $reason) ?: 'backup';
    $filename = "memoir-$safeReason-$stamp-" . bin2hex(random_bytes(3)) . '.json';
    $target = $directory . '/' . $filename;
    $temporary = $target . '.tmp';
    $json = json_encode(workspace_backup_payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $target)) {
        @unlink($temporary);
        throw new RuntimeException('Could not write the backup file.');
    }
    @chmod($target, 0640);
    return $filename;
}

// Request-driven scheduling works on shared hosting without cron: the first
// authenticated request after the interval creates the next backup.
function maybe_create_automatic_backup(): void {
    try {
        $settings = db()->query(
            "SELECT backup_enabled, backup_interval_hours, backup_keep, backup_last_at FROM settings WHERE id = 1"
        )->fetch();
        if (!$settings || !(int) $settings['backup_enabled']) return;
        $hours = max(1, min(720, (int) $settings['backup_interval_hours']));
        if (!empty($settings['backup_last_at']) && strtotime($settings['backup_last_at']) > time() - ($hours * 3600)) return;

        $lockName = 'memoir_workspace_backup';
        $lock = db()->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) return;
        try {
            write_workspace_backup('automatic');
            db()->exec("UPDATE settings SET backup_last_at = NOW() WHERE id = 1");
            $keep = max(1, min(50, (int) $settings['backup_keep']));
            $files = glob(__DIR__ . '/storage/backups/memoir-automatic-*.json') ?: [];
            rsort($files, SORT_STRING);
            foreach (array_slice($files, $keep) as $old) {
                if (is_file($old)) @unlink($old);
            }
        } finally {
            $release = db()->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    } catch (Throwable) {
        // A backup failure must not make the note application unavailable.
    }
}

// Convert the subset of Markdown the editor understands into note HTML.
// The result is passed through sanitize_note_html by the caller.
function markdown_to_note_html(string $md): string {
    $inline = function (string $text): string {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/!\[([^\]]*)\]\(([^)\s]+)\)/', '<img src="$2" alt="$1">', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^*\s][^*]*)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/~~([^~]+)~~/', '<s>$1</s>', $text);
        return $text;
    };

    $html = '';
    $list = null;        // 'ul' | 'ol' | 'checklist' while inside a list
    $inCode = false;
    $code = [];
    $closeList = function () use (&$html, &$list): void {
        if ($list) {
            $html .= $list === 'ol' ? '</ol>' : '</ul>';
            $list = null;
        }
    };

    foreach (preg_split('/\r\n|\r|\n/', $md) as $line) {
        if ($inCode) {
            if (preg_match('/^\s*```/', $line)) {
                $html .= '<pre>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
                $inCode = false;
                $code = [];
            } else {
                $code[] = $line;
            }
            continue;
        }
        if (preg_match('/^\s*```/', $line)) {
            $closeList();
            $inCode = true;
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $closeList();
            $level = strlen($m[1]);
            $html .= "<h$level>" . $inline($m[2]) . "</h$level>";
            continue;
        }
        if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) {
            $closeList();
            $html .= '<hr>';
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $closeList();
            $html .= '<blockquote>' . $inline($m[1]) . '</blockquote>';
            continue;
        }
        if (preg_match('/^\s*[-*]\s+\[([ xX])\]\s+(.*)$/', $line, $m)) {
            if ($list !== 'checklist') {
                $closeList();
                $html .= '<ul class="checklist">';
                $list = 'checklist';
            }
            $checked = strtolower($m[1]) === 'x' ? '1' : '0';
            $html .= '<li data-checked="' . $checked . '">' . $inline($m[2]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
            if ($list !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $list = 'ul';
            }
            $html .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
            if ($list !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $list = 'ol';
            }
            $html .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        $closeList();
        if (trim($line) !== '') {
            $html .= '<p>' . $inline($line) . '</p>';
        }
    }
    if ($inCode && $code) {
        $html .= '<pre>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
    $closeList();
    return $html;
}

// Minimal SMTP client for transactional mail (password resets). Uses the
// SMTP settings stored in the settings table; throws on any failure.
function smtp_send(array $smtp, string $to, string $subject, string $body): void {
    $host = trim((string) ($smtp['smtp_host'] ?? ''));
    if ($host === '') {
        throw new RuntimeException('Email is not configured. Set SMTP details in Settings first.');
    }
    $port = (int) ($smtp['smtp_port'] ?? 587);
    $security = $smtp['smtp_security'] ?? 'tls';
    $timeout = 12;

    $remote = ($security === 'ssl' ? "ssl://$host" : $host) . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout);
    if (!$fp) {
        throw new RuntimeException('Could not reach the mail server.');
    }
    stream_set_timeout($fp, $timeout);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return $data;
    };
    $command = function (string $cmd, array $expect) use ($fp, $read): string {
        fwrite($fp, $cmd . "\r\n");
        $resp = $read();
        if (!in_array((int) substr($resp, 0, 3), $expect, true)) {
            throw new RuntimeException('Mail server refused: ' . trim(strtok($resp, "\n")));
        }
        return $resp;
    };

    try {
        $read(); // server greeting
        $command('EHLO memoir', [250]);
        if ($security === 'tls') {
            $command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS negotiation with the mail server failed.');
            }
            $command('EHLO memoir', [250]);
        }
        if (!empty($smtp['smtp_user'])) {
            $command('AUTH LOGIN', [334]);
            $command(base64_encode($smtp['smtp_user']), [334]);
            $command(base64_encode((string) ($smtp['smtp_pass'] ?? '')), [235]);
        }

        $from = $smtp['smtp_from'] ?: ($smtp['smtp_user'] ?? '');
        $command("MAIL FROM:<$from>", [250]);
        $command("RCPT TO:<$to>", [250, 251]);
        $command('DATA', [354]);

        $headers = "From: Memoir <$from>\r\n"
            . "To: <$to>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . 'Date: ' . date('r') . "\r\n";
        $safeBody = str_replace("\n.", "\n..", str_replace("\r\n", "\n", $body));
        fwrite($fp, $headers . "\r\n" . str_replace("\n", "\r\n", $safeBody) . "\r\n.\r\n");
        $resp = $read();
        if ((int) substr($resp, 0, 3) !== 250) {
            throw new RuntimeException('The mail was not accepted: ' . trim(strtok($resp, "\n")));
        }
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

function json_response(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function sanitize_note_html(string $html): string {
    if ($html === '') return '';
    $allowedTags = ['p','br','div','hr','h1','h2','h3','h4','h5','h6','strong','b','em','i','u','s','span','ul','ol','li','blockquote','pre','code','a','img','table','thead','tbody','tr','th','td'];
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><div id="memoir-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $document->getElementById('memoir-root');
    if (!$root) return '';

    $clean = function (DOMNode $node) use (&$clean, $allowedTags): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (!in_array($tag, $allowedTags, true)) {
                $clean($child);
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $kept = [];
            if ($tag === 'a') {
                $href = trim($child->getAttribute('href'));
                if (preg_match('#^(https?://|mailto:|/)#i', $href)) {
                    $kept['href'] = $href;
                    $kept['rel'] = 'noopener noreferrer';
                    if (preg_match('#^https?://#i', $href)) $kept['target'] = '_blank';
                }
                // Internal wiki-style links to other notes.
                $noteLink = $child->getAttribute('data-note-link');
                if (ctype_digit($noteLink)) $kept['data-note-link'] = $noteLink;
            } elseif ($tag === 'img') {
                $src = trim($child->getAttribute('src'));
                if (preg_match('#^(https?://|/|uploads/)#i', $src)) $kept['src'] = $src;
                $alt = mb_substr($child->getAttribute('alt'), 0, 200);
                if ($alt !== '') $kept['alt'] = $alt;
            } elseif ($tag === 'ul') {
                if ($child->getAttribute('class') === 'checklist') $kept['class'] = 'checklist';
            } elseif ($tag === 'li') {
                $checked = $child->getAttribute('data-checked');
                if ($checked === '0' || $checked === '1') $kept['data-checked'] = $checked;
            } elseif ($tag === 'span') {
                // Only text/highlight colors survive — hex or the rgb()/rgba()
                // form browsers normalize inline styles to. Everything else is
                // dropped.
                $style = strtolower(str_replace(' ', '', $child->getAttribute('style')));
                $colorValue = '(#[0-9a-f]{3,8}|rgba?\([0-9.,%]{1,40}\)|transparent)';
                $safeStyles = [];
                if (preg_match('/(?<![-a-z])color:' . $colorValue . '/', $style, $m)) {
                    $safeStyles[] = 'color:' . $m[1];
                }
                if (preg_match('/background-color:' . $colorValue . '/', $style, $m)) {
                    $safeStyles[] = 'background-color:' . $m[1];
                }
                if ($safeStyles) $kept['style'] = implode(';', $safeStyles);
            }
            while ($child->attributes->length) $child->removeAttributeNode($child->attributes->item(0));
            foreach ($kept as $name => $value) $child->setAttribute($name, $value);
            $clean($child);
        }
    };
    $clean($root);
    $output = '';
    foreach ($root->childNodes as $child) $output .= $document->saveHTML($child);
    return $output;
}
