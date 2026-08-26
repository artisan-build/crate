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

Satis must be installed at **build** time. On Laravel Cloud only build-time filesystem writes persist — they land in the deploy artifact shipped to every instance, web and worker. Deploy and post-deploy writes touch one instance's ephemeral disk and are gone on the next deploy, so they cannot be used to install Satis.

Set the build command to install the app dependencies, then Satis:

```bash
composer install --no-dev --prefer-dist && php artisan crate:install-satis
```

`crate:install-satis` runs `composer create-project composer/satis:dev-main <base_path>/satis-tool --no-dev`. Artisan is available at this point because the dependency install runs first — on Laravel Cloud, Scalpels prepends `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader` to every manifest build step, so `vendor/` already exists.

The command is idempotent: if the executable is already there it reports that and exits successfully without reinstalling. Pass `--force` to reinstall, `--dir=` to install somewhere other than `satis-tool`.

**No `CRATE_SATIS_PATH` is needed.** `crate-server.satis_path` defaults to `base_path('satis-tool/bin/satis')`, which is exactly where the command installs the executable. Set the variable only if you install Satis somewhere else.

Satis still gets its own isolated dependency tree: `satis-tool/` is a separate Composer project with its own `composer.json` and `composer.lock`. What matters is that Satis is not required into the app's `vendor/`, not that it lives outside the project directory.

Two details the command exists to get right, and which still apply if you install Satis by hand:

- **Pin `dev-main`.** Satis has no recent stable tag, so an unpinned `composer create-project composer/satis` resolves to satis 1.0.0 and fails on any modern runtime with:

  ```
  Cannot use composer/satis's latest version 1.0.0 as it requires php ^5.6 || ^7.0 which is not satisfied by your platform.
  ```

- **The install directory and the executable are different paths.** The `create-project` target is the install *directory*; the Satis *executable* lands inside it at `<install-dir>/bin/satis` (Composer does not link a root package's bin into `vendor/bin`). Passing the executable path as the `create-project` target nests it one level too deep (`.../bin/satis/bin/satis`) and the app then tries to execute a directory. `CRATE_SATIS_PATH`, if you set it, must point at the executable.

If you install Satis by hand on a traditional/VM deploy, hardcode a fixed absolute install directory rather than deriving it from `$CRATE_SATIS_PATH`:

```bash
composer create-project composer/satis:dev-main /var/www/satis-tool --no-dev
CRATE_SATIS_PATH=/var/www/satis-tool/bin/satis
```

- On Laravel Cloud, `$CRATE_SATIS_PATH` is not reliably exported to the build or runtime shell anyway — Cloud injects environment variables into Laravel's environment, not the shell.
- The path must be absolute because the build job runs Satis with its working directory set to a temporary build directory; a relative `satis_path` resolves against that temp dir and breaks. `base_path()` is absolute, so the default already satisfies this.

Confirmed working on Laravel Cloud: Satis 3.0.0-dev on PHP 8.5.

Do not `composer require` Satis into the Crate app. Its dependency tree must stay separate from the Laravel app's.

Ensure `git` is available anywhere Satis runs, including the build and queue runtimes. Satis uses it to read VCS repositories during registry builds. (`crate:install-satis` itself installs from dist archives and does not need git.)

## Configure

Configuration must be in place before the first build. In particular, `CRATE_URL` is required: if it is unset when `crate:build` runs, the generated `satis.json` contains `homepage: null` and `archive.prefix-url: null`, and Satis fails the entire build with this cryptic error:

```
In BuildCommand.php line 416:

  The json config file does not match the expected JSON schema
```

If you see that error, check `CRATE_URL` first.

### On Laravel Cloud

Set `CRATE_URL` as a Cloud environment variable — in the dashboard, or via the Cloud CLI:

```bash
cloud environment:variables <env> --action=set --key=CRATE_URL --value=https://crate.example.com
```

`CRATE_SATIS_PATH` needs no value when the build command runs `crate:install-satis`; the config default already points at what it installed.

Do not use `crate:install` on Laravel Cloud: it writes `.env`, and `.env` changes do not persist there — the environment is Cloud-managed.

`CRATE_ARCHIVE_DISK` can be left unset on Cloud. It defaults to the environment's `FILESYSTEM_DISK`, which Cloud sets to its `private` disk wired to the provisioned object-storage bucket — that works.

### On A Traditional/VM Deploy

Run the installer on the deployed environment. Run it interactively (it prompts for each value, defaulting to any current value), or pass the flags for a non-interactive/automated deploy hook:

```bash
# interactive
php artisan crate:install

# non-interactive (a bare crate:install with no TTY and no flags makes no changes)
# --satis-path is the Satis EXECUTABLE inside the isolated install from the
# Build Command step, not the install directory
php artisan crate:install --no-interaction \
  --url="https://crate.example.com" \
  --archive-disk="crate-archive" \
  --satis-path="/var/www/satis-tool/bin/satis" \
  --credential-api=true
```

The installer is idempotent and will not overwrite an existing value without confirmation (pass `--force` non-interactively). It configures only these app values:

- `CRATE_URL`: the public Crate registry URL used as the Satis homepage and archive prefix.
- `CRATE_ARCHIVE_DISK`: the object-storage filesystem disk name Crate should use for Satis output and mirrored archives.
- `CRATE_SATIS_PATH`: the path to the isolated Satis executable (`<install-dir>/bin/satis`), which the build job executes directly. The config default is `base_path('satis-tool/bin/satis')`, where `crate:install-satis` installs it — set this only when Satis lives elsewhere, as it does in the hand-rolled VM install above.
- `BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED`: whether built-for-cloud's admin-token credential API is enabled.

Then run migrations:

```bash
php artisan migrate --force
```

## First Run

The order matters. Configure first (`CRATE_URL`, and `CRATE_ARCHIVE_DISK` / `CRATE_SATIS_PATH` if you are not using their defaults), then migrate, then register repositories, then build. Running `crate:build` before `CRATE_URL` is set fails with the JSON-schema error described in Configure.

Create an admin token for the credential API and issuer SDK:

```bash
php artisan token:create ci --abilities=admin
```

`token:create` dispatches through the Laravel Cloud CLI by default. When you are already running inside the target environment, add `--execute` to execute the token creation there directly.

Register a private package repository, storing a source-read token if the repository is private:

```bash
php artisan crate:repos:add vendor/pkg <git-url> --source-token=...
```

Security note: running `crate:repos:add ... --source-token=<PAT>` through `cloud command:run` leaves the token in Laravel Cloud's command history in cleartext. Crate stores it encrypted in its own database — the exposure is purely the Cloud command history. Use a fine-grained, revocable, read-only PAT (repository Contents: Read and Metadata: Read only), and rotate it after the first successful build.

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
