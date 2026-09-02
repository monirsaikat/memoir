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
