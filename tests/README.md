<!--
tests/README.md
Copyright (C) 2026 Stefano Natangelo
SPDX-License-Identifier: AGPL-3.0-only
-->

# Automated OJS integration tests

This suite exercises Journal Quota inside a real **OJS 3.5.0-6** Docker installation, using MariaDB/InnoDB and HTTP requests. It automates many of the scenarios in the [testing and validation notes](../docs/testing.md).

The reference dataset has four journals and defines **128 checks: 101 CLI and 27 HTTP**. The CLI count varies with the number of journals. These are suite counts, not an attached execution report.

## Requirements

- A **disposable local OJS copy** with the matching journals, users, submissions and genres. The suite does not create a complete test installation or ship a database dump.
- Bash 4 or later, `curl`, Docker and Docker Compose. The reference integration fixture was prepared with Docker Desktop and Git Bash on Windows.
- Two already-running Compose services named `ojs` and `db`, belonging to the same project and sharing a dedicated bridge network containing only those two containers. Extra networks, host overrides and legacy container links are not accepted.
- A **local Docker daemon** reached through a Unix socket or a local Windows named pipe. TCP/SSH endpoints, TLS overrides and conflicting Docker context/host overrides are refused.
- OJS at `/var/www/html`, publishing **only `127.0.0.1:8080` to container port `80`**. The `db` service must have the `db` network alias and no published host ports.
- Explicit disposable-fixture markers in the host environment, OJS container environment and both containers' labels, as described below.
- OJS database configuration using `mysql` or `mysqli`, host `db`, database `ojs` and the local-only username/password `ojs` / `ojs`; the port must be `3306` or left at its default.
- OJS base URLs using the approved local origin; journal-specific base URLs may append a path. External base URLs are refused before OJS is bootstrapped.
- The local `import-locale.ps1` file contains `AdminPassword = '...'`; the HTTP runner reads it at runtime. This file and the password are not included.
- OJS email configuration `[email] default = log`. This is checked before OJS bootstrap and again by the runner.

The runner does not start containers or guess a Compose directory. Missing, inconsistent or unverified configuration stops the run before plugin installation or test mutations.

## Mark the disposable fixture

Choose an identifier beginning with `jq-test-`, followed by 8–56 lowercase letters, digits or hyphens (starting with a letter or digit). Use the **same identifier** in the host environment, the OJS environment and both container labels.

The following is a **configuration fragment**, not a complete Compose file. Adapt the actual configuration of your disposable fixture, retaining its existing image, volumes and other required settings:

```yaml
services:
  ojs:
    environment:
      JQ_TEST_DISPOSABLE: "1"
      JQ_TEST_INSTANCE: "jq-test-local-20261116"
    labels:
      org.jsenetwork.journal-quota.disposable: "true"
      org.jsenetwork.journal-quota.test-instance: "jq-test-local-20261116"
    ports:
      - "127.0.0.1:8080:80"
    networks:
      - jq_test
  db:
    labels:
      org.jsenetwork.journal-quota.disposable: "true"
      org.jsenetwork.journal-quota.test-instance: "jq-test-local-20261116"
    networks:
      jq_test:
        aliases:
          - db
networks:
  jq_test:
    driver: bridge
```

Replace other published OJS ports and remove database host ports; both containers must use only this dedicated network. Do not assume that adding an override file removes existing ports or networks: Compose can merge these settings. Recreate and start the disposable fixture yourself after changing its configuration; the runner will only inspect an already-running instance.

These markers authorize **test data**, not live data. They are safeguards against accidental target confusion, not a sandbox for deliberately relabelled production containers or volumes. The underlying database and files must genuinely be disposable.

## Run

From the repository root, explicitly select and authorize the existing local fixture:

```bash
export JQ_LOCALE="/c/path/to/disposable-ojs/locale"
export JQ_TEST_DISPOSABLE=1
export JQ_TEST_INSTANCE=jq-test-local-20261116
bash tests/run.sh check
bash tests/run.sh
```

`JQ_BASE` defaults to `http://localhost:8080`. The only accepted values are that URL and `http://127.0.0.1:8080`, each with an optional trailing slash; requests are pinned to `127.0.0.1`. Other hosts, ports, schemes, credentials, paths, query strings and fragments are refused. The HTTP transport disables curl configuration files, proxies, redirects and URL globbing.

Before sending login credentials, the runner verifies that the HTTP origin reaches the inspected OJS container using a fresh, random, non-secret static file. The file is removed after the check. Consequently, **`check` is not completely read-only**: it creates and removes this synthetic probe, but does not install the plugin, bootstrap OJS or run data-changing tests.

The runner uses this repository as the plugin source. Set `JQ_PLUGIN_DIR` explicitly when testing a different plugin directory or keeping the tests in a separate directory. `JQ_LOCALE` is always required.

| Command | Scope |
|---|---|
| `bash tests/run.sh check` | Target, configuration and HTTP-identity preflight only |
| `bash tests/run.sh` | CLI and HTTP checks |
| `bash tests/run.sh cli` | CLI checks only |
| `bash tests/run.sh http` | HTTP checks only |
| `bash tests/run.sh cli avvisi` | One CLI group |

CLI groups: `schema`, `misura`, `file`, `scansioni`, `registro`, `cartelle`, `prenotazioni`, `concorrenza`, `avvisi`, `blocco`, `problemi`.

A successful integration run reports `RISULTATO: tutti i test superati` and exits with status 0. Failed assertions produce a nonzero exit status. PHP diagnostics use a unique `journalQuota-tests.XXXXXXXX.log` file under `$TMPDIR` (or `/tmp` when unset); the runner reports its path when integration assertions fail.

Invoke tests through `run.sh`. Direct HTTP/CLI entry points require an active runner authorization and a valid initial snapshot; they are not independent shortcuts around preflight. The runner's mail-driver and snapshot operations are the initialization exceptions.

## Safety-only regressions

These checks are separate from the 128 OJS integration assertions:

```bash
bash tests/safety-test.sh
php tests/preflight-test.php
```

The Bash regressions use simulated Docker and curl commands: no Docker daemon, OJS instance or network request is used. They exercise rejection of external URLs, missing or conflicting authorization, mismatched containers/networks/ports, invalid HTTP identity and unsafe transport options. The PHP regressions use synthetic configuration fixtures and do not bootstrap OJS or connect to a database; they require a local PHP interpreter.

Passing these checks demonstrates the behavior of the **test protections**, not successful execution of the full OJS suite or compatibility with every Docker Desktop configuration.

## Coverage

- **CLI:** schema/version; disk accounting checked against an independent count; submission-file creation, copies, revisions and deletion; deterministic interleavings of scans and file changes; inaccessible directories; reservation rejection, expiry and release; groups of 8 and 20 concurrent reservation requests from separate PHP processes; notifications, hysteresis, reminders, manager recipients, mail failure and retry; submission blocking and administrator problem notices.
- **HTTP:** quota pages and authentication; CSRF-protected settings and rescans; HTML escaping and Vue protection; blocked uploads through the API, library grid, XML-import route and temporary-file attachment; permitted reads; small-file acceptance and oversized-file rejection; administrator exemptions, anonymous access and API tokens.

The concurrency checks exercise database-backed reservations, not complete simultaneous HTTP uploads. They check accepted counts and surfaced errors; they do not establish that no internally retried deadlock occurred.

## Fixture configuration

Several values can be overridden with environment variables:

| Variable | Reference value | Purpose |
|---|---|---|
| `JQ_LOCALE` | Required explicit local path | Docker Compose directory |
| `JQ_TEST_DISPOSABLE` | Required: `1` | Explicit host authorization for disposable testing |
| `JQ_TEST_INSTANCE` | Required, matching fixture markers | Identity of the authorized disposable fixture |
| `JQ_PLUGIN_DIR` | Repository root | Plugin source |
| `JQ_BASE` | `http://localhost:8080` | Allowlisted local HTTP origin; normalized to `127.0.0.1` |
| `JQ_SIDM_ID` / `JQ_SIDM_PATH` | `2` / `sidm` | Upload-test journal |
| `JQ_EDITOR` / `JQ_SUBMISSION` / `JQ_GENRE` | `40` / `54` / `13` | HTTP upload fixtures |
| `JQ_FILES_CTX` / `JQ_ALERT_CTX` | `3` / `3` | CLI files and notifications |
| `JQ_DIR_CTX` / `JQ_XSS_CTX` | `4` / `4` | Directory and HTML-escaping checks |

Not every fixture assumption is configurable: some checks also use journal 3, publication 1, genre 1 and user 1. Review `cli.php` and `http.sh` when adapting to another local dataset.

## State and cleanup

After preflight, the runner copies the plugin into the verified local OJS installation and clears local caches. It requires a successful initial snapshot of plugin settings, file-ledger, reservation and notification state before starting tests, then attempts restoration on exit. Individual checks also clean up their test files and other fixtures.

A per-run authorization and a lock on the fixed temporary test directory prevent overlapping runners and stale helper invocations. Recovery data is retained when setup or restoration fails; inspect or recreate the disposable fixture instead of automatically discarding that state and retrying.

This is **not a complete OJS backup or guaranteed rollback**. In particular, token tests replace the selected users' API keys and clear them afterwards rather than restoring previous keys. An interrupted run may leave temporary files or other fixture changes. Use a disposable local copy and test users.

Email assertions use an in-memory transport; the unreachable-server case uses a closed localhost port. No production email configuration or credentials are included.

## Outside the automated suite

- Real delivery from the production site to journal managers.
- Complete XML import of a real issue.
- Profile-image upload exemption.
- Installation and upgrade of the packaged plugin on a live site.

These are automation boundaries, not a statement that the plugin is undeployed: Journal Quota 1.0.0 is installed and functioning on JSENetwork with MySQL.

## Continuous integration

GitHub Actions runs PHP 8.4 syntax checks, Bash syntax checks, copyright/SPDX-header checks and the safety-only Bash/PHP regressions. It does **not** provision OJS or execute the 128 local OJS integration checks. A green workflow therefore does not establish that the full integration suite has passed.
