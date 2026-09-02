{*
    Changelog page: CHANGELOG.md rendered as HTML for a signed-in user.
    Served by changelog.php; extends layouts/base.tpl.

    This page intentionally does not load app.css. Its own rules live in
    assets/css/changelog.css (formerly an inline <style> block).

    Variables: csrf, appName, changelogHtml.
*}
{extends file='layouts/base.tpl'}

{block name=meta}
    <meta name="csrf-token" content="{$csrf}">
{/block}

{block name=title}Changelog · {$appName}{/block}

{block name=icons}
    <link rel="icon" type="image/png" href="{'assets/img/favicon.png'|asset}">
    <link rel="manifest" href="{'manifest.json'|asset}">
    <meta name="theme-color" content="#6f5ee8">
{/block}

{block name=styles}
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="{'assets/css/changelog.css'|asset}">
{/block}

{block name=body}
    <main class="changelog-page">
        <header class="changelog-header">
            <a class="changelog-brand" href="{'index.php'|asset}">
                <img src="{'assets/img/favicon.png'|asset}" alt="">
                <strong>{$appName}</strong>
            </a>

            <a class="changelog-back" href="{'index.php'|asset}">
                <i class="fa-solid fa-arrow-left"></i>
                Back to notes
            </a>
        </header>

        <article class="changelog-content">
{* HTML produced by render_changelog_markdown() in changelog.php, which escapes every piece of Markdown text itself. *}
            {$changelogHtml nofilter}
        </article>
    </main>

    <script>
{literal}
        window.MEMOIR = {
            csrf: document.querySelector('meta[name="csrf-token"]').content
        };
{/literal}
    </script>

{* Pre-existing quirk kept on purpose: the src has no ".js" and resolves to assets/js/app?v=<version>. *}
    <script src="{'assets/js/app'|asset}"></script>

{* Not wrapped in literal because it embeds the sw.js asset URL. Its remaining braces are "{" + newline and "{}", both left alone by Smarty. *}
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('{'sw.js'|asset}').catch(function() {});
        }
    </script>
{/block}
