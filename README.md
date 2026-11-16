# Journal Quota for OJS

Journal Quota is a site-wide plugin for [Open Journal Systems (OJS)](https://pkp.sfu.ca/software/ojs/) that tracks and limits disk usage **per journal** on a shared installation.

The plugin is particularly useful for institutions and non-commercial hosts running multiple journals on one OJS instance.

## Features

- Site-wide default quotas and individual journal overrides.
- Warning thresholds, configurable grace periods, and automatic closure of new submissions and uploads at the applicable limit.
- Accounting for submission files, issue files, publisher libraries, and other private files, with optional inclusion of public files and an estimate of journal database usage.
- Immediate accounting for supported file changes and periodic full reconciliation, including files changed outside OJS.
- A site-administrator overview and a per-journal usage page for journal managers.
- In-app notifications and email alerts to the affected journal's managers.
- Database-backed upload reservations to account for concurrent requests.
- Site administrators remain exempt from upload restrictions.

## Compatibility

Version **1.0.0** was developed for **OJS 3.5.0-6** and is **installed and functioning on JSENetwork with MySQL**. Development testing was performed on a local OJS 3.5.0-6 Docker installation with MariaDB/InnoDB. The repository includes an automated OJS integration and HTTP regression suite; see [Testing](#testing).

Compatibility with other OJS versions and database engines beyond MySQL and MariaDB has not yet been verified.

## Installation

1. Place the plugin contents in `plugins/generic/journalQuota` in your OJS installation. The directory name must be `journalQuota`.
2. Use the OJS plugin management interface as a site administrator to install or enable **Journal Quota**.
3. Open the plugin settings, then configure the default quota and thresholds. Set individual journal overrides where needed.
4. Run an initial full scan and review the reported usage before enforcing limits.

The plugin creates its own database tables for the file ledger and temporary upload reservations. Back up your OJS database and files before changing a production installation.

## Usage

- **Site administrator:** use `/index/quota` to view journal usage, configure defaults, and set per-journal limits.
- **Journal manager:** use `/<journal-path>/quota` to view that journal's disk usage and the largest submissions.
- **Quota enforcement:** in *block* mode, uploads and new submissions close at the quota; in *grace* mode, they close at the configured tolerance limit. Existing files remain available for reading, download, and deletion.

The reported database component is an estimate. Full scans reconcile the ledger with the filesystem; usage can temporarily lag behind changes made outside OJS.

## Testing

The [automated test suite](tests/README.md) exercises the real OJS bootstrap, database, filesystem and HTTP endpoints. In the reference four-journal Docker dataset it defines **128 checks: 101 CLI and 27 HTTP**.

- File accounting, submission-file creation, copying, revisions, deletion and reconciliation.
- Quota reservations and concurrent reservation requests from separate PHP processes, in groups of 8 and 20.
- Notification thresholds, hysteresis, reminders, recipients and mail-failure retries.
- HTTP upload enforcement, remaining-space limits, site-administrator exemptions, API tokens, CSRF checks and HTML escaping.

The [development test record](docs/development-testing.md) also documents 64 functional, integration, security and concurrency checks performed during development.

**GitHub Actions checks PHP 8.4 syntax and Bash script syntax.** The integration suite is run separately against a disposable local OJS copy with the required test data; it does not provision a fresh OJS installation and is not run by GitHub Actions. The check counts describe the suite, not a GitHub Actions integration-test result. See the test README for setup, fixture assumptions and scenarios that remain manual.

## Support and contributions

Please use [GitHub Issues](https://github.com/SNatangelo/ojs-journal-quota/issues) for reproducible bug reports and feature requests. Include your OJS/PHP/database versions and the steps needed to reproduce a problem; do not include credentials or private manuscript files.

Pull requests are welcome. Changes to `main` go through a pull request and automated PHP syntax checks.

## License

Copyright © 2026 Stefano Natangelo.

Licensed under the **GNU Affero General Public License, version 3 only** (`AGPL-3.0-only`). See [LICENSE](LICENSE).
