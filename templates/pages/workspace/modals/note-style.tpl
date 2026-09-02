{*
    Workspace dialog: note appearance (icon grid and accent colour row).
    Included by pages/workspace/index.tpl.
*}
<!-- Modal: note appearance -->
<div class="modal-backdrop hidden" id="styleModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="styleModalTitle">
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

        <div class="modal-actions">
            <button data-close>Close</button>
        </div>
    </div>
</div>
