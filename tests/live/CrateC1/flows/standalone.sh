#!/usr/bin/env bash

set -euo pipefail

pass fresh-postgres-thin-host-schema

role_jar() {
    printf '%s/%s.cookies' "${WORK_DIR}" "$1"
}

role_token() {
    form_value "${WORK_DIR}/$1-login.html" _token
}

for role in owner admin member; do
    jar="${WORK_DIR}/${role}.cookies"
    page="${WORK_DIR}/${role}-login.html"
    curl --fail --silent --show-error --cookie-jar "${jar}" "${CRATE_URL}/bfc/login" --output "${page}"
    csrf="$(form_value "${page}" _token)"
    status="$(curl --silent --show-error --cookie "${jar}" --cookie-jar "${jar}" --output /dev/null --write-out '%{http_code}' \
        --request POST "${CRATE_URL}/bfc/login" \
        --data-urlencode "_token=${csrf}" \
        --data-urlencode "email=${role}@crate-c1.example.test" \
        --data-urlencode "password=$(fixture_secret "password-${role}")")"
    [[ "${status}" == "302" ]] || fail "standalone-${role}-login expected 302, observed ${status}"
    assert_status "${role}-repository-list" 200 "${CRATE_URL}/crate/repositories" --cookie "${jar}"
    assert_status "${role}-build-list" 200 "${CRATE_URL}/crate/builds" --cookie "${jar}"
done
pass standalone-role-sessions

before="$(php "${CRATE_C1_DIR}/state/seed.php" count-repositories)"
member_status="$(curl --silent --show-error --cookie "$(role_jar member)" --output /dev/null --write-out '%{http_code}' \
    --request POST "${CRATE_URL}/crate/repositories" \
    --data-urlencode "_token=$(role_token member)" \
    --data-urlencode 'name=crate-c1/forbidden' \
    --data-urlencode "url=${CRATE_C1_FIXTURE_REPO_URL}")"
[[ "${member_status}" == "403" ]] || fail "member-repository-add expected 403, observed ${member_status}"
[[ "$(php "${CRATE_C1_DIR}/state/seed.php" count-repositories)" == "${before}" ]] || fail "member denial mutated repositories"
pass member-add-denied-without-mutation

owner_add="${WORK_DIR}/owner-add.json"
owner_status="$(curl --silent --show-error --cookie "$(role_jar owner)" --output "${owner_add}" --write-out '%{http_code}' \
    --request POST "${CRATE_URL}/crate/repositories" \
    --data-urlencode "_token=$(role_token owner)" \
    --data-urlencode 'name=crate-c1/fixture' \
    --data-urlencode "url=${CRATE_C1_FIXTURE_REPO_URL}")"
[[ "${owner_status}" == "201" ]] || fail "owner repository add expected 201, observed ${owner_status}"
pass owner-repository-add

for _ in {1..120}; do
    [[ "$(php "${CRATE_C1_DIR}/state/seed.php" build-state)" == "succeeded" ]] && break
    sleep 0.5
done
php "${CRATE_C1_DIR}/state/seed.php" assert-registry-built
pass real-redis-queue-satis-git-minio-build

for role in owner admin member; do
    secret="$(fixture_secret "personal-${role}")"
    assert_status "${role}-personal-basic-metadata" 200 "${CRATE_URL}/packages.json" --user "token:${secret}"
    assert_status "${role}-personal-basic-provider" 200 "${CRATE_URL}/p2/crate-c1/fixture.json" --user "token:${secret}"
done
assert_status installation-bound-basic 200 "${CRATE_URL}/packages.json" --user "token:$(fixture_secret installation)"
assert_status missing-basic-denial 401 "${CRATE_URL}/packages.json"
assert_status malformed-basic-denial 401 "${CRATE_URL}/packages.json" --user 'token:not-a-credential'
assert_status wrong-purpose-basic-denial 401 "${CRATE_URL}/packages.json" --user "token:$(fixture_secret wrong-purpose)"
assert_status revoked-basic-denial 401 "${CRATE_URL}/packages.json" --user "token:$(fixture_secret revoked)"
pass standalone-basic-role-and-denial-matrix

CONSUMER_DIR="${WORK_DIR}/consumer"
mkdir -p "${CONSUMER_DIR}"
cat >"${CONSUMER_DIR}/composer.json" <<JSON
{
    "name": "crate-c1/consumer",
    "repositories": [{"type": "composer", "url": "${CRATE_URL}"}],
    "require": {"crate-c1/fixture": "1.0.0"},
    "config": {"secure-http": false}
}
JSON
CRATE_TOKEN="$(fixture_secret personal-member)" php "${APP_DIR}/artisan" crate:auth --path="${CONSUMER_DIR}/auth.json"
COMPOSER_HOME="${CONSUMER_DIR}" composer install --working-dir="${CONSUMER_DIR}" --no-interaction --prefer-dist
php -r 'require $argv[1]; if (\CrateC1\Fixture\Fixture::VALUE !== "crate-c1-live") { exit(1); }' "${CONSUMER_DIR}/vendor/autoload.php"
pass crate-auth-real-composer-install
