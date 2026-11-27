#!/usr/bin/env bash
# tests/safety.sh
# Copyright (C) 2026 Stefano Natangelo
# SPDX-License-Identifier: AGPL-3.0-only
#
# Shared fail-closed target checks for the disposable Docker test fixture.
# Sourcing this file never authorizes a target; each entry point must preflight.

_JQ_PREFLIGHT_OK=0

jq_safety_error() {
  printf 'Journal Quota safety check: %s\n' "$1" >&2
  return 2
}

# Do not let ambient context or TLS settings alter the pinned daemon.
_jq_docker_clean() {
  env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS \
    -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH docker "$@"
}

jq_docker() {
  [ -n "${JQ_DOCKER_ENDPOINT:-}" ] || {
    jq_safety_error 'Docker endpoint has not been verified.'; return 2;
  }
  _jq_docker_clean --host "$JQ_DOCKER_ENDPOINT" "$@"
}

# Use the pinned container, never a fresh Compose service lookup. Disposable
# markers are deliberately not injected: PHP must find them in the container.
jqcli() {
  [ "$_JQ_PREFLIGHT_OK" = 1 ] && [[ "${JQ_RUN_NONCE:-}" =~ ^[a-f0-9]{32}$ ]] || {
    jq_safety_error 'CLI helpers require an authorized preflighted run.'; return 2;
  }
  jq_docker exec -u www-data -e JQ_RUN_NONCE -e JQ_DB_IP \
    -e JQ_FILES_CTX -e JQ_ALERT_CTX -e JQ_DIR_CTX \
    "$JQ_OJS_ID" php /tmp/jqtests/cli.php "$@"
}

_jq_record() {
  local separators
  [[ "$1" != *$'\n'* && "$1" != *$'\r'* ]] || return 2
  separators=${1//[^|]/}
  [ "${#separators}" = "$2" ]
}

_jq_ipv4() {
  local a b c d part
  [[ "$1" =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]] || return 2
  IFS=. read -r a b c d <<< "$1"
  for part in "$a" "$b" "$c" "$d"; do
    [[ "$part" = 0 || "$part" != 0* ]] || return 2
    (( 10#$part <= 255 )) || return 2
  done
  (( 10#$a > 0 && 10#$a < 224 && 10#$a != 127 ))
}

jq_preflight() {
  local context endpoint base tests_dir service id info
  local actual running project actual_service disposable instance extra links networks
  local ojs_project='' db_project='' ojs_network='' db_network=''
  local network_name network_id address alias ojs_network_name='' ojs_ip=''
  local net_id net_name driver scope net_project net_label members member_ids ports
  local -a member_list
  local core_format network_format network_core_format members_format ports_format

  _JQ_PREFLIGHT_OK=0
  unset JQ_OJS_ID JQ_DB_ID JQ_DB_IP JQ_DOCKER_ENDPOINT

  [ "${JQ_TEST_DISPOSABLE:-}" = 1 ] || {
    jq_safety_error 'Set JQ_TEST_DISPOSABLE=1 only for an explicitly disposable fixture.'; return 2;
  }
  [[ "${JQ_TEST_INSTANCE:-}" =~ ^jq-test-[a-z0-9][a-z0-9-]{7,55}$ ]] || {
    jq_safety_error 'JQ_TEST_INSTANCE must identify the authorized disposable fixture.'; return 2;
  }
  [ -n "${JQ_LOCALE:-}" ] && [ -d "$JQ_LOCALE" ] || {
    jq_safety_error 'JQ_LOCALE must explicitly name an existing Docker Compose directory.'; return 2;
  }
  LOCALE=$(cd -- "$JQ_LOCALE" && pwd -P) || {
    jq_safety_error 'Cannot resolve JQ_LOCALE.'; return 2;
  }
  tests_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P) || {
    jq_safety_error 'Cannot resolve the test directory.'; return 2;
  }
  JQ_TESTS_DIR=$tests_dir
  [ -f "$JQ_TESTS_DIR/preflight.php" ] || {
    jq_safety_error 'The read-only PHP preflight is missing.'; return 2;
  }

  base=${JQ_BASE-http://localhost:8080}
  case "$base" in
    http://localhost:8080|http://localhost:8080/|http://127.0.0.1:8080|http://127.0.0.1:8080/)
      JQ_BASE=http://127.0.0.1:8080 ;;
    *) jq_safety_error 'Only the fixed local test origin on port 8080 is allowed.'; return 2 ;;
  esac

  [ -z "${DOCKER_TLS:-}${DOCKER_TLS_VERIFY:-}${DOCKER_CERT_PATH:-}" ] || {
    jq_safety_error 'Docker TLS and certificate overrides are not permitted.'; return 2;
  }
  if [ -n "${DOCKER_HOST:-}" ] && [ -n "${DOCKER_CONTEXT:-}" ]; then
    jq_safety_error 'Conflicting DOCKER_HOST and DOCKER_CONTEXT overrides are not permitted.'; return 2
  fi
  if [ -n "${DOCKER_HOST:-}" ]; then
    endpoint=$DOCKER_HOST
  else
    context=${DOCKER_CONTEXT:-}
    if [ -z "$context" ]; then
      context=$(_jq_docker_clean context show) || {
        jq_safety_error 'Cannot determine the Docker context.'; return 2;
      }
    fi
    [[ "$context" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || {
      jq_safety_error 'The Docker context name is invalid.'; return 2;
    }
    endpoint=$(_jq_docker_clean context inspect --format '{{.Endpoints.docker.Host}}' "$context") || {
      jq_safety_error 'Cannot inspect the Docker context.'; return 2;
    }
  fi
  if [[ "$endpoint" =~ ^unix:///[A-Za-z0-9._/-]+$ ]] || \
     [[ "$endpoint" =~ ^npipe:////\./pipe/[A-Za-z0-9._-]+$ ]]; then
    JQ_DOCKER_ENDPOINT=$endpoint
  else
    jq_safety_error 'Only a local Unix socket or local Windows named pipe is allowed.'; return 2
  fi

  # Discovery is read-only. Never start containers or execute in a service name.
  JQ_OJS_ID=$(cd -- "$LOCALE" && jq_docker compose ps -q ojs) || {
    jq_safety_error 'Cannot discover the OJS fixture container.'; return 2;
  }
  JQ_DB_ID=$(cd -- "$LOCALE" && jq_docker compose ps -q db) || {
    jq_safety_error 'Cannot discover the database fixture container.'; return 2;
  }
  [[ "$JQ_OJS_ID" =~ ^[a-f0-9]{64}$ && "$JQ_DB_ID" =~ ^[a-f0-9]{64}$ && "$JQ_OJS_ID" != "$JQ_DB_ID" ]] || {
    jq_safety_error 'Exactly one running container per fixture service is required.'; return 2;
  }

  core_format='{{.Id}}|{{.State.Running}}|{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "org.jsenetwork.journal-quota.disposable"}}|{{index .Config.Labels "org.jsenetwork.journal-quota.test-instance"}}|{{len .HostConfig.ExtraHosts}}|{{if .HostConfig.Links}}{{len .HostConfig.Links}}{{else}}0{{end}}|{{len .NetworkSettings.Networks}}'
  network_format='{{range $name, $net := .NetworkSettings.Networks}}{{printf "%s|%s|%s|" $name $net.NetworkID $net.IPAddress}}{{range $net.Aliases}}{{if eq . "db"}}db{{end}}{{end}}{{println}}{{end}}'
  ports_format='{{range $port, $bindings := .NetworkSettings.Ports}}{{range $bindings}}{{printf "%s|%s|%s\n" $port .HostIp .HostPort}}{{end}}{{end}}'
  for service in ojs db; do
    if [ "$service" = ojs ]; then id=$JQ_OJS_ID; else id=$JQ_DB_ID; fi
    info=$(jq_docker container inspect --format "$core_format" "$id") || {
      jq_safety_error 'Cannot inspect a fixture container.'; return 2;
    }
    _jq_record "$info" 8 || {
      jq_safety_error 'Malformed container identity.'; return 2;
    }
    IFS='|' read -r actual running project actual_service disposable instance extra links networks <<< "$info"
    [[ "$actual" = "$id" && "$running" = true && "$actual_service" = "$service" && \
       "$disposable" = true && "$instance" = "$JQ_TEST_INSTANCE" && \
       "$extra" = 0 && "$links" = 0 && "$networks" = 1 && \
       "$project" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]] || {
      jq_safety_error 'Container identity, disposable labels or network configuration do not match.'; return 2;
    }
    info=$(jq_docker container inspect --format "$network_format" "$id") || {
      jq_safety_error 'Cannot inspect the container network.'; return 2;
    }
    _jq_record "$info" 3 || {
      jq_safety_error 'The fixture must use one unambiguous network.'; return 2;
    }
    IFS='|' read -r network_name network_id address alias <<< "$info"
    [[ "$network_id" =~ ^[a-f0-9]{64}$ && "$network_name" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] && _jq_ipv4 "$address" || {
      jq_safety_error 'The fixture network identity or IPv4 address is invalid.'; return 2;
    }
    ports=$(jq_docker container inspect --format "$ports_format" "$id") || {
      jq_safety_error 'Cannot inspect published fixture ports.'; return 2;
    }
    if [ "$service" = ojs ]; then
      [ -z "$alias" ] && [ "$ports" = '80/tcp|127.0.0.1|8080' ] || {
        jq_safety_error 'OJS must publish only 127.0.0.1:8080 to container port 80.'; return 2;
      }
      ojs_project=$project; ojs_network=$network_id; ojs_network_name=$network_name; ojs_ip=$address
    else
      [ "$alias" = db ] && [ -z "$ports" ] || {
        jq_safety_error 'The database needs the db alias and must not publish host ports.'; return 2;
      }
      db_project=$project; db_network=$network_id; JQ_DB_IP=$address
      [ "$network_name" = "$ojs_network_name" ] || {
        jq_safety_error 'OJS and database network names differ.'; return 2;
      }
    fi
  done
  [[ "$ojs_project" = "$db_project" && "$ojs_network" = "$db_network" && "$ojs_ip" != "$JQ_DB_IP" ]] || {
    jq_safety_error 'OJS and database must belong to the same isolated Compose fixture.'; return 2;
  }

  network_core_format='{{.Id}}|{{.Name}}|{{.Driver}}|{{.Scope}}|{{index .Labels "com.docker.compose.project"}}|{{index .Labels "com.docker.compose.network"}}|{{len .Containers}}'
  members_format='{{range $id, $_ := .Containers}}{{println $id}}{{end}}'
  info=$(jq_docker network inspect --format "$network_core_format" "$ojs_network") || {
    jq_safety_error 'Cannot inspect the shared fixture network.'; return 2;
  }
  _jq_record "$info" 6 || {
    jq_safety_error 'Malformed shared-network identity.'; return 2;
  }
  IFS='|' read -r net_id net_name driver scope net_project net_label members <<< "$info"
  [[ "$net_id" = "$ojs_network" && "$net_name" = "$ojs_network_name" && \
     "$driver" = bridge && "$scope" = local && "$net_project" = "$ojs_project" && \
     "$net_label" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ && "$members" = 2 ]] || {
    jq_safety_error 'The shared network is not the isolated Compose test network.'; return 2;
  }
  member_ids=$(jq_docker network inspect --format "$members_format" "$ojs_network") || {
    jq_safety_error 'Cannot inspect network membership.'; return 2;
  }
  mapfile -t member_list <<< "$member_ids"
  [ "${#member_list[@]}" = 2 ] && \
    { [[ "${member_list[0]}" = "$JQ_OJS_ID" && "${member_list[1]}" = "$JQ_DB_ID" ]] || \
      [[ "${member_list[0]}" = "$JQ_DB_ID" && "${member_list[1]}" = "$JQ_OJS_ID" ]]; } || {
    jq_safety_error 'The test network must contain only the two verified containers.'; return 2;
  }

  # This script reads configuration and explicit fixture markers without OJS bootstrap.
  jq_docker exec -i "$JQ_OJS_ID" php -- "$JQ_TEST_INSTANCE" "$JQ_DB_IP" < "$JQ_TESTS_DIR/preflight.php" || {
    jq_safety_error 'The container configuration or disposable fixture marker was rejected.'; return 2;
  }
  export LOCALE JQ_BASE JQ_OJS_ID JQ_DB_ID JQ_DB_IP JQ_DOCKER_ENDPOINT JQ_TESTS_DIR
  _JQ_PREFLIGHT_OK=1
}

# A narrow curl interface prevents later callers from overriding transport guards.
# Only the options used by this suite and exactly one local URL are accepted.
jq_http() {
  local -a args=("$@")
  local arg url='' i=0
  [ "$_JQ_PREFLIGHT_OK" = 1 ] && [ "${JQ_BASE:-}" = http://127.0.0.1:8080 ] || {
    jq_safety_error 'HTTP requests require a successful local preflight.'; return 2;
  }
  while (( i < ${#args[@]} )); do
    arg=${args[$i]}
    case "$arg" in
      -s|-S|--silent|--show-error) i=$((i + 1)) ;;
      -b|-c|-o|-w|-d|-H|-F|-X|--cookie|--cookie-jar|--output|--write-out|--data|--data-urlencode|--header|--form|--request)
        (( i + 1 < ${#args[@]} )) || { jq_safety_error 'An HTTP option is missing its value.'; return 2; }
        i=$((i + 2)) ;;
      http://127.0.0.1:8080/*)
        [ -z "$url" ] && (( i == ${#args[@]} - 1 )) && \
          [[ "$arg" != *$'\r'* && "$arg" != *$'\n'* && "$arg" != *' '* ]] || {
          jq_safety_error 'Exactly one local HTTP URL is required.'; return 2;
        }
        url=$arg; i=$((i + 1)) ;;
      *) jq_safety_error 'An HTTP option or URL is outside the test transport allowlist.'; return 2 ;;
    esac
  done
  [ -n "$url" ] || { jq_safety_error 'A local HTTP URL is required.'; return 2; }
  command curl -q --silent --show-error --proxy '' --noproxy '*' \
    --proto '=http' --proto-redir '=http' --max-redirs 0 \
    --connect-timeout 5 --max-time 30 --globoff "${args[@]}"
}

# Prove that the fixed HTTP origin reaches the inspected OJS container before
# sending fixture credentials. The random, short-lived file contains no user data.
jq_attest_http() (
  local nonce probe_path response status payload created=0
  [ "$_JQ_PREFLIGHT_OK" = 1 ] || {
    jq_safety_error 'HTTP attestation requires a successful local preflight.'; return 2;
  }
  nonce=$(LC_ALL=C od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
  [[ "$nonce" =~ ^[a-f0-9]{32}$ ]] || {
    jq_safety_error 'Cannot generate a fresh HTTP identity challenge.'; return 2;
  }
  probe_path="/var/www/html/public/jq-test-probe-$nonce.txt"
  _jq_probe_cleanup() {
    [ "$created" = 1 ] || return 0
    jq_docker exec -u www-data "$JQ_OJS_ID" rm -f -- "$probe_path"
  }
  trap '_jq_probe_cleanup' EXIT
  trap 'exit 2' HUP INT TERM
  # Also attempt cleanup if Docker loses contact after creating the fresh file.
  created=1
  printf '%s' "$nonce" | jq_docker exec -i -u www-data "$JQ_OJS_ID" \
    sh -c 'set -eu; umask 077; set -C; cat > "$1"' jq-test-probe "$probe_path" || {
    jq_safety_error 'Cannot create the HTTP identity challenge.'; return 2;
  }
  response=$(jq_http -w $'\n%{http_code}' "$JQ_BASE/public/jq-test-probe-$nonce.txt") || {
    jq_safety_error 'The HTTP identity challenge request failed.'; return 2;
  }
  status=${response##*$'\n'}
  payload=${response%$'\n'*}
  [ "$status" = 200 ] && [ "$payload" = "$nonce" ] || {
    jq_safety_error 'The local HTTP response does not identify the verified OJS container.'; return 2;
  }
  _jq_probe_cleanup || {
    jq_safety_error 'Cannot remove the HTTP identity challenge.'; return 2;
  }
  created=0
)
