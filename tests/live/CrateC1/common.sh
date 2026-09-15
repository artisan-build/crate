#!/usr/bin/env bash

set -euo pipefail

CRATE_C1_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CRATE_ROOT="$(cd "${CRATE_C1_DIR}/../../.." && pwd)"

PASS_NAMES=()
BLOCKED_NAMES=()
CHILD_PIDS=()

log() {
    printf '%s\n' "$*"
}

pass() {
    local name="$1"
    PASS_NAMES+=("${name}")
    printf 'PASS %s\n' "${name}"
}

verifier_blocked() {
    local name="$1"
    local reason="$2"
    BLOCKED_NAMES+=("${name}")
    printf 'VERIFIER_BLOCKED %s %s\n' "${name}" "${reason}"
}

fail() {
    printf 'FAIL %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

assert_loopback() {
    local label="$1"
    local host="$2"

    if [[ "${CRATE_C1_ALLOW_NON_LOOPBACK:-0}" == "1" ]]; then
        return
    fi

    case "${host}" in
        127.0.0.1|localhost|::1) ;;
        *) fail "${label} host must be loopback unless CRATE_C1_ALLOW_NON_LOOPBACK=1" ;;
    esac
}

register_pid() {
    CHILD_PIDS+=("$1")
}

wait_for_http() {
    local url="$1"
    local attempts="${2:-60}"
    local attempt

    for ((attempt = 1; attempt <= attempts; attempt++)); do
        if curl --fail --silent --show-error --max-time 2 "${url}" >/dev/null 2>&1; then
            return 0
        fi
        sleep 0.25
    done

    fail "HTTP endpoint did not become ready: ${url}"
}

assert_status() {
    local name="$1"
    local expected="$2"
    local url="$3"
    shift 3
    local actual

    actual="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$@" "${url}")"
    [[ "${actual}" != 5* ]] || fail "${name}: denial returned ${actual}"
    [[ "${actual}" == "${expected}" ]] || fail "${name}: expected HTTP ${expected}, observed ${actual}"
    pass "${name}"
}

form_value() {
    php -r '
        $html = file_get_contents($argv[1]);
        $name = preg_quote($argv[2], "/");
        if (! is_string($html) || preg_match("/<input[^>]+name=\\\"{$name}\\\"[^>]+value=\\\"([^\\\"]+)\\\"/", $html, $matches) !== 1) {
            exit(1);
        }
        echo html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
    ' "$1" "$2"
}

fixture_secret() {
    php -r 'echo hash_hmac("sha256", $argv[1], getenv("CRATE_C1_FIXTURE_SECRET"));' "$1"
}

bfc_artisan() {
    local has_local=0
    local argument

    for argument in "$@"; do
        if [[ "${argument}" == "--local" ]]; then
            has_local=1
            break
        fi
    done

    [[ "${has_local}" == "1" ]] || fail "state-changing Built for Cloud command omitted --local"
    php "${APP_DIR}/artisan" "$@"
}

redis_delete_prefix() {
    local cursor=0
    local response
    local next
    local key

    while :; do
        response="$(redis-cli -h "${CRATE_C1_REDIS_HOST}" -p "${CRATE_C1_REDIS_PORT}" --raw SCAN "${cursor}" MATCH "${CRATE_C1_REDIS_PREFIX}*" COUNT 200)"
        next="${response%%$'\n'*}"
        while IFS= read -r key; do
            [[ -n "${key}" && "${key}" != "${next}" ]] || continue
            redis-cli -h "${CRATE_C1_REDIS_HOST}" -p "${CRATE_C1_REDIS_PORT}" UNLINK "${key}" >/dev/null
        done <<< "${response#*$'\n'}"
        cursor="${next}"
        [[ "${cursor}" == "0" ]] && break
    done
}

print_summary() {
    local name

    log "C1 live verification summary"
    for name in "${PASS_NAMES[@]:-}"; do
        [[ -n "${name}" ]] && printf 'PASS %s\n' "${name}"
    done
    for name in "${BLOCKED_NAMES[@]:-}"; do
        [[ -n "${name}" ]] && printf 'VERIFIER_BLOCKED %s\n' "${name}"
    done

    return 0
}

cleanup() {
    local status=$?
    local pid

    trap - EXIT INT TERM
    for pid in "${CHILD_PIDS[@]:-}"; do
        [[ -n "${pid}" ]] || continue
        kill "${pid}" >/dev/null 2>&1 || true
        wait "${pid}" >/dev/null 2>&1 || true
    done

    if [[ -n "${APP_DIR:-}" && -f "${CRATE_C1_DIR}/state/storage.php" ]]; then
        php "${CRATE_C1_DIR}/state/storage.php" delete-bucket >/dev/null 2>&1 || true
    fi

    if [[ -n "${CRATE_C1_REDIS_PREFIX:-}" ]]; then
        redis_delete_prefix >/dev/null 2>&1 || true
    fi

    if [[ -n "${CRATE_C1_DB_NAME:-}" ]]; then
        [[ "${CRATE_C1_DB_NAME}" =~ ^crate_c1_[a-f0-9]{12}$ ]] || fail "refusing to drop unexpected database name"
        PGPASSWORD="${CRATE_C1_PG_PASSWORD}" psql \
            --host="${CRATE_C1_PG_HOST}" \
            --port="${CRATE_C1_PG_PORT}" \
            --username="${CRATE_C1_PG_USER}" \
            --dbname=postgres \
            --set=ON_ERROR_STOP=1 \
            --command="SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${CRATE_C1_DB_NAME}' AND pid <> pg_backend_pid();" \
            --command="DROP DATABASE IF EXISTS \"${CRATE_C1_DB_NAME}\";" >/dev/null 2>&1 || true
    fi

    if [[ "${CRATE_C1_KEEP:-0}" == "1" ]]; then
        log "KEPT workspace=${WORK_DIR:-unset} database=${CRATE_C1_DB_NAME:-unset} bucket=${CRATE_C1_S3_BUCKET:-unset} redis_prefix=${CRATE_C1_REDIS_PREFIX:-unset}"
    elif [[ -n "${WORK_DIR:-}" ]]; then
        rm -rf -- "${WORK_DIR}"
    fi

    exit "${status}"
}
