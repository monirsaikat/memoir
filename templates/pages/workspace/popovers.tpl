{*
    Workspace popovers positioned by JavaScript: quick switcher (Ctrl+P),
    share menu, wiki-link suggestions, sort menu, folder options and the
    move-to-folder picker.
    Included by pages/workspace/index.tpl.
*}
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

    <div class="share-divider"></div>
    <p class="share-hint">People with access can edit this note.</p>
    <form id="inviteForm" class="invite-form">
        <input id="inviteEmail" type="email" placeholder="Email address" autocomplete="off" required>
        <button type="submit" class="primary-btn">Invite</button>
    </form>
    <span id="inviteStatus" class="pw-status"></span>
    <div id="collaboratorList" class="collaborator-list"></div>
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
