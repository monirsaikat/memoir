<?php
require __DIR__ . '/bootstrap.php';

$user = require_auth();
$csrfToken = csrf_token();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
ensure_schema();
maybe_create_automatic_backup();

$settings = db()->query("SELECT * FROM settings WHERE id=1")->fetch();

$folders = db()->query(
    "SELECT f.*, (SELECT COUNT(*) FROM notes n WHERE n.folder_id = f.id AND n.deleted_at IS NULL) note_count
     FROM folders f
     ORDER BY sort_order, name"
)->fetchAll();

$notes = db()->query(
    "SELECT n.*, f.name folder_name
     FROM notes n
     LEFT JOIN folders f ON f.id = n.folder_id
     WHERE n.deleted_at IS NULL
     ORDER BY n.is_pinned DESC, n.updated_at DESC
     LIMIT 100"
)->fetchAll();

$trashCount = (int) db()->query("SELECT COUNT(*) FROM notes WHERE deleted_at IS NOT NULL")->fetchColumn();

$tagCounts = [];
foreach (db()->query("SELECT tags FROM notes WHERE tags <> '' AND deleted_at IS NULL")->fetchAll() as $row) {
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title><?= e($settings['app_name'] ?? 'Memoir') ?></title>
    <script>
    // Apply the saved theme before first paint to avoid a light/dark flash.
    (function () {
        try {
            var choice = localStorage.getItem('memoir-theme') || 'system';
            var darkFlavors = { dark: 1, ocean: 1, midnight: 1, forest: 1, dusk: 1, aurora: 1, nord: 1 };
            if (choice === 'system') {
                choice = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            if (!/^(light|dark|sepia|ocean|midnight|forest|dusk|aurora|paper|nord|soft)$/.test(choice)) choice = 'light';
            document.documentElement.dataset.theme = choice;
            document.documentElement.dataset.mode = darkFlavors[choice] ? 'dark' : 'light';
            var accent = localStorage.getItem('memoir-accent');
            if (accent && /^#[0-9a-fA-F]{6}$/.test(accent)) {
                document.documentElement.style.setProperty('--accent', accent);
            }
        } catch (e) {}
    })();
    </script>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#6f5ee8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" id="hlThemeLight" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-light.min.css">
    <link rel="stylesheet" id="hlThemeDark" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <script>
    // Keep only the highlight palette matching the active theme mode.
    (function () {
        var dark = document.documentElement.dataset.mode === 'dark';
        document.getElementById('hlThemeLight').disabled = dark;
        document.getElementById('hlThemeDark').disabled = !dark;
    })();
    </script>
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

        <div class="sidebar-scroll">
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
            <button class="nav-item" data-trash="1">
                <i class="fa-regular fa-trash-can"></i>
                <span>Trash</span>
                <span class="count" id="trashCount"><?= $trashCount ?></span>
            </button>
        </nav>

        <div class="section-title">
            <span>Folders</span>
            <button id="addFolder" title="Add folder"><i class="fa-solid fa-plus"></i></button>
        </div>

        <div id="folderList" class="folder-list">
            <?php foreach ($folders as $folder): ?>
            <div class="folder-row">
                <button class="folder-item" data-folder="<?= $folder['id'] ?>">
                    <i class="fa-solid <?= e($folder['icon']) ?>" style="color:<?= e($folder['color']) ?>"></i>
                    <span><?= e($folder['name']) ?></span>
                    <span class="count"><?= $folder['note_count'] ?></span>
                </button>
                <button class="folder-menu-btn" data-folder="<?= $folder['id'] ?>" type="button" aria-label="Folder options">
                    <i class="fa-solid fa-ellipsis"></i>
                </button>
            </div>
            <?php endforeach ?>
        </div>

        <div class="section-title" id="tagSectionTitle" <?= $tagCounts ? '' : 'hidden' ?>><span>Tags</span></div>
        <div id="tagList" class="tag-list">
            <?php foreach ($tagCounts as $tag => $count): ?>
            <button class="tag-item" data-tag="<?= e($tag) ?>">#<?= e($tag) ?><span class="count"><?= $count ?></span></button>
            <?php endforeach ?>
        </div>
        </div>

        <div class="sidebar-bottom">
            <button id="whatsNewBtn">
                <i class="fa-solid fa-sparkles"></i> What’s new
                <span class="version-pill">v<?= e(MEMOIR_VERSION) ?></span>
            </button>
            <button id="settingsBtn"><i class="fa-solid fa-sliders"></i> Settings</button>
            <form method="post" action="logout.php">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
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
            <div class="list-head-actions">
                <button id="sortBtn" class="icon-btn" type="button" title="Sort notes">
                    <i class="fa-solid fa-arrow-down-wide-short"></i>
                </button>
                <button id="selectModeBtn" class="icon-btn" type="button" title="Select notes">
                    <i class="fa-solid fa-check-double"></i>
                </button>
                <button id="collapseSidebar" class="icon-btn mobile-only" type="button" aria-label="Open navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>

        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="globalSearch" type="search" name="memoir_note_search" placeholder="Search notes"
                   aria-label="Search notes" autocomplete="off" autocapitalize="off" spellcheck="false"
                   readonly data-1p-ignore data-lpignore="true" data-form-type="other">
            <button type="button" id="searchFilterBtn" class="search-filter-btn" aria-label="Advanced search" title="Advanced search">
                <i class="fa-solid fa-sliders"></i>
            </button>
            <kbd>⌘ K</kbd>
            <div class="search-filter-panel hidden" id="searchFilterPanel">
                <div class="search-filter-head">
                    <strong>Advanced search</strong>
                    <button type="button" id="clearSearchFilters">Clear</button>
                </div>
                <label>Search in
                    <select id="searchScope">
                        <option value="all">Title, content, and tags</option>
                        <option value="title">Title only</option>
                        <option value="content">Content only</option>
                        <option value="tags">Tags only</option>
                    </select>
                </label>
                <div class="search-filter-grid">
                    <label>Pin status
                        <select id="searchPinned">
                            <option value="">Any</option>
                            <option value="1">Pinned</option>
                            <option value="0">Not pinned</option>
                        </select>
                    </label>
                    <label>Location
                        <select id="searchState">
                            <option value="">Current view</option>
                            <option value="active">Active notes</option>
                            <option value="trash">Trash</option>
                            <option value="all">Active and Trash</option>
                        </select>
                    </label>
                    <label>Updated after<input id="searchAfter" type="date"></label>
                    <label>Updated before<input id="searchBefore" type="date"></label>
                </div>
                <p>Power search: <code>tag:work</code> <code>folder:"Ideas"</code> <code>is:pinned</code> <code>before:2026-09-01</code> <code>in:title</code></p>
                <button type="button" class="primary-btn search-apply" id="applySearchFilters">Apply filters</button>
            </div>
        </div>
        <div class="active-search-filters hidden" id="activeSearchFilters"></div>

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

        <!-- Bulk actions shown while selecting notes -->
        <div class="bulk-bar hidden" id="bulkBar">
            <div class="bulk-summary">
                <span id="bulkCount">0 selected</span>
                <button type="button" id="bulkCancel" aria-label="Cancel selection" title="Cancel selection"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="bulk-actions">
                <button type="button" id="bulkSelectAll"><i class="fa-solid fa-check-double"></i> Select all</button>
                <button type="button" id="bulkRestore" class="hidden"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                <button type="button" id="bulkDelete" class="bulk-danger"><i class="fa-regular fa-trash-can"></i> <span id="bulkDeleteLabel">Delete</span></button>
            </div>
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
            <div class="trash-banner hidden" id="trashBanner">
                <i class="fa-regular fa-trash-can"></i>
                <span>This note is in the Trash.</span>
                <button type="button" id="restoreNote">Restore</button>
                <button type="button" id="destroyNote" class="danger">Delete forever</button>
            </div>
            <header class="editor-head">
                <button class="icon-btn mobile-only" id="backToList" type="button" aria-label="Back to notes">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <div class="crumb">
                    <button type="button" class="crumb-btn" id="crumbFolder" title="Move to folder">Unfiled</button>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span id="saveStatus">Saved</span>
                </div>
                <div class="editor-actions">
                    <button class="icon-btn" id="shareNote" title="Share note" aria-label="Share note"><i class="fa-solid fa-share-nodes"></i></button>
                    <button class="icon-btn" id="historyNote" title="Version history" aria-label="Version history"><i class="fa-solid fa-clock-rotate-left"></i></button>
                    <button class="icon-btn" id="pinNote" title="Pin" aria-label="Pin note"><i class="fa-solid fa-thumbtack"></i></button>
                    <button class="icon-btn" id="noteStyle" title="Note style" aria-label="Note style"><i class="fa-solid fa-palette"></i></button>
                    <button class="icon-btn danger" id="deleteNote" title="Delete" aria-label="Delete note"><i class="fa-regular fa-trash-can"></i></button>
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
                            <button type="button" class="tool-label" id="headingBtn" title="Headings (# … ###### + space)">
                                <span id="headingLabel">H</span><i class="fa-solid fa-chevron-down heading-caret"></i>
                            </button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="insertUnorderedList" data-state="insertUnorderedList" title="Bullet list (- + space)"><i class="fa-solid fa-list-ul"></i></button>
                            <button type="button" data-cmd="insertOrderedList" data-state="insertOrderedList" title="Numbered list (1. + space)"><i class="fa-solid fa-list-ol"></i></button>
                            <button type="button" id="checklistBtn" title="Task list ([] + space)"><i class="fa-solid fa-list-check"></i></button>
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
                            <button type="button" id="insertTableBtn" title="Insert table"><i class="fa-solid fa-table"></i></button>
                            <button type="button" data-cmd="insertHorizontalRule" title="Divider (--- + Enter)"><i class="fa-solid fa-minus"></i></button>
                        </div>
                        <span class="tool-sep"></span>
                        <div class="tool-group">
                            <button type="button" data-cmd="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
                        </div>
                        <input type="file" id="imageInput" accept="image/*" hidden>
                    </div>

                    <!-- Heading picker; anchored below the H button by JS -->
                    <div class="heading-sheet hidden" id="headingSheet" role="menu">
                        <button type="button" data-h="p"><span class="hs-normal">Normal text</span></button>
                        <?php for ($level = 1; $level <= 6; $level++): ?>
                        <button type="button" data-h="h<?= $level ?>">
                            <span class="hs-h<?= $level ?>">Heading <?= $level ?></span>
                            <kbd><?= str_repeat('#', $level) ?></kbd>
                        </button>
                        <?php endfor ?>
                    </div>

                    <!-- Color picker sheet; anchored below its toolbar button by JS -->
                    <div class="color-sheet hidden" id="colorSheet" role="menu">
                        <div class="color-sheet-title" id="colorSheetTitle">Text color</div>
                        <div class="swatch-row" id="colorSwatches"></div>
                        <button type="button" class="swatch-clear" id="colorClear">Remove color</button>
                    </div>
                </div>

                <div id="noteContent" class="rich-editor" contenteditable="true" spellcheck="true"></div>

                <div class="references hidden" id="backlinks">
                    <section class="reference-group hidden" id="linkedReferences">
                        <div class="reference-heading">
                            <span><i class="fa-solid fa-arrow-turn-up"></i> Linked references</span>
                            <span class="reference-count" id="linkedReferenceCount">0</span>
                        </div>
                        <div class="reference-list" id="backlinkList"></div>
                    </section>
                    <section class="reference-group hidden" id="unlinkedReferences">
                        <div class="reference-heading">
                            <span><i class="fa-solid fa-magnifying-glass"></i> Unlinked mentions</span>
                            <span class="reference-count" id="unlinkedReferenceCount">0</span>
                        </div>
                        <p class="reference-help">These notes mention this title in plain text but do not link to it yet.</p>
                        <div class="reference-list" id="unlinkedMentionList"></div>
                    </section>
                </div>
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

        <!-- Table tools shown while the caret is inside a table -->
        <div class="table-menu hidden" id="tableMenu" role="toolbar" aria-label="Table tools">
            <button type="button" data-tbl="addRow" title="Add row below">+ Row</button>
            <button type="button" data-tbl="addCol" title="Add column right">+ Col</button>
            <button type="button" data-tbl="delRow" title="Delete row">&minus; Row</button>
            <button type="button" data-tbl="delCol" title="Delete column">&minus; Col</button>
            <span class="b-sep"></span>
            <button type="button" data-tbl="delTable" title="Delete table"><i class="fa-regular fa-trash-can"></i></button>
        </div>
    </main>

</div>

<!-- Quick switcher (Ctrl+P) -->
<div class="palette-backdrop hidden" id="palette">
    <div class="palette" role="dialog" aria-label="Jump to note">
        <div class="palette-head">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input id="paletteInput" placeholder="Jump to a note…" autocomplete="off" spellcheck="false">
            <kbd>Esc</kbd>
        </div>
        <div class="palette-results" id="paletteResults"></div>
    </div>
</div>

<!-- Popover: share this note -->
<div class="mini-menu share-menu hidden" id="shareMenu">
    <div id="shareOff">
        <p class="share-hint">Create a read-only link anyone can open — no account needed. You can revoke it at any time.</p>
        <button type="button" class="primary-btn share-full" id="shareEnable">Create share link</button>
    </div>
    <div id="shareOn" class="hidden">
        <p class="share-hint">Anyone with this link can view the note.</p>
        <input id="shareUrl" readonly spellcheck="false">
        <div class="share-actions">
            <button type="button" id="copyShare"><i class="fa-regular fa-copy"></i> Copy link</button>
            <button type="button" id="shareDisable" class="danger">Stop sharing</button>
        </div>
    </div>
</div>

<!-- Popover: wiki-link suggestions while typing [[ -->
<div class="mini-menu hidden" id="wikiMenu" role="menu"></div>

<!-- Popover: sort options -->
<div class="mini-menu hidden" id="sortMenu" role="menu">
    <button type="button" data-sort="updated"><i class="fa-regular fa-clock"></i> Last updated</button>
    <button type="button" data-sort="created"><i class="fa-regular fa-calendar-plus"></i> Date created</button>
    <button type="button" data-sort="title"><i class="fa-solid fa-arrow-down-a-z"></i> Title A–Z</button>
</div>

<!-- Popover: folder options (rename / reorder / delete) -->
<div class="mini-menu hidden" id="folderMenu" role="menu">
    <button type="button" data-fm="edit"><i class="fa-solid fa-pen"></i> Edit folder</button>
    <button type="button" data-fm="up"><i class="fa-solid fa-arrow-up"></i> Move up</button>
    <button type="button" data-fm="down"><i class="fa-solid fa-arrow-down"></i> Move down</button>
    <button type="button" data-fm="delete" class="danger"><i class="fa-regular fa-trash-can"></i> Delete folder</button>
</div>

<!-- Popover: move the open note to a folder -->
<div class="mini-menu hidden" id="folderPicker" role="menu"></div>

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

<!-- Modal: note version history -->
<div class="modal-backdrop hidden" id="historyModal">
    <div class="modal wide history-modal" role="dialog" aria-modal="true" aria-labelledby="historyModalTitle">
        <div class="history-head">
            <div>
                <h3 id="historyModalTitle">Version history</h3>
                <p>Memoir keeps up to 100 snapshots, coalesced during active editing.</p>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close history"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="history-layout">
            <div class="history-list" id="historyList"><span class="history-empty">Loading history…</span></div>
            <div class="history-preview">
                <div class="history-preview-meta" id="historyPreviewMeta">Select a version to preview</div>
                <h2 id="historyPreviewTitle"></h2>
                <div id="historyPreviewContent"></div>
            </div>
        </div>
        <div class="modal-actions">
            <span class="history-status" id="historyStatus"></span>
            <button type="button" data-close>Close</button>
            <button type="button" class="primary-btn" id="restoreVersion" disabled>Restore this version</button>
        </div>
    </div>
</div>

<!-- Modal: settings -->
<div class="modal-backdrop hidden" id="settingsModal">
    <div class="modal settings-modal" role="dialog" aria-modal="true" aria-labelledby="settingsModalTitle">
        <div class="settings-layout">
            <nav class="settings-nav">
                <span class="settings-nav-title" id="settingsModalTitle">Settings</span>
                <button type="button" class="active" data-pane="appearance"><i class="fa-solid fa-palette"></i> Appearance</button>
                <button type="button" data-pane="general"><i class="fa-solid fa-sliders"></i> General</button>
                <button type="button" data-pane="email"><i class="fa-solid fa-envelope"></i> Email</button>
                <button type="button" data-pane="account"><i class="fa-solid fa-user-shield"></i> Account</button>
                <button type="button" data-pane="data"><i class="fa-solid fa-file-import"></i> Data</button>
                <button type="button" data-pane="updates"><i class="fa-solid fa-cloud-arrow-down"></i> Updates <span class="update-nav-badge hidden" id="updateNavBadge">1</span></button>
                <div class="settings-nav-foot">Memoir v<?= e(MEMOIR_VERSION) ?></div>
            </nav>

            <div class="settings-pane">
                <section class="settings-panel" data-panel="appearance">
                    <h4>Theme</h4>
                    <div class="theme-cards" id="themeToggle" role="radiogroup" aria-label="Theme">
                        <button type="button" data-theme-opt="light">
                            <span class="theme-thumb thumb-light"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-sun"></i> Light</span>
                        </button>
                        <button type="button" data-theme-opt="dark">
                            <span class="theme-thumb thumb-dark"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-moon"></i> Dark</span>
                        </button>
                        <button type="button" data-theme-opt="system">
                            <span class="theme-thumb thumb-system">
                                <span class="tt-half thumb-light"><span class="tt-side"></span><span class="tt-main"><span></span><span></span></span></span>
                                <span class="tt-half thumb-dark"><span class="tt-side"></span><span class="tt-main"><span></span><span></span></span></span>
                            </span>
                            <span class="theme-name"><i class="fa-solid fa-circle-half-stroke"></i> System</span>
                        </button>
                        <button type="button" data-theme-opt="sepia">
                            <span class="theme-thumb thumb-sepia"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-mug-saucer"></i> Sepia</span>
                        </button>
                        <button type="button" data-theme-opt="ocean">
                            <span class="theme-thumb thumb-ocean"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-water"></i> Ocean</span>
                        </button>
                        <button type="button" data-theme-opt="midnight">
                            <span class="theme-thumb thumb-midnight"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-star"></i> Midnight</span>
                        </button>
                        <button type="button" data-theme-opt="forest">
                            <span class="theme-thumb thumb-forest"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-tree"></i> Forest</span>
                        </button>
                        <button type="button" data-theme-opt="dusk">
                            <span class="theme-thumb thumb-dusk"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-cloud-moon"></i> Dusk</span>
                        </button>
                        <button type="button" data-theme-opt="aurora">
                            <span class="theme-thumb thumb-aurora"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-wand-magic-sparkles"></i> Aurora</span>
                        </button>
                        <button type="button" data-theme-opt="paper">
                            <span class="theme-thumb thumb-paper"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            <span class="theme-name"><i class="fa-solid fa-file-lines"></i> Paper</span>
                        </button>
                    </div>

                    <h4>Accent color</h4>
                    <div class="accent-row" id="accentRow" role="radiogroup" aria-label="Accent color">
                        <?php foreach (['#6F5EE8', '#3F7FC2', '#2E9E8F', '#3D8F68', '#C98A2D', '#D65C7E', '#C75454', '#64748B'] as $accent): ?>
                        <button type="button" data-accent="<?= $accent ?>" style="background:<?= $accent ?>" aria-label="<?= $accent ?>"></button>
                        <?php endforeach ?>
                    </div>
                </section>

                <section class="settings-panel hidden" data-panel="general">
                    <h4>General</h4>
                    <div class="settings-grid">
                        <div class="full">
                            <label>App name</label>
                            <input id="setAppName" autocomplete="off" value="<?= e($settings['app_name'] ?? 'Memoir') ?>">
                        </div>
                    </div>
                </section>

                <section class="settings-panel hidden" data-panel="email">
                    <h4>Email (SMTP)</h4>
                    <div class="settings-grid">
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
                </section>

                <section class="settings-panel hidden" data-panel="account">
                    <h4>Change password</h4>
                    <div class="settings-grid">
                        <div class="full">
                            <label>Current password</label>
                            <input type="password" id="pwCurrent" autocomplete="current-password">
                        </div>
                        <div>
                            <label>New password</label>
                            <input type="password" id="pwNew" minlength="12" autocomplete="new-password">
                        </div>
                        <div>
                            <label>Confirm new password</label>
                            <input type="password" id="pwConfirm" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="pw-actions">
                        <span id="pwStatus" class="pw-status"></span>
                        <button type="button" class="primary-btn" id="changePassword">Update password</button>
                    </div>
                </section>

                <section class="settings-panel hidden" data-panel="data">
                    <h4>Import notes</h4>
                    <p class="settings-hint">Import Markdown (.md) or plain-text files — each file becomes a note. Headings, lists, task lists, code blocks, and links are preserved. Up to 50 files, 1&nbsp;MB each.</p>
                    <input type="file" id="importFiles" multiple accept=".md,.markdown,.txt" hidden>
                    <div class="pw-actions">
                        <span id="importStatus" class="pw-status"></span>
                        <button type="button" class="primary-btn" id="importBtn">Choose files…</button>
                    </div>

                    <div class="data-divider"></div>
                    <h4>Workspace backup</h4>
                    <p class="settings-hint">Backups contain folders, notes, Trash, and version history. Passwords and public share tokens are never exported.</p>
                    <div class="data-actions">
                        <button type="button" id="downloadBackup"><i class="fa-solid fa-download"></i> Download backup</button>
                        <button type="button" id="backupNow"><i class="fa-solid fa-hard-drive"></i> Save on server now</button>
                    </div>
                    <span id="backupStatus" class="pw-status"><?= !empty($settings['backup_last_at']) ? 'Last server backup: ' . e($settings['backup_last_at']) : 'No server backup has run yet.' ?></span>

                    <div class="backup-schedule">
                        <label class="check-row"><input type="checkbox" id="backupEnabled" <?= !isset($settings['backup_enabled']) || $settings['backup_enabled'] ? 'checked' : '' ?>> Automatic server backups</label>
                        <label>Every
                            <select id="backupInterval">
                                <?php foreach ([1, 6, 12, 24, 72, 168] as $hours): ?>
                                <?php $intervalLabel = $hours < 24 ? $hours . ' ' . ($hours === 1 ? 'hour' : 'hours') : ($hours / 24) . ' ' . ($hours === 24 ? 'day' : 'days'); ?>
                                <option value="<?= $hours ?>" <?= (int) ($settings['backup_interval_hours'] ?? 24) === $hours ? 'selected' : '' ?>><?= $intervalLabel ?></option>
                                <?php endforeach ?>
                            </select>
                        </label>
                        <label>Keep
                            <input type="number" id="backupKeep" min="1" max="50" value="<?= (int) ($settings['backup_keep'] ?? 7) ?>">
                        </label>
                    </div>

                    <div class="data-divider"></div>
                    <h4>Restore workspace</h4>
                    <p class="settings-hint">Restoring replaces the current folders, notes, Trash, and history. Memoir creates a server-side safety backup first.</p>
                    <input type="file" id="restoreBackupFile" accept="application/json,.json" hidden>
                    <div class="data-actions">
                        <button type="button" class="danger-outline" id="restoreBackup"><i class="fa-solid fa-rotate-left"></i> Choose backup to restore</button>
                    </div>
                    <span id="restoreBackupStatus" class="pw-status"></span>
                </section>

                <section class="settings-panel hidden" data-panel="updates">
                    <h4>Software updates</h4>
                    <div class="update-card">
                        <div class="update-icon" id="updateIcon"><i class="fa-solid fa-cloud-arrow-down"></i></div>
                        <div class="update-copy">
                            <strong id="updateTitle">Memoir is checking for updates</strong>
                            <p id="updateSummary">Installed version <?= e(MEMOIR_VERSION) ?></p>
                        </div>
                    </div>
                    <dl class="update-details">
                        <div><dt>Installed</dt><dd>v<?= e(MEMOIR_VERSION) ?></dd></div>
                        <div><dt>Latest</dt><dd id="updateLatest">Checking…</dd></div>
                        <div><dt>Last checked</dt><dd id="updateChecked">Never</dd></div>
                    </dl>
                    <p class="settings-hint" id="updateCapability">Memoir checks GitHub once per day. Updates are never installed automatically.</p>
                    <pre class="update-notes hidden" id="updateNotes"></pre>
                    <div class="data-actions update-actions">
                        <button type="button" id="checkUpdate"><i class="fa-solid fa-rotate"></i> Check for updates</button>
                        <button type="button" class="primary-btn hidden" id="installUpdate"><i class="fa-solid fa-download"></i> Update now</button>
                        <a class="hidden" id="viewRelease" href="#" target="_blank" rel="noopener noreferrer">View release notes</a>
                    </div>
                    <span id="updateStatus" class="pw-status" role="status" aria-live="polite"></span>
                </section>
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
                <h3 id="whatsNewTitle">Faster where it matters</h3>
            </div>
        </div>

        <p class="release-copy">Long notes now keep up with your typing, with a luminous new workspace and stronger update connectivity.</p>

        <ul class="release-list">
            <li>
                <i class="fa-solid fa-gauge-high"></i>
                <div>
                    <strong>Fast long-note editing</strong>
                    <span>Word counts and background editor work no longer compete with each keystroke in large documents.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <div>
                    <strong>Aurora theme</strong>
                    <span>A deep arctic-teal dark theme with cyan and violet light across the complete workspace.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-cloud-arrow-down"></i>
                <div>
                    <strong>More reliable updates</strong>
                    <span>IPv4 preference, longer connection time, and a release-feed fallback improve shared-host compatibility.</span>
                </div>
            </li>
        </ul>

        <div class="modal-actions">
            <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener">Full changelog</a>
            <button data-close>Got it</button>
        </div>
    </div>
</div>

<script>window.MEMOIR = {
    csrf: document.querySelector('meta[name="csrf-token"]').content,
    initialNotes: <?= json_encode($clientNotes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>,
    initialActiveComplete: <?= count($notes) < 100 ? 'true' : 'false' ?>,
    initialContentComplete: <?= $allNoteContentCached ? 'true' : 'false' ?>
};</script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script>
// Syntax highlighting is useful, but it must not hold the whole application
// hostage on a slow CDN connection. Load it once the editor is interactive.
(window.requestIdleCallback || function (fn) { setTimeout(fn, 1); })(function () {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js';
    script.onload = function () { window.dispatchEvent(new Event('memoir:highlight-ready')); };
    document.head.appendChild(script);
});
</script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
}
</script>
</body>
</html>
