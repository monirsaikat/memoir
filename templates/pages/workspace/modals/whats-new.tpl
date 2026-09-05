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
                <h3 id="whatsNewTitle">Cover images for notes</h3>
            </div>
        </div>

        <p class="release-copy">Memoir 2.0.10 lets you give a note an illustrated cover image, right from the appearance menu.</p>

        <ul class="release-list">
            <li>
                <i class="fa-solid fa-image"></i>
                <div>
                    <strong>Eight cover images</strong>
                    <span>Mountains, Ocean, Meadow, Night Sky, Sunset, Forest, Terminal, and Bubbles — pick one from Note appearance to add a banner to the top of a note.</span>
                </div>
            </li>
            <li>
                <i class="fa-solid fa-clock-rotate-left"></i>
                <div>
                    <strong>Tracked in history</strong>
                    <span>Changing or removing a cover creates a version snapshot, same as editing the title or content.</span>
                </div>
            </li>
        </ul>

        <div class="modal-actions">
            <a class="changelog-link" href="changelog.php" target="_blank" rel="noopener">Full changelog</a>
            <button data-close>Got it</button>
        </div>
    </div>
</div>
