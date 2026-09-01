<?php
require __DIR__ . '/bootstrap.php';

$user = require_auth();
ensure_schema();

$settings = db()->query("SELECT * FROM settings WHERE id=1")->fetch();

$folders = db()->query(
    "SELECT f.*, (SELECT COUNT(*) FROM notes n WHERE n.folder_id = f.id) note_count
     FROM folders f
     ORDER BY sort_order, name"
)->fetchAll();

$notes = db()->query(
    "SELECT n.*, f.name folder_name
     FROM notes n
     LEFT JOIN folders f ON f.id = n.folder_id
     ORDER BY n.is_pinned DESC, n.updated_at DESC
     LIMIT 100"
)->fetchAll();

$tagCounts = [];
foreach (db()->query("SELECT tags FROM notes WHERE tags <> ''")->fetchAll() as $row) {
    foreach (explode(',', $row['tags']) as $tag) {
        $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
    }
}
ksort($tagCounts, SORT_NATURAL | SORT_FLAG_CASE);

$folderIcons = ['fa-folder', 'fa-code', 'fa-server', 'fa-briefcase', 'fa-lightbulb', 'fa-book', 'fa-heart', 'fa-star', 'fa-globe', 'fa-terminal'];
$noteIcons = ['fa-note-sticky', 'fa-code', 'fa-terminal', 'fa-lightbulb', 'fa-book', 'fa-heart', 'fa-star', 'fa-server', 'fa-list-check', 'fa-wand-magic-sparkles'];
$accentColors = ['#6F5EE8', '#4E9A7C', '#E7A93D', '#D66666', '#4A86C5', '#A866B7', '#64748B'];

function note_preview(string $content): string {
    $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return mb_strimwidth($text, 0, 115, '…');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= e($settings['app_name'] ?? 'Memoir') ?></title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>

<div class="app-shell">

    <!-- Sidebar: brand, navigation, folders, settings -->
    <aside class="sidebar">
        <div class="brand">
            <img class="brand-logo" src="assets/img/memoir-logo.png" alt="">
            <span><?= e($settings['app_name'] ?? 'Memoir') ?></span>
            <button class="icon-btn mobile-only sidebar-close" id="closeSidebar" type="button" aria-label="Close navigation">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <button class="new-note-btn" id="newNote"><i class="fa-solid fa-plus"></i> New note</button>

        <nav class="main-nav">
            <button class="nav-item active" data-folder="">
                <i class="fa-regular fa-note-sticky"></i>
                <span>All notes</span>
                <span class="count"><?= count($notes) ?></span>
            </button>
            <button class="nav-item" data-pinned="1">
                <i class="fa-solid fa-thumbtack"></i>
                <span>Pinned</span>
            </button>
        </nav>

        <div class="section-title">
            <span>Folders</span>
            <button id="addFolder" title="Add folder"><i class="fa-solid fa-plus"></i></button>
        </div>

        <div id="folderList" class="folder-list">
            <?php foreach ($folders as $folder): ?>
            <button class="folder-item" data-folder="<?= $folder['id'] ?>">
                <i class="fa-solid <?= e($folder['icon']) ?>" style="color:<?= e($folder['color']) ?>"></i>
                <span><?= e($folder['name']) ?></span>
                <span class="count"><?= $folder['note_count'] ?></span>
            </button>
            <?php endforeach ?>
        </div>

        <div class="section-title" id="tagSectionTitle" <?= $tagCounts ? '' : 'hidden' ?>><span>Tags</span></div>
        <div id="tagList" class="tag-list">
            <?php foreach ($tagCounts as $tag => $count): ?>
            <button class="tag-item" data-tag="<?= e($tag) ?>">#<?= e($tag) ?><span class="count"><?= $count ?></span></button>
            <?php endforeach ?>
        </div>

        <div class="sidebar-bottom">
            <button id="whatsNewBtn">
                <i class="fa-solid fa-sparkles"></i> What’s new
                <span class="version-pill">v<?= e(MEMOIR_VERSION) ?></span>
            </button>
            <button id="settingsBtn"><i class="fa-solid fa-sliders"></i> Settings</button>
            <form method="post" action="logout.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <button type="submit"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</button>
            </form>
        </div>
    </aside>

    <button class="mobile-scrim" id="mobileScrim" type="button" aria-label="Close navigation"></button>

    <!-- Middle panel: searchable note list -->
    <section class="note-list-panel">
        <div class="list-head">
            <div>
                <h1 id="listTitle">All notes</h1>
                <span id="listCount"><?= count($notes) ?> notes</span>
            </div>
            <button id="collapseSidebar" class="icon-btn mobile-only" type="button" aria-label="Open navigation">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="globalSearch" type="search" name="memoir_note_search" placeholder="Search notes"
                   aria-label="Search notes" autocomplete="off" autocapitalize="off" spellcheck="false"
                   readonly data-1p-ignore data-lpignore="true" data-form-type="other">
            <kbd>⌘ K</kbd>
        </div>

        <div id="noteList" class="note-list">
            <?php if (!$notes): ?>
            <div class="list-empty">
                <i class="fa-regular fa-compass"></i>
                <strong>No notes yet</strong>
                <span>Create your first note to get started.</span>
            </div>
            <?php endif ?>

            <?php foreach ($notes as $note): ?>
            <button class="note-card" data-id="<?= $note['id'] ?>" data-folder="<?= $note['folder_id'] ?? '' ?>" data-pinned="<?= $note['is_pinned'] ?>">
                <div class="note-card-top">
                    <i class="fa-solid <?= e($note['icon']) ?>" style="color:<?= e($note['color'] === '#FFFFFF' ? '#6f5ee8' : $note['color']) ?>"></i>
                    <?php if ($note['is_pinned']): ?><i class="fa-solid fa-thumbtack pin-mini"></i><?php endif ?>
                </div>
                <strong><?= e($note['title']) ?></strong>
                <p><?= e(note_preview($note['content'])) ?></p>
                <div class="note-meta">
                    <span><?= e($note['folder_name'] ?? 'Unfiled') ?><?= ($note['tags'] ?? '') !== '' ? ' · #' . e(str_replace(',', ' #', $note['tags'])) : '' ?></span>
                    <time><?= date('M j', strtotime($note['updated_at'])) ?></time>
                </div>
            </button>
            <?php endforeach ?>
        </div>
    </section>

    <!-- Right panel: note editor -->
    <main class="editor-panel">
        <div id="emptyState" class="empty-state">
            <div class="empty-icon"><i class="fa-regular fa-pen-to-square"></i></div>
            <h2>Choose a note</h2>
            <p>Select a note from the list or create a new one.</p>
        </div>

        <div id="editorView" class="editor-view hidden">
            <header class="editor-head">
                <button class="icon-btn mobile-only" id="backToList" type="button" aria-label="Back to notes">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <div class="crumb">
                    <span id="crumbFolder">Unfiled</span>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span id="saveStatus">Saved</span>
                </div>
                <div class="editor-actions">
                    <button class="icon-btn" id="pinNote" title="Pin"><i class="fa-solid fa-thumbtack"></i></button>
                    <button class="icon-btn" id="noteStyle" title="Note style"><i class="fa-solid fa-palette"></i></button>
                    <button class="icon-btn danger" id="deleteNote" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
                </div>
            </header>

            <div class="editor-body">
                <input id="noteTitle" class="title-input" value="" placeholder="Untitled note">

                <div class="tag-row">
                    <i class="fa-solid fa-tag"></i>
                    <div class="tag-chips" id="tagChips"></div>
                    <input id="tagInput" placeholder="Add tag" maxlength="30" autocomplete="off">
                </div>

                <div class="toolbar-wrap">
                    <div class="toolbar">
                        <div class="tool-group">
                            <button type="button" data-cmd="undo" title="Undo (Ctrl+Z)"><i class="fa-solid fa-rotate-left"></i></button>
                            <button type="button" data-cmd="redo" title="Redo (Ctrl+Y)"><i class="fa-solid fa-rotate-right"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="bold" data-state="bold" title="Bold (Ctrl+B or **text**)"><i class="fa-solid fa-bold"></i></button>
                            <button type="button" data-cmd="italic" data-state="italic" title="Italic (Ctrl+I or *text*)"><i class="fa-solid fa-italic"></i></button>
                            <button type="button" data-cmd="underline" data-state="underline" title="Underline (Ctrl+U)"><i class="fa-solid fa-underline"></i></button>
                            <button type="button" data-cmd="strikeThrough" data-state="strikeThrough" title="Strikethrough (~~text~~)"><i class="fa-solid fa-strikethrough"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" class="tool-label" data-block="h2" title="Heading (# + space)">H2</button>
                            <button type="button" class="tool-label" data-block="h3" title="Subheading (## + space)">H3</button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="insertUnorderedList" data-state="insertUnorderedList" title="Bullet list (- + space)"><i class="fa-solid fa-list-ul"></i></button>
                            <button type="button" data-cmd="insertOrderedList" data-state="insertOrderedList" title="Numbered list (1. + space)"><i class="fa-solid fa-list-ol"></i></button>
                            <button type="button" data-cmd="formatBlock" data-value="blockquote" title="Quote (&gt; + space)"><i class="fa-solid fa-quote-left"></i></button>
                            <button type="button" data-cmd="formatBlock" data-value="pre" title="Code block (``` + Enter)"><i class="fa-solid fa-code"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" id="textColorBtn" title="Text color"><i class="fa-solid fa-font"></i><span class="color-bar" id="textColorBar"></span></button>
                            <button type="button" id="highlightBtn" title="Highlight"><i class="fa-solid fa-highlighter"></i><span class="color-bar" id="highlightBar"></span></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" id="insertLink" title="Insert link"><i class="fa-solid fa-link"></i></button>
                            <button type="button" id="insertImage" title="Insert image"><i class="fa-regular fa-image"></i></button>
                            <button type="button" data-cmd="insertHorizontalRule" title="Divider (--- + Enter)"><i class="fa-solid fa-minus"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
                        </div>
                        <input type="file" id="imageInput" accept="image/*" hidden>
                    </div>

                    <!-- Color picker sheet; anchored below its toolbar button by JS -->
                    <div class="color-sheet hidden" id="colorSheet" role="menu">
                        <div class="color-sheet-title" id="colorSheetTitle">Text color</div>
                        <div class="swatch-row" id="colorSwatches"></div>
                        <button type="button" class="swatch-clear" id="colorClear">Remove color</button>
                    </div>
                </div>

                <div id="noteContent" class="rich-editor" contenteditable="true" spellcheck="true"></div>
            </div>

            <footer class="editor-foot">
                <span id="wordCount">0 words</span>
                <span id="updatedAt"></span>
            </footer>
        </div>

        <!-- Floating format bubble shown over selected text -->
        <div class="format-bubble hidden" id="formatBubble" role="toolbar" aria-label="Format selection">
            <button type="button" data-bcmd="bold" data-bstate="bold" title="Bold"><i class="fa-solid fa-bold"></i></button>
            <button type="button" data-bcmd="italic" data-bstate="italic" title="Italic"><i class="fa-solid fa-italic"></i></button>
            <button type="button" data-bcmd="underline" data-bstate="underline" title="Underline"><i class="fa-solid fa-underline"></i></button>
            <button type="button" data-bcmd="strikeThrough" data-bstate="strikeThrough" title="Strikethrough"><i class="fa-solid fa-strikethrough"></i></button>
            <span class="b-sep"></span>
            <button type="button" id="bubbleLink" title="Link"><i class="fa-solid fa-link"></i></button>
            <button type="button" id="bubbleHighlight" title="Highlight"><i class="fa-solid fa-highlighter"></i></button>
            <button type="button" data-bcmd="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
        </div>
    </main>

</div>

<!-- Modal: create folder -->
<div class="modal-backdrop hidden" id="folderModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="folderModalTitle">
        <h3 id="folderModalTitle">New folder</h3>

        <label>Name</label>
        <input id="folderName" placeholder="e.g. Server notes" autocomplete="off">

        <label>Icon</label>
        <div class="icon-grid" id="folderIcons">
            <?php foreach ($folderIcons as $icon): ?>
            <button data-icon="<?= $icon ?>"><i class="fa-solid <?= $icon ?>"></i></button>
            <?php endforeach ?>
        </div>

        <label>Color</label>
        <div class="color-row" id="folderColors">
            <?php foreach ($accentColors as $color): ?>
            <button data-color="<?= $color ?>" style="background:<?= $color ?>"></button>
            <?php endforeach ?>
        </div>

        <div class="modal-actions">
            <button data-close>Cancel</button>
            <button class="primary-btn" id="saveFolder">Create folder</button>
        </div>
    </div>
</div>

<!-- Modal: note appearance -->
<div class="modal-backdrop hidden" id="styleModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="styleModalTitle">
        <h3 id="styleModalTitle">Note appearance</h3>

        <label>Icon</label>
        <div class="icon-grid" id="noteIcons">
            <?php foreach ($noteIcons as $icon): ?>
            <button data-icon="<?= $icon ?>"><i class="fa-solid <?= $icon ?>"></i></button>
            <?php endforeach ?>
        </div>

        <label>Accent color</label>
        <div class="color-row" id="noteColors">
            <?php foreach ($accentColors as $color): ?>
            <button data-color="<?= $color ?>" style="background:<?= $color ?>"></button>
            <?php endforeach ?>
        </div>

        <div class="modal-actions">
            <button data-close>Close</button>
        </div>
    </div>
</div>

<!-- Modal: settings -->
<div class="modal-backdrop hidden" id="settingsModal">
    <div class="modal wide" role="dialog" aria-modal="true" aria-labelledby="settingsModalTitle">
        <h3 id="settingsModalTitle">Settings</h3>

        <div class="settings-grid">
            <div>
                <label>App name</label>
                <input id="setAppName" autocomplete="off" value="<?= e($settings['app_name'] ?? 'Memoir') ?>">
            </div>
            <div>
                <label>SMTP host</label>
                <input id="setSmtpHost" autocomplete="off" value="<?= e($settings['smtp_host'] ?? '') ?>">
            </div>
            <div>
                <label>SMTP port</label>
                <input id="setSmtpPort" value="<?= e((string) ($settings['smtp_port'] ?? 587)) ?>">
            </div>
            <div>
                <label>Security</label>
                <select id="setSmtpSecurity">
                    <option value="tls" <?= ($settings['smtp_security'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= ($settings['smtp_security'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="none">None</option>
                </select>
            </div>
            <div>
                <label>SMTP username</label>
                <input id="setSmtpUser" autocomplete="off" value="<?= e($settings['smtp_user'] ?? '') ?>">
            </div>
            <div>
                <label>SMTP password</label>
                <input type="password" id="setSmtpPass" autocomplete="new-password" placeholder="Leave blank to keep current">
            </div>
            <div class="full">
                <label>From email</label>
                <input id="setSmtpFrom" value="<?= e($settings['smtp_from'] ?? '') ?>">
            </div>
        </div>

        <div class="modal-actions">
            <button data-close>Cancel</button>
            <button class="primary-btn" id="saveSettings">Save settings</button>
        </div>
    </div>
</div>

<!-- Modal: what's new / release notes -->
<div class="modal-backdrop hidden" id="whatsNewModal">
    <div class="modal release-modal" role="dialog" aria-modal="true" aria-labelledby="whatsNewTitle">
        <div class="release-head">
            <img src="assets/img/memoir-logo.png" alt="">
            <div>
                <span class="release-label">Memoir <?= e(MEMOIR_VERSION) ?></span>
                <h3 id="whatsNewTitle">Fresh start, safer foundation</h3>
            </div>
        </div>

        <p class="release-copy">This release makes Memoir ready for simple ZIP-to-cPanel installation and public open-source use.</p>

        <ul class="release-list">
            <li>
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <div>
                    <strong>A cleaner installer</strong>
                    <span>Guided setup, server checks, safer validation, and useful cPanel defaults.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-shield-halved"></i>
                <div>
                    <strong>Security hardening</strong>
                    <span>Protected runtime files, sanitized note HTML, safer sessions, and POST-only sign out.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-bookmark"></i>
                <div>
                    <strong>A real Memoir identity</strong>
                    <span>A new memory-page logo and favicon across the product.</span>
                </div>
            </li>
        </ul>

        <div class="modal-actions">
            <a class="changelog-link" href="CHANGELOG.md" target="_blank" rel="noopener">Full changelog</a>
            <button data-close>Got it</button>
        </div>
    </div>
</div>

<script>window.MEMOIR = {csrf: document.querySelector('meta[name="csrf-token"]').content};</script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
</body>
</html>
