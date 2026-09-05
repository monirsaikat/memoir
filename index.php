<?php
require __DIR__ . '/bootstrap.php';

$user = require_auth();
$csrfToken = csrf_token();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
ensure_schema();
maybe_create_automatic_backup();

$settings = db()->query("SELECT * FROM settings WHERE id=1")->fetch();

$folders = [];
if ($user['role'] === 'owner') {
    $folders = db()->query(
        "SELECT f.*, (SELECT COUNT(*) FROM notes n WHERE n.folder_id = f.id AND n.deleted_at IS NULL) note_count
         FROM folders f
         ORDER BY sort_order, name"
    )->fetchAll();
}

$accessible = accessible_notes_clause();

$notesStmt = db()->prepare(
    "SELECT n.*, f.name folder_name
     FROM notes n
     LEFT JOIN folders f ON f.id = n.folder_id
     WHERE n.deleted_at IS NULL AND $accessible
     ORDER BY n.is_pinned DESC, n.updated_at DESC
     LIMIT 100"
);
$notesStmt->execute([$user['id'], $user['id']]);
$notes = $notesStmt->fetchAll();

$trashStmt = db()->prepare("SELECT COUNT(*) FROM notes n WHERE deleted_at IS NOT NULL AND $accessible");
$trashStmt->execute([$user['id'], $user['id']]);
$trashCount = (int) $trashStmt->fetchColumn();

$tagCounts = [];
$tagStmt = db()->prepare("SELECT tags FROM notes n WHERE tags <> '' AND deleted_at IS NULL AND $accessible");
$tagStmt->execute([$user['id'], $user['id']]);
foreach ($tagStmt->fetchAll() as $row) {
    foreach (explode(',', $row['tags']) as $tag) {
        $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
    }
}
ksort($tagCounts, SORT_NATURAL | SORT_FLAG_CASE);

$folderIcons = ['fa-folder', 'fa-code', 'fa-server', 'fa-briefcase', 'fa-lightbulb', 'fa-book', 'fa-heart', 'fa-star', 'fa-globe', 'fa-terminal'];
$noteIcons = ['fa-note-sticky', 'fa-code', 'fa-terminal', 'fa-lightbulb', 'fa-book', 'fa-heart', 'fa-star', 'fa-server', 'fa-list-check', 'fa-wand-magic-sparkles'];
$accentColors = ['#6F5EE8', '#4E9A7C', '#E7A93D', '#D66666', '#4A86C5', '#A866B7', '#64748B'];

// Presentation data for the settings dialog (templates cannot call PHP).
$themeAccents = ['#6F5EE8', '#3F7FC2', '#2E9E8F', '#3D8F68', '#C98A2D', '#D65C7E', '#C75454', '#64748B'];
$themes = [
    ['id' => 'light', 'icon' => 'fa-sun', 'label' => 'Light'],
    ['id' => 'dark', 'icon' => 'fa-moon', 'label' => 'Dark'],
    ['id' => 'system', 'icon' => 'fa-circle-half-stroke', 'label' => 'System'],
    ['id' => 'sepia', 'icon' => 'fa-mug-saucer', 'label' => 'Sepia'],
    ['id' => 'ocean', 'icon' => 'fa-water', 'label' => 'Ocean'],
    ['id' => 'midnight', 'icon' => 'fa-star', 'label' => 'Midnight'],
    ['id' => 'forest', 'icon' => 'fa-tree', 'label' => 'Forest'],
    ['id' => 'dusk', 'icon' => 'fa-cloud-moon', 'label' => 'Dusk'],
    ['id' => 'aurora', 'icon' => 'fa-wand-magic-sparkles', 'label' => 'Aurora'],
    ['id' => 'paper', 'icon' => 'fa-file-lines', 'label' => 'Paper'],
    ['id' => 'nord', 'icon' => 'fa-snowflake', 'label' => 'Nord'],
    ['id' => 'soft', 'icon' => 'fa-cloud-sun', 'label' => 'Soft'],
    ['id' => 'velvet', 'icon' => 'fa-crown', 'label' => 'Velvet', 'premium' => true],
    ['id' => 'linen', 'icon' => 'fa-scroll', 'label' => 'Linen', 'premium' => true],
];
$backupIntervals = [];
foreach ([1, 6, 12, 24, 72, 168] as $hours) {
    $backupIntervals[$hours] = $hours < 24 ? $hours . ' ' . ($hours === 1 ? 'hour' : 'hours') : ($hours / 24) . ' ' . ($hours === 24 ? 'day' : 'days');
}

function note_preview(string $content): string {
    $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_strimwidth($text, 0, 115, '…');
}
foreach ($notes as $i => $note) {
    $notes[$i]['_preview'] = note_preview((string) ($note['content'] ?? ''));
}
$clientContentBudget = 2 * 1024 * 1024;
$allNoteContentCached = true;
$clientNotes = array_map(static function (array $note) use (&$clientContentBudget, &$allNoteContentCached): array {
    $note['_preview'] = note_preview((string) ($note['content'] ?? ''));
    $bytes = strlen((string) ($note['content'] ?? ''));
    if ($bytes > $clientContentBudget) {
        $note['content'] = '';
        $note['_content_cached'] = false;
        $allNoteContentCached = false;
    } else {
        $note['_content_cached'] = true;
        $clientContentBudget -= $bytes;
    }
    return $note;
}, $notes);

render('pages/workspace/index.tpl', [
    'csrf' => $csrfToken,
    'version' => MEMOIR_VERSION,
    'appName' => $settings['app_name'] ?? 'Memoir',
    'userRole' => $user['role'],
    'settings' => $settings,
    'folders' => $folders,
    'notes' => $notes,
    'trashCount' => $trashCount,
    'tagCounts' => $tagCounts,
    'folderIcons' => $folderIcons,
    'noteIcons' => $noteIcons,
    'accentColors' => $accentColors,
    'themeAccents' => $themeAccents,
    'themes' => $themes,
    'backupIntervals' => $backupIntervals,
    'clientNotes' => $clientNotes,
    'initialActiveComplete' => count($notes) < 100,
    'initialContentComplete' => $allNoteContentCached,
]);
