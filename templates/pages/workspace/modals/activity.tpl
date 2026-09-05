{*
    Workspace dialog: activity log for the open note (who edited, shared,
    trashed, or invited on this note, and when).
    Included by pages/workspace/index.tpl.
*}
<!-- Modal: note activity -->
<div class="modal-backdrop hidden" id="activityModal">
    <div class="modal wide activity-modal" role="dialog" aria-modal="true" aria-labelledby="activityModalTitle">
        <div class="history-head">
            <div>
                <h3 id="activityModalTitle">Activity</h3>
                <p>Who did what on this note, most recent first.</p>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Close activity"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="activity-list" id="activityList"><span class="history-empty">Loading activity…</span></div>
        <div class="modal-actions">
            <button type="button" data-close>Close</button>
        </div>
    </div>
</div>
