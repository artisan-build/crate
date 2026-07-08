# Crate Client

Client-side helpers for Crate.

This package has two surfaces:

- Consumer auth helper: write Composer HTTP Basic auth for a Crate registry.
- Issuer SDK: call a Crate deployment's admin-token gated `/api/credentials` endpoint.

## Consumer Helper

Configure the customer app with the Crate registry URL and an issued registry credential:

```bash
CRATE_URL=https://crate.example.com
CRATE_TOKEN=issued-registry-credential
```

Generate Composer auth:

```bash
php artisan crate:auth
```

By default, `crate:auth` writes or merges `auth.json` in the current working directory. It adds this shape:

```json
{
  "http-basic": {
    "crate.example.com": {
      "username": "token",
      "password": "issued-registry-credential"
    }
  }
}
```

Use a different path with `--path`:

```bash
php artisan crate:auth --path=/path/to/auth.json
```

Print the JSON instead of writing a file with `--print`, which is useful for `COMPOSER_AUTH` in CI:

```bash
export COMPOSER_AUTH="$(php artisan crate:auth --print)"
```

Then configure Composer to use the Crate registry and require packages normally:

```bash
composer config repositories.crate composer https://crate.example.com
composer require vendor/pkg
```

## Issuer SDK

Use `CrateIssuer` in the operator's own Laravel app to issue and revoke credentials around billing, onboarding, or access logic that you own.

Configure the issuer client:

```bash
CRATE_ISSUER_URL=https://crate.example.com
CRATE_ADMIN_TOKEN=admin-ability-token
CRATE_ISSUER_RETRIES=2
CRATE_ISSUER_RETRY_SLEEP=100
```

`CRATE_ISSUER_URL` defaults to `CRATE_URL` when omitted.

Example:

```php
use ArtisanBuild\CrateClient\CrateIssuer;

$issuer = CrateIssuer::fromConfig();

$credential = $issuer->issue('build-bot');
$tokens = $issuer->list();
$issuer->revoke('build-bot');
```

The SDK calls:

- `POST /api/credentials` for `issue(...)`, returning an `ArtisanBuild\CrateContracts\Credential` with plaintext shown once.
- `GET /api/credentials` for `list()`, returning metadata without plaintext.
- `DELETE /api/credentials/{name}` for `revoke(...)`.

Requests use Bearer auth with the configured admin token, accept JSON, retry transient failures according to config, and throw on non-2xx responses.
