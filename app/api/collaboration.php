<?php

switch ($action) {

case 'invite-collaborator':
    require_method('POST');
    $data = request_json();
    $noteId = (int) ($data['note_id'] ?? 0);
    require_note_access($noteId, $user['id'], 'owner');

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'message' => 'Enter a valid email address.'], 422);
    }
    if ($email === strtolower($user['email'])) {
        json_response(['ok' => false, 'message' => 'You already have access to this note.'], 422);
    }

    $noteStmt = db()->prepare("SELECT title FROM notes WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $noteStmt->execute([$noteId]);
    $note = $noteStmt->fetch();
    if (!$note) json_response(['ok' => false, 'message' => 'Note not found'], 404);

    $existingUser = db()->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $existingUser->execute([$email]);
    $existingUserId = $existingUser->fetchColumn() ?: null;
    if ($existingUserId && (int) $existingUserId === (int) $user['id']) {
        json_response(['ok' => false, 'message' => 'You already have access to this note.'], 422);
    }

    $collaboratorStmt = db()->prepare("SELECT id, status FROM note_collaborators WHERE note_id = ? AND invited_email = ? LIMIT 1");
    $collaboratorStmt->execute([$noteId, $email]);
    $collaborator = $collaboratorStmt->fetch();
    if ($collaborator && $collaborator['status'] === 'accepted') {
        json_response(['ok' => false, 'message' => 'That person already has access.'], 422);
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = 'DATE_ADD(NOW(), INTERVAL 7 DAY)';

    if ($collaborator) {
        db()->prepare(
            "UPDATE note_collaborators
             SET status = 'pending', invite_token_hash = ?, invite_expires = $expires, invited_by = ?, user_id = ?
             WHERE id = ?"
        )->execute([$tokenHash, $user['id'], $existingUserId, $collaborator['id']]);
    } else {
        db()->prepare(
            "INSERT INTO note_collaborators(note_id, invited_email, user_id, status, invite_token_hash, invite_expires, invited_by)
             VALUES (?, ?, ?, 'pending', ?, $expires, ?)"
        )->execute([$noteId, $email, $existingUserId, $tokenHash, $user['id']]);
    }

    global $config;
    $link = rtrim($config['app']['url'], '/') . '/accept-invite.php?token=' . $token;
    $settings = db()->query('SELECT * FROM settings WHERE id=1')->fetch() ?: [];
    $body = "Hello,\n\n"
        . "{$user['name']} invited you to edit the note \"{$note['title']}\" on Memoir.\n"
        . "Open this link within 7 days to accept:\n\n"
        . $link . "\n\n"
        . "If you weren't expecting this, you can ignore this email.\n";
    try {
        smtp_send($settings, $email, "{$user['name']} shared a Memoir note with you", $body);
    } catch (Throwable $exception) {
        json_response(['ok' => false, 'message' => 'The invite was saved, but the email could not be sent: ' . $exception->getMessage()], 502);
    }

    log_activity($user['id'], $noteId, 'collaborator_invited', sprintf('%s invited %s to edit "%s"', $user['name'], $email, $note['title']));
    json_response(['ok' => true]);

case 'list-collaborators':
    require_method('GET');
    $noteId = (int) ($_GET['note_id'] ?? 0);
    require_note_access($noteId, $user['id'], 'owner');

    $stmt = db()->prepare(
        "SELECT nc.id, nc.invited_email, nc.status, nc.created_at, nc.accepted_at, u.name user_name
         FROM note_collaborators nc
         LEFT JOIN users u ON u.id = nc.user_id
         WHERE nc.note_id = ? AND nc.status != 'revoked'
         ORDER BY nc.created_at DESC"
    );
    $stmt->execute([$noteId]);
    json_response(['ok' => true, 'collaborators' => $stmt->fetchAll()]);

case 'remove-collaborator':
    require_method('POST');
    $data = request_json();
    $noteId = (int) ($data['note_id'] ?? 0);
    $collaboratorId = (int) ($data['id'] ?? 0);
    require_note_access($noteId, $user['id'], 'owner');

    $stmt = db()->prepare("SELECT invited_email FROM note_collaborators WHERE id = ? AND note_id = ? LIMIT 1");
    $stmt->execute([$collaboratorId, $noteId]);
    $email = $stmt->fetchColumn();
    if ($email === false) json_response(['ok' => false, 'message' => 'Collaborator not found'], 404);

    db()->prepare("UPDATE note_collaborators SET status = 'revoked' WHERE id = ?")->execute([$collaboratorId]);
    log_activity($user['id'], $noteId, 'collaborator_removed', sprintf('%s removed %s\'s access', $user['name'], $email));
    json_response(['ok' => true]);

case 'resend-invite':
    require_method('POST');
    $data = request_json();
    $noteId = (int) ($data['note_id'] ?? 0);
    $collaboratorId = (int) ($data['id'] ?? 0);
    require_note_access($noteId, $user['id'], 'owner');

    $stmt = db()->prepare("SELECT invited_email FROM note_collaborators WHERE id = ? AND note_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$collaboratorId, $noteId]);
    $email = $stmt->fetchColumn();
    if ($email === false) json_response(['ok' => false, 'message' => 'Collaborator not found'], 404);

    $noteStmt = db()->prepare("SELECT title FROM notes WHERE id = ? LIMIT 1");
    $noteStmt->execute([$noteId]);
    $title = $noteStmt->fetchColumn() ?: 'Untitled note';

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        "UPDATE note_collaborators SET invite_token_hash = ?, invite_expires = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = ?"
    )->execute([hash('sha256', $token), $collaboratorId]);

    global $config;
    $link = rtrim($config['app']['url'], '/') . '/accept-invite.php?token=' . $token;
    $settings = db()->query('SELECT * FROM settings WHERE id=1')->fetch() ?: [];
    $body = "Hello,\n\n"
        . "{$user['name']} invited you to edit the note \"{$title}\" on Memoir.\n"
        . "Open this link within 7 days to accept:\n\n"
        . $link . "\n\n"
        . "If you weren't expecting this, you can ignore this email.\n";
    try {
        smtp_send($settings, $email, "{$user['name']} shared a Memoir note with you", $body);
    } catch (Throwable $exception) {
        json_response(['ok' => false, 'message' => 'Could not resend the invite: ' . $exception->getMessage()], 502);
    }

    json_response(['ok' => true]);

case 'note-activity':
    require_method('GET');
    $noteId = (int) ($_GET['note_id'] ?? 0);
    require_note_access($noteId, $user['id']);

    $stmt = db()->prepare(
        "SELECT a.id, a.action, a.message, a.created_at, u.name actor_name
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.actor_id
         WHERE a.note_id = ?
         ORDER BY a.id DESC LIMIT 100"
    );
    $stmt->execute([$noteId]);
    json_response(['ok' => true, 'activity' => $stmt->fetchAll()]);

case 'activity':
    require_method('GET');
    require_owner();

    $stmt = db()->prepare(
        "SELECT a.id, a.action, a.message, a.created_at, a.note_id, n.title note_title, u.name actor_name
         FROM activity_log a
         LEFT JOIN notes n ON n.id = a.note_id
         LEFT JOIN users u ON u.id = a.actor_id
         WHERE a.note_id IN (SELECT id FROM notes WHERE owner_id = ?) OR (a.note_id IS NULL AND a.actor_id = ?)
         ORDER BY a.id DESC LIMIT 100"
    );
    $stmt->execute([$user['id'], $user['id']]);
    json_response(['ok' => true, 'activity' => $stmt->fetchAll()]);

}
