#!/usr/bin/env bash

set -euo pipefail

source_secret="$(fixture_secret source-credential)"
before_build="$(php "${CRATE_C1_DIR}/state/seed.php" latest-build-id)"
put_response="${WORK_DIR}/member-source-put.json"
put_status="$(curl --silent --show-error --cookie "$(role_jar member)" --output "${put_response}" --write-out '%{http_code}' \
    --request PUT "${CRATE_URL}/crate/repositories/crate-c1/fixture/source-credential" \
    --data-urlencode "_token=$(role_token member)" \
    --data-urlencode "source_credential=${source_secret}")"
[[ "${put_status}" == "200" ]] || fail "member-source-credential-put expected 200, observed ${put_status}"
php "${CRATE_C1_DIR}/state/seed.php" assert-source-secret-stored
pass member-source-credential-put-encrypted-and-redacted

for _ in {1..120}; do
    [[ "$(php "${CRATE_C1_DIR}/state/seed.php" build-state-after "${before_build}")" == "succeeded" ]] && break
    sleep 0.5
done
php "${CRATE_C1_DIR}/state/seed.php" assert-registry-built
php "${CRATE_C1_DIR}/state/seed.php" assert-source-build-safe
pass source-credential-build-auth-removed-and-output-redacted

before_build="$(php "${CRATE_C1_DIR}/state/seed.php" latest-build-id)"
delete_response="${WORK_DIR}/member-source-delete.txt"
delete_status="$(curl --silent --show-error --cookie "$(role_jar member)" --output "${delete_response}" --write-out '%{http_code}' \
    --request DELETE "${CRATE_URL}/crate/repositories/crate-c1/fixture/source-credential" \
    --data-urlencode "_token=$(role_token member)")"
[[ "${delete_status}" == "204" ]] || fail "member-source-credential-delete expected 204, observed ${delete_status}"
php "${CRATE_C1_DIR}/state/seed.php" assert-source-secret-cleared
pass member-source-credential-delete

for _ in {1..120}; do
    [[ "$(php "${CRATE_C1_DIR}/state/seed.php" build-state-after "${before_build}")" == "succeeded" ]] && break
    sleep 0.5
done
php "${CRATE_C1_DIR}/state/seed.php" assert-registry-built
php "${CRATE_C1_DIR}/state/seed.php" assert-source-build-safe
php "${CRATE_C1_DIR}/state/inspect.php" source-secret
pass source-secret-absent-from-retained-output-and-logs
