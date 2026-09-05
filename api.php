<?php
require __DIR__ . '/bootstrap.php';

$user = require_auth();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    verify_csrf();
}

// PHP's default file-backed sessions hold an exclusive lock until request
// shutdown. The API is session-read-only after authentication, so release the
// lock now and allow fast clicks/autosaves to run concurrently.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 10 * 1024 * 1024) {
    json_response(['ok' => false, 'message' => 'Request is too large'], 413);
}

require_once __DIR__ . '/app/api/helpers.php';

$projectRoot = __DIR__;

$handlers = [
    'note' => 'notes.php',
    'references' => 'notes.php',
    'switcher' => 'notes.php',
    'create-note' => 'notes.php',
    'save-note' => 'notes.php',
    'note-history' => 'notes.php',
    'note-version' => 'notes.php',
    'restore-version' => 'notes.php',
    'delete-note' => 'notes.php',
    'delete-notes' => 'notes.php',
    'restore-notes' => 'notes.php',
    'destroy-notes' => 'notes.php',
    'folder' => 'navigation.php',
    'rename-folder' => 'navigation.php',
    'delete-folder' => 'navigation.php',
    'reorder-folders' => 'navigation.php',
    'search' => 'navigation.php',
    'sidebar' => 'navigation.php',
    'share-note' => 'content.php',
    'import' => 'content.php',
    'upload' => 'content.php',
    'invite-collaborator' => 'collaboration.php',
    'list-collaborators' => 'collaboration.php',
    'remove-collaborator' => 'collaboration.php',
    'resend-invite' => 'collaboration.php',
    'note-activity' => 'collaboration.php',
    'activity' => 'collaboration.php',
    'backup-export' => 'backups.php',
    'backup-now' => 'backups.php',
    'backup-restore' => 'backups.php',
    'change-password' => 'account.php',
    'settings' => 'account.php',
    'update-status' => 'updates.php',
    'check-update' => 'updates.php',
    'install-update' => 'updates.php',
];

$handler = $handlers[$action] ?? null;
if ($handler === null) {
    json_response(['ok' => false, 'message' => 'Unknown action'], 404);
}

require $projectRoot . '/app/api/' . $handler;
