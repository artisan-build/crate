# Crate

Crate is a self-hosted, unmetered private Composer registry for Laravel, built to run on Laravel Cloud.

It is a fork-and-deploy Satis wrapper behind a credential gate: register the private repositories you want to serve, build Composer metadata and mirrored dist archives, issue unified credentials, then point customer apps at your Crate host.

Crate is single-tenant by construction. Each deployment lives in your own Laravel Cloud account, with your own compute, database, queue, and object storage. It is the self-hosted floor below hosted products like Private Packagist and Anystack: no per-seat registry bill, no third-party registry holding your private packages, and no per-package ACLs. Access is credential-level in this release.

Crate is MIT licensed.

## Deploying

See [`docs/deploy.md`](docs/deploy.md) for the Laravel Cloud deployment guide, including resource provisioning, `crate:install`, first-run commands, and customer-app consumption.

Crate runs Satis as an isolated tool, so the deploy has to install one. Put `php artisan crate:install-satis` in the build command:

```bash
composer install --no-dev --prefer-dist && php artisan crate:install-satis
```

It installs `composer/satis:dev-main` into `satis-tool/` — a separate Composer project with its own dependency tree, never required into the app's `vendor/` — and the `CRATE_SATIS_PATH` default already points at the executable it produces. It must run at build time: on Laravel Cloud only build-time filesystem writes persist into the deploy artifact.

## What Ships

- `artisan-build/crate-contracts`: framework-free DTOs and enums shared by the client and server packages.
- `artisan-build/crate-client`: a consumer auth helper plus an issuer SDK for `/bfc/credentials`.
- `artisan-build/crate-server`: served-repo storage, Satis config/build orchestration, and gated Composer registry routes.
- `artisan-build/built-for-cloud`: the canonical user, versioned session-role, credential-store, lifecycle UI, and credential API implementation.

Crate adds no application-specific account or credential UI. Built for Cloud owns login, membership, sessions, transitions, and personal and installation credential management under `/bfc/*`; Crate owns repository/build JSON endpoints and system-authority CLI commands.

## Test Drive

After deploying the app and provisioning Laravel Cloud resources, run the operator flow against your deployed Crate environment:

```bash
php artisan crate:repos:add vendor/pkg https://github.com/vendor/pkg.git --source-token=...
php artisan crate:build
```

`crate:repos:add` stores the served package and encrypts the source credential. `crate:build` generates `satis.json` from the database and dispatches the Satis build job. These commands, the queue worker, scheduler, and Satis process run as explicit system authority, never as a synthetic human. The build writes Composer metadata and mirrored dist archives to the configured storage disk, served back through Crate rather than public object-storage URLs.

Owner, Admin, and Member users can manage personal and installation credentials in the package-owned UI. For an operator integration, use a unified operator service credential with the verb abilities needed by the request. The fixed API is `/bfc/credentials`; issue an installation-owned Composer credential with purpose `crate.composer.consume`:

```bash
curl -X POST "$CRATE_URL/bfc/credentials" \
  -H "Authorization: Bearer $CRATE_SERVICE_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"subject_type":"installation","subject_ref":"build-bot","kind":"basic","purpose":"consumption","name":"build-bot"}'
```

The response reveals the HTTP Basic password once. `subject_ref` names the automation for routing and attribution; installation identity is the installation-local credential store, not this value. You can also issue, list, rotate, and revoke credentials by stable credential ID from another Laravel app with `ArtisanBuild\CrateClient\CrateIssuer`.

In the customer app, install `artisan-build/crate-client`, configure Composer to use the Crate registry, set the credential, and write Composer auth:

```bash
export CRATE_URL="https://crate.example.com"
export CRATE_TOKEN="the-issued-credential"

composer config repositories.crate composer "$CRATE_URL"

php artisan crate:auth
composer require vendor/pkg
```

`crate:auth` writes or merges Composer `auth.json` HTTP Basic credentials for the Crate host. Composer then reads `/packages.json`, `/p2/...`, and `/dist/...` through Crate's credential gate.

## Authentication And Roles

Built for Cloud owns the canonical user and Laravel `web` session. Session identity carries the package's authority generation and closed `owner`, `admin`, or `member` role; unknown or stale contexts fail closed. Every active role can use Crate, manage its own personal Composer credentials, and manage installation credentials. Owner and Admin can add or remove repositories; every role can list repository/build state and replace or clear encrypted source credentials.

Composer HTTP Basic accepts only active `basic` credentials mapped to the fixed app purpose `crate.composer.consume` (wire purpose `consumption`). It grants all-package registry reads and no repository, credential, transition, or shell authority. Personal credentials are account-bound; installation credentials survive their creator's departure. There is no fallback credential, generic ability token, or configurable package ACL.

## Configuration

Crate-specific server config lives in `config/crate-server.php`:

- `CRATE_URL`: the public registry URL used as Satis `homepage` and archive prefix. Must be set before the first `crate:build` — when unset, the generated `satis.json` fails Satis' schema validation and the whole build errors with `The json config file does not match the expected JSON schema`.
- `CRATE_ARCHIVE_DISK`: disk for Composer metadata and mirrored dist archives. On Laravel Cloud the default (the environment's `FILESYSTEM_DISK`, wired to the `private` object-storage disk) works.
- `CRATE_SATIS_PATH`: path to the isolated Satis executable (`<install-dir>/bin/satis`), run directly by the build job. Run `php artisan crate:install-satis` to install it; the default (`base_path('satis-tool/bin/satis')`) is where that command puts it, so a deploy that runs the command needs no value here. `satis-tool/` has its own dependency tree and is not part of the app's vendor tree, which is the isolation that matters. See `docs/deploy.md`.
- `CRATE_OUTPUT_DIR`: storage prefix for generated registry output.

Do not hand-set Laravel Cloud managed resource credentials for database, queue, cache, or object storage. Let Cloud inject them.

## Unified Credential API

Built for Cloud always mounts the fixed operator routes:

- `GET /bfc/credentials`: list credential summaries without secret material.
- `POST /bfc/credentials`: issue a credential and reveal delivery material once.
- `POST /bfc/credentials/{id}/rotate`: rotate by stable credential ID and reveal replacement delivery once.
- `DELETE /bfc/credentials/{id}`: revoke by stable credential ID.

Operator credentials need the corresponding closed verb ability (`credential:read`, `credential:mint`, `credential:rotate`, or `credential:revoke`). A Composer credential has none of these. The package-owned personal and installation UI applies role and ownership policy without exposing operator credentials to a browser user.

## Non-Goals

- No hosted control plane.
- No Crate-specific account or credential dashboard; those surfaces are package-owned.
- No mirroring of packagist.org public packages.
- No per-package or per-vendor access control in this release.
- No search, download stats, organization hierarchy, or billing logic.
