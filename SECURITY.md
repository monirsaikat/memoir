# Security policy

## Supported versions

Security fixes are provided for the latest released version of Memoir.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use
[GitHub private vulnerability reporting](https://github.com/monirsaikat/memoir/security/advisories/new)
and include the affected version, reproduction steps, and potential impact.

Do not include real credentials, private notes, or personal uploads in a report.
You can expect an initial response within seven days.

## Deployment responsibilities

Memoir stores private notes and database credentials on the server. Production
installations should use HTTPS, a maintained PHP version, a dedicated database
user, strong owner and SMTP passwords, and regular filesystem/database backups.
