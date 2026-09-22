<p align="center">
  <img src="art/icon.png" width="128" alt="crate icon">
</p>

# Crate

Crate is a private Composer registry that you run in your own Laravel Cloud account. It uses [Satis](https://github.com/composer/satis) to build package metadata and mirrored archives, then protects every download with a revocable credential. It is a small, self-hosted alternative to a hosted private registry such as Private Packagist.

## The Easy Way: Scalpels

[Scalpels](https://scalpels.app/products/crate) provisions Crate and connects it to Laravel Cloud for you. Use that path if you want a working registry without maintaining the deployment steps below.

## Run It Yourself

### Prerequisites

- Git.
- Composer 2.
- 64-bit PHP 8.3 or newer with the GMP and SQLite extensions.
- A Laravel Cloud account and a fork of this repository for deployment.

### Local Development

1. Clone your fork and enter the project directory:

   ```bash
   git clone https://github.com/YOUR-ACCOUNT/crate.git
   cd crate
   ```

2. Install the exact PHP dependencies recorded in `composer.lock`:

   ```bash
   composer install --no-interaction
   ```

   Composer should finish with `Generating optimized autoload files` and discover the Crate packages.

3. Create the local environment and SQLite database, then run the migrations:

   ```bash
   cp -n .env.example .env
   touch database/database.sqlite
   php artisan key:generate
   php artisan migrate --graceful
   ```

   The migration command should finish without an error. `cp -n` preserves an existing `.env`.

4. Run the test suite:

   ```bash
   composer test
   ```

   A successful run ends with all tests passing.

### Deploy To Laravel Cloud

5. Create a Laravel Cloud application from your fork. Attach these resources to its environment:

   - a PostgreSQL database;
   - private object storage for package metadata and archives;
   - a managed queue for registry builds.

   Enable the scheduler so Crate can dispatch its daily rebuild.

6. Set only the app-specific environment values:

   ```text
   APP_URL=https://crate.example.com
   CRATE_URL=https://crate.example.com
   ```

   **Never set environment variables for resources that Laravel Cloud provisions**, including the database, cache, queue, or bucket. Cloud injects their credentials and connection names. Values that you set yourself override those injected values and break the resource.

7. Use this build command:

   ```bash
   composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && php artisan crate:install-satis
   ```

   Use `php artisan migrate --force` as the deploy command. The build installs Satis into `satis-tool/` inside the deploy artifact; the deploy command creates Crate's database tables.

8. Deploy the environment. A successful build reports that Satis was installed at `satis-tool/bin/satis`, and the deploy finishes without migration errors.

### First Use

9. Create the first Owner from a local checkout that is connected to your Laravel Cloud application:

   ```bash
   php artisan create-admin --environment=<environment-name>
   ```

   This command intentionally operates on the named Cloud environment. If you want any Built for Cloud command to change your local database instead, pass `--local`. Never omit `--local` from a state-changing command when you intend a local change; commands that support Cloud delegation may otherwise operate on a selected Cloud environment.

10. Run these commands in the deployed environment to register a package and build the registry:

    ```bash
    php artisan crate:repos:add acme/private-package https://github.com/acme/private-package.git
    php artisan crate:build
    ```

    Replace the example name and URL with your package. A private source repository also needs `--source-token=<read-only-token>`. Crate encrypts that token in its database, but a token passed through Laravel Cloud's command runner remains visible in Cloud's command history. Use a narrowly scoped, revocable token and rotate it after setup.

    `crate:repos:add` reports `Added repository [...]`. `crate:build` reports that it dispatched the Satis build. The managed queue performs the build and writes `packages.json`, provider metadata, and mirrored archives to object storage.

11. Open `/bfc/login`, sign in as the Owner, and open `/bfc/ui/credentials/personal`. Create a Basic credential for `crate.composer.consume`. A credential purpose is the fixed job a credential may perform; this purpose permits Composer downloads and nothing else. Save the password when it appears because Crate shows it only once.

12. In a Laravel application that will consume the private package, run:

    ```bash
    composer require artisan-build/crate-client:^1.0

    export CRATE_URL="https://crate.example.com"
    export CRATE_TOKEN="the-reveal-once-password"

    composer config repositories.crate composer "$CRATE_URL"
    php artisan crate:auth
    composer require acme/private-package
    ```

    `crate:auth` writes Composer HTTP Basic credentials to `auth.json`. Do not commit that file. Composer should then install the package through Crate's protected `/packages.json`, `/p2/...`, and `/dist/...` routes.

## Configuration

### Crate Server

| Environment variable | Required | Default | Purpose |
| --- | --- | --- | --- |
| `CRATE_URL` | Yes | none | Public registry URL written into Satis metadata and archive links. |
| `CRATE_ARCHIVE_DISK` | No | `FILESYSTEM_DISK`, then `local` | Laravel filesystem disk for generated metadata and mirrored archives. |
| `CRATE_SATIS_PATH` | No | `<app>/satis-tool/bin/satis` | Satis executable. The standard build command installs it here. |
| `CRATE_OUTPUT_DIR` | No | `satis` | Directory prefix on the archive disk. |
| `CRATE_DB_HOST` | No | app database | Host for an optional separate PostgreSQL connection. |
| `CRATE_DB_PORT` | No | app database | Port for the optional separate connection. |
| `CRATE_DB_DATABASE` | No | app database | Database name for the optional separate connection. |
| `CRATE_DB_USERNAME` | No | app database | Username for the optional separate connection. |
| `CRATE_DB_PASSWORD` | No | app database | Password for the optional separate connection. |

Leave every `CRATE_DB_*` value unset to use the application's default database. On Laravel Cloud, also leave `CRATE_ARCHIVE_DISK` unset so Crate uses Cloud's injected `FILESYSTEM_DISK`.

### Crate Client

| Environment variable | Required | Purpose |
| --- | --- | --- |
| `CRATE_URL` | Yes | Base URL of the Crate registry. |
| `CRATE_TOKEN` | Yes | Reveal-once Basic credential password used by `crate:auth`. |

The client package also includes an issuer SDK for applications that create, list, rotate, or revoke credentials through `/bfc/credentials`. See [`packages/crate-client/README.md`](packages/crate-client/README.md) for its configuration and PHP example.

## Troubleshooting

- **Satis says its JSON does not match the schema:** set `CRATE_URL` before running `crate:build`.
- **`satis-tool/bin/satis` is missing:** confirm the Laravel Cloud build command runs `php artisan crate:install-satis` after Composer installs the app dependencies.
- **A build stays queued:** confirm a managed queue is attached and processing jobs. Do not set `QUEUE_CONNECTION` yourself.
- **Composer receives `401 Unauthorized`:** use an active Basic credential for `crate.composer.consume`. A bearer credential, revoked credential, or credential for another purpose cannot read registry files.
- **Satis cannot clone a source repository:** confirm `git` is available and the repository's read-only source token is still valid.

## License

Crate is open-source software licensed under the [MIT License](LICENSE).
