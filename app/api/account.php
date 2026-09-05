<?php

switch ($action) {

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
    require_owner();
    $data = request_json();

    $existing = db()->query("SELECT * FROM settings WHERE id=1")->fetch();
    $pass = ($data['smtp_pass'] ?? '') !== '' ? $data['smtp_pass'] : ($existing['smtp_pass'] ?? null);
    $brevoKey = ($data['brevo_api_key'] ?? '') !== '' ? $data['brevo_api_key'] : ($existing['brevo_api_key'] ?? null);

    $stmt = db()->prepare(
        "UPDATE settings
         SET app_name = ?, mail_provider = ?, smtp_host = ?, smtp_port = ?, smtp_security = ?, smtp_user = ?, smtp_pass = ?, smtp_from = ?, brevo_api_key = ?,
             backup_enabled = ?, backup_interval_hours = ?, backup_keep = ?
         WHERE id = 1"
    );
    $stmt->execute([
        mb_substr(trim($data['app_name'] ?? 'Memoir') ?: 'Memoir', 0, 120),
        in_array(($data['mail_provider'] ?? 'smtp'), ['smtp', 'brevo'], true) ? $data['mail_provider'] : 'smtp',
        mb_substr(trim($data['smtp_host'] ?? ''), 0, 190) ?: null,
        max(1, min(65535, (int) ($data['smtp_port'] ?? 587))),
        in_array(($data['smtp_security'] ?? 'tls'), ['tls', 'ssl', 'none'], true) ? $data['smtp_security'] : 'tls',
        mb_substr(trim($data['smtp_user'] ?? ''), 0, 190) ?: null,
        $pass,
        filter_var(trim($data['smtp_from'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
        $brevoKey !== null ? trim((string) $brevoKey) : null,
        !empty($data['backup_enabled']) ? 1 : 0,
        max(1, min(720, (int) ($data['backup_interval_hours'] ?? 24))),
        max(1, min(50, (int) ($data['backup_keep'] ?? 7))),
    ]);

    json_response(['ok' => true]);

}
