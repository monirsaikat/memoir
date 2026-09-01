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

function json_response(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function sanitize_note_html(string $html): string {
    if ($html === '') return '';
    $allowedTags = ['p','br','div','h2','h3','strong','b','em','i','u','s','ul','ol','li','blockquote','pre','code','a','img'];
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
