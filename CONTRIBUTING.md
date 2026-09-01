# Contributing to Memoir

Thanks for helping make self-hosted notes easier and safer. Memoir intentionally
stays small, dependency-light, and friendly to ordinary shared hosting.

## Before you start

- Search existing issues and pull requests before opening a duplicate.
- For a bug, include the Memoir version, PHP version, database/version, browser,
  hosting environment, reproduction steps, and the expected/actual result.
- Discuss large features in an issue before investing in an implementation.
- Report vulnerabilities privately through the process in [SECURITY.md](SECURITY.md).
  Never publish an exploit or real user data in an issue.

## Local development

1. Fork and clone the repository into a PHP-capable document root.
2. Create an empty MySQL or MariaDB database and a dedicated local user.
3. Confirm PHP 8.1+ has `pdo_mysql`, `fileinfo`, `mbstring`, and `dom` enabled.
4. Open `/install/` and complete setup with local-only credentials.
5. Create a focused branch such as `fix/search-autofill` or
   `feature/export-notes`.

Memoir has no Composer or Node.js build step. Runtime files are intentionally
ignored; never commit `config.php`, `storage/` contents, uploaded files, database
exports, credentials, or personal notes.

## Project principles

- Keep installation possible through a ZIP upload and browser-based setup.
- Preserve PHP 8.1 compatibility and avoid requiring shell access on production.
- Prefer small, readable PHP, CSS, and browser-native JavaScript.
- Treat note content, filenames, request data, and rendered HTML as untrusted.
- Maintain keyboard access, useful labels, visible focus states, and responsive UI.
- Do not add tracking, telemetry, external accounts, or third-party services by
  default.

## Code style

- Start PHP files with `declare(strict_types=1);` when practical.
- Use prepared statements for every value that reaches SQL.
- Escape HTML output with `e()` and sanitize rich note HTML before storage.
- Require CSRF protection for state-changing requests.
- Keep API errors useful without revealing credentials or server internals.
- Use existing design tokens and components before introducing new UI patterns.
- Write plain-language interface copy and keep controls accessible by keyboard.

## Testing changes

At minimum, run:

```bash
php -l bootstrap.php
php -l install/index.php
php -l login.php
php -l index.php
php -l api.php
php -l logout.php
node --check assets/js/app.js
git diff --check
```

For UI changes, manually verify:

- a fresh installer page with no `config.php`;
- sign-in and sign-out;
- creating, editing, searching, pinning, and deleting a note;
- image upload and a rejected unsupported upload;
- desktop and mobile-width navigation;
- browser console output and keyboard-only use.

Do not run installation tests against a database containing real notes. Use a
disposable database and remove its test data when finished.

## Pull requests

Keep each pull request focused. In the description, explain:

- what changed and why;
- how it was tested;
- security, migration, hosting, or compatibility considerations;
- screenshots for visible UI changes.

Before requesting review, confirm:

- [ ] Syntax checks and relevant manual tests pass.
- [ ] No secrets, runtime files, personal data, or generated test uploads appear
      in the diff or Git history.
- [ ] Documentation and `CHANGELOG.md` are updated when users are affected.
- [ ] The installer still works without Composer, Node.js, or SSH.
- [ ] The change works with PHP 8.1 and a narrow/mobile viewport.

By contributing, you agree that your contribution is licensed under Memoir’s
[MIT License](LICENSE).
