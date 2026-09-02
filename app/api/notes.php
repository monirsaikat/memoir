<?php

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

    // Reference discovery can scan candidate note bodies, so it lives in a
    // separate lazy endpoint and never delays opening the note itself.
    json_response([
        'ok' => true,
        'note' => $note,
        'backlinks' => [],
        'references' => ['linked' => [], 'unlinked' => []],
    ]);

case 'references':
    require_method('GET');
    $id = (int) ($_GET['id'] ?? 0);
    $noteStmt = db()->prepare("SELECT id, title FROM notes WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $noteStmt->execute([$id]);
    $note = $noteStmt->fetch();
    if (!$note) json_response(['ok' => false, 'message' => 'Note not found'], 404);

    // Notes whose content links here with a wiki link, including enough
    // context to make the backlink useful without opening every result.
    $stmt = db()->prepare(
        "SELECT n.id, n.title, n.content, n.updated_at, f.name folder_name
         FROM notes n
         LEFT JOIN folders f ON f.id = n.folder_id
         WHERE n.deleted_at IS NULL AND n.id != ? AND n.content LIKE ?
         ORDER BY n.updated_at DESC LIMIT 20"
    );
    $stmt->execute([$id, '%data-note-link="' . $id . '"%']);
    $linked = array_map(static function (array $row) use ($note): array {
        $label = (string) $note['title'];
        if (preg_match('/<a\b[^>]*data-note-link=["\']' . preg_quote((string) $note['id'], '/') . '["\'][^>]*>(.*?)<\/a>/isu', (string) $row['content'], $match)) {
            $label = note_plain_text($match[1]) ?: $label;
        }
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'folder_name' => $row['folder_name'],
            'excerpt' => note_reference_excerpt((string) $row['content'], $label),
            'updated_at' => $row['updated_at'],
        ];
    }, $stmt->fetchAll());

    // Unlinked mentions are exact, case-insensitive title occurrences in the
    // visible text of another note. SQL narrows candidates; PHP verifies text
    // boundaries after removing markup and entities.
    $unlinked = [];
    $targetTitle = trim((string) $note['title']);
    if (mb_strlen($targetTitle, 'UTF-8') >= 3) {
        preg_match('/[\p{L}\p{N}]{3,}/u', $targetTitle, $tokenMatch);
        $candidateSql =
            "SELECT n.id, n.title, n.content, n.updated_at, f.name folder_name
             FROM notes n
             LEFT JOIN folders f ON f.id = n.folder_id
             WHERE n.deleted_at IS NULL AND n.id != ?
               AND n.content NOT LIKE ?";
        $candidateParams = [$id, '%data-note-link="' . $id . '"%'];
        if (!empty($tokenMatch[0])) {
            $candidateSql .= " AND n.content LIKE ? ESCAPE '\\\\'";
            $candidateParams[] = '%' . addcslashes($tokenMatch[0], '%_\\') . '%';
        }
        $candidateSql .= ' ORDER BY n.updated_at DESC LIMIT 200';
        $candidateStmt = db()->prepare($candidateSql);
        $candidateStmt->execute($candidateParams);
        foreach ($candidateStmt->fetchAll() as $row) {
            $plainText = note_plain_text((string) $row['content']);
            if (!note_contains_unlinked_title($plainText, $targetTitle)) continue;
            $unlinked[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'folder_name' => $row['folder_name'],
                'excerpt' => note_reference_excerpt((string) $row['content'], $targetTitle),
                'updated_at' => $row['updated_at'],
            ];
            if (count($unlinked) >= 20) break;
        }
    }

    json_response([
        'ok' => true,
        'backlinks' => $linked,
        'references' => ['linked' => $linked, 'unlinked' => $unlinked],
    ]);

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
        'references' => ['linked' => [], 'unlinked' => []],
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

}
