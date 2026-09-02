{*
    Workspace dialog: note version history (snapshot list, preview, restore).
    Included by pages/workspace/index.tpl.
*}
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
