{*
    Workspace dialog: note appearance (icon grid, accent colour row, and
    cover image picker). Included by pages/workspace/index.tpl.
*}
<!-- Modal: note appearance -->
<div class="modal-backdrop hidden" id="styleModal">
    <div class="modal wide" role="dialog" aria-modal="true" aria-labelledby="styleModalTitle">
        <h3 id="styleModalTitle">Note appearance</h3>

        <label>Icon</label>
        <div class="icon-grid" id="noteIcons">
            {foreach $noteIcons as $icon}
            <button data-icon="{$icon}"><i class="fa-solid {$icon}"></i></button>
            {/foreach}
        </div>

        <label>Accent color</label>
        <div class="color-row" id="noteColors">
            {foreach $accentColors as $color}
            <button data-color="{$color}" style="background:{$color}"></button>
            {/foreach}
        </div>

        <label>Cover image</label>
        <div class="background-grid" id="noteBackgrounds">
            <button type="button" class="background-none" data-background="" title="No cover"><i class="fa-solid fa-ban"></i></button>
            {foreach $noteBackgrounds as $id => $label}
            <button type="button" data-background="{$id}" style='background-image:url({"assets/img/note-bg-{$id}.svg"|asset})' title="{$label}"></button>
            {/foreach}
        </div>

        <div class="modal-actions">
            <button data-close>Close</button>
        </div>
    </div>
</div>
