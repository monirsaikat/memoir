<?php

function require_method(string $expected): void {
    global $method;
    if ($method !== $expected) {
        header('Allow: ' . $expected);
        json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }
}

function request_json(): array {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

// Folder management and workspace settings are owner-only — collaborators
// only ever interact with the specific notes shared with them.
function require_owner(): void {
    global $user;
    if (($user['role'] ?? 'owner') !== 'owner') {
        json_response(['ok' => false, 'message' => 'Only the workspace owner can do this'], 403);
    }
}

// Normalize a submitted tag list into the stored "a,b,c" form:
// trimmed, comma-free, max 30 chars each, unique, at most 8 tags.
function sanitize_tags(mixed $raw): string {
    if (!is_array($raw)) return '';
    $tags = [];
    foreach ($raw as $tag) {
        $tag = trim(preg_replace('/[,\s]+/u', ' ', (string) $tag));
        $tag = mb_substr($tag, 0, 30);
        if ($tag !== '' && !in_array($tag, $tags, true)) $tags[] = $tag;
        if (count($tags) >= 8) break;
    }
    return implode(',', $tags);
}

function snapshot_hash(array $note): string {
    $fields = ['folder_id', 'title', 'content', 'color', 'tags', 'icon', 'background', 'is_pinned'];
    $snapshot = [];
    foreach ($fields as $field) $snapshot[$field] = $note[$field] ?? null;
    return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// A note's cover image, or null. Only an id from NOTE_BACKGROUNDS is ever
// stored — anything else (including a blank "no cover" choice) is null.
function sanitize_note_background(mixed $value): ?string {
    $value = (string) $value;
    return isset(NOTE_BACKGROUNDS[$value]) ? $value : null;
}

function store_note_version(array $note, string $source = 'autosave', bool $force = false): void {
    $hash = snapshot_hash($note);
    $latest = db()->prepare(
        "SELECT snapshot_hash, created_at FROM note_versions WHERE note_id = ? ORDER BY id DESC LIMIT 1"
    );
    $latest->execute([(int) $note['id']]);
    $previous = $latest->fetch();
    if ($previous && hash_equals((string) $previous['snapshot_hash'], $hash)) return;
    if (!$force && $previous && strtotime((string) $previous['created_at']) > time() - 300) return;

    db()->prepare(
        "INSERT INTO note_versions
         (note_id, folder_id, title, content, color, tags, icon, background, is_pinned, source, snapshot_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        (int) $note['id'], $note['folder_id'] ?: null, $note['title'], $note['content'],
        $note['color'], $note['tags'], $note['icon'], $note['background'] ?? null, (int) $note['is_pinned'], $source, $hash,
    ]);

    $oldIds = db()->prepare("SELECT id FROM note_versions WHERE note_id = ? ORDER BY id DESC LIMIT 100, 100000");
    $oldIds->execute([(int) $note['id']]);
    $ids = array_map('intval', $oldIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM note_versions WHERE id IN ($marks)")->execute($ids);
    }
}

function parse_advanced_query(string $query): array {
    $filters = [];
    $text = preg_replace_callback(
        '/(?:^|\s)(tag|folder|is|before|after|in):(?:"([^"]+)"|(\S+))/iu',
        static function (array $match) use (&$filters): string {
            $key = strtolower($match[1]);
            $value = trim($match[2] !== '' ? $match[2] : $match[3]);
            if ($value !== '') $filters[$key][] = $value;
            return ' ';
        },
        $query
    );
    return ['text' => trim(preg_replace('/\s+/u', ' ', $text ?? $query)), 'filters' => $filters];
}

function valid_date_filter(string $value): ?string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : null;
}

function note_plain_text(string $html): string {
    $text = preg_replace('/<\/?(?:p|div|h[1-6]|li|blockquote|pre|br|hr|tr|table)\b[^>]*>/iu', ' ', $html);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

function note_reference_excerpt(string $html, string $needle, int $radius = 72): string {
    $text = note_plain_text($html);
    if ($text === '') return '';
    $position = $needle !== '' ? mb_stripos($text, $needle, 0, 'UTF-8') : false;
    if ($position === false) return mb_strimwidth($text, 0, 150, '…', 'UTF-8');
    $start = max(0, $position - $radius);
    $excerpt = mb_substr($text, $start, mb_strlen($needle) + ($radius * 2), 'UTF-8');
    return ($start > 0 ? '…' : '') . $excerpt . ($start + mb_strlen($excerpt) < mb_strlen($text) ? '…' : '');
}

function note_contains_unlinked_title(string $plainText, string $title): bool {
    $title = trim($title);
    if (mb_strlen($title, 'UTF-8') < 3) return false;
    $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($title, '/') . '(?![\p{L}\p{N}])/iu';
    return preg_match($pattern, $plainText) === 1;
}
