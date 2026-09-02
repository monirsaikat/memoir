<?php

switch ($action) {

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

}
