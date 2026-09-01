# Changelog

All notable changes to Memoir are documented here. This project follows
[Semantic Versioning](https://semver.org/).

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

[1.0.0]: https://github.com/monirsaikat/memoir/releases/tag/v1.0.0
