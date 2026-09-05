{*
    Workspace dialog: settings with its panes (appearance, general, email,
    account, data, updates). Theme cards come from $themes (the `system` card
    has a split thumbnail), accent swatches from $themeAccents, the backup
    interval options from $backupIntervals; current values from $settings.
    Included by pages/workspace/index.tpl.
*}
<!-- Modal: settings -->
<div class="modal-backdrop hidden" id="settingsModal">
    <div class="modal settings-modal" role="dialog" aria-modal="true" aria-labelledby="settingsModalTitle">
        <div class="settings-layout">
            <nav class="settings-nav">
                <span class="settings-nav-title" id="settingsModalTitle">Settings</span>
                <button type="button" class="active" data-pane="appearance"><i class="fa-solid fa-palette"></i> Appearance</button>
                {if $userRole === 'owner'}
                <button type="button" data-pane="general"><i class="fa-solid fa-sliders"></i> General</button>
                <button type="button" data-pane="email"><i class="fa-solid fa-envelope"></i> Email</button>
                {/if}
                <button type="button" data-pane="account"><i class="fa-solid fa-user-shield"></i> Account</button>
                {if $userRole === 'owner'}
                <button type="button" data-pane="data"><i class="fa-solid fa-file-import"></i> Data</button>
                <button type="button" data-pane="activity"><i class="fa-solid fa-clock-rotate-left"></i> Activity</button>
                <button type="button" data-pane="updates"><i class="fa-solid fa-cloud-arrow-down"></i> Updates <span class="update-nav-badge hidden" id="updateNavBadge">1</span></button>
                {/if}
                <div class="settings-nav-foot">Memoir v{$version}</div>
            </nav>

            <div class="settings-pane">
                <section class="settings-panel" data-panel="appearance">
                    <h4>Theme</h4>
                    <div class="theme-cards" id="themeToggle" role="radiogroup" aria-label="Theme">
                        {foreach $themes as $theme}
                        <button type="button" data-theme-opt="{$theme.id}">
                            {if $theme.id === 'system'}
                            <span class="theme-thumb thumb-system">
                                <span class="tt-half thumb-light"><span class="tt-side"></span><span class="tt-main"><span></span><span></span></span></span>
                                <span class="tt-half thumb-dark"><span class="tt-side"></span><span class="tt-main"><span></span><span></span></span></span>
                            </span>
                            {else}
                            <span class="theme-thumb thumb-{$theme.id}"><span class="tt-side"></span><span class="tt-main"><span></span><span></span><span></span></span></span>
                            {/if}
                            <span class="theme-name"><i class="fa-solid {$theme.icon}"></i> {$theme.label}</span>
                        </button>
                        {/foreach}
                    </div>

                    <h4>Accent color</h4>
                    <div class="accent-row" id="accentRow" role="radiogroup" aria-label="Accent color">
                        {foreach $themeAccents as $accent}
                        <button type="button" data-accent="{$accent}" style="background:{$accent}" aria-label="{$accent}"></button>
                        {/foreach}
                    </div>
                </section>

                <section class="settings-panel hidden" data-panel="general">
                    <h4>General</h4>
                    <div class="settings-grid">
                        <div class="full">
                            <label>App name</label>
                            <input id="setAppName" autocomplete="off" value="{$appName}">
                        </div>
                    </div>
                </section>

                <section class="settings-panel hidden" data-panel="email">
                    <h4>Email (SMTP)</h4>
                    <div class="settings-grid">
                        <div>
                            <label>SMTP host</label>
                            <input id="setSmtpHost" autocomplete="off" value="{$settings.smtp_host|default:''}">
                        </div>
                        <div>
                            <label>SMTP port</label>
                            <input id="setSmtpPort" value="{$settings.smtp_port|default:587}">
                        </div>
                        <div>
                            <label>Security</label>
                            <select id="setSmtpSecurity">
                                <option value="tls"{if ($settings.smtp_security|default:'') === 'tls'} selected{/if}>TLS</option>
                                <option value="ssl"{if ($settings.smtp_security|default:'') === 'ssl'} selected{/if}>SSL</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                        <div>
                            <label>SMTP username</label>
                            <input id="setSmtpUser" autocomplete="off" value="{$settings.smtp_user|default:''}">
                        </div>
                        <div>
                            <label>SMTP password</label>
                            <input type="password" id="setSmtpPass" autocomplete="new-password" placeholder="Leave blank to keep current">
                        </div>
                        <div class="full">
                            <label>From email</label>
                            <input id="setSmtpFrom" value="{$settings.smtp_from|default:''}">
                        </div>
                    </div>
                </section>

                <section class="settings-panel hidden" data-panel="account">
                    <h4>Change password</h4>
                    <div class="settings-grid">
                        <div class="full">
                            <label>Current password</label>
                            <input type="password" id="pwCurrent" autocomplete="current-password">
                        </div>
                        <div>
                            <label>New password</label>
                            <input type="password" id="pwNew" minlength="12" autocomplete="new-password">
                        </div>
                        <div>
                            <label>Confirm new password</label>
                            <input type="password" id="pwConfirm" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="pw-actions">
                        <span id="pwStatus" class="pw-status"></span>
                        <button type="button" class="primary-btn" id="changePassword">Update password</button>
                    </div>
                </section>

                <section class="settings-panel hidden" data-panel="data">
                    <h4>Import notes</h4>
                    <p class="settings-hint">Import Markdown (.md) or plain-text files — each file becomes a note. Headings, lists, task lists, code blocks, and links are preserved. Up to 50 files, 1&nbsp;MB each.</p>
                    <input type="file" id="importFiles" multiple accept=".md,.markdown,.txt" hidden>
                    <div class="pw-actions">
                        <span id="importStatus" class="pw-status"></span>
                        <button type="button" class="primary-btn" id="importBtn">Choose files…</button>
                    </div>

                    <div class="data-divider"></div>
                    <h4>Workspace backup</h4>
                    <p class="settings-hint">Backups contain folders, notes, Trash, and version history. Passwords and public share tokens are never exported.</p>
                    <div class="data-actions">
                        <button type="button" id="downloadBackup"><i class="fa-solid fa-download"></i> Download backup</button>
                        <button type="button" id="backupNow"><i class="fa-solid fa-hard-drive"></i> Save on server now</button>
                    </div>
                    <span id="backupStatus" class="pw-status">{if !empty($settings.backup_last_at)}Last server backup: {$settings.backup_last_at}{else}No server backup has run yet.{/if}</span>

                    <div class="backup-schedule">
                        <label class="check-row"><input type="checkbox" id="backupEnabled"{if !isset($settings.backup_enabled) || $settings.backup_enabled} checked{/if}> Automatic server backups</label>
                        <label>Every
                            <select id="backupInterval">
                                {foreach $backupIntervals as $hours => $intervalLabel}
                                <option value="{$hours}"{if (int)($settings.backup_interval_hours|default:24) === $hours} selected{/if}>{$intervalLabel}</option>
                                {/foreach}
                            </select>
                        </label>
                        <label>Keep
                            <input type="number" id="backupKeep" min="1" max="50" value="{(int)($settings.backup_keep|default:7)}">
                        </label>
                    </div>

                    <div class="data-divider"></div>
                    <h4>Restore workspace</h4>
                    <p class="settings-hint">Restoring replaces the current folders, notes, Trash, and history. Memoir creates a server-side safety backup first.</p>
                    <input type="file" id="restoreBackupFile" accept="application/json,.json" hidden>
                    <div class="data-actions">
                        <button type="button" class="danger-outline" id="restoreBackup"><i class="fa-solid fa-rotate-left"></i> Choose backup to restore</button>
                    </div>
                    <span id="restoreBackupStatus" class="pw-status"></span>
                </section>

                <section class="settings-panel hidden" data-panel="activity">
                    <h4>Activity</h4>
                    <p class="settings-hint">The latest actions across your notes — edits, trash, restores, and collaborator changes.</p>
                    <div class="activity-list" id="globalActivityList"><span class="history-empty">Loading activity…</span></div>
                </section>

                <section class="settings-panel hidden" data-panel="updates">
                    <h4>Software updates</h4>
                    <div class="update-card">
                        <div class="update-icon" id="updateIcon"><i class="fa-solid fa-cloud-arrow-down"></i></div>
                        <div class="update-copy">
                            <strong id="updateTitle">Memoir is checking for updates</strong>
                            <p id="updateSummary">Installed version {$version}</p>
                        </div>
                    </div>
                    <dl class="update-details">
                        <div><dt>Installed</dt><dd>v{$version}</dd></div>
                        <div><dt>Latest</dt><dd id="updateLatest">Checking…</dd></div>
                        <div><dt>Last checked</dt><dd id="updateChecked">Never</dd></div>
                    </dl>
                    <p class="settings-hint" id="updateCapability">Memoir checks GitHub once per day. Updates are never installed automatically.</p>
                    <pre class="update-notes hidden" id="updateNotes"></pre>
                    <div class="data-actions update-actions">
                        <button type="button" id="checkUpdate"><i class="fa-solid fa-rotate"></i> Check for updates</button>
                        <button type="button" class="primary-btn hidden" id="installUpdate"><i class="fa-solid fa-download"></i> Update now</button>
                        <a class="hidden" id="viewRelease" href="#" target="_blank" rel="noopener noreferrer">View release notes</a>
                    </div>
                    <span id="updateStatus" class="pw-status" role="status" aria-live="polite"></span>
                </section>
            </div>
        </div>

        <div class="modal-actions">
            <button data-close>Cancel</button>
            <button class="primary-btn" id="saveSettings">Save settings</button>
        </div>
    </div>
</div>
