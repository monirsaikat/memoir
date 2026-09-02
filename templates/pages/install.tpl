{*
    Installer served by install/index.php (the only page that does not extend
    layouts/base.tpl: it has its own minimal head, a light-only colour scheme,
    no web font, and asset paths are ../ relative because it lives in /install/).

    Variables: version, checks (label => bool), requirementsMet, notice, errors,
    csrf and form (the submitted field values, with the installer defaults applied).
*}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>Install Memoir</title>
<link rel="icon" type="image/png" href="../assets/img/favicon.png">
<link rel="stylesheet" href="../{'assets/css/install.css'|asset}">
</head>
<body>

<main class="shell">
    <div class="brand">
        <img src="../assets/img/memoir-logo.png" alt="Memoir logo">
        <div>
            <strong>Memoir</strong>
            <span>Private notes on your own server</span>
        </div>
    </div>

    <section class="card">
        <header class="intro">
            <div>
                <div class="eyebrow">Quick setup</div>
                <h1>Make this space yours.</h1>
                <p>Connect a database, create the owner account, and Memoir will handle the tables and configuration.</p>
            </div>
            <span class="version">Version {$version}</span>
        </header>

        <div class="body">
            <aside class="checks">
                <h2>Server checks</h2>
                {foreach $checks as $label => $ok}
                <div class="check {if $ok}ok{else}bad{/if}">
                    <b>{if $ok}✓{else}!{/if}</b>
                    <span>{$label}</span>
                </div>
                {/foreach}
                <small>On cPanel, enable missing PHP extensions in Select PHP Version. Use 755 for folders in most setups.</small>
            </aside>

            <div class="form">
                {if $notice}
                <div class="alert notice">{$notice}</div>
                {/if}

                {foreach $errors as $error}
                <div class="alert error">{$error}</div>
                {/foreach}

                <form method="post" autocomplete="off">
                    <input type="hidden" name="_csrf" value="{$csrf}">

                    <div class="section">
                        <h2>1 · Database</h2>
                        <div class="grid">
                            <div>
                                <label for="db_host">Host</label>
                                <input id="db_host" name="db_host" value="{$form.db_host}" required>
                            </div>
                            <div>
                                <label for="db_port">Port</label>
                                <input id="db_port" name="db_port" inputmode="numeric" value="{$form.db_port}" required>
                            </div>
                            <div>
                                <label for="db_name">Database name</label>
                                <input id="db_name" name="db_name" value="{$form.db_name}" required>
                            </div>
                            <div>
                                <label for="db_user">Database user</label>
                                <input id="db_user" name="db_user" value="{$form.db_user}" required>
                            </div>
                            <div class="full">
                                <label for="db_pass">Database password</label>
                                <input id="db_pass" type="password" name="db_pass" autocomplete="new-password">
                                <span class="hint">Use the complete cPanel-prefixed database and username.</span>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2>2 · Site</h2>
                        <div class="grid">
                            <div>
                                <label for="app_name">App name</label>
                                <input id="app_name" name="app_name" maxlength="120" value="{$form.app_name}">
                            </div>
                            <div>
                                <label for="timezone">Timezone</label>
                                <input id="timezone" name="timezone" value="{$form.timezone}">
                            </div>
                            <div class="full">
                                <label for="app_url">Application URL</label>
                                <input id="app_url" type="url" name="app_url" value="{$form.app_url}" required>
                                <span class="hint">Do not include /install at the end.</span>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2>3 · Owner account</h2>
                        <div class="grid">
                            <div>
                                <label for="admin_name">Your name</label>
                                <input id="admin_name" name="admin_name" maxlength="120" value="{$form.admin_name}">
                            </div>
                            <div>
                                <label for="admin_email">Email</label>
                                <input id="admin_email" type="email" name="admin_email" autocomplete="username" value="{$form.admin_email}" required>
                            </div>
                            <div class="full">
                                <label for="admin_pass">Password</label>
                                <input id="admin_pass" type="password" name="admin_pass" minlength="12" autocomplete="new-password" required>
                                <span class="hint">Use at least 12 characters and a password you do not reuse elsewhere.</span>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <h2>4 · Email (optional)</h2>
                        <div class="grid">
                            <div>
                                <label for="smtp_host">SMTP host</label>
                                <input id="smtp_host" name="smtp_host" value="{$form.smtp_host}" placeholder="mail.example.com">
                            </div>
                            <div>
                                <label for="smtp_port">SMTP port</label>
                                <input id="smtp_port" name="smtp_port" inputmode="numeric" value="{$form.smtp_port}">
                            </div>
                            <div>
                                <label for="smtp_security">Security</label>
                                <select id="smtp_security" name="smtp_security">
                                    <option value="tls">TLS</option>
                                    <option value="ssl">SSL</option>
                                    <option value="none">None</option>
                                </select>
                            </div>
                            <div>
                                <label for="smtp_user">SMTP username</label>
                                <input id="smtp_user" name="smtp_user" value="{$form.smtp_user}">
                            </div>
                            <div>
                                <label for="smtp_pass">SMTP password</label>
                                <input id="smtp_pass" type="password" name="smtp_pass" autocomplete="new-password">
                            </div>
                            <div>
                                <label for="smtp_from">From email</label>
                                <input id="smtp_from" type="email" name="smtp_from" value="{$form.smtp_from}">
                            </div>
                        </div>
                    </div>

                    <div class="submit">
                        <span>No data leaves your server during setup.</span>
                        <button class="btn" type="submit"{if !$requirementsMet} disabled{/if}>Install Memoir →</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>

</body>
</html>
