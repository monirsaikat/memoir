# Changelog

All notable changes to Memoir are documented here. This project follows
[Semantic Versioning](https://semver.org/).

## [1.5.0] - 2026-09-01

### Added

- Quick switcher: Ctrl+P opens a command palette that jumps to any note by
  title, with type-ahead filtering, keyboard navigation, and match
  highlighting.
- Wiki-style note links: type `[[` in the editor to link to another note
  from an inline suggestion menu; links are click-to-follow, survive the
  sanitizer, and every note shows a "Linked from" backlinks strip.
- Sort options for the note list — last updated, date created, or title
  A–Z — remembered per browser, with pinned notes always on top.
- Full-text search: a MySQL FULLTEXT index over titles, content, and tags
  with per-word prefix matching (and a LIKE fallback for short words), plus
  highlighted matches and match-centered previews in the note list.

## [1.4.0] - 2026-09-01

### Added

- Trash: deleting notes (single or bulk) now moves them to a recoverable
  Trash view with restore and delete-forever actions, a read-only banner on
  trashed notes, and automatic purge after 30 days. Permanent deletion only
  works on notes already in the trash.
- Move notes between folders from the editor breadcrumb — click the folder
  name to pick a new folder (or Unfiled).
- Folder management: a hover menu on each sidebar folder to edit its name,
  icon, and color, reorder it, or delete it (its notes move to Unfiled).
- Sidebar counts (folders, All notes, tags, Trash) refresh live after every
  change.

## [1.3.0] - 2026-09-01

### Added

- Forgot-password flow: request a reset link from the sign-in page, delivered
  through the configured SMTP settings by a new dependency-free SMTP client
  (TLS/SSL, AUTH LOGIN). Tokens are stored hashed, valid for 45 minutes,
  single-use, and requests are rate-limited; responses never reveal whether
  an email address has an account.

## [1.2.0] - 2026-09-01

### Added

- Bulk note management: a select mode (toolbar button, Ctrl/Cmd+click, or Esc
  to exit) with per-card check circles, select-all, and one-shot deletion of
  up to 200 notes.
- Modern visual pass: accent-colored New note button and sidebar active
  pills, note cards that lift on hover, and modal entrance animations — all
  honoring reduced-motion preferences.
- Syntax highlighting in code blocks (highlight.js): language auto-detection
  across 14 common languages, palettes that follow the light/dark theme mode,
  caret-safe re-highlighting while editing, and clean storage — highlight
  markup is stripped on save and rebuilt on load.
- Full heading support: H1 through H6 via markdown (`#` … `######` + space)
  and a toolbar heading dropdown that shows the caret's current level, with
  distinct typography for every level.
- Tables in the editor: insert a table from the toolbar, Tab/Shift+Tab to move
  between cells (Tab past the last cell adds a row), and a floating table menu
  with add/remove row and column and delete-table actions.
- Task lists: checkbox items via the toolbar or by typing `[]` + space, with
  click-to-toggle checkboxes, strike-through on completion, and safe
  persistence through the sanitizer.
- Theme switching: light, dark, and system modes with a full dark palette
  across the app and sign-in page. The choice is remembered per browser and
  applied before first paint, and "system" follows the OS live.
- Three premium theme flavors: Sepia (warm paper), Ocean (Nordic slate blue),
  and Midnight (true-black OLED), each with its own preview card and full
  palette across every surface, and all compatible with any accent color.
- Accent colors: eight selectable accents that instantly re-tint the whole
  interface (buttons, chips, highlights, selection) via a single CSS variable.
- Password change in Settings, protected by current-password verification,
  a 12-character minimum, and session renewal after the change.

### Changed

- Settings is now a two-pane hub: section navigation (Appearance, General,
  Email, Account) on the left, content on the right, with miniature CSS-drawn
  theme preview cards instead of plain toggle buttons.

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

- Text colors and highlights survive saving: browsers normalize inline styles
  to `rgb()` form, which the sanitizer's hex-only whitelist silently stripped.
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

[1.5.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.5.0
[1.4.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.4.0
[1.3.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.3.0
[1.2.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.2.0
[1.1.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.1.0
[1.0.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.0.0
