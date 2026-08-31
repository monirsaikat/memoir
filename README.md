# Memoir

Memoir is a lightweight, single-user personal note manager designed for ordinary cPanel/shared hosting.

## Requirements
- PHP 8.1+
- PDO MySQL
- fileinfo
- mbstring
- MySQL/MariaDB
- Apache recommended

## Install
1. Upload and extract the ZIP.
2. Make sure `storage/` and `uploads/` are writable.
3. Visit `/install/`.
4. Enter database credentials, app URL, admin account, and optional SMTP settings.
5. Finish installation and sign in.

The installer creates all tables automatically. If the database does not exist and the supplied MySQL account has CREATE DATABASE privilege, Memoir will create it too.

## Security
After installation, `storage/installed.lock` prevents the installer from running again.
# memoir
