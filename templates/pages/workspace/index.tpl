{*
    Workspace: the notes app served by index.php. Extends layouts/base.tpl and
    fills the head blocks, then includes one partial per screen region
    (sidebar, note list, editor), the popovers, one file per dialog under
    modals/ and the page scripts.
*}
{extends file='layouts/base.tpl'}

{block name=meta}
    <meta name="csrf-token" content="{$csrf}">
{/block}

{block name=title}{$appName}{/block}

{block name=icons}
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#6f5ee8">
{/block}

{block name=styles}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" id="hlThemeLight" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-light.min.css">
    <link rel="stylesheet" id="hlThemeDark" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <script>
{literal}
    // Keep only the highlight palette matching the active theme mode.
    (function () {
        var dark = document.documentElement.dataset.mode === 'dark';
        document.getElementById('hlThemeLight').disabled = dark;
        document.getElementById('hlThemeDark').disabled = !dark;
    })();
{/literal}
    </script>
    <link rel="stylesheet" href="{'assets/css/app.css'|asset}">
{/block}

{block name=body}

<div class="app-shell">

{include file='pages/workspace/sidebar.tpl'}

    <button class="mobile-scrim" id="mobileScrim" type="button" aria-label="Close navigation"></button>

{include file='pages/workspace/note-list.tpl'}

{include file='pages/workspace/editor.tpl'}

</div>

{include file='pages/workspace/popovers.tpl'}

{include file='pages/workspace/modals/folder.tpl'}

{include file='pages/workspace/modals/note-style.tpl'}

{include file='pages/workspace/modals/history.tpl'}

{include file='pages/workspace/modals/settings.tpl'}

{include file='pages/workspace/modals/whats-new.tpl'}

{include file='pages/workspace/scripts.tpl'}
{/block}
