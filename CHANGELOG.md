# Changelog

All notable changes to Memoir are documented here. This project follows
[Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-09-01

### Added

- Markdown typing shortcuts in the editor: `#`/`##`/`###` + space for headings,
  `-`/`*` and `1.` for lists, `>` for quotes, ` ``` ` + Enter for code blocks,
  `---` for dividers, and inline `**bold**`, `*italic*`, `~~strike~~`, `` `code` ``.
- Text color and highlight support with an anchored color sheet that always
  opens inside the viewport; chosen colors survive saving through a strict
  hex-only sanitizer whitelist.
- Floating format bubble over selected text (bold, italic, underline,
  strikethrough, link, highlight, clear formatting) that reflects the
  selection's current formatting and flips below the selection when there is
  no room above.
- Tags system: tag chips in the editor (Enter/comma to add, max 8 per note),
  a sidebar tag cloud with note counts and one-click filtering, tags on note
  cards, tag-aware search, and automatic schema migration for existing installs.
- URL state: the open note, folder/tag filter, pinned view, and search query
  live in query params, so reloads and bookmarks restore the exact view and
  the browser back/forward buttons navigate between views.
- Redesigned editor toolbar with grouped actions, undo/redo, strikethrough,
  divider and clear-formatting buttons, shortcut-hint tooltips, and active
  states that follow the caret.

### Fixed

- Markdown block conversion no longer formats the wrong line: deleting a typed
  marker could leave an empty block that silently snapped the caret to the
  previous line before the format was applied.
- Markdown markers are recognized even when the line carries leftover
  zero-width caret anchors from earlier inline conversions.
- CSS/JS URLs are version-stamped from file modification time, so browsers
  pick up updated assets immediately instead of serving stale cached copies.

### Changed

- Slim, unobtrusive scrollbars in the editor and note list; the toolbar wraps
  instead of showing a horizontal scrollbar (and stays a swipeable single row
  on touch screens).
- Smoother editor feel: accent caret and selection tint, styled inline code,
  links and dividers, entrance animations that respect reduced-motion
  preferences.
- Reformatted the entire codebase (PHP, HTML, CSS, JS) for readability:
  consistent indentation, one statement/rule per line, section comments, and
  extracted helpers.

## [1.0.0] - 2026-09-01

### Added

- Guided, responsive web installer for cPanel and shared hosting.
- Original Memoir logo and favicon throughout the installer and app.
- In-dashboard “What’s new” panel with the current version.
- MIT license, security policy, and public-release documentation.
- Contributor guide with local setup, security expectations, and a PR checklist.

### Security

- Added installer CSRF protection and strict server-side validation.
- Added safer session, browser, and response headers.
- Sanitized stored rich-text note HTML before saving.
- Restricted every API action to its intended HTTP method.
- Added basic sign-in throttling and POST-only sign out.
- Strengthened protection for configuration, storage, and uploaded files.
- Removed runtime upload and installer-lock files from release tracking.

### Changed

- Installed sites now redirect away from the installer instead of returning a
  bare 403 response.
- The installer uses atomic configuration writes and refuses databases that
  already contain an owner account.
- Prevented password managers from autofilling the note search, improved note
  previews and empty states, and added usable mobile list/editor navigation.

[1.1.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.1.0
[1.0.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.0.0
