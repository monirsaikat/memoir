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
                <h3 id="whatsNewTitle">A fresh space for your ideas</h3>
            </div>
        </div>

        <p class="release-copy">Memoir 2.1.0 brings a redesigned workspace with clearer navigation, comfortable typography, and a calmer writing experience.</p>

        <ul class="release-list">
            <li>
                <i class="fa-solid fa-palette"></i>
                <div>
                    <strong>Your workspace, refined</strong>
                    <span>Compact note rows, 13 refreshed themes, and locally hosted Inter fonts. Your existing theme and accent choices stay with you.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-pen-to-square"></i>
                <div>
                    <strong>More room to write</strong>
                    <span>Long titles wrap naturally, the document scrolls independently, and secondary note actions are neatly grouped in the More menu.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-mobile-screen"></i>
                <div>
                    <strong>Better on every screen</strong>
                    <span>Tablet and phone navigation, reliable dialog placement, and improved keyboard focus make your notes easier to reach.</span>
                </div>
            </li>
        </ul>

        <div class="modal-actions">
            <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener">Full changelog</a>
            <button data-close>Got it</button>
        </div>
    </div>
</div>
