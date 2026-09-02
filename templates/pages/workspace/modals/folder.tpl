{*
    Workspace dialog: create/edit folder (name, icon grid, colour row).
    Included by pages/workspace/index.tpl.
*}
<!-- Modal: create folder -->
<div class="modal-backdrop hidden" id="folderModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="folderModalTitle">
        <h3 id="folderModalTitle">New folder</h3>

        <label>Name</label>
        <input id="folderName" placeholder="e.g. Server notes" autocomplete="off">

        <label>Icon</label>
        <div class="icon-grid" id="folderIcons">
            {foreach $folderIcons as $icon}
            <button data-icon="{$icon}"><i class="fa-solid {$icon}"></i></button>
            {/foreach}
        </div>

        <label>Color</label>
        <div class="color-row" id="folderColors">
            {foreach $accentColors as $color}
            <button data-color="{$color}" style="background:{$color}"></button>
            {/foreach}
        </div>

        <div class="modal-actions">
            <button data-close>Cancel</button>
            <button class="primary-btn" id="saveFolder">Create folder</button>
        </div>
    </div>
</div>
