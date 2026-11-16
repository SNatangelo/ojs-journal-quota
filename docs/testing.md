<!--
docs/testing.md
Copyright (C) 2026 Stefano Natangelo
SPDX-License-Identifier: AGPL-3.0-only
-->

# Testing and validation

Journal Quota **1.0.0** was developed for **OJS 3.5.0-6**. It is installed and functioning on **JSENetwork with MySQL**. Development tests used a separate local OJS 3.5.0-6 installation with MariaDB/InnoDB.

## Evidence available

| Evidence | Scope | Where to find it |
|---|---|---|
| Automated OJS integration and HTTP regression suite | **128 checks** in the reference four-journal dataset: 101 CLI and 27 HTTP | [Test runner and setup](../tests/README.md) |
| Development test record | **64 documented checks** across functional behavior, file accounting, access control, concurrency, notifications and resilience | [Historical test log](https://github.com/SNatangelo/ojs-journal-quota/blob/17a489f23d43c40cee469211acb8578aba6ab2c2/docs/development-testing.md) |
| Continuous integration | PHP 8.4 syntax, Bash syntax and source-header checks | [GitHub Actions workflow](../.github/workflows/php-lint.yml) |
| Live deployment | Plugin installed and operational on JSENetwork with MySQL | Reported production deployment; not a substitute for automated production tests |

The historical log records internal development revisions numbered 1.0–1.7.6. Those numbers **are not public releases**; the public version remains **1.0.0**. The log is retained in Git history for traceability, without making its internal revision-by-revision checklist the main public testing document.

## Automated coverage

The CLI tests load OJS and the plugin, then exercise the database, filesystem, submission-file lifecycle, quota calculations, reconciliation, notifications and database-backed upload reservations. The HTTP tests cover upload restrictions, remaining-space checks, access control, API tokens, CSRF protection and HTML escaping.

Concurrency checks start independent PHP processes and exercise simultaneous **reservation requests**. They are not full simultaneous HTTP file uploads. Some scan/file-change races are deterministic interleavings.

The check count depends on the configured local dataset. The existence of the suite and the number of assertions **must not be presented as an execution result**. GitHub Actions does not currently run this integration suite; it runs syntax and header checks.

## Environment and limits

- The suite requires an existing, disposable Docker OJS copy with matching journals, users, submissions and database fixtures. It does not provision OJS from scratch.
- Its snapshot/restore logic is best-effort and not a complete backup or guaranteed rollback. The token checks replace and then clear local test users' API keys.
- Production email delivery to real journal managers, complete XML issue import, profile-image exemption, and live package install/upgrade are outside the automated suite.
- Compatibility with OJS versions other than 3.5.0-6 and databases other than MySQL/MariaDB is not yet verified.

See the [test README](../tests/README.md) for the complete list of fixture assumptions and run commands.
