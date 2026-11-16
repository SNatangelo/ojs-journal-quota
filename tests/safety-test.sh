#!/usr/bin/env bash
# tests/safety-test.sh
# Copyright (C) 2026 Stefano Natangelo
# SPDX-License-Identifier: AGPL-3.0-only
#
# Offline regressions for the test runner's safety boundary, not OJS tests.
# Docker and curl are replaced by temporary copies of this script. Unexpected
# commands fail instead of falling through to a real daemon or HTTP client.

mock_unexpected() {
  printf 'UNEXPECTED %s\n' "$*" >> "$MOCK_LOG"
  exit 97
}

mock_docker() {
  printf 'DOCKER' >> "$MOCK_LOG"
  printf ' %q' "$@" >> "$MOCK_LOG"
  printf '\n' >> "$MOCK_LOG"
  if [ "${1:-}" = --host ]; then
    case "${2:-}" in
      unix:///var/run/docker.sock|npipe:////./pipe/docker_engine) ;;
      *) mock_unexpected "Docker endpoint: ${2:-}" ;;
    esac
    shift 2
  fi
  case "${1:-}" in
    context)
      case "${2:-}" in
        show) printf 'default\n' ;;
        inspect)
          case "$MOCK_CASE" in
            remote-context) printf 'ssh://example.invalid\n' ;;
            *) printf 'unix:///var/run/docker.sock\n' ;;
          esac
          ;;
        *) mock_unexpected 'Docker context command' ;;
      esac
      ;;
    compose)
      [ "$PWD" = "$MOCK_LOCALE" ] || mock_unexpected 'Compose working directory'
      [ "${2:-} ${3:-}" = 'ps -q' ] || mock_unexpected "Compose command: $*"
      case "${4:-}" in
        ojs)
          case "$MOCK_CASE" in
            missing-ojs) return 0 ;;
            duplicate-ojs) printf '%s\n%s\n' "$OJS_ID" "$DB_ID" ;;
            malformed-id) printf 'not-a-container-id\n' ;;
            *) printf '%s\n' "$OJS_ID" ;;
          esac
          ;;
        db)
          case "$MOCK_CASE" in
            missing-db) return 0 ;;
            *) printf '%s\n' "$DB_ID" ;;
          esac
          ;;
        *) mock_unexpected "Compose service: ${4:-}" ;;
      esac
      ;;
    container)
      [ "${2:-} ${3:-}" = 'inspect --format' ] || mock_unexpected "Container command: $*"
      mock_container_inspect "${4:-}" "${5:-}"
      ;;
    network)
      [ "${2:-} ${3:-}" = 'inspect --format' ] || mock_unexpected "Network command: $*"
      [ "${5:-}" = "$NETWORK_ID" ] || mock_unexpected "Network ID: ${5:-}"
      case "${4:-}" in
        *'{{.Id}}|{{.Name}}|{{.Driver}}|{{.Scope}}|'*)
          case "$MOCK_CASE" in
            wrong-network-project) printf '%s|jq-fixture_default|bridge|local|other-project|default|2\n' "$NETWORK_ID" ;;
            unmanaged-network) printf '%s|jq-fixture_default|bridge|local|jq-fixture||2\n' "$NETWORK_ID" ;;
            extra-network-member) printf '%s|jq-fixture_default|bridge|local|jq-fixture|default|3\n' "$NETWORK_ID" ;;
            *) printf '%s|jq-fixture_default|bridge|local|jq-fixture|default|2\n' "$NETWORK_ID" ;;
          esac
          ;;
        *'range $id, $_ := .Containers'*)
          printf '%s\n' "$OJS_ID"
          if [ "$MOCK_CASE" = wrong-network-member ]; then
            printf 'd%.0s' {1..64}; printf '\n'
          else
            printf '%s\n' "$DB_ID"
          fi
          ;;
        *) mock_unexpected "Network format: ${4:-}" ;;
      esac
      ;;
    exec)
      if [ "${14:-} ${15:-} ${16:-} ${17:-}" = "$OJS_ID php /tmp/jqtests/cli.php snapshot-check" ] && [ "$MOCK_CASE" = missing-snapshot ]; then
        printf 'CHECK_SNAPSHOT\n' >> "$MOCK_LOG"
        return 2
      fi
      if [ "${2:-} ${3:-} ${4:-} ${5:-}" = "-i $OJS_ID php --" ]; then
        [ "${6:-}" = "$JQ_TEST_INSTANCE" ] || mock_unexpected 'Preflight instance'
        [ "${7:-}" = 172.30.0.3 ] || mock_unexpected 'Preflight database IP'
        cat > /dev/null
        printf 'READ_CONFIG\n' >> "$MOCK_LOG"
        case "$MOCK_CASE" in
          config-rejected|mail-rejected|db-config-rejected) return 2 ;;
          *) return 0 ;;
        esac
      fi
      if [ "${2:-} ${3:-} ${4:-} ${5:-} ${6:-} ${7:-} ${9:-}" = "-i -u www-data $OJS_ID sh -c jq-test-probe" ]; then
        local nonce probe_path=${10:-}
        nonce=$(cat)
        [[ "$nonce" =~ ^[0-9a-f]{32}$ ]] || mock_unexpected 'Probe nonce'
        [ "$probe_path" = "/var/www/html/public/jq-test-probe-$nonce.txt" ] || mock_unexpected 'Probe path'
        grep -q '^READ_CONFIG' "$MOCK_LOG" || mock_unexpected 'Probe before configuration check'
        printf 'PROBE_WRITE %s\n' "$probe_path" >> "$MOCK_LOG"
        printf '%s' "$nonce" > "$MOCK_PROBE"
        [ "$MOCK_CASE" != challenge-write-error ] || return 1
        return 0
      fi
      if [ "${2:-} ${3:-} ${4:-} ${5:-} ${6:-} ${7:-}" = "-u www-data $OJS_ID rm -f --" ]; then
        case "${8:-}" in
          /var/www/html/public/jq-test-probe-*.txt) ;;
          *) mock_unexpected 'Probe cleanup path' ;;
        esac
        printf 'PROBE_CLEAN\n' >> "$MOCK_LOG"
        rm -f -- "$MOCK_PROBE"
        return 0
      fi
      mock_unexpected "Docker exec: $*"
      ;;
    cp|start|run|restart|stop|rm)
      printf 'MUTATE\n' >> "$MOCK_LOG"
      mock_unexpected "Mutating Docker command: $*"
      ;;
    *) mock_unexpected "Docker command: $*" ;;
  esac
}

mock_container_inspect() {
  local format=$1 id=$2 service running=true project=jq-fixture disposable=true
  local instance=$JQ_TEST_INSTANCE extras=0 links=0 networks=1 reported_id=$2
  case "$id" in
    "$OJS_ID") service=ojs ;;
    "$DB_ID") service=db ;;
    *) mock_unexpected "Container ID: $id" ;;
  esac
  case "$format" in
    *'{{.Id}}|{{.State.Running}}|'*)
      case "$MOCK_CASE:$service" in
        stopped-ojs:ojs) running=false ;;
        stopped-db:db) running=false ;;
        wrong-container-id:ojs) reported_id=$DB_ID ;;
        missing-disposable:ojs) disposable='<no value>' ;;
        db-not-disposable:db) disposable=false ;;
        wrong-instance:db) instance=jq-test-other-fixture ;;
        wrong-project:db) project=other-project ;;
        wrong-service:ojs) service=web ;;
        extra-hosts:ojs) extras=1 ;;
        legacy-links:ojs) links=1 ;;
        multiple-networks:ojs) networks=2 ;;
      esac
      printf '%s|%s|%s|%s|%s|%s|%s|%s|%s\n' "$reported_id" "$running" "$project" "$service" "$disposable" "$instance" "$extras" "$links" "$networks"
      ;;
    *'range $name, $net := .NetworkSettings.Networks'*)
      if [ "$service" = ojs ]; then
        printf 'jq-fixture_default|%s|172.30.0.2|\n' "$NETWORK_ID"
      else
        case "$MOCK_CASE" in
          different-network) printf 'other_default|%s|172.31.0.3|db\n' "$OJS_ID" ;;
          missing-db-alias) printf 'jq-fixture_default|%s|172.30.0.3|\n' "$NETWORK_ID" ;;
          *) printf 'jq-fixture_default|%s|172.30.0.3|db\n' "$NETWORK_ID" ;;
        esac
      fi
      ;;
    *'range $port, $bindings := .NetworkSettings.Ports'*)
      if [ "$service" = ojs ]; then
        case "$MOCK_CASE" in
          public-binding) printf '80/tcp|0.0.0.0|8080\n' ;;
          wrong-port) printf '80/tcp|127.0.0.1|8081\n' ;;
          missing-binding) return 0 ;;
          extra-binding) printf '80/tcp|127.0.0.1|8080\n80/tcp|0.0.0.0|8080\n' ;;
          *) printf '80/tcp|127.0.0.1|8080\n' ;;
        esac
      elif [ "$MOCK_CASE" = published-db ]; then
        printf '3306/tcp|127.0.0.1|3306\n'
      fi
      ;;
    *) mock_unexpected "Container format: $format" ;;
  esac
}

mock_curl() {
  printf 'CURL' >> "$MOCK_LOG"
  printf ' %q' "$@" >> "$MOCK_LOG"
  printf '\n' >> "$MOCK_LOG"
  [ "${1:-}" = -q ] || mock_unexpected 'curl must disable curlrc first'
  local proxy=0 no_proxy=0 protocol=0 redirects=0 url='' format='' nonce
  shift
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --silent|--show-error|--globoff) shift ;;
      --proxy) [ "${2-wrong}" = '' ] || mock_unexpected 'curl proxy'; proxy=1; shift 2 ;;
      --noproxy) [ "${2:-}" = '*' ] || mock_unexpected 'curl noproxy'; no_proxy=1; shift 2 ;;
      --proto) [ "${2:-}" = '=http' ] || mock_unexpected 'curl protocols'; protocol=1; shift 2 ;;
      --proto-redir) [ "${2:-}" = '=http' ] || mock_unexpected 'curl redirect protocols'; shift 2 ;;
      --max-redirs) [ "${2:-}" = 0 ] || mock_unexpected 'curl redirects'; redirects=1; shift 2 ;;
      --connect-timeout|--max-time) shift 2 ;;
      -w|--write-out) format=${2:-}; shift 2 ;;
      http://127.0.0.1:8080/public/jq-test-probe-*.txt)
        [ -z "$url" ] || mock_unexpected 'Multiple HTTP targets'
        url=$1; shift
        ;;
      *) mock_unexpected "curl argument: $1" ;;
    esac
  done
  [ "$proxy$no_proxy$protocol$redirects" = 1111 ] || mock_unexpected 'Missing curl safeguards'
  [ "$format" = $'\n%{http_code}' ] || mock_unexpected 'Probe HTTP status format'
  [ -f "$MOCK_PROBE" ] || mock_unexpected 'HTTP request before probe creation'
  nonce=$(cat "$MOCK_PROBE")
  [ "$url" = "http://127.0.0.1:8080/public/jq-test-probe-$nonce.txt" ] || mock_unexpected 'Wrong probe URL'
  case "$MOCK_CASE" in
    challenge-mismatch) printf 'wrong-fixture\n200' ;;
    challenge-redirect) printf '%s\n302' "$nonce" ;;
    challenge-http-error) printf '%s\n404' "$nonce" ;;
    challenge-network-error) return 7 ;;
    *) printf '%s\n200' "$nonce" ;;
  esac
}

# Only the temporary fake executables enter this branch.
if [ "${JQ_SAFETY_MOCK:-}" = 1 ]; then
  case "${0##*/}" in
    docker) mock_docker "$@"; exit $? ;;
    curl) mock_curl "$@"; exit $? ;;
    cygpath)
      [ "${1:-}" = -m ] || [ "${1:-}" = -w ] || mock_unexpected 'cygpath option'
      case "${2:-}" in
        "$MOCK_WORK"/*) printf '%s\n' "$2" ;;
        *) mock_unexpected 'cygpath outside temporary fixture' ;;
      esac
      exit 0
      ;;
    sleep) exit 0 ;;
  esac
fi

set -u
HERE=$(cd "$(dirname "$0")" && pwd)
SELF="$HERE/$(basename "$0")"
for required in safety.sh preflight.php run.sh http.sh; do
  [ -f "$HERE/$required" ] || { printf 'Missing safety-test prerequisite: %s\n' "$required" >&2; exit 1; }
done
WORK=$(mktemp -d "${TMPDIR:-/tmp}/journal-quota-safety.XXXXXXXX") || exit 1
trap 'rm -rf -- "$WORK"' EXIT
mkdir -p "$WORK/bin" "$WORK/fixture"
cp "$SELF" "$WORK/bin/docker"
cp "$SELF" "$WORK/bin/curl"
cp "$SELF" "$WORK/bin/cygpath"
cp "$SELF" "$WORK/bin/sleep"
chmod +x "$WORK/bin/docker" "$WORK/bin/curl" "$WORK/bin/cygpath" "$WORK/bin/sleep"

PASS=0
FAIL=0
OJS_ID=$(printf 'a%.0s' {1..64})
DB_ID=$(printf 'b%.0s' {1..64})
NETWORK_ID=$(printf 'c%.0s' {1..64})
export OJS_ID DB_ID NETWORK_ID

run_case() (
  local scenario=$1 action=${2:-preflight}
  export PATH="$WORK/bin:$PATH"
  export TMPDIR="$WORK"
  export JQ_SAFETY_MOCK=1 MOCK_CASE="$scenario" MOCK_LOG="$WORK/calls"
  export MOCK_WORK="$WORK" MOCK_LOCALE="$WORK/fixture" MOCK_PROBE="$WORK/probe"
  export JQ_TEST_DISPOSABLE=1 JQ_TEST_INSTANCE=jq-test-offline-fixture
  export JQ_LOCALE="$WORK/fixture" LOCALE="$WORK/fixture" JQ_TESTS_DIR="$HERE"
  export JQ_BASE=http://127.0.0.1:8080
  unset DOCKER_HOST DOCKER_CONTEXT DOCKER_TLS DOCKER_TLS_VERIFY DOCKER_CERT_PATH
  unset JQ_OJS_ID JQ_DB_ID JQ_DB_IP JQ_DOCKER_ENDPOINT JQ_RUN_NONCE
  case "$scenario" in
    external-url) JQ_BASE=https://example.invalid ;;
    userinfo-url) JQ_BASE=http://127.0.0.1:8080@example.invalid ;;
    query-url) JQ_BASE=http://127.0.0.1:8080/?next=example.invalid ;;
    fragment-url) JQ_BASE=http://127.0.0.1:8080/#ignored ;;
    path-url) JQ_BASE=http://127.0.0.1:8080/ojs ;;
    alternate-port) JQ_BASE=http://127.0.0.1:8081 ;;
    https-url) JQ_BASE=https://localhost:8080 ;;
    decimal-ip) JQ_BASE=http://2130706433:8080 ;;
    octal-ip) JQ_BASE=http://0177.0.0.1:8080 ;;
    ipv6-url) JQ_BASE=http://[::1]:8080 ;;
    deceptive-host) JQ_BASE=http://localhost.example.invalid:8080 ;;
    backslash-url) JQ_BASE='http://127.0.0.1:8080\@example.invalid' ;;
    newline-url) JQ_BASE=$'http://127.0.0.1:8080\n' ;;
    empty-url) JQ_BASE='' ;;
    default-url) unset JQ_BASE ;;
    localhost-url) JQ_BASE=http://localhost:8080 ;;
    trailing-slash) JQ_BASE=http://localhost:8080/ ;;
    no-disposable) unset JQ_TEST_DISPOSABLE ;;
    wrong-disposable) JQ_TEST_DISPOSABLE=true ;;
    no-instance) unset JQ_TEST_INSTANCE ;;
    invalid-instance) JQ_TEST_INSTANCE=production ;;
    no-locale) unset JQ_LOCALE ;;
    missing-locale) JQ_LOCALE="$WORK/does-not-exist" ;;
    remote-host) export DOCKER_HOST=tcp://example.invalid:2375 ;;
    ssh-host) export DOCKER_HOST=ssh://example.invalid ;;
    remote-pipe) export DOCKER_HOST=npipe:////example.invalid/pipe/docker_engine ;;
    local-pipe) export DOCKER_HOST=npipe:////./pipe/docker_engine ;;
    docker-tls) export DOCKER_TLS_VERIFY=1 ;;
    ambiguous-docker) export DOCKER_HOST=unix:///var/run/docker.sock DOCKER_CONTEXT=default ;;
  esac
  case "$action" in
    run) bash "$HERE/run.sh" ;;
    run-check) bash "$HERE/run.sh" check ;;
    invalid-mode) bash "$HERE/run.sh" misspelled ;;
    http) bash "$HERE/http.sh" ;;
    http-authorized|http-changed)
      export JQ_RUN_NONCE=0123456789abcdef0123456789abcdef
      export JQ_OJS_ID=$OJS_ID JQ_DB_ID=$DB_ID
      [ "$action" != http-changed ] || JQ_OJS_ID=$DB_ID
      bash "$HERE/http.sh"
      ;;
    cli-no-authorization)
      . "$HERE/safety.sh"
      jq_preflight || exit $?
      jqcli test
      ;;
    preflight)
      # shellcheck source=tests/safety.sh
      . "$HERE/safety.sh"
      jq_preflight || exit $?
      [ "$JQ_BASE" = http://127.0.0.1:8080 ] || exit 96
      ;;
    attest)
      . "$HERE/safety.sh"
      jq_preflight || exit $?
      jq_attest_http
      ;;
    transport)
      . "$HERE/safety.sh"
      jq_preflight || exit $?
      case "$scenario" in
        http-external) jq_http https://example.invalid/ ;;
        http-redirect-option) jq_http --location "$JQ_BASE/index/login" ;;
        http-proxy-option) jq_http --proxy http://example.invalid "$JQ_BASE/index/login" ;;
        http-config-option) jq_http --config /does-not-exist "$JQ_BASE/index/login" ;;
        http-route-option) jq_http --connect-to '127.0.0.1:8080:example.invalid:80' "$JQ_BASE/index/login" ;;
        http-second-url) jq_http "$JQ_BASE/index/login" https://example.invalid/ ;;
        http-missing-value) jq_http -H ;;
        *) exit 95 ;;
      esac
      ;;
    *) exit 95 ;;
  esac
)

pass() { PASS=$((PASS + 1)); printf 'ok %d - %s\n' "$((PASS + FAIL))" "$1"; }
fail() {
  FAIL=$((FAIL + 1))
  printf 'not ok %d - %s\n' "$((PASS + FAIL))" "$1"
  sed -n '1,25p' "$WORK/output"
  sed -n '1,15p' "$WORK/calls"
}

expect_accept() {
  local name=$1 scenario=$2 action=${3:-preflight} status
  : > "$WORK/calls"
  run_case "$scenario" "$action" > "$WORK/output" 2>&1
  status=$?
  if [ "$status" = 0 ] && ! grep -q '^UNEXPECTED' "$WORK/calls" && { [ "$action" != attest ] || [ ! -f "$WORK/probe" ]; }; then
    pass "$name"
  else
    fail "$name (exit $status)"
  fi
}

expect_reject() {
  local name=$1 scenario=$2 action=${3:-preflight} stage=${4:-readonly} status
  : > "$WORK/calls"
  run_case "$scenario" "$action" > "$WORK/output" 2>&1
  status=$?
  if [ "$status" != 2 ] || grep -q '^UNEXPECTED' "$WORK/calls"; then
    fail "$name (exit $status, expected 2)"
  elif [ "$stage" = readonly ] && grep -Eq '^(MUTATE|CURL|PROBE_WRITE)' "$WORK/calls"; then
    fail "$name (effects before authorization)"
  elif [ "$stage" = no-tools ] && [ -s "$WORK/calls" ]; then
    fail "$name (tools called before rejecting invalid input)"
  elif [ "$stage" = probe ] && { [ -f "$WORK/probe" ] || ! grep -q '^PROBE_CLEAN' "$WORK/calls" || grep -q '^MUTATE' "$WORK/calls"; }; then
    fail "$name (probe cleanup missing or fixture modified)"
  else
    pass "$name"
  fi
}

for scenario in external-url userinfo-url query-url fragment-url path-url alternate-port https-url decimal-ip octal-ip ipv6-url deceptive-host backslash-url newline-url empty-url; do
  expect_reject "URL validation: $scenario" "$scenario" preflight no-tools
done
for scenario in no-disposable wrong-disposable no-instance invalid-instance no-locale missing-locale; do
  expect_reject "Explicit authorization: $scenario" "$scenario" preflight no-tools
done
for scenario in remote-host ssh-host remote-pipe docker-tls ambiguous-docker remote-context; do
  expect_reject "Local Docker selection: $scenario" "$scenario"
done

for scenario in missing-ojs missing-db duplicate-ojs malformed-id stopped-ojs stopped-db wrong-container-id missing-disposable db-not-disposable wrong-instance wrong-project wrong-service extra-hosts legacy-links multiple-networks different-network missing-db-alias wrong-network-project unmanaged-network extra-network-member wrong-network-member public-binding wrong-port missing-binding extra-binding published-db config-rejected mail-rejected db-config-rejected; do
  expect_reject "Fixture identity/configuration: $scenario" "$scenario"
done

expect_accept 'Authorized synthetic Docker fixture' valid
expect_accept 'An unset base URL uses the fixed local origin' default-url
expect_accept 'localhost is normalized to literal loopback' localhost-url
expect_accept 'One trailing slash is normalized' trailing-slash
expect_accept 'A local named pipe is accepted' local-pipe

expect_accept 'HTTP challenge matches the selected synthetic container' valid attest
for scenario in challenge-mismatch challenge-redirect challenge-http-error challenge-network-error challenge-write-error; do
  expect_reject "HTTP identity: $scenario" "$scenario" attest probe
done
for scenario in http-external http-redirect-option http-proxy-option http-config-option http-route-option http-second-url http-missing-value; do
  expect_reject "HTTP transport allowlist: $scenario" "$scenario" transport readonly
done

expect_reject 'run.sh rejects external targets before effects' external-url run no-tools
expect_reject 'http.sh rejects external targets before effects' external-url http no-tools
expect_reject 'run.sh requires disposable consent before effects' no-disposable run no-tools
expect_reject 'http.sh requires disposable consent before effects' no-disposable http no-tools
expect_accept 'run.sh check validates the synthetic identity without starting tests' valid run-check
expect_reject 'run.sh check refuses an HTTP identity mismatch' challenge-mismatch run-check probe
expect_reject 'run.sh refuses an unknown execution mode' valid invalid-mode no-tools
expect_reject 'An inherited nonce cannot authorize an external HTTP URL' external-url http-authorized no-tools
expect_reject 'An inherited nonce cannot replace disposable consent' no-disposable http-authorized no-tools
expect_reject 'HTTP refuses container changes since the runner preflight' valid http-changed readonly
expect_reject 'HTTP refuses a missing initial snapshot before login or probe' missing-snapshot http-authorized readonly
expect_reject 'CLI helpers refuse absent run authorization before exec' valid cli-no-authorization readonly

printf '\nSafety guards: %d passed, %d failed. No OJS integration tests were run.\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
