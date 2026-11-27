<!--
CHANGELOG.md
Copyright (C) 2026 Stefano Natangelo
SPDX-License-Identifier: AGPL-3.0-only
-->

# Changelog

## 1.0.0.1 — Maintenance release

- Adopt four-part PKP plugin version numbering.
- Include the post-v1.0.0 English server-log messages and uninstall SQL in the installable package.
- Document privacy and security reporting.
- Automate allowlisted release packaging, archive verification and MD5/SHA-256 generation in CI.
- Date archive entries with SOURCE_DATE_EPOCH or the HEAD commit time instead of 1970-01-01: OJS 3.5 treats templates with a zero timestamp as missing, which broke manual `tar -xzf` installations.
- No new quota features or schema changes are introduced by this release preparation.

## Log messages in English — 2026-11-20

- Translated the remaining Italian server log messages (disk scans, database estimate, email failures) and the lock-file problem notice to English.
- Plugin version, v1.0.0 tag and installable release archive remain unchanged.

## Repository documentation — 2026-11-20

- Documented upgrading, disabling and complete removal of the plugin in the README.
- Added `uninstall.sql`, which removes the plugin's tables, settings, email template, leftover notifications and version record; tested on the local OJS 3.5.0-6 MariaDB copy.
- Plugin version, v1.0.0 tag and installable release archive remain unchanged.

## Repository maintenance — 2026-11-16

- Added consistent file-path, copyright and SPDX headers throughout the tracked source and documentation, keeping the canonical LICENSE unchanged.
- Standardized the remaining English source comment in Italian without changing plugin behavior or localized interface text.
- Replaced the revision-by-revision development test checklist with concise public testing and validation notes; the detailed log remains in Git history.
- Added automated checks for required source headers; the public plugin version and v1.0.0 release archive remain unchanged.

## Repository testing update — 2026-11-16

- Published the local OJS integration and HTTP regression suite (128 checks in the reference dataset) and its fixture requirements.
- Added the historical development test record and updated compatibility/testing documentation.
- Added Bash syntax validation alongside PHP syntax checks in GitHub Actions.
- Plugin version, v1.0.0 tag and installable release archive remain unchanged.

## 1.0.0 — 2026-11-15

Initial public release.

- Per-journal disk quotas, usage views, threshold notifications, and upload/submission controls.
- File ledger with reconciliation scans and optional public-file/database accounting.
- Database-backed upload reservations with concurrent-request handling and bounded retries.
- English and Italian localizations.
