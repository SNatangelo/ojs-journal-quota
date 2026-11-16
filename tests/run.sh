#!/usr/bin/env bash
# tests/run.sh
# Copyright (C) 2026 Stefano Natangelo
# SPDX-License-Identifier: AGPL-3.0-only
#
# Automated journalQuota suite — LOCAL Docker copy ONLY.
#
#   bash run.sh            run all tests
#   bash run.sh cli        run CLI tests only
#   bash run.sh http       run HTTP tests only
#   bash run.sh cli avvisi run one CLI test group
#
# First snapshots the plugin state (settings, file ledger,
# reservations, notifications), then attempts to restore it on exit.
# No real email messages are sent.

set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export MSYS_NO_PATHCONV=1
WHAT=${1:-all}; GROUP=${2:-}
case "$WHAT" in all|cli|http|check) ;; *) echo 'Usage: tests/run.sh [all|cli|http|check] [CLI group]' >&2; exit 2 ;; esac

source "$HERE/safety.sh"
jq_preflight || exit 2
jq_attest_http || exit 2
if [ "$WHAT" = check ]; then
  echo 'Fixture identity and configuration verified; no OJS regression tests were run.'
  exit 0
fi

PLUGIN_DIR="${JQ_PLUGIN_DIR:-$HERE/..}"
for source_path in JournalQuotaPlugin.php version.xml emailTemplates.xml classes templates locale; do
  [ -e "$PLUGIN_DIR/$source_path" ] || { echo "Missing plugin source: $source_path" >&2; exit 2; }
done

# Only the inspected containers may be modified. An existing directory is a lock
# or recovery state, never something to remove automatically before a test run.
jq_docker exec "$JQ_OJS_ID" mkdir -m 700 /tmp/jqtests || {
  echo 'An active or interrupted run already exists at /tmp/jqtests. Inspect or recreate the disposable fixture.' >&2
  exit 2
}
export JQ_RUN_NONCE
JQ_RUN_NONCE=$(LC_ALL=C od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
[[ "$JQ_RUN_NONCE" =~ ^[a-f0-9]{32}$ ]] || exit 2
export JQLOG
JQLOG=$(mktemp "${TMPDIR:-/tmp}/journalQuota-tests.XXXXXXXX.log")
snapshot_ready=0

restore() {
  local result=$?
  trap - EXIT HUP INT TERM
  if [ "$snapshot_ready" = 1 ]; then
    if ! jqcli restore 2>>"$JQLOG"; then
      echo 'Snapshot restore failed; recovery files were retained in /tmp/jqtests.' >&2
      exit 2
    fi
    # Only remove the known runner files; do not erase unexpected recovery data.
    jq_docker exec "$JQ_OJS_ID" rm -f -- /tmp/jqtests/cli.php /tmp/jqtests/guard.php /tmp/jqtests/preflight.php /tmp/jqtests/authorization || result=2
    jq_docker exec "$JQ_OJS_ID" rmdir /tmp/jqtests || result=2
  else
    echo 'Setup or snapshot failed; /tmp/jqtests was retained for inspection. No regression tests started.' >&2
    result=2
  fi
  exit "$result"
}
trap restore EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

host_path() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else printf '%s\n' "$1"; fi; }
for helper in cli.php preflight.php guard.php; do
  jq_docker cp "$(host_path "$HERE/$helper")" "$JQ_OJS_ID:/tmp/jqtests/$helper"
done
printf '%s\n%s\n%s\n' "$JQ_RUN_NONCE" "$JQ_TEST_INSTANCE" "$JQ_DB_IP" | \
  jq_docker exec -i "$JQ_OJS_ID" sh -c 'umask 077; cat > /tmp/jqtests/authorization'
jq_docker exec "$JQ_OJS_ID" chown -R www-data:www-data /tmp/jqtests

# Preserve the OJS-level mail check as well as the early no-bootstrap check.
MAIL=$(jqcli mail-driver 2>>"$JQLOG" | tail -1)
[ "$MAIL" = log ] || { echo 'OJS mail driver must be log; tests refused.' >&2; exit 2; }

echo "Installing plugin source into verified disposable instance $JQ_TEST_INSTANCE"
jq_docker exec "$JQ_OJS_ID" mkdir -p /var/www/html/plugins/generic/journalQuota
for source_path in JournalQuotaPlugin.php version.xml emailTemplates.xml classes templates locale; do
  jq_docker cp "$(host_path "$PLUGIN_DIR/$source_path")" "$JQ_OJS_ID:/var/www/html/plugins/generic/journalQuota/"
done
jq_docker exec "$JQ_OJS_ID" chown -R www-data:www-data /var/www/html/plugins/generic/journalQuota
jq_docker exec "$JQ_OJS_ID" sh -c 'find /var/www/html/cache/t_compile /var/www/html/cache/opcache -maxdepth 1 -type f -delete; find /var/www/html/cache -maxdepth 1 -type f -name "fc-locale*" -delete'

# A successful write and read-back are required before any mutating test helper.
jqcli snapshot 2>>"$JQLOG"
jqcli snapshot-check 2>>"$JQLOG"
snapshot_ready=1

rc=0
if [ "$WHAT" = all ] || [ "$WHAT" = cli ]; then
  jqcli test ${GROUP:+"$GROUP"} 2>>"$JQLOG" || rc=1
fi
if [ "$WHAT" = all ] || [ "$WHAT" = http ]; then
  bash "$HERE/http.sh" || rc=1
fi
echo
if [ "$rc" = 0 ]; then
  echo 'RISULTATO: tutti i test superati'
else
  echo "RISULTATO: ci sono test falliti (PHP log: $JQLOG)"
fi
exit "$rc"
