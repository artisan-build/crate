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
| `CRATE_SERVICE_TOKEN` | Bearer credential for unified credential API calls. | A unified operator credential with only the required credential verb abilities. Treat it as a secret. |
| `CRATE_ISSUER_SUBJECT_REF` | Automation routing identity placed on credentials issued by `CrateIssuer`. | A stable operator-chosen identity such as `billing-worker`; it is not an installation ID. |
| `CRATE_ISSUER_RETRIES` | Attempt count passed to Laravel's HTTP client `retry(...)` for issuer calls. | Optional integer; defaults to `2`. |
| `CRATE_ISSUER_RETRY_SLEEP` | Delay in milliseconds passed to `retry(...)` between issuer attempts. | Optional integer; defaults to `100`. |

Do not put `CRATE_TOKEN` or `CRATE_SERVICE_TOKEN` in `composer.json`, source control, chat, logs, or agent tool results. Keep generated `auth.json` out of source control.

## Get a credential

For a consumer app hosted on Laravel Cloud or Forge, use Scalpels' `connect_site` tool with the `target` and Crate `provider_deployment` handles returned by Scalpels' listing tools. Confirm the individual site with the user before calling it. Scalpels issues the consumer credential and writes the connection values into the site's environment; it never returns the plaintext credential.

Outside that hosted flow, an active Owner, Admin, or Member can issue a personal or installation-owned Basic credential for `crate.composer.consume` in Built for Cloud's package-owned UI. An operator integration can use `CrateIssuer::issue()`. Transfer reveal-once delivery directly into the target secret store; do not paste it into chat, a commit, or a tool result. Rotate and revoke by the stable credential `id`, not its decorative name.

For trusted local CLI administration, Built for Cloud supplies `bfc:credential:mint`, `list`, `rotate`, and `revoke`. Every state-changing local invocation must include `--local`; omitting it can delegate to a selected Laravel Cloud environment.

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

`CrateIssuer::fromConfig(): CrateIssuer` constructs the client from the issuer config. The public constructor accepts `(string $baseUrl, string $serviceToken, string $subjectRef, int $retries = 2, int $retrySleepMs = 100)` for explicit configuration.

```php
use ArtisanBuild\CrateClient\CrateIssuer;
use Carbon\CarbonImmutable;

$issuer = CrateIssuer::fromConfig();
$issued = $issuer->issue(
    'build-bot',
    CarbonImmutable::parse('2027-01-01T00:00:00+00:00'),
);

// Write $issued['delivery']['password'] directly to a secret store. Never log or return it.
$credentials = $issuer->list();
$rotated = $issuer->rotate($issued['credential']['id']);
$issuer->revoke($rotated['credential']['id']);
```

| Method | HTTP request | Return value |
|---|---|---|
| `issue(string $name, ?CarbonInterface $expiresAt = null): array` | `POST /bfc/credentials` with fixed `subject_type: installation`, configured `subject_ref`, `kind: basic`, `purpose: consumption`, name, and optional expiry. | `credential` summary plus reveal-once `delivery` (`basic_auth` username/password). |
| `list(): Collection` | `GET /bfc/credentials` | `Collection<int, array<string, mixed>>` of unified summaries, including stable `id`, without secret material. |
| `rotate(string $credentialId, bool $emergency = false): array` | `POST /bfc/credentials/{rawurlencoded-id}/rotate` with `emergency`. | Replacement `credential`, `superseded_id`, and reveal-once `delivery`. |
| `revoke(string $credentialId): void` | `DELETE /bfc/credentials/{rawurlencoded-id}` | No value. |

Every issuer request uses Bearer auth with `CRATE_SERVICE_TOKEN`, requests JSON, and includes bfc-client's canonical client-ID and contract-version headers. Those headers are metadata, not authorization. The service credential needs the corresponding closed verb ability: `credential:read`, `credential:mint`, `credential:rotate`, or `credential:revoke`.

`CrateIssuer` deliberately fixes the issued shape. The app-purpose authority exposed to Composer is `crate.composer.consume`; Built for Cloud persists its mapped protocol purpose `consumption`. The installation-local credential store is the installation boundary, while `subject_ref` identifies the consuming automation for routing and attribution.

### Incumbent mapping

| Incumbent | Real Crate client equivalent |
|---|---|
| Private Packagist | Replace the consuming app's Composer repository URL and auth with `composer config repositories.crate composer "$CRATE_URL"` plus `crate:auth`. Use `CrateIssuer::issue()`, `list()`, `rotate()`, and `revoke()` only for consumer credential lifecycle. There is no `crate-client` equivalent for organization, team, or per-package access management. |
| Repman | Replace the consuming app's Composer repository URL and token setup with the same Composer config and `crate:auth` flow. There is no `crate-client` equivalent for hosted package synchronization or organization management. |
| Satis | Replace the consuming app's repository URL with the Crate URL and add Crate's HTTP Basic credential through `crate:auth`. There is no client call equivalent to editing `satis.json` or running `satis build`; the Crate server owns repository registration and builds. |

## Behaviour to know

- Composer requires the generated `auth.json` / `COMPOSER_AUTH` shape to be `http-basic` keyed by the registry host, with literal username `token` and the consumer credential as the password.
- Add the registry to the consuming app's `composer.json` with `composer config repositories.crate composer "$CRATE_URL"`. Package installation then uses normal Composer commands.
- Only an active Basic credential for fixed purpose `crate.composer.consume` grants access to every package served by that Crate deployment. Crate has no per-package or per-vendor ACL.
- Personal credentials are account-bound and follow the canonical user's versioned role, status, authority generation, and managed freshness. Installation credentials are creator-independent and survive creator removal.
- Credential names are decorative. Lifecycle operations use stable IDs. `issue()` and `rotate()` return delivery once; `list()` never returns secret material.
- `issue()`, `list()`, `rotate()`, and `revoke()` are synchronous HTTP calls. They do not return poll handles and do not use webhooks.
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

An HTTP `401` intentionally does not distinguish missing, malformed, wrong-purpose, expired, revoked, removed-account, or otherwise invalid credentials. Do not print the generated auth file while diagnosing it.

## Contribute back

If you used this default guide to migrate from a specific product and no migration guide for it
exists yet, offer to open a pull request to this repository adding
`packages/crate-client/docs/integrate/<incumbent>.md` with what you learned.
