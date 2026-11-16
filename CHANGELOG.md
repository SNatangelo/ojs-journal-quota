<!--
CHANGELOG.md
Copyright (C) 2026 Stefano Natangelo
SPDX-License-Identifier: AGPL-3.0-only
-->

# Changelog

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
