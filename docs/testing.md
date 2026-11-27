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
| Automated OJS integration and HTTP regression suite | **128 checks** in the reference four-journal dataset: 101 CLI and 27 HTTP | [Test runner and setup](https://github.com/SNatangelo/ojs-journal-quota/blob/main/tests/README.md) |
| Test-target safety regressions | Simulated Docker/HTTP identities and synthetic PHP configuration fixtures; no OJS bootstrap, live database or network traffic | [Safety-only checks](https://github.com/SNatangelo/ojs-journal-quota/blob/main/tests/README.md#safety-only-regressions) |
| Development test record | **64 documented checks** across functional behavior, file accounting, access control, concurrency, notifications and resilience | [Historical test log](https://github.com/SNatangelo/ojs-journal-quota/blob/17a489f23d43c40cee469211acb8578aba6ab2c2/docs/development-testing.md) |
| Continuous integration | PHP 8.4 syntax, Bash syntax, source-header checks and safety-only Bash/PHP regressions | [GitHub Actions workflow](https://github.com/SNatangelo/ojs-journal-quota/blob/main/.github/workflows/php-lint.yml) |
| Live deployment | Plugin installed and operational on JSENetwork with MySQL | Reported production deployment; not a substitute for automated production tests |

The historical log records internal development revisions numbered 1.0–1.7.6. Those numbers **are not public releases**; v1.0.0 was the initial public release. The log is retained in Git history for traceability, without making its internal revision-by-revision checklist the main public testing document.

## Automated coverage

The CLI tests load OJS and the plugin, then exercise the database, filesystem, submission-file lifecycle, quota calculations, reconciliation, notifications and database-backed upload reservations. The HTTP tests cover upload restrictions, remaining-space checks, access control, API tokens, CSRF protection and HTML escaping.

Concurrency checks start independent PHP processes and exercise simultaneous **reservation requests**. They are not full simultaneous HTTP file uploads. Some scan/file-change races are deterministic interleavings.

The check count depends on the configured local dataset. The existence of the suite and the number of assertions **must not be presented as an execution result**. GitHub Actions runs syntax, header and safety-guard checks, not the full OJS integration suite.

## Test-target protections

The integration runner fails closed unless the host explicitly authorizes a disposable fixture and its identity matches the OJS environment and both Docker container labels. It checks a local Docker daemon, the two running Compose containers, their dedicated bridge network and restricted port bindings. Before OJS bootstrap, a separate PHP preflight validates the fixture's local database settings, local base URLs and `log` mail configuration.

HTTP requests are restricted to the fixed loopback origin on port 8080, with proxies, redirects and curl configuration files disabled. A fresh static-file challenge confirms that the HTTP endpoint reaches the inspected OJS container before credentials are sent. This probe creates and removes a synthetic file; `bash tests/run.sh check` is therefore a limited preflight, **not an entirely read-only command**.

The runner does not start containers or choose a fallback Compose directory. Test helpers require an active per-run authorization and a valid initial snapshot, with initialization exceptions for the mail-driver and snapshot commands. A temporary-directory lock prevents concurrent runners; failures can retain recovery state for inspection.

`tests/safety-test.sh` uses mock commands and `tests/preflight-test.php` uses synthetic configuration fixtures. Their results validate rejection behavior independently of OJS. They do not establish that a live Docker/OJS setup has passed preflight or that the 128 integration assertions passed.

## Environment and limits

- The suite requires an existing, disposable Docker OJS copy with matching journals, users, submissions and database fixtures. It does not provision OJS from scratch.
- Disposable markers and identity checks protect against accidental targeting errors; they do not make live data disposable or isolate deliberately mislabelled production mounts.
- Its snapshot/restore logic is best-effort and not a complete backup or guaranteed rollback. The token checks replace and then clear local test users' API keys.
- Production email delivery to real journal managers, complete XML issue import, profile-image exemption, and live package install/upgrade are outside the automated suite.
- Compatibility with OJS versions other than 3.5.0-6 and databases other than MySQL/MariaDB is not yet verified.

See the [test README](https://github.com/SNatangelo/ojs-journal-quota/blob/main/tests/README.md) for fixture markers, a Compose configuration fragment and run commands. Test scripts are excluded from the installable release package; v1.0.0 was the initial public release.

## Maintenance release 1.0.0.1

CI builds and verifies the allowlisted archive and records MD5 and SHA-256. Archive validation is not an OJS installation test. Before publishing, verify a fresh installation and an upgrade from v1.0.0 in a disposable OJS 3.5.0-6 fixture, then manually run the PHP syntax workflow on `main` with the `publish` input enabled. The workflow publishes only after the CI checks pass. Do not run integration tests against production.
