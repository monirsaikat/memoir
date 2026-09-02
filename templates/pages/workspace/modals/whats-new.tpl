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
                <h3 id="whatsNewTitle">A cleaner foundation</h3>
            </div>
        </div>

        <p class="release-copy">Memoir 2.0.1 brings two new workspace styles and a cleaner, more consistent interface foundation.</p>

        <ul class="release-list">
            <li>
                <i class="fa-solid fa-snowflake"></i>
                <div>
                    <strong>Nord theme</strong>
                    <span>An arctic blue dark workspace with crisp contrast across notes, dialogs, code, and navigation.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-cloud-sun"></i>
                <div>
                    <strong>Soft theme</strong>
                    <span>A calm pastel light workspace designed for comfortable, distraction-free writing.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-layer-group"></i>
                <div>
                    <strong>Consistent everywhere</strong>
                    <span>Every page now shares the same template system, and all themes work across sign-in, password reset, and the changelog.</span>
                </div>
            </li>
        </ul>

        <div class="modal-actions">
            <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener">Full changelog</a>
            <button data-close>Got it</button>
        </div>
    </div>
</div>
