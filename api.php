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

// Normalize a submitted tag list into the stored "a,b,c" form:
// trimmed, comma-free, max 30 chars each, unique, at most 8 tags.
function sanitize_tags(mixed $raw): string {
    if (!is_array($raw)) return '';
    $tags = [];
    foreach ($raw as $tag) {
        $tag = trim(preg_replace('/[,\s]+/u', ' ', (string) $tag));
        $tag = mb_substr($tag, 0, 30);
        if ($tag !== '' && !in_array($tag, $tags, true)) $tags[] = $tag;
        if (count($tags) >= 8) break;
    }
    return implode(',', $tags);
}

function snapshot_hash(array $note): string {
    $fields = ['folder_id', 'title', 'content', 'color', 'tags', 'icon', 'is_pinned'];
    $snapshot = [];
    foreach ($fields as $field) $snapshot[$field] = $note[$field] ?? null;
    return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function store_note_version(array $note, string $source = 'autosave', bool $force = false): void {
    $hash = snapshot_hash($note);
    $latest = db()->prepare(
        "SELECT snapshot_hash, created_at FROM note_versions WHERE note_id = ? ORDER BY id DESC LIMIT 1"
    );
    $latest->execute([(int) $note['id']]);
    $previous = $latest->fetch();
    if ($previous && hash_equals((string) $previous['snapshot_hash'], $hash)) return;
    if (!$force && $previous && strtotime((string) $previous['created_at']) > time() - 300) return;

    db()->prepare(
        "INSERT INTO note_versions
         (note_id, folder_id, title, content, color, tags, icon, is_pinned, source, snapshot_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        (int) $note['id'], $note['folder_id'] ?: null, $note['title'], $note['content'],
        $note['color'], $note['tags'], $note['icon'], (int) $note['is_pinned'], $source, $hash,
    ]);

    $oldIds = db()->prepare("SELECT id FROM note_versions WHERE note_id = ? ORDER BY id DESC LIMIT 100, 100000");
    $oldIds->execute([(int) $note['id']]);
    $ids = array_map('intval', $oldIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM note_versions WHERE id IN ($marks)")->execute($ids);
    }
}

function parse_advanced_query(string $query): array {
    $filters = [];
    $text = preg_replace_callback(
        '/(?:^|\s)(tag|folder|is|before|after|in):(?:"([^"]+)"|(\S+))/iu',
        static function (array $match) use (&$filters): string {
            $key = strtolower($match[1]);
            $value = trim($match[2] !== '' ? $match[2] : $match[3]);
            if ($value !== '') $filters[$key][] = $value;
            return ' ';
        },
        $query
    );
    return ['text' => trim(preg_replace('/\s+/u', ' ', $text ?? $query)), 'filters' => $filters];
}

function valid_date_filter(string $value): ?string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

// maybe_create_automatic_backup();

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

    // Notes whose content links here with a wiki link.
    $stmt = db()->prepare(
        "SELECT id, title FROM notes
         WHERE deleted_at IS NULL AND id != ? AND content LIKE ?
         ORDER BY updated_at DESC LIMIT 20"
    );
    $stmt->execute([$id, '%data-note-link="' . $id . '"%']);

    json_response(['ok' => true, 'note' => $note, 'backlinks' => $stmt->fetchAll()]);

case 'switcher':
    require_method('GET');
    $rows = db()->query(
        "SELECT n.id, n.title, f.name folder_name
         FROM notes n
         LEFT JOIN folders f ON f.id = n.folder_id
         WHERE n.deleted_at IS NULL
         ORDER BY n.updated_at DESC
         LIMIT 500"
    )->fetchAll();
    json_response(['ok' => true, 'notes' => $rows]);

case 'create-note':
    require_method('POST');

    $data = request_json();
    $folder = !empty($data['folder_id'])
        ? (int) $data['folder_id']
        : null;

    $stmt = db()->prepare(
        "INSERT INTO notes(folder_id, title, content)
         VALUES(?, ?, ?)"
    );

    $stmt->execute([
        $folder,
        'Untitled note',
        ''
    ]);

    $id = (int) db()->lastInsertId();

    $stmt = db()->prepare(
        "SELECT
            n.*,
            f.name AS folder_name
         FROM notes n
         LEFT JOIN folders f ON f.id = n.folder_id
         WHERE n.id = ?
         LIMIT 1"
    );

    $stmt->execute([$id]);

    $note = $stmt->fetch();

    json_response([
        'ok' => true,
        'id' => $id,
        'note' => $note,
        'backlinks' => [],
    ]);

case 'save-note':
    require_method('POST');
    $data = request_json();

    $id = (int) ($data['id'] ?? 0);
    $title = mb_substr(trim((string) ($data['title'] ?? '')) ?: 'Untitled note', 0, 255);
    $content = sanitize_note_html((string) ($data['content'] ?? ''));
    $folder = isset($data['folder_id']) && $data['folder_id'] !== '' ? (int) $data['folder_id'] : null;
    $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($data['icon'] ?? '')) ? $data['icon'] : 'fa-note-sticky';
    $color = preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($data['color'] ?? '')) ? $data['color'] : '#6F5EE8';
    $tags = sanitize_tags($data['tags'] ?? []);
    $pinned = !empty($data['is_pinned']) ? 1 : 0;

    db()->beginTransaction();
    try {
        $existingStmt = db()->prepare("SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'Note not found'], 404);
        }
        $changed = (string) $existing['title'] !== $title
            || (string) $existing['content'] !== $content
            || (int) ($existing['folder_id'] ?? 0) !== (int) ($folder ?? 0)
            || (string) $existing['icon'] !== $icon
            || strtoupper((string) $existing['color']) !== strtoupper($color)
            || (string) $existing['tags'] !== $tags
            || (int) $existing['is_pinned'] !== $pinned;
        if ($changed) {
            store_note_version($existing);
            db()->prepare(
                "UPDATE notes
                 SET folder_id = ?, title = ?, content = ?, icon = ?, color = ?, tags = ?, is_pinned = ?, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$folder, $title, $content, $icon, $color, $tags, $pinned, $id]);
        }
        db()->commit();
        json_response(['ok' => true, 'updated_at' => date('c'), 'changed' => $changed]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

case 'note-history':
    require_method('GET');
    $noteId = (int) ($_GET['id'] ?? 0);
    $exists = db()->prepare("SELECT id FROM notes WHERE id = ? LIMIT 1");
    $exists->execute([$noteId]);
    if (!$exists->fetchColumn()) json_response(['ok' => false, 'message' => 'Note not found'], 404);
    $stmt = db()->prepare(
        "SELECT id, title, source, created_at, CHAR_LENGTH(content) content_length
         FROM note_versions WHERE note_id = ? ORDER BY id DESC LIMIT 100"
    );
    $stmt->execute([$noteId]);
    json_response(['ok' => true, 'versions' => $stmt->fetchAll()]);

case 'note-version':
    require_method('GET');
    $noteId = (int) ($_GET['note_id'] ?? 0);
    $versionId = (int) ($_GET['version_id'] ?? 0);
    $stmt = db()->prepare(
        "SELECT id, note_id, folder_id, title, content, color, tags, icon, is_pinned, source, created_at
         FROM note_versions WHERE id = ? AND note_id = ? LIMIT 1"
    );
    $stmt->execute([$versionId, $noteId]);
    $version = $stmt->fetch();
    if (!$version) json_response(['ok' => false, 'message' => 'Version not found'], 404);
    json_response(['ok' => true, 'version' => $version]);

case 'restore-version':
    require_method('POST');
    $data = request_json();
    $noteId = (int) ($data['note_id'] ?? 0);
    $versionId = (int) ($data['version_id'] ?? 0);
    db()->beginTransaction();
    try {
        $currentStmt = db()->prepare("SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        $currentStmt->execute([$noteId]);
        $currentNote = $currentStmt->fetch();
        $versionStmt = db()->prepare("SELECT * FROM note_versions WHERE id = ? AND note_id = ? LIMIT 1");
        $versionStmt->execute([$versionId, $noteId]);
        $version = $versionStmt->fetch();
        if (!$currentNote || !$version) {
            db()->rollBack();
            json_response(['ok' => false, 'message' => 'Note or version not found'], 404);
        }
        store_note_version($currentNote, 'restore', true);
        $folderId = $version['folder_id'] ?: null;
        if ($folderId) {
            $folderCheck = db()->prepare("SELECT id FROM folders WHERE id = ?");
            $folderCheck->execute([$folderId]);
            if (!$folderCheck->fetchColumn()) $folderId = null;
        }
        db()->prepare(
            "UPDATE notes SET folder_id = ?, title = ?, content = ?, color = ?, tags = ?, icon = ?, is_pinned = ?, updated_at = NOW() WHERE id = ?"
        )->execute([
            $folderId, $version['title'], $version['content'], $version['color'], $version['tags'],
            $version['icon'], (int) $version['is_pinned'], $noteId,
        ]);
        db()->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

case 'delete-note':
    require_method('POST');
    $data = request_json();

    db()->prepare("UPDATE notes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")
        ->execute([(int) ($data['id'] ?? 0)]);

    json_response(['ok' => true]);

case 'delete-notes':
case 'restore-notes':
case 'destroy-notes':
    require_method('POST');
    $data = request_json();
    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array) ($data['ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    )));
    if (!$ids) {
        json_response(['ok' => false, 'message' => 'No notes selected'], 422);
    }
    $ids = array_slice($ids, 0, 200);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'delete-notes') {
        // Move to trash.
        db()->prepare("UPDATE notes SET deleted_at = NOW() WHERE id IN ($placeholders) AND deleted_at IS NULL")->execute($ids);
    } elseif ($action === 'restore-notes') {
        db()->prepare("UPDATE notes SET deleted_at = NULL WHERE id IN ($placeholders)")->execute($ids);
    } else {
        // Permanent deletion is only possible for notes already in the trash.
        db()->prepare("DELETE FROM notes WHERE id IN ($placeholders) AND deleted_at IS NOT NULL")->execute($ids);
    }

    json_response(['ok' => true, 'count' => count($ids)]);

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

case 'rename-folder':
    require_method('POST');
    $data = request_json();

    $id = (int) ($data['id'] ?? 0);
    $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
    if (!$id || !$name) {
        json_response(['ok' => false, 'message' => 'Folder name required'], 422);
    }
    $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($data['icon'] ?? '')) ? $data['icon'] : 'fa-folder';
    $color = preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($data['color'] ?? '')) ? $data['color'] : '#6F5EE8';

    db()->prepare("UPDATE folders SET name = ?, icon = ?, color = ? WHERE id = ?")
        ->execute([$name, $icon, $color, $id]);

    json_response(['ok' => true, 'id' => $id, 'name' => $name, 'icon' => $icon, 'color' => $color]);

case 'delete-folder':
    require_method('POST');
    $data = request_json();
    $id = (int) ($data['id'] ?? 0);
    if (!$id) {
        json_response(['ok' => false, 'message' => 'Unknown folder'], 422);
    }
    // The foreign key moves the folder's notes to Unfiled (folder_id NULL).
    db()->prepare("DELETE FROM folders WHERE id = ?")->execute([$id]);

    json_response(['ok' => true]);

case 'reorder-folders':
    require_method('POST');
    $data = request_json();
    $ids = array_values(array_filter(
        array_map('intval', (array) ($data['ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    ));
    if (!$ids) {
        json_response(['ok' => false, 'message' => 'No folders given'], 422);
    }
    $stmt = db()->prepare("UPDATE folders SET sort_order = ? WHERE id = ?");
    foreach ($ids as $i => $folderId) {
        $stmt->execute([$i + 1, $folderId]);
    }

    json_response(['ok' => true]);

case 'search':
    require_method('GET');
    $parsedQuery = parse_advanced_query(trim((string) ($_GET['q'] ?? '')));
    $q = trim((string) $parsedQuery['text'], " \t\n\r\0\x0B\"");
    $operators = $parsedQuery['filters'];
    $folder = $_GET['folder'] ?? '';
    $pinned = (string) ($_GET['pinned'] ?? '');
    $tag = trim($_GET['tag'] ?? '');
    $state = (string) ($_GET['state'] ?? (($_GET['trash'] ?? '') === '1' ? 'trash' : 'active'));
    if (!in_array($state, ['active', 'trash', 'all'], true)) $state = 'active';
    $scope = (string) ($_GET['scope'] ?? 'all');
    if (!in_array($scope, ['all', 'title', 'content', 'tags'], true)) $scope = 'all';
    $after = valid_date_filter((string) ($_GET['after'] ?? ''));
    $before = valid_date_filter((string) ($_GET['before'] ?? ''));

    foreach ($operators['is'] ?? [] as $is) {
        $is = strtolower($is);
        if ($is === 'pinned') $pinned = '1';
        elseif ($is === 'unpinned') $pinned = '0';
        elseif (in_array($is, ['trashed', 'trash'], true)) $state = 'trash';
        elseif ($is === 'active') $state = 'active';
    }
    if (!empty($operators['before'])) $before = valid_date_filter((string) end($operators['before']));
    if (!empty($operators['after'])) $after = valid_date_filter((string) end($operators['after']));
    if (!empty($operators['in'])) {
        $operatorScope = strtolower((string) end($operators['in']));
        if (in_array($operatorScope, ['title', 'content', 'tags'], true)) $scope = $operatorScope;
    }

    $sql = "SELECT n.id, n.folder_id, n.title, n.content, n.icon, n.color, n.tags, n.is_pinned, n.deleted_at, n.updated_at, f.name folder_name
            FROM notes n
            LEFT JOIN folders f ON f.id = n.folder_id WHERE 1=1";
    $params = [];

    if ($state === 'active') $sql .= " AND n.deleted_at IS NULL";
    elseif ($state === 'trash') $sql .= " AND n.deleted_at IS NOT NULL";

    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $words = array_values(array_filter(array_map(
            static fn (string $word): string => preg_replace('/[^\p{L}\p{N}]+/u', '', $word),
            preg_split('/\s+/u', $q)
        )));
        if ($scope === 'all' && $words) {
            $boolean = implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', $words));
            $sql .= " AND (MATCH(n.title, n.content, n.tags) AGAINST(? IN BOOLEAN MODE)
                      OR n.title LIKE ? ESCAPE '\\\\' OR n.content LIKE ? ESCAPE '\\\\' OR n.tags LIKE ? ESCAPE '\\\\')";
            $params[] = $boolean;
            array_push($params, $like, $like, $like);
        } else {
            $columns = [
                'title' => 'n.title',
                'content' => 'n.content',
                'tags' => 'n.tags',
            ];
            if ($scope === 'all') {
                $sql .= " AND (n.title LIKE ? ESCAPE '\\\\' OR n.content LIKE ? ESCAPE '\\\\' OR n.tags LIKE ? ESCAPE '\\\\')";
                array_push($params, $like, $like, $like);
            } else {
                $sql .= " AND {$columns[$scope]} LIKE ? ESCAPE '\\\\'";
                $params[] = $like;
            }
        }
    }
    if ($folder !== '') {
        $sql .= " AND n.folder_id = ?";
        $params[] = (int) $folder;
    }
    if ($pinned === '1' || $pinned === '0') $sql .= " AND n.is_pinned = " . (int) $pinned;
    if ($tag !== '') {
        $sql .= " AND FIND_IN_SET(?, n.tags)";
        $params[] = $tag;
    }
    foreach ($operators['tag'] ?? [] as $operatorTag) {
        $sql .= " AND FIND_IN_SET(?, n.tags)";
        $params[] = mb_substr($operatorTag, 0, 30);
    }
    foreach ($operators['folder'] ?? [] as $operatorFolder) {
        if (strtolower($operatorFolder) === 'unfiled') {
            $sql .= " AND n.folder_id IS NULL";
        } else {
            $sql .= " AND f.name = ?";
            $params[] = mb_substr($operatorFolder, 0, 120);
        }
    }
    if ($after) {
        $sql .= " AND n.updated_at >= ?";
        $params[] = $after . ' 00:00:00';
    }
    if ($before) {
        $sql .= " AND n.updated_at < ?";
        $params[] = $before . ' 00:00:00';
    }

    $orders = [
        'updated' => 'n.updated_at DESC',
        'created' => 'n.created_at DESC',
        'title' => 'n.title ASC',
    ];
    $sort = $orders[$_GET['sort'] ?? 'updated'] ?? $orders['updated'];
    $sql .= " ORDER BY n.is_pinned DESC, $sort LIMIT 100";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    json_response([
        'ok' => true,
        'notes' => $stmt->fetchAll(),
        'query_text' => $q,
        'filters' => ['scope' => $scope, 'pinned' => $pinned, 'state' => $state, 'after' => $after, 'before' => $before],
    ]);

case 'sidebar':
    require_method('GET');
    $tagCounts = [];
    foreach (db()->query("SELECT tags FROM notes WHERE tags <> '' AND deleted_at IS NULL")->fetchAll() as $row) {
        foreach (explode(',', $row['tags']) as $tag) {
            $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
        }
    }
    ksort($tagCounts, SORT_NATURAL | SORT_FLAG_CASE);

    $folderCounts = db()->query(
        "SELECT f.id, COUNT(n.id) c
         FROM folders f
         LEFT JOIN notes n ON n.folder_id = f.id AND n.deleted_at IS NULL
         GROUP BY f.id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    json_response([
        'ok' => true,
        'tags' => $tagCounts,
        'folders' => $folderCounts,
        'all' => (int) db()->query("SELECT COUNT(*) FROM notes WHERE deleted_at IS NULL")->fetchColumn(),
        'trash' => (int) db()->query("SELECT COUNT(*) FROM notes WHERE deleted_at IS NOT NULL")->fetchColumn(),
    ]);

case 'share-note':
    require_method('POST');
    $data = request_json();
    $id = (int) ($data['id'] ?? 0);
    if (!$id) {
        json_response(['ok' => false, 'message' => 'Unknown note'], 422);
    }

    if (!empty($data['enable'])) {
        $token = bin2hex(random_bytes(24));
        $stmt = db()->prepare("UPDATE notes SET share_token = ? WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$token, $id]);
        if (!$stmt->rowCount()) {
            json_response(['ok' => false, 'message' => 'Note not found'], 404);
        }
        global $config;
        json_response([
            'ok' => true,
            'token' => $token,
            'url' => rtrim($config['app']['url'], '/') . '/share.php?t=' . $token,
        ]);
    }

    db()->prepare("UPDATE notes SET share_token = NULL WHERE id = ?")->execute([$id]);
    json_response(['ok' => true]);

case 'import':
    require_method('POST');
    if (empty($_FILES['files'])) {
        json_response(['ok' => false, 'message' => 'No files received'], 422);
    }

    $names = (array) $_FILES['files']['name'];
    $tmpNames = (array) $_FILES['files']['tmp_name'];
    $errors = (array) $_FILES['files']['error'];
    $sizes = (array) $_FILES['files']['size'];
    $imported = 0;
    $skipped = 0;

    foreach (array_slice(array_keys($names), 0, 50) as $i) {
        $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
        if ($errors[$i] !== UPLOAD_ERR_OK
            || $sizes[$i] > 1024 * 1024
            || !in_array($ext, ['md', 'markdown', 'txt'], true)) {
            $skipped++;
            continue;
        }
        $raw = (string) file_get_contents($tmpNames[$i]);

        // Title: the first "# " heading if present, else the file name.
        $title = pathinfo($names[$i], PATHINFO_FILENAME);
        if (preg_match('/^#\s+(.+?)\s*$/m', $raw, $m)) {
            $title = $m[1];
            $raw = preg_replace('/^#\s+.+?$\R?/m', '', $raw, 1);
        }
        $title = mb_substr(trim($title) ?: 'Imported note', 0, 255);

        $content = sanitize_note_html(markdown_to_note_html($raw));
        db()->prepare("INSERT INTO notes(title, content) VALUES(?, ?)")->execute([$title, $content]);
        $imported++;
    }

    json_response(['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);

case 'backup-export':
    require_method('GET');
    $payload = workspace_backup_payload();
    $filename = 'memoir-backup-' . gmdate('Y-m-d-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;

case 'backup-now':
    require_method('POST');
    try {
        $filename = write_workspace_backup('manual');
        db()->exec("UPDATE settings SET backup_last_at = NOW() WHERE id = 1");
        json_response(['ok' => true, 'filename' => $filename]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 500);
    }

case 'backup-restore':
    require_method('POST');
    if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'message' => 'Choose a valid Memoir backup file.'], 422);
    }
    if ((int) $_FILES['backup']['size'] > 10 * 1024 * 1024) {
        json_response(['ok' => false, 'message' => 'Backup files must be 10MB or smaller.'], 422);
    }
    try {
        $payload = json_decode((string) file_get_contents($_FILES['backup']['tmp_name']), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        json_response(['ok' => false, 'message' => 'This is not a valid JSON backup.'], 422);
    }
    if (!is_array($payload)
        || ($payload['format'] ?? '') !== 'memoir-workspace'
        || (int) ($payload['schema_version'] ?? 0) !== 1
        || !is_array($payload['folders'] ?? null)
        || !is_array($payload['notes'] ?? null)
        || !is_array($payload['versions'] ?? null)) {
        json_response(['ok' => false, 'message' => 'This file is not a supported Memoir workspace backup.'], 422);
    }
    if (count($payload['folders']) > 1000 || count($payload['notes']) > 100000 || count($payload['versions']) > 500000) {
        json_response(['ok' => false, 'message' => 'The backup exceeds the supported workspace limits.'], 422);
    }

    $validatedFolderIds = [];
    foreach ($payload['folders'] as $row) {
        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        if ($id < 1 || isset($validatedFolderIds[$id]) || trim((string) ($row['name'] ?? '')) === '') {
            json_response(['ok' => false, 'message' => 'The backup contains an invalid or duplicate folder.'], 422);
        }
        $validatedFolderIds[$id] = true;
    }
    $validatedNoteIds = [];
    foreach ($payload['notes'] as $row) {
        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        $folderId = is_array($row) ? (int) ($row['folder_id'] ?? 0) : 0;
        if ($id < 1 || isset($validatedNoteIds[$id]) || ($folderId && !isset($validatedFolderIds[$folderId]))) {
            json_response(['ok' => false, 'message' => 'The backup contains an invalid note or folder reference.'], 422);
        }
        $validatedNoteIds[$id] = true;
    }
    $validatedVersionIds = [];
    foreach ($payload['versions'] as $row) {
        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        $noteId = is_array($row) ? (int) ($row['note_id'] ?? 0) : 0;
        if ($id < 1 || isset($validatedVersionIds[$id]) || !isset($validatedNoteIds[$noteId])) {
            json_response(['ok' => false, 'message' => 'The backup contains an invalid version-history reference.'], 422);
        }
        $validatedVersionIds[$id] = true;
    }

    try {
        $safetyBackup = write_workspace_backup('before-restore');
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => 'Restore stopped because a safety backup could not be created: ' . $e->getMessage()], 500);
    }

    db()->beginTransaction();
    try {
        db()->exec("DELETE FROM notes"); // cascades version history
        db()->exec("DELETE FROM folders");

        $folderIds = [];
        $insertFolder = db()->prepare(
            "INSERT INTO folders(id, name, icon, color, sort_order, created_at, updated_at) VALUES(?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($payload['folders'] as $row) {
            if (!is_array($row)) continue;
            $id = (int) ($row['id'] ?? 0);
            $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 120);
            if ($id < 1 || $name === '' || isset($folderIds[$id])) continue;
            $folderIds[$id] = true;
            $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($row['icon'] ?? '')) ? $row['icon'] : 'fa-folder';
            $color = preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($row['color'] ?? '')) ? $row['color'] : '#6F5EE8';
            $created = valid_backup_datetime($row['created_at'] ?? null) ?? date('Y-m-d H:i:s');
            $updated = valid_backup_datetime($row['updated_at'] ?? null) ?? $created;
            $insertFolder->execute([$id, $name, $icon, $color, (int) ($row['sort_order'] ?? 0), $created, $updated]);
        }

        $noteIds = [];
        $insertNote = db()->prepare(
            "INSERT INTO notes(id, folder_id, title, content, color, tags, icon, is_pinned, deleted_at, share_token, created_at, updated_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)"
        );
        foreach ($payload['notes'] as $row) {
            if (!is_array($row)) continue;
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($noteIds[$id])) continue;
            $noteIds[$id] = true;
            $folderId = (int) ($row['folder_id'] ?? 0);
            if (!$folderId || !isset($folderIds[$folderId])) $folderId = null;
            $title = mb_substr(trim((string) ($row['title'] ?? '')) ?: 'Untitled note', 0, 255);
            $content = sanitize_note_html((string) ($row['content'] ?? ''));
            $color = preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($row['color'] ?? '')) ? $row['color'] : '#6F5EE8';
            $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($row['icon'] ?? '')) ? $row['icon'] : 'fa-note-sticky';
            $tags = sanitize_tags(explode(',', (string) ($row['tags'] ?? '')));
            $deleted = valid_backup_datetime($row['deleted_at'] ?? null);
            $created = valid_backup_datetime($row['created_at'] ?? null) ?? date('Y-m-d H:i:s');
            $updated = valid_backup_datetime($row['updated_at'] ?? null) ?? $created;
            $insertNote->execute([$id, $folderId, $title, $content, $color, $tags, $icon, !empty($row['is_pinned']) ? 1 : 0, $deleted, $created, $updated]);
        }

        $insertVersion = db()->prepare(
            "INSERT INTO note_versions(id, note_id, folder_id, title, content, color, tags, icon, is_pinned, source, snapshot_hash, created_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $versionIds = [];
        foreach ($payload['versions'] as $row) {
            if (!is_array($row)) continue;
            $id = (int) ($row['id'] ?? 0);
            $noteId = (int) ($row['note_id'] ?? 0);
            if ($id < 1 || !isset($noteIds[$noteId]) || isset($versionIds[$id])) continue;
            $versionIds[$id] = true;
            $folderId = (int) ($row['folder_id'] ?? 0);
            if (!$folderId || !isset($folderIds[$folderId])) $folderId = null;
            $version = [
                'folder_id' => $folderId,
                'title' => mb_substr(trim((string) ($row['title'] ?? '')) ?: 'Untitled note', 0, 255),
                'content' => sanitize_note_html((string) ($row['content'] ?? '')),
                'color' => preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($row['color'] ?? '')) ? $row['color'] : '#6F5EE8',
                'tags' => sanitize_tags(explode(',', (string) ($row['tags'] ?? ''))),
                'icon' => preg_match('/^fa-[a-z0-9-]+$/', (string) ($row['icon'] ?? '')) ? $row['icon'] : 'fa-note-sticky',
                'is_pinned' => !empty($row['is_pinned']) ? 1 : 0,
            ];
            $source = in_array(($row['source'] ?? ''), ['autosave', 'restore', 'import'], true) ? $row['source'] : 'import';
            $created = valid_backup_datetime($row['created_at'] ?? null) ?? date('Y-m-d H:i:s');
            $hash = preg_match('/^[a-f0-9]{64}$/', (string) ($row['snapshot_hash'] ?? ''))
                ? $row['snapshot_hash'] : snapshot_hash($version);
            $insertVersion->execute([
                $id, $noteId, $folderId, $version['title'], $version['content'], $version['color'],
                $version['tags'], $version['icon'], $version['is_pinned'], $source, $hash, $created,
            ]);
        }

        $appName = mb_substr(trim((string) ($payload['settings']['app_name'] ?? '')), 0, 120);
        if ($appName !== '') db()->prepare("UPDATE settings SET app_name = ? WHERE id = 1")->execute([$appName]);
        db()->commit();
        json_response([
            'ok' => true,
            'folders' => count($folderIds),
            'notes' => count($noteIds),
            'versions' => count($versionIds),
            'safety_backup' => $safetyBackup,
        ]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        json_response(['ok' => false, 'message' => 'Restore failed; your existing workspace was left unchanged.'], 500);
    }

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

case 'change-password':
    require_method('POST');
    $data = request_json();
    $currentPass = (string) ($data['current'] ?? '');
    $newPass = (string) ($data['password'] ?? '');

    if (strlen($newPass) < 12) {
        json_response(['ok' => false, 'message' => 'New password must be at least 12 characters.'], 422);
    }
    $stmt = db()->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($currentPass, $hash)) {
        json_response(['ok' => false, 'message' => 'Current password is incorrect.'], 422);
    }

    db()->prepare("UPDATE users SET password = ? WHERE id = ?")
        ->execute([password_hash($newPass, PASSWORD_DEFAULT), $user['id']]);
    session_regenerate_id(true);

    json_response(['ok' => true]);

case 'settings':
    require_method('POST');
    $data = request_json();

    $existing = db()->query("SELECT * FROM settings WHERE id=1")->fetch();
    $pass = ($data['smtp_pass'] ?? '') !== '' ? $data['smtp_pass'] : ($existing['smtp_pass'] ?? null);

    $stmt = db()->prepare(
        "UPDATE settings
         SET app_name = ?, smtp_host = ?, smtp_port = ?, smtp_security = ?, smtp_user = ?, smtp_pass = ?, smtp_from = ?,
             backup_enabled = ?, backup_interval_hours = ?, backup_keep = ?
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
        !empty($data['backup_enabled']) ? 1 : 0,
        max(1, min(720, (int) ($data['backup_interval_hours'] ?? 24))),
        max(1, min(50, (int) ($data['backup_keep'] ?? 7))),
    ]);

    json_response(['ok' => true]);

default:
    json_response(['ok' => false, 'message' => 'Unknown action'], 404);
}
