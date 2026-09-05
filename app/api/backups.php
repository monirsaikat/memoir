<?php

require_owner();

switch ($action) {

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
            "INSERT INTO notes(id, folder_id, owner_id, title, content, color, tags, icon, is_pinned, deleted_at, share_token, created_at, updated_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)"
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
            // Restored notes belong to whoever ran the restore — a backup carries
            // no ownership/collaborator data of its own.
            $insertNote->execute([$id, $folderId, $user['id'], $title, $content, $color, $tags, $icon, !empty($row['is_pinned']) ? 1 : 0, $deleted, $created, $updated]);
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

}
