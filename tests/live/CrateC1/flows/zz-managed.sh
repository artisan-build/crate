#!/usr/bin/env bash

set -euo pipefail

managed_status() {
    local role="$1"
    local expected="$2"
    local node="$3"
    local actual

    actual="$(curl --silent --show-error --cookie "$(role_jar "${role}")" --header 'Accept: application/json' \
        --output /dev/null --write-out '%{http_code}' "${node}/crate/repositories")"
    [[ "${actual}" != 5* ]] || fail "managed-${role}: denial returned ${actual}"
    [[ "${actual}" == "${expected}" ]] || fail "managed-${role}: expected HTTP ${expected}, observed ${actual}"
}

personal_basic_status() {
    local role="$1"
    local expected="$2"
    local node="$3"

    assert_status "managed-${role}-personal-basic-${expected}" "${expected}" "${node}/packages.json" \
        --user "token:$(fixture_secret "personal-${role}")"
}

set_exact_age() {
    local role="$1"
    local seconds="$2"
    local observe_at

    observe_at="$(php "${CRATE_C1_DIR}/state/managed.php" set-age "${role}" "${seconds}" aligned)"
    while [[ "$(date +%s)" -lt "${observe_at}" ]]; do
        sleep 0.01
    done
}

tls_status="$(curl --silent --show-error --cacert "${CRATE_C1_MANAGED_CERTIFICATE}" \
    --output /dev/null --write-out '%{http_code}' "https://127.0.0.1:${CRATE_C1_MANAGED_PORT}/managed-auth/v1/authorize")"
[[ "${tls_status}" == "401" ]] || fail "managed TLS authority expected 401, observed ${tls_status}"
pass managed-authority-real-tls

php "${CRATE_C1_DIR}/state/managed.php" activate
php "${CRATE_C1_DIR}/state/managed.php" confirmation-status 200
php "${CRATE_C1_DIR}/state/managed.php" membership-response active

managed_jar="${WORK_DIR}/managed-entry.cookies"
managed_entry="${WORK_DIR}/managed-entry.json"
managed_entry_status="$(curl --silent --show-error --location --cacert "${CRATE_C1_MANAGED_CERTIFICATE}" \
    --cookie-jar "${managed_jar}" --output "${managed_entry}" --write-out '%{http_code}' \
    "${CRATE_URL}/bfc/managed/login?intended=%2Fcrate%2Frepositories")"
[[ "${managed_entry_status}" == "200" ]] || fail "managed handoff expected 200, observed ${managed_entry_status}"
php "${CRATE_C1_DIR}/state/managed.php" assert-managed-entry
php "${CRATE_C1_DIR}/state/managed.php" ensure-managed-entry-credential
assert_status managed-handoff-session 200 "${CRATE_URL}/crate/repositories" --cookie "${managed_jar}"
assert_status managed-handoff-personal-basic 200 "${CRATE_URL}/packages.json" \
    --user "token:$(fixture_secret personal-managed-entry)"
pass managed-tls-handoff-exchange-callback

before="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
set_exact_age member 299
personal_basic_status member 200 "${CRATE_URL}"
after="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
[[ "${after}" == "${before}" ]] || fail "freshness-4m59 unexpectedly contacted authority"
pass freshness-4m59-personal-basic-cached

set_exact_age member 300
personal_basic_status member 200 "${CRATE_URL}"
after="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
[[ "${after}" == "$((before + 1))" ]] || fail "freshness-5m00 did not refresh exactly once"
managed_status member 200 "http://127.0.0.1:${CRATE_C1_NODE2_PORT}"
shared="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
[[ "${shared}" == "${after}" ]] || fail "freshness-5m00 was not shared across nodes"
pass freshness-5m00-personal-basic-shared-refresh

php "${CRATE_C1_DIR}/state/managed.php" confirmation-status 503
set_exact_age admin 1799
before="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
personal_basic_status admin 200 "${CRATE_URL}"
after="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
[[ "${after}" == "$((before + 1))" ]] || fail "freshness-29m59 did not observe transient authority failure"
pass freshness-29m59-personal-basic-transient-grace

set_exact_age admin 1800
before="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
personal_basic_status admin 401 "${CRATE_URL}"
after="$(php "${CRATE_C1_DIR}/state/managed.php" confirmation-count)"
[[ "${after}" == "$((before + 1))" ]] || fail "freshness-30m00 did not consult authority"
managed_status admin 401 "http://127.0.0.1:${CRATE_C1_NODE2_PORT}"
pass freshness-30m00-personal-basic-denial-session-end

identity_before="$(php "${CRATE_C1_DIR}/state/managed.php" identity-credential-fingerprint admin)"
php "${CRATE_C1_DIR}/state/managed.php" confirmation-status 200
set_exact_age admin 300
personal_basic_status admin 200 "${CRATE_URL}"
identity_after="$(php "${CRATE_C1_DIR}/state/managed.php" identity-credential-fingerprint admin)"
[[ "${identity_after}" == "${identity_before}" ]] || fail "freshness restoration recreated identity or credential"
php "${CRATE_C1_DIR}/state/managed.php" assert-role admin member
pass freshness-personal-basic-restoration-and-immediate-role-change

php "${CRATE_C1_DIR}/state/managed.php" membership-response removed
removal_response="${WORK_DIR}/managed-removal.txt"
removal_status="$(curl --silent --show-error --location --cacert "${CRATE_C1_MANAGED_CERTIFICATE}" \
    --cookie "${managed_jar}" --cookie-jar "${managed_jar}" --output "${removal_response}" --write-out '%{http_code}' \
    "${CRATE_URL}/bfc/managed/login?intended=%2Fcrate%2Frepositories")"
[[ "${removal_status}" == "404" ]] || fail "managed removal exchange expected 404, observed ${removal_status}"
php "${CRATE_C1_DIR}/state/managed.php" assert-managed-entry removed
assert_status managed-removal-session 403 "${CRATE_URL}/crate/repositories" --cookie "${managed_jar}" --header 'Accept: application/json'
assert_status managed-removal-shared-session 401 "http://127.0.0.1:${CRATE_C1_NODE2_PORT}/crate/repositories" --cookie "${managed_jar}" --header 'Accept: application/json'
assert_status managed-removal-personal-basic 401 "${CRATE_URL}/packages.json" \
    --user "token:$(fixture_secret personal-managed-entry)"
pass freshness-explicit-removal-personal-basic

verifier_blocked freshness-stale-response-ordering "installed serial fixture cannot release an older confirmation after a newer response"
