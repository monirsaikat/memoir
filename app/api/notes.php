<?php

switch ($action) {

case 'note':
    require_method('GET');
    $id = (int) ($_GET['id'] ?? 0);
    require_note_access($id, $user['id']);

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
    require_note_access($id, $user['id']);
    $noteStmt = db()->prepare("SELECT id, title FROM notes WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $noteStmt->execute([$id]);
    $note = $noteStmt->fetch();
    if (!$note) json_response(['ok' => false, 'message' => 'Note not found'], 404);

    // Notes whose content links here with a wiki link, restricted to notes
    // this user can see so a collaborator never learns of unrelated notes.
    $stmt = db()->prepare(
        "SELECT n.id, n.title, n.content, n.updated_at, f.name folder_name
         FROM notes n
         LEFT JOIN folders f ON f.id = n.folder_id
         WHERE n.deleted_at IS NULL AND n.id != ? AND n.content LIKE ?
           AND (n.owner_id = ? OR n.id IN (SELECT note_id FROM note_collaborators WHERE user_id = ? AND status = 'accepted'))
         ORDER BY n.updated_at DESC LIMIT 20"
    );
    $stmt->execute([$id, '%data-note-link="' . $id . '"%', $user['id'], $user['id']]);
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
               AND n.content NOT LIKE ?
               AND (n.owner_id = ? OR n.id IN (SELECT note_id FROM note_collaborators WHERE user_id = ? AND status = 'accepted'))";
        $candidateParams = [$id, '%data-note-link="' . $id . '"%', $user['id'], $user['id']];
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
    $stmt = db()->prepare(
        "SELECT n.id, n.title, f.name folder_name
         FROM notes n
         LEFT JOIN folders f ON f.id = n.folder_id
         WHERE n.deleted_at IS NULL
           AND (n.owner_id = ? OR n.id IN (SELECT note_id FROM note_collaborators WHERE user_id = ? AND status = 'accepted'))
         ORDER BY n.updated_at DESC
         LIMIT 500"
    );
    $stmt->execute([$user['id'], $user['id']]);
    json_response(['ok' => true, 'notes' => $stmt->fetchAll()]);

case 'create-note':
    require_method('POST');

    $data = request_json();
    $folder = $user['role'] === 'owner' && !empty($data['folder_id'])
        ? (int) $data['folder_id']
        : null;

    $stmt = db()->prepare(
        "INSERT INTO notes(folder_id, owner_id, title, content)
         VALUES(?, ?, ?, ?)"
    );

    $stmt->execute([
        $folder,
        $user['id'],
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
    $role = require_note_access($id, $user['id']);
    $title = mb_substr(trim((string) ($data['title'] ?? '')) ?: 'Untitled note', 0, 255);
    $content = sanitize_note_html((string) ($data['content'] ?? ''));
    $folder = isset($data['folder_id']) && $data['folder_id'] !== '' ? (int) $data['folder_id'] : null;
    $icon = preg_match('/^fa-[a-z0-9-]+$/', (string) ($data['icon'] ?? '')) ? $data['icon'] : 'fa-note-sticky';
    $color = preg_match('/^#[A-Fa-f0-9]{6}$/', (string) ($data['color'] ?? '')) ? $data['color'] : '#6F5EE8';
    $background = sanitize_note_background($data['background'] ?? '');
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
        // Collaborators edit note content only — the folder tree belongs to the owner.
        if ($role !== 'owner') $folder = $existing['folder_id'] !== null ? (int) $existing['folder_id'] : null;
        $changed = (string) $existing['title'] !== $title
            || (string) $existing['content'] !== $content
            || (int) ($existing['folder_id'] ?? 0) !== (int) ($folder ?? 0)
            || (string) $existing['icon'] !== $icon
            || strtoupper((string) $existing['color']) !== strtoupper($color)
            || (string) ($existing['background'] ?? '') !== (string) ($background ?? '')
            || (string) $existing['tags'] !== $tags
            || (int) $existing['is_pinned'] !== $pinned;
        if ($changed) {
            store_note_version($existing);
            db()->prepare(
                "UPDATE notes
                 SET folder_id = ?, title = ?, content = ?, icon = ?, color = ?, background = ?, tags = ?, is_pinned = ?, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$folder, $title, $content, $icon, $color, $background, $tags, $pinned, $id]);
            log_activity($user['id'], $id, 'note_updated', sprintf('%s edited "%s"', $user['name'], $title));
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
    require_note_access($noteId, $user['id']);
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
    require_note_access($noteId, $user['id']);
    $stmt = db()->prepare(
        "SELECT id, note_id, folder_id, title, content, color, tags, icon, background, is_pinned, source, created_at
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
    require_note_access($noteId, $user['id']);
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
            "UPDATE notes SET folder_id = ?, title = ?, content = ?, color = ?, tags = ?, icon = ?, background = ?, is_pinned = ?, updated_at = NOW() WHERE id = ?"
        )->execute([
            $folderId, $version['title'], $version['content'], $version['color'], $version['tags'],
            $version['icon'], $version['background'] ?? null, (int) $version['is_pinned'], $noteId,
        ]);
        db()->commit();
        log_activity($user['id'], $noteId, 'version_restored', sprintf('%s restored an earlier version of "%s"', $user['name'], $version['title']));
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

case 'delete-note':
    require_method('POST');
    $data = request_json();
    $id = (int) ($data['id'] ?? 0);
    require_note_access($id, $user['id'], 'owner');

    $titleStmt = db()->prepare("UPDATE notes SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $titleStmt->execute([$id]);
    if ($titleStmt->rowCount()) {
        log_activity($user['id'], $id, 'note_trashed', sprintf('%s moved a note to Trash', $user['name']));
    }

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
    // Only notes this user owns are affected — trash/restore/permanent
    // deletion are lifecycle decisions collaborators don't get to make.
    $ids = array_values(array_filter($ids, static fn (int $id): bool => note_role_for_user($id, $user['id']) === 'owner'));
    if (!$ids) {
        json_response(['ok' => false, 'message' => 'No notes selected'], 422);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    if ($action === 'delete-notes') {
        // Move to trash.
        db()->prepare("UPDATE notes SET deleted_at = NOW() WHERE id IN ($placeholders) AND deleted_at IS NULL")->execute($ids);
        foreach ($ids as $noteId) log_activity($user['id'], $noteId, 'note_trashed', sprintf('%s moved a note to Trash', $user['name']));
    } elseif ($action === 'restore-notes') {
        db()->prepare("UPDATE notes SET deleted_at = NULL WHERE id IN ($placeholders)")->execute($ids);
        foreach ($ids as $noteId) log_activity($user['id'], $noteId, 'note_restored', sprintf('%s restored a note from Trash', $user['name']));
    } else {
        // Permanent deletion is only possible for notes already in the trash.
        foreach ($ids as $noteId) log_activity($user['id'], null, 'note_deleted_permanently', sprintf('%s permanently deleted a note', $user['name']));
        db()->prepare("DELETE FROM notes WHERE id IN ($placeholders) AND deleted_at IS NOT NULL")->execute($ids);
    }

    json_response(['ok' => true, 'count' => count($ids)]);

}
