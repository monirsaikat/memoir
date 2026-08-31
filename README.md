# Memoir

Memoir is a lightweight, single-user, self-hosted note manager designed for ordinary cPanel/shared hosting.

It is built for people who want a private notes app on their own domain without Docker, Node.js, Composer, or a VPS.

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
- `fileinfo`
- `mbstring`
- MySQL or MariaDB
- Apache recommended

## Self-host on cPanel

Memoir is designed around a simple shared-hosting flow:

```text
Download ZIP
   ↓
Upload to cPanel
   ↓
Extract
   ↓
Create MySQL database + user
   ↓
Open /install/
   ↓
Enter database + owner details
   ↓
Memoir creates its tables
   ↓
Sign in
```

### 1. Choose where Memoir will live

You can install Memoir in a subfolder:

```text
https://example.com/memoir
```

or on a subdomain:

```text
https://notes.example.com
```

For a subfolder, open **cPanel → File Manager → public_html** and create a folder such as `memoir`.

For a subdomain, create the subdomain in cPanel first and upload Memoir into that subdomain's document root.

### 2. Upload and extract Memoir

Upload the Memoir ZIP into the chosen folder and use **Extract** in cPanel File Manager.

After extraction, the application root should look roughly like this:

```text
memoir/
├── assets/
├── install/
├── storage/
├── uploads/
├── api.php
├── bootstrap.php
├── index.php
├── login.php
└── config.example.php
```

Do not leave the project inside an extra nested folder such as `memoir/Memoir-main/` unless that is intentionally your application URL.

### 3. Create the MySQL database

On most shared cPanel hosts, the easiest route is:

**cPanel → MySQL Database Wizard**

Create:

1. A new database
2. A new database user
3. A strong database password
4. Assign the user to the database with **ALL PRIVILEGES**

Keep these values ready:

```text
Database host: localhost
Database name: yourcpanel_memoir
Database user: yourcpanel_memoiruser
Database password: ************
```

cPanel usually prefixes database names and usernames with your hosting account username. Use the complete values shown by cPanel.

You do **not** need to import an SQL file manually. Memoir's installer creates the required tables automatically.

### 4. Check PHP

Use **cPanel → Select PHP Version** or **MultiPHP Manager** and select PHP 8.1 or newer.

Make sure these extensions are enabled:

```text
pdo_mysql
fileinfo
mbstring
```

### 5. Run the installer

Open the install URL in your browser:

```text
https://example.com/memoir/install/
```

or:

```text
https://notes.example.com/install/
```

The installer checks the server requirements before installation.

Enter your database credentials, application URL, owner email/password, timezone, and optional SMTP configuration.

Example application URL:

```text
https://example.com/memoir
```

Do not include `/install/` in the application URL.

### 6. File permissions

Memoir needs to write to:

```text
storage/
uploads/
```

On most cPanel servers, normal permissions such as `755` for folders work automatically.

If the installer reports that either directory is not writable, use cPanel File Manager → **Change Permissions**. Avoid `777` unless your hosting provider explicitly requires it.

### 7. Finish installation

Click **Install Memoir**.

Memoir will:

- connect to MySQL/MariaDB
- create its tables
- create the single owner account
- save the application configuration
- create the installer lock
- redirect you to the login page

After installation, sign in with the owner email and password you entered during setup.

### 8. Images and uploads

Images pasted or dropped into notes are stored inside:

```text
uploads/
```

The folder is protected from executing PHP-style files by the included `.htaccess` rules.

For public repositories, runtime uploads are ignored by Git so personal note images are not accidentally committed.

### 9. Optional SMTP

SMTP is optional for the current Memoir experience. If you want to configure it during installation, typical cPanel mail settings look like:

```text
SMTP host: mail.example.com
SMTP port: 465
Security: SSL
Username: you@example.com
Password: your mailbox password
From email: you@example.com
```

Your hosting provider may instead recommend port `587` with TLS.

### 10. Updating Memoir later

Before replacing application files, back up:

```text
config.php
uploads/
storage/
```

and export the MySQL database from **phpMyAdmin**.

Do not overwrite your runtime `config.php` with `config.example.php`.

## Keyboard shortcuts

- `Ctrl/Cmd + N` — new note
- `Ctrl/Cmd + K` — global search
- `Ctrl/Cmd + S` — save immediately
- `Ctrl/Cmd + Shift + M` — toggle Markdown mode
- `Ctrl/Cmd + B` — bold in the rich editor
- `Ctrl/Cmd + I` — italic in the rich editor
- `Esc` — close an open dialog

## Troubleshooting

**Installer redirects or shows a blank page**  
Confirm PHP 8.1+ is selected and PHP errors are not being suppressed by the hosting configuration.

**Database connection failed**  
Use the complete cPanel database name/user, confirm the password, and make sure the user is assigned to the database with all privileges.

**Images do not upload**  
Check that `uploads/` is writable and that PHP `file_uploads` is enabled.

**Installer says Memoir is already installed**  
That is expected after installation. Memoir creates `storage/installed.lock` to disable the installer.

## Security

After installation, `storage/installed.lock` prevents the installer from running again. Runtime `config.php`, installer locks, and uploaded note media are ignored by Git so credentials and personal data are not committed accidentally.

Use HTTPS for every production installation and keep PHP and Memoir updated.
