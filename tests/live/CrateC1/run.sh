#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tests/live/CrateC1/common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
    cat <<'USAGE'
Usage: tests/live/CrateC1/run.sh

Disposable service coordinates:
  CRATE_C1_PG_HOST              default 127.0.0.1
  CRATE_C1_PG_PORT              default 32790
  CRATE_C1_PG_USER              default crate_c1
  CRATE_C1_PG_PASSWORD          default crate_c1
  CRATE_C1_REDIS_HOST           default 127.0.0.1
  CRATE_C1_REDIS_PORT           default 32791
  CRATE_C1_S3_HOST              default 127.0.0.1
  CRATE_C1_S3_PORT              default 32792
  CRATE_C1_S3_ACCESS_KEY        default cratec1access
  CRATE_C1_S3_SECRET_KEY        default cratec1secretkey

Harness controls:
  CRATE_C1_NODE1_PORT           default 32801
  CRATE_C1_NODE2_PORT           default 32802
  CRATE_C1_STUB_PORT            default 32803
  CRATE_C1_FIXTURE_PORT         default 32804
  CRATE_C1_ALLOW_NON_LOOPBACK   default 0
  CRATE_C1_KEEP                 default 0
USAGE
}

if [[ "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi
[[ "$#" == "0" ]] || fail "unexpected arguments (use --help)"

export CRATE_C1_PG_HOST="${CRATE_C1_PG_HOST:-127.0.0.1}"
export CRATE_C1_PG_PORT="${CRATE_C1_PG_PORT:-32790}"
export CRATE_C1_PG_USER="${CRATE_C1_PG_USER:-crate_c1}"
export CRATE_C1_PG_PASSWORD="${CRATE_C1_PG_PASSWORD:-crate_c1}"
export CRATE_C1_REDIS_HOST="${CRATE_C1_REDIS_HOST:-127.0.0.1}"
export CRATE_C1_REDIS_PORT="${CRATE_C1_REDIS_PORT:-32791}"
export CRATE_C1_S3_HOST="${CRATE_C1_S3_HOST:-127.0.0.1}"
export CRATE_C1_S3_PORT="${CRATE_C1_S3_PORT:-32792}"
export CRATE_C1_NODE1_PORT="${CRATE_C1_NODE1_PORT:-32801}"
export CRATE_C1_NODE2_PORT="${CRATE_C1_NODE2_PORT:-32802}"
export CRATE_C1_STUB_PORT="${CRATE_C1_STUB_PORT:-32803}"
export CRATE_C1_FIXTURE_PORT="${CRATE_C1_FIXTURE_PORT:-32804}"
export CRATE_C1_S3_ACCESS_KEY="${CRATE_C1_S3_ACCESS_KEY:-cratec1access}"
export CRATE_C1_S3_SECRET_KEY="${CRATE_C1_S3_SECRET_KEY:-cratec1secretkey}"

assert_loopback PostgreSQL "${CRATE_C1_PG_HOST}"
assert_loopback Redis "${CRATE_C1_REDIS_HOST}"
assert_loopback MinIO "${CRATE_C1_S3_HOST}"

for command in php composer git curl psql redis-cli tar; do
    require_command "${command}"
done

RUN_SUFFIX="$(php -r 'echo bin2hex(random_bytes(6));')"
export CRATE_C1_RUN_ID="crate_c1_${RUN_SUFFIX}"
export CRATE_C1_DB_NAME="${CRATE_C1_RUN_ID}"
export CRATE_C1_REDIS_PREFIX="${CRATE_C1_RUN_ID}:"
export CRATE_C1_S3_BUCKET="crate-c1-${RUN_SUFFIX}"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/crate-c1.XXXXXX")"
APP_DIR="${WORK_DIR}/app"
export WORK_DIR APP_DIR
export CRATE_C1_AUTHORITY_STATE="${WORK_DIR}/authority-state.json"
export CRATE_C1_AUTHORITY_LOG="${WORK_DIR}/authority-requests.jsonl"
export CRATE_C1_FIXTURE_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
export CRATE_C1_CONTROL_TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"

trap cleanup EXIT INT TERM

mkdir -p "${APP_DIR}"
git -C "${CRATE_ROOT}" archive HEAD | tar -x -C "${APP_DIR}" --strip-components=0
pass committed-source-archive

[[ "${CRATE_C1_DB_NAME}" =~ ^crate_c1_[a-f0-9]{12}$ ]] || fail "generated database name is invalid"
PGPASSWORD="${CRATE_C1_PG_PASSWORD}" psql \
    --host="${CRATE_C1_PG_HOST}" \
    --port="${CRATE_C1_PG_PORT}" \
    --username="${CRATE_C1_PG_USER}" \
    --dbname=postgres \
    --set=ON_ERROR_STOP=1 \
    --command="CREATE DATABASE \"${CRATE_C1_DB_NAME}\";"
pass isolated-postgres-created

export APP_ENV=testing
export APP_DEBUG=false
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_URL="http://127.0.0.1:${CRATE_C1_NODE1_PORT}"
export DB_CONNECTION=pgsql
export DB_HOST="${CRATE_C1_PG_HOST}"
export DB_PORT="${CRATE_C1_PG_PORT}"
export DB_DATABASE="${CRATE_C1_DB_NAME}"
export DB_USERNAME="${CRATE_C1_PG_USER}"
export DB_PASSWORD="${CRATE_C1_PG_PASSWORD}"
export CRATE_DB_HOST="${CRATE_C1_PG_HOST}"
export CRATE_DB_PORT="${CRATE_C1_PG_PORT}"
export CRATE_DB_DATABASE="${CRATE_C1_DB_NAME}"
export CRATE_DB_USERNAME="${CRATE_C1_PG_USER}"
export CRATE_DB_PASSWORD="${CRATE_C1_PG_PASSWORD}"
export CACHE_STORE=redis
export SESSION_DRIVER=redis
export QUEUE_CONNECTION=redis
export REDIS_HOST="${CRATE_C1_REDIS_HOST}"
export REDIS_PORT="${CRATE_C1_REDIS_PORT}"
export REDIS_PREFIX="${CRATE_C1_REDIS_PREFIX}"
export CACHE_PREFIX="${CRATE_C1_REDIS_PREFIX}cache:"
export FILESYSTEM_DISK=s3
export CRATE_ARCHIVE_DISK=s3
export AWS_ACCESS_KEY_ID="${CRATE_C1_S3_ACCESS_KEY}"
export AWS_SECRET_ACCESS_KEY="${CRATE_C1_S3_SECRET_KEY}"
export AWS_DEFAULT_REGION=us-east-1
export AWS_BUCKET="${CRATE_C1_S3_BUCKET}"
export AWS_ENDPOINT="http://${CRATE_C1_S3_HOST}:${CRATE_C1_S3_PORT}"
export AWS_USE_PATH_STYLE_ENDPOINT=true
export MAIL_MAILER=log
export LOG_CHANNEL=single
export SCALPELS_URL="http://127.0.0.1:${CRATE_C1_STUB_PORT}"
export CRATE_URL="http://127.0.0.1:${CRATE_C1_NODE1_PORT}"
export CRATE_C1_FIXTURE_REPO_URL="http://127.0.0.1:${CRATE_C1_FIXTURE_PORT}/fixture.git"

composer install --working-dir="${APP_DIR}" --no-interaction --prefer-dist
php "${APP_DIR}/artisan" migrate --force --no-interaction
php "${APP_DIR}/artisan" crate:install-satis --no-interaction
php "${CRATE_C1_DIR}/state/storage.php" create-bucket
php "${CRATE_C1_DIR}/state/seed.php" substrate
pass production-like-substrate

FIXTURE_WORK="${WORK_DIR}/fixture-work"
FIXTURE_HTTP="${WORK_DIR}/fixture-http"
mkdir -p "${FIXTURE_WORK}/src" "${FIXTURE_HTTP}"
cat >"${FIXTURE_WORK}/composer.json" <<'JSON'
{
    "name": "crate-c1/fixture",
    "description": "Disposable Crate C1 fixture",
    "version": "1.0.0",
    "autoload": {"psr-4": {"CrateC1\\Fixture\\": "src/"}}
}
JSON
cat >"${FIXTURE_WORK}/src/Fixture.php" <<'PHP'
<?php

declare(strict_types=1);

namespace CrateC1\Fixture;

final class Fixture
{
    public const string VALUE = 'crate-c1-live';
}
PHP
git -C "${FIXTURE_WORK}" init --quiet
git -C "${FIXTURE_WORK}" config user.name "Crate C1 Fixture"
git -C "${FIXTURE_WORK}" config user.email "crate-c1@example.test"
git -C "${FIXTURE_WORK}" add composer.json src/Fixture.php
git -C "${FIXTURE_WORK}" commit --quiet -m "fixture 1.0.0"
git -C "${FIXTURE_WORK}" tag 1.0.0
git clone --quiet --bare "${FIXTURE_WORK}" "${FIXTURE_HTTP}/fixture.git"
git --git-dir="${FIXTURE_HTTP}/fixture.git" update-server-info
php -S "127.0.0.1:${CRATE_C1_FIXTURE_PORT}" -t "${FIXTURE_HTTP}" >"${WORK_DIR}/fixture-http.log" 2>&1 &
register_pid "$!"
wait_for_http "${CRATE_C1_FIXTURE_REPO_URL}/info/refs"
pass disposable-git-repository

php -S "127.0.0.1:${CRATE_C1_STUB_PORT}" "${CRATE_C1_DIR}/authority-stub.php" >"${WORK_DIR}/authority.log" 2>&1 &
register_pid "$!"
wait_for_http "http://127.0.0.1:${CRATE_C1_STUB_PORT}/health"
pass disposable-authority-stub

php "${APP_DIR}/artisan" serve --host=127.0.0.1 --port="${CRATE_C1_NODE1_PORT}" >"${WORK_DIR}/node1.log" 2>&1 &
register_pid "$!"
php "${APP_DIR}/artisan" serve --host=127.0.0.1 --port="${CRATE_C1_NODE2_PORT}" >"${WORK_DIR}/node2.log" 2>&1 &
register_pid "$!"
php "${APP_DIR}/artisan" queue:work redis --sleep=1 --tries=1 --timeout=120 >"${WORK_DIR}/worker.log" 2>&1 &
register_pid "$!"
wait_for_http "http://127.0.0.1:${CRATE_C1_NODE1_PORT}/up"
wait_for_http "http://127.0.0.1:${CRATE_C1_NODE2_PORT}/up"
pass two-http-nodes-and-worker

for flow in "${CRATE_C1_DIR}"/flows/*.sh; do
    [[ -e "${flow}" ]] || continue
    # shellcheck source=/dev/null
    source "${flow}"
done

php "${APP_DIR}/artisan" schedule:run --no-interaction
pass scheduler-invoked

php "${CRATE_C1_DIR}/state/inspect.php" final
print_summary
