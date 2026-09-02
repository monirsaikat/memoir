{*
    Public read-only view of a shared note, served by share.php.

    Shows the note (title, last-updated date, pre-sanitized HTML content, footer
    and the highlight.js loader) or a "not available" card when the share token
    is unknown or revoked. The page is always light: the theme block is left
    empty on purpose, and the page's own rules live in assets/css/share.css.

    Data: note (title, content, updated_at; null when not found), appName.
*}
{extends file='layouts/base.tpl'}

{block name=htmlAttributes} data-theme="light" data-mode="light"{/block}

{block name=meta}
    <meta name="robots" content="noindex">
{/block}

{block name=title}{if $note}{$note.title}{else}Note not found{/if} — {$appName}{/block}

{block name=theme}{/block}

{block name=styles}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-light.min.css">
    <link rel="stylesheet" href="{'assets/css/app.css'|asset}">
    <link rel="stylesheet" href="{'assets/css/share.css'|asset}">
{/block}

{block name=bodyAttributes} class="share-page"{/block}

{block name=body}
{if !$note}
<div class="share-doc share-missing">
    <h1 class="share-title">This note is not available</h1>
    <p style="color:var(--muted)">The link may have been revoked or the note removed.</p>
</div>
{else}
<article class="share-doc">
    <h1 class="share-title">{$note.title}</h1>
    <span class="share-updated">Updated {$note.updated_at|date:'F j, Y'}</span>
    <div class="rich-editor">{$note.content nofilter}{* stored pre-sanitized *}</div>
</article>
<div class="share-foot">Shared from {$appName} · Self-hosted personal notes</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
{literal}
if (window.hljs) {
    document.querySelectorAll('.rich-editor pre').forEach(function (pre) {
        pre.innerHTML = hljs.highlightAuto(pre.innerText).value;
    });
}
{/literal}
</script>
{/if}
{/block}
