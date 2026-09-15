# Crate Client

Client-side helpers for Crate.

See the [default integration guide](docs/integrate/default.md) for agent-ready installation, configuration, API, and verification steps.

This package has two surfaces:

- Consumer auth helper: write Composer HTTP Basic auth for a Crate registry.
- Issuer SDK: call a Crate deployment's unified `/bfc/credentials` endpoint with an operator service credential.

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
CRATE_SERVICE_TOKEN=operator-service-credential
CRATE_ISSUER_SUBJECT_REF=build-bot
CRATE_ISSUER_RETRIES=2
CRATE_ISSUER_RETRY_SLEEP=100
```

`CRATE_ISSUER_URL` defaults to `CRATE_URL` when omitted.

Example:

```php
use ArtisanBuild\CrateClient\CrateIssuer;

$issuer = CrateIssuer::fromConfig();

$issued = $issuer->issue('build-bot');
$credentials = $issuer->list();
$rotated = $issuer->rotate($issued['credential']['id']);
$issuer->revoke($rotated['credential']['id']);
```

The SDK calls:

- `POST /bfc/credentials` for `issue(...)`, fixed to installation-owned Basic `consumption` credentials.
- `GET /bfc/credentials` for `list()`, returning summaries without secret material.
- `POST /bfc/credentials/{id}/rotate` for `rotate(...)`, returning replacement delivery once.
- `DELETE /bfc/credentials/{id}` for `revoke(...)`.

Requests use Bearer auth with `CRATE_SERVICE_TOKEN`, accept JSON, carry bfc-client's canonical client identity and contract-version headers, retry according to config, and throw on non-2xx responses. The service credential needs the matching closed credential verb abilities. Client identity is metadata, not authorization.
