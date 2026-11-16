<!--
README.md
Copyright (C) 2026 Stefano Natangelo
SPDX-License-Identifier: AGPL-3.0-only
-->

# Journal Quota for OJS

[![OJS 3.5.0-6](https://img.shields.io/badge/OJS-3.5.0--6-blue)](#compatibility)
[![Release 1.0.0](https://img.shields.io/badge/release-1.0.0-blue)](https://github.com/SNatangelo/ojs-journal-quota/releases/tag/v1.0.0)
[![AGPL-3.0-only](https://img.shields.io/badge/license-AGPL--3.0--only-green)](LICENSE)

Journal Quota is a site-wide plugin for [Open Journal Systems (OJS)](https://pkp.sfu.ca/software/ojs/) that tracks and limits disk usage **per journal** on a shared installation.

Site administrators set storage limits and monitor all journals in one place. Journal managers can view their own usage and receive alerts before they run out of space.

## Features

- Site-wide default quotas and individual journal overrides.
- A site overview, per-journal usage breakdowns and lists of the largest submissions.
- Accounting for submission files, issue files, publisher libraries and other private files; optional inclusion of public files and estimated database usage.
- Configurable warning thresholds, storage tolerance, in-app notifications and email reminders.
- Immediate accounting for supported file changes, periodic full reconciliation and database-backed reservations for concurrent uploads.
- English and Italian interfaces.

## Compatibility

Version **1.0.0** was developed for **OJS 3.5.0-6**. It is installed and functioning on **JSENetwork with MySQL**; development testing used a separate local OJS 3.5.0-6 Docker installation with MariaDB/InnoDB.

Other OJS versions and database engines beyond MySQL/MariaDB have not yet been verified.

## Installation and quick start

1. Download [journalQuota-1.0.0.tar.gz](https://github.com/SNatangelo/ojs-journal-quota/releases/download/v1.0.0/journalQuota-1.0.0.tar.gz), the installable release package, rather than GitHub's automatic **Source code** downloads.
2. Install the archive through the OJS plugin management interface as a **site administrator**, then enable **Journal Quota**.
3. Open the plugin's **Settings** to view the storage overview. Leave quotas at `0` (unlimited) while running an initial full scan and reviewing usage.
4. Configure the default quota, warning threshold and enforcement mode. Select individual journals to override the defaults where needed.

For manual installation, the plugin directory must be `plugins/generic/journalQuota`, with `version.xml` directly inside it. Complete installation through OJS so its database migrations are applied.

The plugin creates tables for its file ledger and temporary upload reservations. Back up your OJS database and files before changing a production installation.

Keep the OJS task scheduler running for periodic reconciliation and notification checks.

## How quota enforcement works

- **Site administrators:** use the plugin settings or `/index/quota` to manage defaults and per-journal limits.
- **Journal managers:** use `/<journal-path>/quota` to inspect their journal's usage and largest submissions.

These paths are relative to the OJS application and follow its URL configuration.

| Setting | New submissions and uploads |
|---|---|
| Quota `0` | Usage is tracked without a quota limit. |
| **Block** mode | Closed when usage reaches the configured quota. |
| **Grace** mode | Closed when usage reaches the quota plus the configured percentage tolerance. |

Grace is a **storage allowance, not a time period**: a 1,000 MB quota with 10% tolerance has a blocking limit of 1,100 MB. Uploads that would exceed the applicable limit are rejected, taking pending upload reservations into account.

Existing files remain available for reading, download and deletion. Quota restrictions lift when usage falls below the limit; the journal's own submission settings still apply. Site administrators are exempt from these restrictions.

## Testing and known limitations

The [automated OJS suite](https://github.com/SNatangelo/ojs-journal-quota/blob/main/tests/README.md) defines **128 checks** in its reference four-journal dataset: **101 CLI and 27 HTTP**. It covers file accounting, reservations, notifications, upload enforcement and access control. These are suite counts, not an execution report.

The CI workflow checks PHP 8.4 syntax, Bash syntax and file headers, and runs isolated safety-guard regressions using mocks and synthetic configuration/snapshot fixtures. These checks do not start Docker or OJS or contact a journal. The full 128-check integration suite requires a separately prepared, explicitly marked disposable local OJS copy and is **not run by CI**.

- Database usage is an estimate; external filesystem changes are reconciled by full scans and may not appear immediately.
- Concurrency checks exercise simultaneous reservation requests, not complete simultaneous HTTP uploads.
- Production email delivery, complete XML issue import, profile-image exemption and live package installation/upgrade remain outside the automated suite.

See the [testing and validation notes](docs/testing.md) for development evidence and the [test README](https://github.com/SNatangelo/ojs-journal-quota/blob/main/tests/README.md) for setup and cleanup limits. Test scripts are not part of the installable release package.

## Support and contributions

Please use [GitHub Issues](https://github.com/SNatangelo/ojs-journal-quota/issues) for reproducible bug reports and feature requests. Include your OJS/PHP/database versions and the steps needed to reproduce a problem; do not include credentials or private manuscript files.

Pull requests are welcome. Keep changes focused and include relevant test evidence; changes to `main` go through review and automated checks.

## License

Copyright © 2026 Stefano Natangelo.

Licensed under the **GNU Affero General Public License, version 3 only** (`AGPL-3.0-only`). See [LICENSE](LICENSE).
