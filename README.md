# Memoir

Memoir is a lightweight, single-user, self-hosted note manager designed for ordinary cPanel/shared hosting.

## Features
- Folder-based notes
- Global search
- Rich-text editing
- Markdown mode
- Copy/paste and drag/drop image uploads
- Note pinning, icons, and accent colors
- Autosave
- Keyboard shortcuts
- Light-only interface
- Optional SMTP configuration
- Installer-driven setup

## Requirements
- PHP 8.1+
- PDO MySQL
- fileinfo
- mbstring
- MySQL/MariaDB
- Apache recommended

## Install
1. Upload and extract Memoir on your server.
2. Make sure `storage/` and `uploads/` are writable.
3. Visit `/install/`.
4. Enter database credentials, application URL, owner account, and optional SMTP settings.
5. Finish installation and sign in.

The installer creates all tables automatically. If the database does not exist and the supplied MySQL account has `CREATE DATABASE` permission, Memoir will create it too. Shared-hosting users can simply create an empty database and user in cPanel first.

## Keyboard shortcuts
- `Ctrl/Cmd + N` — new note
- `Ctrl/Cmd + K` — global search
- `Ctrl/Cmd + S` — save immediately
- `Ctrl/Cmd + Shift + M` — toggle Markdown mode
- `Ctrl/Cmd + B` — bold in the rich editor
- `Ctrl/Cmd + I` — italic in the rich editor
- `Esc` — close an open dialog

## Security
After installation, `storage/installed.lock` prevents the installer from running again. Runtime `config.php`, installer locks, and uploaded note media are ignored by Git so credentials and personal data are not committed accidentally.
