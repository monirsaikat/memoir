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
                <h3 id="whatsNewTitle">Two new premium themes</h3>
            </div>
        </div>

        <p class="release-copy">Memoir 2.0.5 adds Velvet and Linen, two textured premium themes, and fixes Nord and Soft so they finally show up in the theme picker.</p>

        <ul class="release-list">
            <li>
                <i class="fa-solid fa-crown"></i>
                <div>
                    <strong>Velvet</strong>
                    <span>A dark plum workspace with a fine grain-and-gold-fleck texture across the sidebar, notes, and editor.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-scroll"></i>
                <div>
                    <strong>Linen</strong>
                    <span>A warm ivory workspace with a subtle woven-fabric texture — soft, quiet, and easy on the eyes.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-snowflake"></i>
                <div>
                    <strong>Nord &amp; Soft are back</strong>
                    <span>Both themes were fully styled but missing from the theme picker — they're now selectable in Settings → Appearance.</span>
                </div>
            </li>
        </ul>

        <div class="modal-actions">
            <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener">Full changelog</a>
            <button data-close>Got it</button>
        </div>
    </div>
</div>
