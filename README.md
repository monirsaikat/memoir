<p align="center">
  <img src="assets/img/memoir-logo.png" width="104" alt="Memoir logo">
</p>

<h1 align="center">Memoir</h1>

<p align="center">A calm, private notes app that runs on ordinary cPanel hosting.</p>

Memoir is a lightweight, single-owner note manager for people who want their
notes on their own domain. It needs PHP and MySQL—no Docker, Node.js, Composer,
SSH, or VPS required.

## Features

- Folder-based notes, pinning, advanced search, icons, and accent colors
- Rich-text editing with image paste, drop, and upload
- Autosave, recoverable version history, and useful keyboard shortcuts
- Automatic/on-demand workspace backups with validated one-click restore
- Responsive, distraction-free interface
- Browser-based installer built for cPanel/shared hosting
- Optional SMTP settings
- Installer lock, CSRF protection, upload restrictions, and stored-HTML sanitizing

## Requirements

- PHP 8.1 or newer
- MySQL 5.7+, MariaDB 10.3+, or a compatible newer version
- PHP extensions: `pdo_mysql`, `fileinfo`, `mbstring`, and `dom`
- Apache 2.4+ with `.htaccess` support (recommended)
- Write access to the app root during installation and to `storage/` and `uploads/`

## Install from a ZIP on cPanel

1. Download the latest release ZIP from GitHub. Prefer a release asset over the
   automatic “Source code” archive when both are available.
2. In **cPanel → File Manager**, open `public_html`, create your chosen folder
   (for example `memoir`), upload the ZIP, and extract it.
3. Make sure `index.php`, `install/`, `assets/`, and `storage/` are directly in
   that folder—not inside an extra `memoir-main/` directory.
4. In **cPanel → MySQL Database Wizard**, create a database and user, assign the
   user with **ALL PRIVILEGES**, and keep the full cPanel-prefixed names.
5. In **Select PHP Version** or **MultiPHP Manager**, select PHP 8.1+ and enable
   `pdo_mysql`, `fileinfo`, `mbstring`, and `dom`.
6. Visit `https://example.com/memoir/install/` and complete the guided setup.
7. Sign in with the owner account you created.

The installer creates the tables, writes `config.php`, and creates
`storage/installed.lock`. You do not need to import SQL manually.

Automatic backups are request-driven for shared-hosting compatibility: the
first authenticated request after the configured interval writes the next JSON
snapshot under `storage/backups/`. Keep `storage/` writable and protected from
direct web access.

### Folder and URL examples

Subfolder installation:

```text
Files: public_html/memoir/
App URL: https://example.com/memoir
Installer: https://example.com/memoir/install/
```

Subdomain installation:

```text
Files: the document root configured for notes.example.com
App URL: https://notes.example.com
Installer: https://notes.example.com/install/
```

Do not include `/install/` in the application URL field.

### Permissions

Most cPanel hosts work with `755` for directories and `644` for files. Memoir
must be able to write the application root once to create `config.php`, and must
keep `storage/` and `uploads/` writable. Avoid `777` unless your host explicitly
requires it.

## Updating

Before every update, back up:

```text
config.php
storage/
uploads/
your MySQL/MariaDB database
```

Then replace the application files with the new release while preserving those
runtime files and folders. Read [CHANGELOG.md](CHANGELOG.md) for release-specific
notes. Never overwrite `config.php` with `config.example.php`.

## Security notes

- Always use HTTPS in production.
- Keep PHP, the database server, and Memoir updated.
- Use a dedicated database user and a strong, unique owner password.
- Do not commit or publish `config.php`, `storage/`, or uploaded files.
- The included Apache rules block direct access to configuration/storage and
  prevent script execution inside `uploads/`. If your host ignores `.htaccess`,
  reproduce those protections in its web-server configuration before use.
- SMTP credentials are stored in the database so Memoir can use them; protect
  database backups accordingly.

Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Troubleshooting

### `/install/` returns 403 Forbidden

Confirm the release contains `install/index.php` and that the `install` folder
and file are readable (`755` directory, `644` file is typical). A missing index
file makes Apache reject directory listing. Memoir redirects an already-installed
site to sign-in instead of showing a 403.

### The installer reports a database error

Use the complete cPanel-prefixed database/user names, confirm the user has been
assigned to the database with all privileges, and try `localhost` unless your
host documents a different hostname.

### Images do not upload

Check that `uploads/` is writable, PHP `file_uploads` is enabled, and your host’s
`upload_max_filesize` and `post_max_size` allow the file. Memoir accepts JPEG,
PNG, WebP, and GIF images up to 8 MB.

### Setup was interrupted

If `config.php` was created, restore it or remove it only when intentionally
starting over. Use an empty database for a clean reinstall. Never delete a live
configuration or database without a backup.

## Development

Clone the repository into a PHP-capable document root, create an empty MySQL
database, then open `/install/`. There is no build step.

Run syntax checks before contributing:

```bash
php -l bootstrap.php
php -l install/index.php
php -l login.php
php -l index.php
php -l api.php
php -l logout.php
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for project principles, security rules,
manual test coverage, and the pull-request checklist.

## Keyboard shortcuts

- `Ctrl/Cmd + N` — new note
- `Ctrl/Cmd + K` — global search
- `Ctrl/Cmd + S` — save immediately
- `Ctrl/Cmd + B` — bold
- `Ctrl/Cmd + I` — italic
- `Esc` — close a dialog

## License

Memoir is open-source software released under the [MIT License](LICENSE).
