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

HERE="$(cd "$(dirname "$0")" && pwd)"
export JQLOG="${TMPDIR:-/tmp}/journalQuota-tests.log"; : > "$JQLOG"
# Supports both tests/ in this repository and the legacy sibling-directory layout.
if [ -f "$HERE/../version.xml" ]; then
  PLUGIN_DIR="${JQ_PLUGIN_DIR:-$HERE/..}"
  DEFAULT_LOCALE="$HERE/../../../locale"
else
  PLUGIN_DIR="${JQ_PLUGIN_DIR:-$HERE/../journalQuota}"
  DEFAULT_LOCALE="$HERE/../../locale"
fi
export LOCALE="${JQ_LOCALE:-$DEFAULT_LOCALE}"
export MSYS_NO_PATHCONV=1
WHAT=${1:-all}; GROUP=$2

dc() { (cd "$LOCALE" && docker compose "$@"); }
jqcli() { dc exec -T -u www-data -e JQ_FILES_CTX -e JQ_ALERT_CTX -e JQ_DIR_CTX ojs php /tmp/jqtests/cli.php "$@" 2>>"$JQLOG"; }
export -f dc jqcli

[ -f "$PLUGIN_DIR/version.xml" ] || { echo "Plugin non trovato in $PLUGIN_DIR: impostare JQ_PLUGIN_DIR."; exit 2; }
[ -d "$LOCALE" ] || { echo "Copia Docker locale non trovata: impostare JQ_LOCALE."; exit 2; }

docker info >/dev/null 2>&1 || { echo "Docker non è avviato: aprite Docker Desktop e riprovate."; exit 2; }
[ "$(dc ps --status running --services 2>/dev/null | grep -c '^ojs$')" = 1 ] || dc up -d >/dev/null 2>&1
curl -s -o /dev/null --retry 10 --retry-connrefused --retry-delay 2 http://localhost:8080/ || { echo "OJS locale non risponde su http://localhost:8080"; exit 2; }

echo "Installo il plugin da $PLUGIN_DIR nella copia locale"
dc cp "$(cygpath -w "$PLUGIN_DIR")/." ojs:/var/www/html/plugins/generic/journalQuota/ >/dev/null 2>&1 || { echo "Copia del plugin fallita."; exit 2; }
dc exec -T ojs sh -c 'rm -rf /tmp/jqtests; mkdir -p /tmp/jqtests; chown -R www-data:www-data plugins/generic/journalQuota; rm -rf cache/t_compile/* cache/fc-locale* cache/opcache/*' || exit 2
dc cp "$(cygpath -w "$HERE/cli.php")" ojs:/tmp/jqtests/cli.php >/dev/null 2>&1 || { echo "Copia della suite fallita."; exit 2; }
dc exec -T ojs chown -R www-data:www-data /tmp/jqtests || exit 2

# Safety: do not run tests when real email delivery is configured.
MAIL=$(jqcli mail-driver | tail -1)
if [ "$MAIL" != log ]; then
  echo "In config.inc.php [email] default = '$MAIL': i test partono solo con 'log' (nessuna email reale)."; exit 2
fi

jqcli snapshot || { echo "Snapshot del plugin fallito: test non avviati."; exit 2; }
restore() {
  echo; jqcli restore
  dc exec -T ojs sh -c 'rm -rf cache/opcache/* /tmp/jqtests/cli.php'
}
trap restore EXIT

rc=0
if [ "$WHAT" = all ] || [ "$WHAT" = cli ]; then
  jqcli test $GROUP || rc=1
fi
if [ "$WHAT" = all ] || [ "$WHAT" = http ]; then
  bash "$HERE/http.sh" || rc=1
fi
echo
[ $rc = 0 ] && echo "RISULTATO: tutti i test superati" || echo "RISULTATO: ci sono test falliti (vedi sopra; messaggi di PHP in $JQLOG)"
exit $rc
