# Templates

Memoir renders every HTML page from the Smarty templates in this directory.
The PHP entry points (`index.php`, `login.php`, `install/index.php`, ...)
collect data and call `render('pages/...', [...])` from `app/view.php`. They
contain no markup.

## Layout

```text
templates/
├── layouts/
│   ├── base.tpl          the HTML document: head, web font, blocks for pages to fill
│   └── auth.tpl          base + the centred card used by login, forgot and reset
├── partials/
│   └── theme-boot.tpl    inline script that applies the saved theme before first paint
└── pages/
    ├── auth/             login.tpl, forgot.tpl, reset.tpl
    ├── workspace/        the notes app served by index.php: index.tpl plus one
    │   └── modals/       partial per screen region and one file per dialog
    ├── changelog.tpl     rendered CHANGELOG.md
    ├── install.tpl       served by install/index.php (asset paths are ../ relative)
    └── share.tpl         public read-only view of a shared note
```

Compiled templates are written to `storage/templates`, which Apache never
serves and git ignores. They are rebuilt automatically when a template changes.

## Conventions

1. **Extend a layout.** Start with `{extends file='layouts/base.tpl'}` (or
   `layouts/auth.tpl`) and fill its blocks. The block list is documented at the
   top of each layout.
2. **Everything is escaped.** `{$title}` is HTML-escaped automatically. Add
   `nofilter` only for markup that was sanitized before it reached the
   template (note HTML from `sanitize_note_html()`, the rendered changelog) and
   for JSON produced by `|json`.
3. **Helpers** registered in `app/view.php`: `{'assets/css/app.css'|asset}`
   (version-stamped URL), `{$data|json nofilter}` (script-safe JSON) and
   `{$row.updated_at|date:'M j'}` (PHP `date()` formatting). Smarty built-ins
   used throughout: `|default:`, `|count`, `|replace:`, `|str_repeat:`,
   `{if isset(...)}`, `{if empty(...)}`.
4. **Inline JavaScript and CSS live inside `{literal}...{/literal}`.** Smarty
   evaluates `{6}` (a regex quantifier) as a template tag. A block that has to
   interpolate a value keeps `{` followed by a space or newline, which Smarty
   leaves alone, and places only the `{$value}` tags inside.
5. **Variables mirror the PHP names** (camelCase) so a template line can be
   traced straight back to the controller that assigned it. Optional keys use
   `|default:`.
6. **Presentation only.** No queries, no business rules. Labels, option lists
   and other data shaping happen in PHP before `render()`.
7. **Comment every file** with `{* ... *}` at the top: what it renders and which
   page includes it.
8. **Toggle attributes inside the tag:** `<div id="x"{if !$tags} hidden{/if}>`.

## Adding a page

1. Create `templates/pages/<name>.tpl` extending `layouts/base.tpl`.
2. In the PHP entry point, `require __DIR__ . '/bootstrap.php';`, gather the
   data, then `render('pages/<name>.tpl', ['key' => $value]);`.
