# Automated OJS integration tests

This suite exercises Journal Quota inside a real **OJS 3.5.0-6** Docker installation, using MariaDB/InnoDB and HTTP requests. It automates many of the scenarios in the [development test record](../docs/development-testing.md).

The reference dataset has four journals and defines **128 checks: 101 CLI and 27 HTTP**. The CLI count varies with the number of journals. These are suite counts, not an attached execution report.

## Requirements

- Windows with Docker Desktop and Git Bash (`cygpath` and Windows `curl` are used).
- A **disposable local OJS copy** with the matching journals, users, submissions and genres. The suite does not create a complete test installation or ship a database dump.
- Docker Compose services named `ojs` and `db`; OJS at `/var/www/html`, served at `http://localhost:8080`.
- The reference local database is named `ojs`, with the local-only credentials `ojs` / `ojs`.
- The local `import-locale.ps1` file contains `AdminPassword = '...'`; the HTTP runner reads it at runtime. This file and the password are not included.
- OJS email configuration `[email] default = log`. The runner refuses to start the tests otherwise.

Do not point these scripts at a live site. `JQ_BASE` is configurable, so local-only use is an operating requirement, not a complete technical safeguard.

## Run

From the repository root in Git Bash, set the path to the existing local Docker copy:

```bash
export JQ_LOCALE="/c/path/to/ojs-backup/locale"
bash tests/run.sh
```

The runner uses this repository as the plugin source. Set `JQ_PLUGIN_DIR` to test a different plugin directory. The original layout, with `journalQuota-tests/` alongside `journalQuota/`, is also supported.

| Command | Scope |
|---|---|
| `bash tests/run.sh` | CLI and HTTP checks |
| `bash tests/run.sh cli` | CLI checks only |
| `bash tests/run.sh http` | HTTP checks only |
| `bash tests/run.sh cli avvisi` | One CLI group |

CLI groups: `schema`, `misura`, `file`, `scansioni`, `registro`, `cartelle`, `prenotazioni`, `concorrenza`, `avvisi`, `blocco`, `problemi`.

A successful run reports `RISULTATO: tutti i test superati` and exits with status 0. Failed assertions produce a nonzero exit status. PHP diagnostics are written to `$TMPDIR/journalQuota-tests.log`, or `/tmp/journalQuota-tests.log` when `TMPDIR` is unset.

## Coverage

- **CLI:** schema/version; disk accounting checked against an independent count; submission-file creation, copies, revisions and deletion; deterministic interleavings of scans and file changes; inaccessible directories; reservation rejection, expiry and release; groups of 8 and 20 concurrent reservation requests from separate PHP processes; notifications, hysteresis, reminders, manager recipients, mail failure and retry; submission blocking and administrator problem notices.
- **HTTP:** quota pages and authentication; CSRF-protected settings and rescans; HTML escaping and Vue protection; blocked uploads through the API, library grid, XML-import route and temporary-file attachment; permitted reads; small-file acceptance and oversized-file rejection; administrator exemptions, anonymous access and API tokens.

The concurrency checks exercise database-backed reservations, not complete simultaneous HTTP uploads. They check accepted counts and surfaced errors; they do not establish that no internally retried deadlock occurred.

## Fixture configuration

Several values can be overridden with environment variables:

| Variable | Reference value | Purpose |
|---|---|---|
| `JQ_LOCALE` | Explicit local path recommended | Docker Compose directory |
| `JQ_PLUGIN_DIR` | Repository root | Plugin source |
| `JQ_BASE` | `http://localhost:8080` | Local HTTP endpoint |
| `JQ_SIDM_ID` / `JQ_SIDM_PATH` | `2` / `sidm` | Upload-test journal |
| `JQ_EDITOR` / `JQ_SUBMISSION` / `JQ_GENRE` | `40` / `54` / `13` | HTTP upload fixtures |
| `JQ_FILES_CTX` / `JQ_ALERT_CTX` | `3` / `3` | CLI files and notifications |
| `JQ_DIR_CTX` / `JQ_XSS_CTX` | `4` / `4` | Directory and HTML-escaping checks |

Not every fixture assumption is configurable: some checks also use journal 3, publication 1, genre 1 and user 1. Review `cli.php` and `http.sh` when adapting to another local dataset.

## State and cleanup

The runner copies the plugin into the local OJS installation and clears local caches. It snapshots plugin settings, file-ledger, reservation and notification state and attempts to restore that state on exit. Individual checks also clean up their test files and other fixtures.

This is **not a complete OJS backup or guaranteed rollback**. In particular, token tests replace the selected users' API keys and clear them afterwards rather than restoring previous keys. An interrupted run may leave temporary files or other fixture changes. Use a disposable local copy and test users.

Email assertions use an in-memory transport; the unreachable-server case uses a closed localhost port. No production email configuration or credentials are included.

## Outside the automated suite

- Real delivery from the production site to journal managers.
- Complete XML import of a real issue.
- Profile-image upload exemption.
- Installation and upgrade of the packaged plugin on a live site.

These are automation boundaries, not a statement that the plugin is undeployed: Journal Quota 1.0.0 is installed and functioning on JSENetwork with MySQL.

## Continuous integration

GitHub Actions runs PHP 8.4 syntax checks, including `cli.php`, and Bash syntax checks for the runners. It does **not** execute this local OJS integration suite.
