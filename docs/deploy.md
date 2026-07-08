# Deploying Crate on Laravel Cloud

## Overview

Crate is a fork-and-deploy private Composer registry for Laravel Cloud. Fork the app, deploy it into your own Laravel Cloud account, provision the app's database, queue, and object storage there, then run the first Crate build against the private repositories you want to serve.

Each deployment is single-tenant and unmetered by Crate. Your Laravel Cloud account owns the compute, Postgres database, Redis queue, and object storage; Crate has no hosted control plane and no web UI in this release.

## Provision Resources

Create these resources in Laravel Cloud and attach them to the Crate environment:

- Postgres for the application database.
- Redis managed queue, suitable for scale-to-zero queue workers.
- Object storage for Satis output and mirrored package archives.

Cardinal rule: never hand-set Cloud-injected resource environment variables. Laravel Cloud injects database, queue, cache, filesystem connection selectors, credentials, and endpoints into the environment. Do not set `DB_*`, `QUEUE_*`, `CACHE_*`, or `FILESYSTEM_DISK` yourself; overriding Cloud's injected values breaks the managed resource.

`php artisan crate:install` only writes Crate's own application config: `CRATE_URL`, `CRATE_ARCHIVE_DISK`, `CRATE_SATIS_PATH`, and `BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED`. It never writes Cloud-managed database, queue, cache, or filesystem env.

## Build Command

Install the app dependencies, then install Satis as an isolated tool with its own dependency tree:

```bash
composer install --no-dev --prefer-dist
composer create-project composer/satis "$CRATE_SATIS_PATH" --no-dev
```

Do not `composer require` Satis into the Crate app. It must stay isolated because its dependency tree is separate from the Laravel app's dependency tree.

Ensure `git` is available anywhere Satis runs, including the build and queue runtimes. Satis uses it to read VCS repositories during registry builds.

## Configure

Run the installer on the deployed environment. Run it interactively (it prompts for each value, defaulting to any current value), or pass the flags for a non-interactive/automated deploy hook:

```bash
# interactive
php artisan crate:install

# non-interactive (a bare crate:install with no TTY and no flags makes no changes)
php artisan crate:install --no-interaction \
  --url="https://crate.example.com" \
  --archive-disk="crate-archive" \
  --satis-path="$CRATE_SATIS_PATH" \
  --credential-api=true
```

The installer is idempotent and will not overwrite an existing value without confirmation (pass `--force` non-interactively). It configures only these app values:

- `CRATE_URL`: the public Crate registry URL used as the Satis homepage and archive prefix.
- `CRATE_ARCHIVE_DISK`: the object-storage filesystem disk name Crate should use for Satis output and mirrored archives.
- `CRATE_SATIS_PATH`: the path to the isolated Satis binary or installation.
- `BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED`: whether built-for-cloud's admin-token credential API is enabled.

Then run migrations:

```bash
php artisan migrate --force
```

## First Run

Create an admin token for the credential API and issuer SDK:

```bash
php artisan token:create ci --abilities=admin
```

`token:create` dispatches through the Laravel Cloud CLI by default. When you are already running inside the target environment, add `--execute` to execute the token creation there directly.

Register a private package repository, storing a source-read token if the repository is private:

```bash
php artisan crate:repos:add vendor/pkg <git-url> --source-token=...
```

Build the registry metadata and mirrored dist archives:

```bash
php artisan crate:build
```

`crate:build` dispatches the Satis build job. Crate generates `satis.json` from the registered repositories, runs isolated Satis, writes Composer metadata and mirrored archives to the configured archive disk, and serves them back through Crate's credential gate.

## Consume From A Customer App

In the customer Laravel app, install the Crate client package, set the issued registry credential, configure Composer to use your Crate host, write Composer auth, then require the package:

```bash
export CRATE_URL="https://crate.example.com"
export CRATE_TOKEN="the-issued-credential"

composer config repositories.crate composer "$CRATE_URL"

php artisan crate:auth
composer require vendor/pkg
```

`crate:auth` writes or merges Composer HTTP Basic auth for the Crate host. Composer then reads `/packages.json`, `/p2/...`, and `/dist/...` through the Crate credential gate.
