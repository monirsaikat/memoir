<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
ensure_schema();

$token = (string) ($_GET['t'] ?? '');
$note = null;

if (preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    $stmt = db()->prepare("SELECT title, content, updated_at FROM notes WHERE share_token = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$token]);
    $note = $stmt->fetch() ?: null;
}

if (!$note) {
    http_response_code(404);
}
$settings = db()->query("SELECT app_name FROM settings WHERE id=1")->fetch() ?: [];
$appName = $settings['app_name'] ?? 'Memoir';
?>
<!doctype html>
<html lang="en" data-theme="light" data-mode="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($note ? $note['title'] : 'Note not found') ?> — <?= e($appName) ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-light.min.css">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
    <style>
    .share-page {
        min-height: 100%;
        background: var(--bg);
        padding: 40px 20px 60px;
    }
    .share-doc {
        max-width: 760px;
        margin: 0 auto;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 20px;
        padding: 44px 48px;
        box-shadow: 0 20px 60px rgba(43, 36, 22, .07);
    }
    .share-doc h1.share-title {
        margin: 0 0 6px;
        font-size: 32px;
        letter-spacing: -.04em;
    }
    .share-updated {
        color: var(--muted);
        font-size: 12px;
        display: block;
        margin-bottom: 26px;
    }
    .share-doc .rich-editor {
        flex: none;
        overflow: visible;
        padding: 0;
    }
    .share-doc .rich-editor a[data-note-link] {
        pointer-events: none;
        border-bottom: 0;
    }
    .share-foot {
        max-width: 760px;
        margin: 18px auto 0;
        text-align: center;
        color: var(--muted);
        font-size: 11px;
    }
    .share-missing {
        text-align: center;
        padding: 60px 20px;
    }
    @media (max-width: 700px) {
        .share-doc { padding: 28px 22px; border-radius: 14px; }
    }
    </style>
</head>
<body class="share-page">

<?php if (!$note): ?>
<div class="share-doc share-missing">
    <h1 class="share-title">This note is not available</h1>
    <p style="color:var(--muted)">The link may have been revoked or the note removed.</p>
</div>
<?php else: ?>
<article class="share-doc">
    <h1 class="share-title"><?= e($note['title']) ?></h1>
    <span class="share-updated">Updated <?= e(date('F j, Y', strtotime($note['updated_at']))) ?></span>
    <div class="rich-editor"><?= $note['content'] /* stored pre-sanitized */ ?></div>
</article>
<div class="share-foot">Shared from <?= e($appName) ?> · Self-hosted personal notes</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
if (window.hljs) {
    document.querySelectorAll('.rich-editor pre').forEach(function (pre) {
        pre.innerHTML = hljs.highlightAuto(pre.innerText).value;
    });
}
</script>
<?php endif ?>

</body>
</html>
