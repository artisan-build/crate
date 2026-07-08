# Crate Server

Server package for the Crate private Composer registry.

It stores the list of private repositories to serve, generates Satis configuration from that database state, dispatches Satis builds, mirrors generated registry output to storage, and serves Composer metadata and dist archives through a credential gate.

## Served Repositories

The package stores served repositories in the `served_repos` table. Each row contains the Composer package name, source URL, repository type, status, optional encrypted source credential, and `last_built_at` timestamp.

Commands:

```bash
php artisan crate:repos:add vendor/pkg https://github.com/vendor/pkg.git --source-token=...
php artisan crate:repos:add vendor/pkg https://github.com/vendor/pkg.git --type=vcs
php artisan crate:repos:list
php artisan crate:repos:remove vendor/pkg
```

`crate:repos:add` validates lowercase `vendor/package` names and stores source credentials encrypted via Eloquent casts. The list command intentionally does not print source credentials.

## Satis Builds

Builds are dispatched with:

```bash
php artisan crate:build
php artisan crate:build vendor/pkg
```

The command dispatches `ArtisanBuild\CrateServer\Jobs\BuildSatis`. A full build has no package argument. Passing a package performs an incremental build for that package and seeds the temporary output directory from the current archive storage before running Satis.

`SatisConfigGenerator` builds `satis.json` from the database:

- `homepage` is `CRATE_URL`.
- repositories come from `served_repos`.
- full builds require all registered packages.
- incremental builds require the requested package only.
- archives are configured as zip files under `dist` with `prefix-url` set to `CRATE_URL`.

For source credentials, the generator writes a scoped Composer auth config for the build process. GitHub hosts use `github-oauth`; other hosts use bearer auth.

`BuildSatis` runs the configured isolated Satis binary via Symfony Process, using the temporary build directory as `COMPOSER_HOME`. Successful builds mirror the generated output to `CRATE_ARCHIVE_DISK` under `CRATE_OUTPUT_DIR`. Failed builds store a redacted output tail so source credentials are not persisted in build logs.

The service provider schedules a daily full rebuild:

```php
$schedule->command('crate:build --trigger=schedule')->daily();
```

## Gated Registry Routes

The package registers Composer registry routes at the app root:

- `GET /packages.json`
- `GET /p2/{path}`
- `GET /dist/{path}`

All routes use `ArtisanBuild\CrateServer\Http\Middleware\EnsureValidCredential`, registered as `crate-server.credential`.

Composer authenticates with HTTP Basic auth. The username is conventional and ignored; the password is the Crate credential. The middleware resolves that password through `ArtisanBuild\BuiltForCloud\TokenRegistry` and returns `401` with `WWW-Authenticate: Basic realm="Crate"` when the credential is missing, unknown, expired, or revoked.

`RegistryFileController` streams files from the configured archive disk and output prefix:

- `packages.json` serves generated Composer root metadata.
- `p2/...` serves Composer v2 provider metadata.
- `dist/...` serves mirrored dist archives.

Traversal attempts containing `..` are rejected before storage access.

## Configuration

Published config key: `crate-server`.

Environment variables:

- `CRATE_URL`: public registry URL used by Satis metadata and dist archive URLs.
- `CRATE_ARCHIVE_DISK`: storage disk for generated metadata and mirrored archives. Defaults to `FILESYSTEM_DISK` then `local`.
- `CRATE_SATIS_PATH`: isolated Satis binary path. Defaults to `vendor/bin/satis`.
- `CRATE_OUTPUT_DIR`: storage prefix for generated registry output. Defaults to `satis`.
- `CRATE_DB_*`: optional separate database connection settings. If omitted, the app's default database connection is reused as `crate`.

This package consumes `artisan-build/built-for-cloud` for token storage and credential resolution. The app mounts built-for-cloud's credential-management API separately via `config/built-for-cloud.php`.
