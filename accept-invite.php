<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
ensure_schema();

function invite_for_token(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = db()->prepare(
        "SELECT nc.*, n.title note_title
         FROM note_collaborators nc
         JOIN notes n ON n.id = nc.note_id
         WHERE nc.invite_token_hash = ? AND nc.invite_expires > NOW() AND nc.status = 'pending'
         LIMIT 1"
    );
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

function accept_invite(array $invite, int $userId): void {
    db()->prepare(
        "UPDATE note_collaborators
         SET status = 'accepted', user_id = ?, accepted_at = NOW(), invite_token_hash = NULL, invite_expires = NULL
         WHERE id = ?"
    )->execute([$userId, $invite['id']]);
    $collaboratorStmt = db()->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
    $collaboratorStmt->execute([$userId]);
    $name = $collaboratorStmt->fetchColumn() ?: 'Someone';
    log_activity($userId, (int) $invite['note_id'], 'collaborator_joined', sprintf('%s accepted the invite to "%s"', $name, $invite['note_title']));
}

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$invite = invite_for_token($token);
$error = '';

$currentUser = auth_user();
$accountExists = false;
if ($invite) {
    $accountStmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $accountStmt->execute([$invite['invited_email']]);
    $accountExists = (bool) $accountStmt->fetchColumn();
}

if ($invite && $currentUser) {
    if (strtolower($currentUser['email']) === strtolower($invite['invited_email'])) {
        accept_invite($invite, (int) $currentUser['id']);
        header('Location: index.php?note=' . (int) $invite['note_id']);
        exit;
    }
    // Signed in as someone else — do not silently mix accounts up.
    $error = 'You are signed in as ' . $currentUser['email'] . ', but this invite was sent to ' . $invite['invited_email'] . '. Sign out and open the link again.';
}

if ($invite && !$currentUser && !$accountExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(false);
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    if ($name === '') {
        $error = 'Enter your name.';
    } elseif (strlen($password) < 12) {
        $error = 'The password must contain at least 12 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } else {
        db()->beginTransaction();
        try {
            db()->prepare("INSERT INTO users(name, email, password, role) VALUES(?, ?, ?, 'collaborator')")
                ->execute([$name, $invite['invited_email'], password_hash($password, PASSWORD_DEFAULT)]);
            $newUserId = (int) db()->lastInsertId();
            accept_invite($invite, $newUserId);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $newUserId;
        header('Location: index.php?note=' . (int) $invite['note_id']);
        exit;
    }
}

render('pages/auth/accept-invite.tpl', [
    'csrf' => csrf_token(),
    'token' => $token,
    'invite' => $invite,
    'accountExists' => $accountExists,
    'signedInAsOther' => $invite && $currentUser && strtolower($currentUser['email']) !== strtolower($invite['invited_email']),
    'error' => $error,
]);
