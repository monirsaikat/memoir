{*
    Applies the saved theme and accent colour before the first paint so the
    page never flashes light/dark. Inline on purpose: it must run before any
    stylesheet is requested. Kept in {literal} because the JavaScript braces
    would otherwise be read as template tags.
*}
<script>
{literal}
    // Apply the saved theme before first paint to avoid a light/dark flash.
    (function () {
        try {
            var choice = localStorage.getItem('memoir-theme') || 'system';
            var darkFlavors = { dark: 1, ocean: 1, midnight: 1, forest: 1, dusk: 1, aurora: 1, nord: 1, velvet: 1 };
            if (choice === 'system') {
                choice = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            if (!/^(light|dark|sepia|ocean|midnight|forest|dusk|aurora|paper|nord|soft|velvet|linen)$/.test(choice)) choice = 'light';
            document.documentElement.dataset.theme = choice;
            document.documentElement.dataset.mode = darkFlavors[choice] ? 'dark' : 'light';
            var accent = localStorage.getItem('memoir-accent');
            if (accent && /^#[0-9a-fA-F]{6}$/.test(accent)) {
                document.documentElement.style.setProperty('--accent', accent);
            }
        } catch (e) {}
    })();
{/literal}
</script>
