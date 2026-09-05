<?php

switch ($action) {

case 'share-note':
    require_method('POST');
    $data = request_json();
    $id = (int) ($data['id'] ?? 0);
    if (!$id) {
        json_response(['ok' => false, 'message' => 'Unknown note'], 422);
    }
    // Only the owner controls the anonymous public link — a collaborator can
    // edit the note's content but not decide who else can view it.
    require_note_access($id, $user['id'], 'owner');

    if (!empty($data['enable'])) {
        $token = bin2hex(random_bytes(24));
        $stmt = db()->prepare("UPDATE notes SET share_token = ? WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$token, $id]);
        if (!$stmt->rowCount()) {
            json_response(['ok' => false, 'message' => 'Note not found'], 404);
        }
        log_activity($user['id'], $id, 'link_share_enabled', sprintf('%s created a public share link', $user['name']));
        global $config;
        json_response([
            'ok' => true,
            'token' => $token,
            'url' => rtrim($config['app']['url'], '/') . '/share.php?t=' . $token,
        ]);
    }

    db()->prepare("UPDATE notes SET share_token = NULL WHERE id = ?")->execute([$id]);
    log_activity($user['id'], $id, 'link_share_disabled', sprintf('%s turned off the public share link', $user['name']));
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
        db()->prepare("INSERT INTO notes(owner_id, title, content) VALUES(?, ?, ?)")->execute([$user['id'], $title, $content]);
        $imported++;
    }

    json_response(['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);

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
    $dir = $projectRoot . "/uploads/$ym";
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        json_response(['ok' => false, 'message' => 'Could not create upload folder'], 500);
    }

    $name = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($_FILES['image']['tmp_name'], "$dir/$name")) {
        json_response(['ok' => false, 'message' => 'Could not save image'], 500);
    }

    global $config;
    json_response(['ok' => true, 'url' => rtrim($config['app']['url'], '/') . "/uploads/$ym/$name"]);

}
