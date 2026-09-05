{*
    Workspace dialog: what's new / release notes for the installed version.
    All copy is static; only the version number comes from the controller.
    Included by pages/workspace/index.tpl.
*}
<!-- Modal: what's new / release notes -->
<div class="modal-backdrop hidden" id="whatsNewModal">
    <div class="modal release-modal" role="dialog" aria-modal="true" aria-labelledby="whatsNewTitle">
        <div class="release-head">
            <img src="assets/img/memoir-logo.png" alt="">
            <div>
                <span class="release-label">Memoir {$version}</span>
                <h3 id="whatsNewTitle">Share a note, see who did what</h3>
            </div>
        </div>

        <p class="release-copy">Memoir 2.0.3 adds collaborators and an activity log, so you can invite someone to edit a note and keep track of changes.</p>

        <ul class="release-list">
            <li>
                <i class="fa-solid fa-user-plus"></i>
                <div>
                    <strong>Invite collaborators</strong>
                    <span>Share a note with someone by email. They accept via a secure link and can edit that note right away.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-clock-rotate-left"></i>
                <div>
                    <strong>Activity log</strong>
                    <span>Every note tracks who edited, shared, trashed, or restored it. Owners get a workspace-wide activity view in Settings.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-lock"></i>
                <div>
                    <strong>Scoped access</strong>
                    <span>Collaborators only ever see the notes shared with them — folders, settings, and backups stay with the workspace owner.</span>
                </div>
            </li>
        </ul>

        <div class="modal-actions">
            <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener">Full changelog</a>
            <button data-close>Got it</button>
        </div>
    </div>
</div>
