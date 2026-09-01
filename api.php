<?php
require __DIR__ . '/bootstrap.php';

$user = require_auth();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    verify_csrf();
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 10 * 1024 * 1024) {
    json_response(['ok' => false, 'message' => 'Request is too large'], 413);
}

function require_method(string $expected): void {
    global $method;
    if ($method !== $expected) {
        header('Allow: ' . $expected);
        json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }
}

function request_json(): array {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

switch ($action) {

case 'note':
    require_method('GET');
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = db()->prepare(
        "SELECT n.*, f.name folder_name
         FROM notes n
         LEFT JOIN folders f ON f.id = n.folder_id
         WHERE n.id = ?"
    );
    $stmt->execute([$id]);
    $note = $stmt->fetch();

    if (!$note) {
        json_response(['ok' => false, 'message' => 'Note not found'], 404);
    }
    json_response(['ok' => true, 'note' => $note]);

case 'create-note':
    require_method('POST');
    $data = request_json();
    $folder = !empty($data['folder_id']) ? (int) $data['folder_id'] : null;

    $stmt = db()->prepare("INSERT INTO notes(folder_id, title, content) VALUES(?, ?, ?)");
    $stmt->execute([$folder, 'Untitled note', '']);

    json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);

case 'save-note':
    require_method('POST');
    $data = request_json();

    $id = (int) ($data['id'] ?? 0);
    $title = mb_substr(trim((string) ($data['title'] ?? '')) ?: 'Untitled note', 0, 255);
    $content = sanitize_note_html((string) ($data['content'] ?? ''));
    $folder = isset($data['folder_id']) && $data['folder_id'] !== '' ? (int) $data['folder_id'] : null;
    $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($data['icon'] ?? '')) ? $data['icon'] : 'fa-note-sticky';
    $color = preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($data['color'] ?? '')) ? $data['color'] : '#6F5EE8';
    $pinned = !empty($data['is_pinned']) ? 1 : 0;

    $stmt = db()->prepare(
        "UPDATE notes
         SET folder_id = ?, title = ?, content = ?, icon = ?, color = ?, is_pinned = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$folder, $title, $content, $icon, $color, $pinned, $id]);

    json_response(['ok' => true, 'updated_at' => date('c')]);

case 'delete-note':
    require_method('POST');
    $data = request_json();

    db()->prepare("DELETE FROM notes WHERE id = ?")->execute([(int) ($data['id'] ?? 0)]);

    json_response(['ok' => true]);

case 'folder':
    require_method('POST');
    $data = request_json();

    $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
    if (!$name) {
        json_response(['ok' => false, 'message' => 'Folder name required'], 422);
    }
    $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($data['icon'] ?? '')) ? $data['icon'] : 'fa-folder';
    $color = preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($data['color'] ?? '')) ? $data['color'] : '#6F5EE8';

    $stmt = db()->prepare("INSERT INTO folders(name, icon, color, sort_order) VALUES(?, ?, ?, 999)");
    $stmt->execute([$name, $icon, $color]);

    json_response([
        'ok' => true,
        'id' => (int) db()->lastInsertId(),
        'name' => $name,
        'icon' => $icon,
        'color' => $color,
    ]);

case 'search':
    require_method('GET');
    $q = trim($_GET['q'] ?? '');
    $folder = $_GET['folder'] ?? '';
    $pinned = $_GET['pinned'] ?? '';

    $sql = "SELECT n.id, n.folder_id, n.title, n.content, n.icon, n.color, n.is_pinned, n.updated_at, f.name folder_name
            FROM notes n
            LEFT JOIN folders f ON f.id = n.folder_id
            WHERE 1=1";
    $params = [];

    if ($q !== '') {
        $sql .= " AND (n.title LIKE ? OR n.content LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    if ($folder !== '') {
        $sql .= " AND n.folder_id = ?";
        $params[] = (int) $folder;
    }
    if ($pinned === '1') {
        $sql .= " AND n.is_pinned = 1";
    }
    $sql .= " ORDER BY n.is_pinned DESC, n.updated_at DESC LIMIT 100";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    json_response(['ok' => true, 'notes' => $stmt->fetchAll()]);

case 'upload':
    require_method('POST');

    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'message' => 'Upload failed'], 422);
    }
    if ($_FILES['image']['size'] > 8 * 1024 * 1024) {
        json_response(['ok' => false, 'message' => 'Image must be under 8MB'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['image']['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extensions[$mime])) {
        json_response(['ok' => false, 'message' => 'Unsupported image type'], 422);
    }
    if (@getimagesize($_FILES['image']['tmp_name']) === false) {
        json_response(['ok' => false, 'message' => 'Invalid image file'], 422);
    }

    $ym = date('Y/m');
    $dir = __DIR__ . "/uploads/$ym";
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        json_response(['ok' => false, 'message' => 'Could not create upload folder'], 500);
    }

    $name = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], "$dir/$name")) {
        json_response(['ok' => false, 'message' => 'Could not save image'], 500);
    }

    global $config;
    json_response(['ok' => true, 'url' => rtrim($config['app']['url'], '/') . "/uploads/$ym/$name"]);

case 'settings':
    require_method('POST');
    $data = request_json();

    $existing = db()->query("SELECT * FROM settings WHERE id=1")->fetch();
    $pass = ($data['smtp_pass'] ?? '') !== '' ? $data['smtp_pass'] : ($existing['smtp_pass'] ?? null);

    $stmt = db()->prepare(
        "UPDATE settings
         SET app_name = ?, smtp_host = ?, smtp_port = ?, smtp_security = ?, smtp_user = ?, smtp_pass = ?, smtp_from = ?
         WHERE id = 1"
    );
    $stmt->execute([
        mb_substr(trim($data['app_name'] ?? 'Memoir') ?: 'Memoir', 0, 120),
        mb_substr(trim($data['smtp_host'] ?? ''), 0, 190) ?: null,
        max(1, min(65535, (int) ($data['smtp_port'] ?? 587))),
        in_array(($data['smtp_security'] ?? 'tls'), ['tls', 'ssl', 'none'], true) ? $data['smtp_security'] : 'tls',
        mb_substr(trim($data['smtp_user'] ?? ''), 0, 190) ?: null,
        $pass,
        filter_var(trim($data['smtp_from'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
    ]);

    json_response(['ok' => true]);

default:
    json_response(['ok' => false, 'message' => 'Unknown action'], 404);
}
