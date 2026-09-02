# Third-party libraries

Memoir has no Composer step, so the libraries it depends on are checked in here
unchanged. Never edit files below this directory; upgrade them instead.

## smarty/ — Smarty 5.8.4

- Source: https://github.com/smarty-php/smarty/releases/tag/v5.8.4
- License: LGPL-3.0 (see `smarty/LICENSE`)
- Contents: the upstream `libs/` and `src/` directories only. `libs/Smarty.class.php`
  registers a PSR-4 autoloader, so no Composer autoloader is needed.
- Loaded by `app/view.php`, which configures the engine and exposes `render()`.

To upgrade: download the new release ZIP, replace `smarty/libs`, `smarty/src`
and `smarty/LICENSE`, update the version in this file, then run the syntax
checks from the README. Compiled templates in `storage/templates` are
regenerated automatically when a template or Smarty itself changes.
