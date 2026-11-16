#!/usr/bin/env bash
# tests/http.sh
# Copyright (C) 2026 Stefano Natangelo
# SPDX-License-Identifier: AGPL-3.0-only
#
# journalQuota HTTP tests against the local copy (http://localhost:8080).
# Invoked by run.sh, which snapshots and restores the plugin state.

set -eu
HERE="$(cd "$(dirname "$0")" && pwd)"
export MSYS_NO_PATHCONV=1
source "$HERE/safety.sh"
# Recheck independently, then require the exact run and snapshot created by run.sh.
EXPECTED_OJS=${JQ_OJS_ID:-}
EXPECTED_DB=${JQ_DB_ID:-}
[[ "${JQ_RUN_NONCE:-}" =~ ^[a-f0-9]{32}$ ]] || {
  echo 'Run HTTP checks through tests/run.sh http; no authorized run exists.' >&2
  exit 2
}
jq_preflight || exit 2
[[ "$JQ_OJS_ID" = "$EXPECTED_OJS" && "$JQ_DB_ID" = "$EXPECTED_DB" ]] || {
  echo 'The Docker fixture changed after the runner preflight.' >&2
  exit 2
}
jqcli snapshot-check || exit 2
jq_attest_http || exit 2
readonly B="$JQ_BASE"
SIDM_ID=${JQ_SIDM_ID:-2}          # journal used for upload checks
SIDM=${JQ_SIDM_PATH:-sidm}
EDITOR=${JQ_EDITOR:-40}           # journal editor (not a site administrator)
SUB=${JQ_SUBMISSION:-54}          # submission in the upload-test journal
GENRE=${JQ_GENRE:-13}
XSS_ID=${JQ_XSS_CTX:-4}

PASS=0; FAIL=0; FAILED=()
ok()   { PASS=$((PASS+1)); echo "  ok    $1"; }
ko()   { FAIL=$((FAIL+1)); FAILED+=("$1"); echo "  FALLITO $1${2:+ — $2}"; }
check(){ if eval "$2"; then ok "$1"; else ko "$1" "${3:-}"; fi; }

# Windows-style path: the curl executable in Git Bash is a Windows program.
WORKDIR=$(mktemp -d) || exit 2
W=$WORKDIR
if command -v cygpath >/dev/null 2>&1; then W=$(cygpath -m "$WORKDIR"); fi
trap 'rm -f -- "$WORKDIR/admin.txt" "$WORKDIR/editor.txt" "$WORKDIR/piccolo.pdf" "$WORKDIR/grande.pdf" "$WORKDIR/import.xml" "$WORKDIR/body" "$WORKDIR/xss.html" "$WORKDIR/tokens"; rmdir "$WORKDIR"' EXIT
A="$W/admin.txt"; E="$W/editor.txt"
head -c 1024 /dev/urandom > "$W/piccolo.pdf"
head -c $((2560*1024)) /dev/urandom > "$W/grande.pdf"
echo '<?xml version="1.0"?><issues/>' > "$W/import.xml"

code() { jq_http -s -o "$W/body" -w '%{http_code}' "$@"; }
body() { cat "$W/body"; }
token(){ jq_http -s -b "$1" "$B/$SIDM/dashboard/editorial" | grep -o 'csrfToken":"[^"]*' | head -1 | cut -d'"' -f3; }
db()   { jq_docker exec "$JQ_DB_ID" mysql -uojs -pojs ojs -N -e "$1" 2>/dev/null; }
# CLI setting changes reach the web process after a few seconds:
# OJS stores cached PHP files that Apache keeps in OPcache.
setq() { local r; r=$(jqcli set-quota "$@"); sleep 3; echo "$r"; }
BLOCKED='has exceeded its disk limit|ha superato il limite di spazio'
TOOLARGE='only .* are left|restano solo'

# --- Sign in as a site administrator
PW=$(grep -o "AdminPassword = '[^']*'" "$LOCALE/import-locale.ps1" | cut -d"'" -f2)
ADMIN=$(db "select u.username from users u join user_user_groups uug on uug.user_id=u.user_id join user_groups g on g.user_group_id=uug.user_group_id where g.role_id=1 and g.context_id is null limit 1")
ADMIN_ID=$(db "select user_id from users where username='$ADMIN'")
LT=$(jq_http -s -c "$A" -b "$A" "$B/index/login" | grep -o 'name="csrfToken" value="[^"]*' | cut -d'"' -f4)
jq_http -s -c "$A" -b "$A" -o /dev/null --data-urlencode "csrfToken=$LT" --data-urlencode "username=$ADMIN" --data-urlencode "password=$PW" "$B/index/login/signIn"
unset PW

echo; echo "## Pagina dell'amministratore"
for q in "index/quota" "index/quota?journal=$SIDM_ID" "index/quota?journal=defaults" "$SIDM/quota"; do
  c=$(code -b "$A" "$B/$q"); check "$q risponde 200" '[ "$c" = 200 ]' "HTTP $c"
done
c=$(jq_http -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/index/quota")
check "utente non collegato mandato al login" '[[ "$c" == 302*login* ]]' "$c"
before=$(jqcli get-setting 3 quotaMb)
jq_http -s -b "$A" -o /dev/null -d "journal=3&custom=1&quotaMb=77" "$B/index/quota/save"
check "salvataggio senza token CSRF ignorato" '[ "$(jqcli get-setting 3 quotaMb)" = "$before" ]'
T=$(jq_http -s -b "$A" "$B/index/quota?journal=3" | grep -o 'name="csrfToken" value="[^"]*' | head -1 | cut -d'"' -f4)
jq_http -s -b "$A" -o /dev/null --data-urlencode "csrfToken=$T" -d "journal=3&custom=1&quotaMb=77&mode=grace&warnPct=80&tolerancePct=10&reminderDays=7&notifyWarn=1&notifyQuota=1&notifyTolerance=1&countPublic=1&countDatabase=1" "$B/index/quota/save"
check "salvataggio con token CSRF registrato" '[ "$(jqcli get-setting 3 quotaMb)" = 77 ]'
s1=$(jqcli age-scan $SIDM_ID); sleep 3
jq_http -s -b "$A" -o /dev/null "$B/$SIDM/quota?refresh=1"
jq_http -s -b "$A" -o /dev/null -d refresh=1 "$B/$SIDM/quota"
check "\"Misura di nuovo\" con GET o senza CSRF non misura" '[ "$(jqcli get-setting $SIDM_ID scannedAt)" = "$s1" ]'
T=$(jq_http -s -b "$A" "$B/$SIDM/quota" | grep -o 'name="csrfToken" value="[^"]*' | head -1 | cut -d'"' -f4)
jq_http -s -b "$A" -o /dev/null --data-urlencode "csrfToken=$T" -d refresh=1 "$B/$SIDM/quota"
check "\"Misura di nuovo\" con CSRF misura" '[ "$(jqcli get-setting $SIDM_ID scannedAt)" != "$s1" ]'
jqcli xss set $XSS_ID >/dev/null; sleep 3
jq_http -s -b "$A" "$B/index/quota?journal=$XSS_ID" > "$W/xss.html"
jqcli xss restore $XSS_ID >/dev/null
check "nome di rivista con HTML: mostrato come testo" '! grep -q "<img src=x" "$W/xss.html" && grep -q "&lt;img src=x" "$W/xss.html"'
check "nome di rivista con {{ }}: protetto da Vue (v-pre)" 'grep -q "v-pre" "$W/xss.html"'

echo; echo "## Rivista bloccata: caricamenti del redattore"
setq $SIDM_ID 1 block >/dev/null
cp "$A" "$E"
jq_http -s -b "$E" -c "$E" -o /dev/null "$B/$SIDM/login/signInAsUser/$EDITOR"
TOK=$(token "$E")
c=$(code -b "$E" -H "X-Csrf-Token: $TOK" -F "file=@$W/piccolo.pdf" -F fileStage=9 -F genreId=$GENRE -F "name[en]=jqtest.pdf" "$B/$SIDM/api/v1/submissions/$SUB/files")
check "caricamento via API rifiutato (HTTP 400, messaggio di blocco)" '[ "$c" = 400 ] && body | grep -Eq "$BLOCKED"' "HTTP $c $(body | head -c 200)"
code -b "$E" -F "uploadedFile=@$W/piccolo.pdf" -F "csrfToken=$TOK" "$B/$SIDM/\$\$\$call\$\$\$/grid/settings/library/library-file-admin-grid/upload-file" >/dev/null
check "caricamento dalla griglia della biblioteca rifiutato" 'body | grep -Eq "$BLOCKED"' "$(body | head -c 200)"
TMP=$(jqcli tempfile $EDITOR 300000)
code -b "$E" "$B/$SIDM/management/importexport/plugin/NativeImportExportPlugin/import?temporaryFileId=$TMP&csrfToken=$TOK" >/dev/null
check "importazione XML (GET con file temporaneo) rifiutata" 'body | grep -Eq "$BLOCKED"' "$(body | head -c 200)"
c=$(code -b "$E" -H "X-Csrf-Token: $TOK" -H 'Content-Type: application/json' -X PUT -d "{\"coverImage\":{\"en\":{\"temporaryFileId\":$TMP,\"altText\":\"\"}}}" "$B/$SIDM/api/v1/submissions/$SUB/publications/1")
check "file temporaneo agganciato in una richiesta JSON rifiutato" 'body | grep -Eq "$BLOCKED"' "HTTP $c $(body | head -c 200)"
c=$(code -b "$E" "$B/$SIDM/api/v1/submissions/$SUB/files?stageId=5")
check "lettura dei file permessa" '[ "$c" = 200 ]' "HTTP $c"

echo; echo "## Spazio quasi pieno: file piccolo sì, file grande no"
read -r used limit blocked <<<"$(setq $SIDM_ID $(( $(setq $SIDM_ID 1 block | cut -d' ' -f1) / 1048576 + 2 )) block)"
check "rivista aperta con circa 1-2 MB liberi" '[ "$blocked" = 0 ]' "usati $used, limite $limit"
c=$(code -b "$E" -H "X-Csrf-Token: $TOK" -F "file=@$W/piccolo.pdf" -F fileStage=9 -F genreId=$GENRE -F "name[en]=jqtest.pdf" "$B/$SIDM/api/v1/submissions/$SUB/files")
ID=$(body | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
check "file da 1 KB accettato" '[ "$c" = 200 ] && [ -n "$ID" ]' "HTTP $c $(body | head -c 200)"
[ -n "$ID" ] && c=$(code -b "$E" -H "X-Csrf-Token: $TOK" -X DELETE "$B/$SIDM/api/v1/submissions/$SUB/files/$ID?stageId=4") && check "e cancellato" '[ "$c" = 200 ]' "HTTP $c"
c=$(code -b "$E" -H "X-Csrf-Token: $TOK" -F "file=@$W/grande.pdf" -F fileStage=9 -F genreId=$GENRE -F "name[en]=jqtest.pdf" "$B/$SIDM/api/v1/submissions/$SUB/files")
check "file da 2,5 MB rifiutato con lo spazio che resta" '[ "$c" = 400 ] && body | grep -Eq "$TOOLARGE"' "HTTP $c $(body | head -c 200)"
c=$(code -b "$E" -H "X-Csrf-Token: $TOK" -F "file=@$W/piccolo.pdf" -F "file2=@$W/grande.pdf" -F fileStage=9 -F genreId=$GENRE -F "name[en]=jqtest.pdf" "$B/$SIDM/api/v1/submissions/$SUB/files")
check "file piccolo + file grande nella stessa richiesta rifiutati" '[ "$c" = 400 ]' "HTTP $c $(body | head -c 200)"
jq_http -s -b "$E" -c "$E" -o /dev/null "$B/$SIDM/login/signOutAsUser"
cp "$E" "$A"   # return the administrator session to its own cookie file

echo; echo "## Chi non viene bloccato"
setq $SIDM_ID 1 block >/dev/null
ATOK=$(token "$A")
c=$(code -b "$A" -H "X-Csrf-Token: $ATOK" -F "file=@$W/piccolo.pdf" -F fileStage=9 -F genreId=$GENRE -F "name[en]=jqtest.pdf" "$B/$SIDM/api/v1/submissions/$SUB/files")
ID=$(body | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
check "l'amministratore del sito carica anche su rivista bloccata" '[ "$c" = 200 ] && [ -n "$ID" ]' "HTTP $c"
[ -n "$ID" ] && c=$(code -b "$A" -H "X-Csrf-Token: $ATOK" -X DELETE "$B/$SIDM/api/v1/submissions/$SUB/files/$ID?stageId=4") && check "e cancella" '[ "$c" = 200 ]' "HTTP $c"
c=$(code -F "file=@$W/piccolo.pdf" -F fileStage=9 -F genreId=$GENRE "$B/$SIDM/api/v1/submissions/$SUB/files")
check "utente anonimo: errore normale di OJS, non del plugin" '[ "$c" != 200 ] && ! body | grep -Eq "$BLOCKED"' "HTTP $c"

echo; echo "## Token API"
jqcli tokens set $EDITOR $ADMIN_ID > "$W/tokens"
ET=$(grep "^$EDITOR " "$W/tokens" | cut -d' ' -f2); AT=$(grep "^$ADMIN_ID " "$W/tokens" | cut -d' ' -f2)
c=$(code -H "Authorization: Bearer $ET" -F "file=@$W/piccolo.pdf" -F fileStage=9 -F genreId=$GENRE "$B/$SIDM/api/v1/submissions/$SUB/files")
check "redattore con token (intestazione): rifiutato" '[ "$c" = 400 ] && body | grep -Eq "$BLOCKED"' "HTTP $c $(body | head -c 200)"
c=$(code -F "file=@$W/piccolo.pdf" -F fileStage=9 -F genreId=$GENRE "$B/$SIDM/api/v1/submissions/$SUB/files?apiToken=$ET")
check "redattore con token (nell'indirizzo): rifiutato" '[ "$c" = 400 ] && body | grep -Eq "$BLOCKED"' "HTTP $c $(body | head -c 200)"
# (In the URL: local Apache does not always forward the Authorization header to OJS.)
c=$(code -F "file=@$W/piccolo.pdf" -F fileStage=9 -F genreId=$GENRE "$B/$SIDM/api/v1/submissions/$SUB/files?apiToken=$AT")
ID=$(body | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
check "amministratore con token: passa" '[ "$c" = 200 ] && [ -n "$ID" ]' "HTTP $c $(body | head -c 200)"
[ -n "$ID" ] && jq_http -s -o /dev/null -X DELETE "$B/$SIDM/api/v1/submissions/$SUB/files/$ID?stageId=4&apiToken=$AT"
jqcli tokens clear $EDITOR $ADMIN_ID
jqcli tempfile-clean

echo; echo "HTTP: $PASS superati, $FAIL falliti"
for f in "${FAILED[@]}"; do echo "  - $f"; done
[ "$FAIL" = 0 ]
