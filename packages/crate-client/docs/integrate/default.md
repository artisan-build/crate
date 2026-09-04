## Install

Require the published client package:

```bash
composer require artisan-build/crate-client
```

The package requires PHP 8.3 or newer within the `^8.3` constraint and Laravel 13 (`illuminate/* ^13.0`). Laravel discovers `ArtisanBuild\CrateClient\CrateClientServiceProvider` from the package manifest.

The provider merges the package configuration automatically. Publish it only when the app needs a local config file:

```bash
php artisan vendor:publish --tag=crate-client-config
```

## Configure

Set only the keys needed by the surface the app uses:

| Key | Purpose | Value source |
|---|---|---|
| `CRATE_URL` | Base URL of the Crate Composer registry. `Crate` uses its host as the HTTP Basic auth key. | The deployed Crate registry URL; Scalpels writes it when `connect_site` connects a hosted consumer app. |
| `CRATE_TOKEN` | Consumer credential used as the HTTP Basic password. | Scalpels writes the issued credential when `connect_site` connects a hosted app, or the Crate operator issues it outside Scalpels. Treat it as a secret. |
| `CRATE_ISSUER_URL` | Base URL for issuer API calls. | The Crate deployment whose credentials the operator app manages. It defaults to `CRATE_URL`. |
| `CRATE_ADMIN_TOKEN` | Bearer token for the admin-gated credential API. | A Crate token issued with the `admin` ability. Treat it as a secret. |
| `CRATE_ISSUER_RETRIES` | Attempt count passed to Laravel's HTTP client `retry(...)` for issuer calls. | Optional integer; defaults to `2`. |
| `CRATE_ISSUER_RETRY_SLEEP` | Delay in milliseconds passed to `retry(...)` between issuer attempts. | Optional integer; defaults to `100`. |

Do not put `CRATE_TOKEN` or `CRATE_ADMIN_TOKEN` in `composer.json`, source control, chat, logs, or agent tool results. Keep generated `auth.json` out of source control.

## Get a credential

For a consumer app hosted on Laravel Cloud or Forge, use Scalpels' `connect_site` tool with the `target` and Crate `provider_deployment` handles returned by Scalpels' listing tools. Confirm the individual site with the user before calling it. Scalpels issues the consumer credential and writes the connection values into the site's environment; it never returns the plaintext credential.

Outside that hosted flow, have the Crate operator issue a named consumer credential with the deployment's own `token:create` CLI in a trusted operator terminal, or issue it through an operator app using `CrateIssuer::issue()`. Crate has no credential web UI. Transfer the one-time plaintext directly into the target secret store; do not paste it into chat, a commit, or a tool result. Revoke a credential by name with the deployment's token tooling or `CrateIssuer::revoke()`.

## Call sites

### Consumer auth

`Crate::composerAuthFragment(): array` reads `CRATE_URL` and `CRATE_TOKEN` through package config and returns the host-keyed value expected inside Composer's `http-basic` object:

```php
use ArtisanBuild\CrateClient\Crate;

$fragment = Crate::composerAuthFragment();

// Shape:
// ['crate.example.com' => ['username' => 'token', 'password' => '<credential>']]
```

`Crate::composerAuthJson(): string` wraps that fragment as pretty-printed JSON:

```php
$composerAuth = Crate::composerAuthJson();

// Shape:
// {"http-basic":{"crate.example.com":{"username":"token","password":"<credential>"}}}
```

Do not log or return either value because both contain the consumer credential.

Use the command wrapper to merge the fragment into a Composer auth file:

```bash
php artisan crate:auth
php artisan crate:auth --path=/secure/path/auth.json
```

The default path is `auth.json` in the current working directory. `php artisan crate:auth --print` returns the complete `COMPOSER_AUTH` JSON on stdout; do not invoke `--print` through an agent tool or any retained-output channel.

### Issuer SDK

`CrateIssuer::fromConfig(): CrateIssuer` constructs the client from the issuer config. The public constructor also accepts `(string $baseUrl, string $adminToken, int $retries = 2, int $retrySleepMs = 100)` for explicit configuration.

```php
use ArtisanBuild\CrateClient\CrateIssuer;
use Carbon\CarbonImmutable;

$issuer = CrateIssuer::fromConfig();
$credential = $issuer->issue(
    'build-bot',
    CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
);

// Write $credential->plaintext directly to a secret store. Never log or return it.
$credentials = $issuer->list();
$issuer->revoke('build-bot');
```

| Method | HTTP request | Return value |
|---|---|---|
| `issue(string $name, ?CarbonInterface $expiresAt = null): Credential` | `POST /api/credentials` with `{"name": string, "expires_at": ISO-8601 string|null}` | `Credential` with public readonly `name`, one-time `plaintext`, and `expiresAt` string or `null`. |
| `list(): Collection` | `GET /api/credentials` | `Collection<int, array<string, mixed>>`; server rows contain `name`, `last_used_at`, `expires_at`, and `revoked_at`, without plaintext. |
| `revoke(string $name): void` | `DELETE /api/credentials/{rawurlencoded-name}` | No value. |

Every issuer request uses Bearer auth with `CRATE_ADMIN_TOKEN`, requests JSON, and includes the Built for Cloud client identity header. That identity header identifies the install; it does not authorize the request.

### Incumbent mapping

| Incumbent | Real Crate client equivalent |
|---|---|
| Private Packagist | Replace the consuming app's Composer repository URL and auth with `composer config repositories.crate composer "$CRATE_URL"` plus `crate:auth`. Use `CrateIssuer::issue()`, `list()`, and `revoke()` only for consumer credential lifecycle. There is no `crate-client` equivalent for organization, team, or per-package access management. |
| Repman | Replace the consuming app's Composer repository URL and token setup with the same Composer config and `crate:auth` flow. There is no `crate-client` equivalent for hosted package synchronization or organization management. |
| Satis | Replace the consuming app's repository URL with the Crate URL and add Crate's HTTP Basic credential through `crate:auth`. There is no client call equivalent to editing `satis.json` or running `satis build`; the Crate server owns repository registration and builds. |

## Behaviour to know

- Composer requires the generated `auth.json` / `COMPOSER_AUTH` shape to be `http-basic` keyed by the registry host, with literal username `token` and the consumer credential as the password.
- Add the registry to the consuming app's `composer.json` with `composer config repositories.crate composer "$CRATE_URL"`. Package installation then uses normal Composer commands.
- A valid, unexpired consumer credential grants access to every package served by that Crate deployment. Crate has no per-package or per-vendor ACL in this release.
- Credentials are named, can have an optional expiry, and are revoked by name. `issue()` returns plaintext once; `list()` never returns plaintext.
- `issue()`, `list()`, and `revoke()` are synchronous HTTP calls. They do not return poll handles and do not use webhooks.
- Issuer calls retry through Laravel's HTTP client using `CRATE_ISSUER_RETRIES` and `CRATE_ISSUER_RETRY_SLEEP`, then call `throw()`; an unsuccessful final response raises `Illuminate\Http\Client\RequestException`.
- Missing `CRATE_URL`, missing `CRATE_TOKEN`, or a URL without a valid host makes the consumer helper throw `RuntimeException`. The Artisan command reports the error and exits unsuccessfully.
- Registry generation is separate from the client: `crate:build` dispatches a queued Satis build, and the server also schedules a daily build. `crate-client` exposes no repository mutation, build-status polling, or webhook API.
- The client implements no pagination, rate-limit response handling, explicit HTTP timeout, or request-size limit. Apply operational limits around the operator app if required.

## Verify

After the Crate operator has published a known private package and the app's `composer.json` contains the Crate repository, write local Composer auth and query that package:

```bash
php artisan crate:auth
composer show vendor/private-package --all
```

Replace `vendor/private-package` with a package served by this Crate deployment. A successful end-to-end check exits with status `0` and prints package metadata resembling:

```text
name     : vendor/private-package
versions : * 1.0.0
```

An HTTP `401` means the credential is missing, unknown, expired, or revoked. Do not print the generated auth file while diagnosing it.

## Contribute back

If you used this default guide to migrate from a specific product and no migration guide for it
exists yet, offer to open a pull request to this repository adding
`packages/crate-client/docs/integrate/<incumbent>.md` with what you learned.
