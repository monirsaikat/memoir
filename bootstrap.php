<?php
declare(strict_types=1);

define('MEMOIR_VERSION', trim((string) @file_get_contents(__DIR__ . '/VERSION')) ?: '1.0.0');

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
    $stmt = db()->prepare("SELECT id,email,name FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_auth(): array {
    $user = auth_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

// Version-stamped asset URL so browsers refetch changed CSS/JS immediately.
function asset(string $path): string {
    $file = __DIR__ . '/' . $path;
    return e($path . '?v=' . (is_file($file) ? filemtime($file) : MEMOIR_VERSION));
}

// Lightweight in-place migrations for installs created before newer features.
function ensure_schema(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        if (!db()->query("SHOW COLUMNS FROM notes LIKE 'tags'")->fetch()) {
            db()->exec("ALTER TABLE notes ADD COLUMN tags VARCHAR(500) NOT NULL DEFAULT '' AFTER color");
        }
        if (!db()->query("SHOW COLUMNS FROM users LIKE 'reset_token'")->fetch()) {
            db()->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_expires DATETIME NULL");
        }
    } catch (Throwable) {
        // Fresh installs get the columns from the installer schema.
    }
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
