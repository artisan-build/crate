# Crate

Crate is a self-hosted, unmetered private Composer registry for Laravel, built to run on Laravel Cloud.

It is a fork-and-deploy Satis wrapper behind a credential gate: register the private repositories you want to serve, build Composer metadata and mirrored dist archives, issue credentials over an admin-token guarded API, then point customer apps at your Crate host.

Crate is single-tenant by construction. Each deployment lives in your own Laravel Cloud account, with your own compute, database, queue, and object storage. It is the self-hosted floor below hosted products like Private Packagist and Anystack: no per-seat registry bill, no third-party registry holding your private packages, and no built-in team dashboard or per-package ACLs. Access is credential-level in this release.

Crate is MIT licensed.

## Deploying

See [`docs/deploy.md`](docs/deploy.md) for the Laravel Cloud deployment guide, including resource provisioning, `crate:install`, first-run commands, and customer-app consumption.

## What Ships

- `artisan-build/crate-contracts`: framework-free DTOs and enums shared by the client and server packages.
- `artisan-build/crate-client`: a consumer auth helper plus an issuer SDK for `/api/credentials`.
- `artisan-build/crate-server`: served-repo storage, Satis config/build orchestration, and gated Composer registry routes.
- `artisan-build/built-for-cloud`: consumed by the app for token storage, token commands, admin-token middleware, and the credential API.

Crate is headless. There is no admin web UI in this release.

## Test Drive

After deploying the app and provisioning Laravel Cloud resources, run the operator flow against your deployed Crate environment:

```bash
php artisan crate:repos:add vendor/pkg https://github.com/vendor/pkg.git --source-token=...
php artisan crate:build
php artisan token:create ci --abilities=admin
```

`crate:repos:add` stores the served package and encrypts the source credential. `crate:build` generates `satis.json` from the database and dispatches the Satis build job. The build writes Composer metadata and mirrored dist archives to the configured storage disk, served back through Crate rather than public object-storage URLs. `token:create` dispatches to the target environment through the Cloud CLI; add `--execute` to run it directly on the environment (for example from a Cloud SSH session).

Use the admin token from `token:create --abilities=admin` to issue customer credentials:

```bash
curl -X POST "$CRATE_URL/api/credentials" \
  -H "Authorization: Bearer $CRATE_ADMIN_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"build-bot"}'
```

You can also issue, list, and revoke credentials from another Laravel app with `ArtisanBuild\CrateClient\CrateIssuer`.

In the customer app, install `artisan-build/crate-client`, configure Composer to use the Crate registry, set the credential, and write Composer auth:

```bash
export CRATE_URL="https://crate.example.com"
export CRATE_TOKEN="the-issued-credential"

composer config repositories.crate composer "$CRATE_URL"

php artisan crate:auth
composer require vendor/pkg
```

`crate:auth` writes or merges Composer `auth.json` HTTP Basic credentials for the Crate host. Composer then reads `/packages.json`, `/p2/...`, and `/dist/...` through Crate's credential gate.

## Configuration

The app publishes `config/built-for-cloud.php` and enables the credential API by default for Crate:

```php
'credential_api' => [
    'enabled' => env('BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED', true),
    'prefix' => env('BUILT_FOR_CLOUD_CREDENTIAL_API_PREFIX', 'api/credentials'),
],
```

Crate-specific server config lives in `config/crate-server.php`:

- `CRATE_URL`: the public registry URL used as Satis `homepage` and archive prefix.
- `CRATE_ARCHIVE_DISK`: disk for Composer metadata and mirrored dist archives.
- `CRATE_SATIS_PATH`: path to the isolated Satis binary.
- `CRATE_OUTPUT_DIR`: storage prefix for generated registry output.

Do not hand-set Laravel Cloud managed resource credentials for database, queue, cache, or object storage. Let Cloud inject them.

## Credential API

The app exposes built-for-cloud's admin-token gated routes:

- `GET /api/credentials`: list token metadata. Plaintext is never returned here.
- `POST /api/credentials`: issue a credential and return plaintext once.
- `DELETE /api/credentials/{name}`: revoke credentials by name.

Only tokens with the `admin` ability can call these routes. Registry credentials without `admin` can pull packages but cannot issue or revoke other credentials.

## Non-Goals

- No hosted control plane.
- No web dashboard.
- No mirroring of packagist.org public packages.
- No per-package or per-vendor access control in this release.
- No search, download stats, team management, or billing logic.
