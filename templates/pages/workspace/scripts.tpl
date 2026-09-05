{*
    Workspace page scripts: the window.MEMOIR bootstrap object (CSRF token,
    initial notes and completeness flags), app.js, the deferred highlight.js
    loader and the service-worker registration.
    Included by pages/workspace/index.tpl. The MEMOIR object keeps its opening
    brace followed by a newline so Smarty leaves it alone; only |json tags
    are interpolated inside it.
*}
<script>window.MEMOIR = {
    csrf: document.querySelector('meta[name="csrf-token"]').content,
    userRole: document.querySelector('meta[name="user-role"]').content,
    initialNotes: {$clientNotes|json nofilter},
    initialActiveComplete: {$initialActiveComplete|json nofilter},
    initialContentComplete: {$initialContentComplete|json nofilter}
};</script>
<script src="{'assets/js/app.js'|asset}"></script>
<script>
{literal}
// Syntax highlighting is useful, but it must not hold the whole application
// hostage on a slow CDN connection. Load it once the editor is interactive.
(window.requestIdleCallback || function (fn) { setTimeout(fn, 1); })(function () {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js';
    script.onload = function () { window.dispatchEvent(new Event('memoir:highlight-ready')); };
    document.head.appendChild(script);
});
{/literal}
</script>
<script>
{literal}
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
}
{/literal}
</script>
