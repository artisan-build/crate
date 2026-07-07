# Crate — Build Handover

**Self-hosted, unmetered private Composer registry for Laravel, on Laravel Cloud.**

Crate is a private package registry you **fork and deploy to your own Laravel Cloud account**. It
wraps [Satis](https://github.com/composer/satis) behind an authenticated gate: register the private
repos you want to serve, and any client holding a valid credential can `composer require` them. An
org that deploys Crate issues and revokes those credentials over an HTTP API and wraps whatever
payment or access logic it wants around them.

> Register a repo, issue a credential, `composer require`. No metering, no per-seat bill, no
> third-party registry holding your packages.

This document is the build spec. It is written to be handed to Claude Code and built straight
through. It mirrors the conventions already established in
[`artisan-build/hone`](https://github.com/artisan-build/hone),
[`artisan-build/matte`](https://github.com/artisan-build/matte), and
[`artisan-build/sink`](https://github.com/artisan-build/sink) — read those repos' READMEs first.
Crate is the same machine with a different payload: the payload is **Composer package metadata +
dist archives**, and the consumer is **`composer` itself**.

---

## Why Crate exists

Private Composer packages are usually delivered one of two ways: a metered hosted registry
([Private Packagist](https://packagist.com), [Anystack](https://anystack.sh)) — another vendor,
per-seat/per-package pricing, and someone else holding your packages — or a hand-rolled tangle of
VCS repositories + per-developer GitHub tokens that leaks access as teams change.

Crate is the self-hosted **floor**: a private registry on compute you own, billed only for the
Cloud resources it uses. Register the repos, gate them behind credentials you issue and revoke, and
point Composer at your own host.

**Positioning.** Private Packagist / Anystack are the full-featured, metered products — per-package
access control, mirroring of packagist.org, team management, the polished dashboard. Crate is the
floor for orgs that want a simple private registry on their own Cloud account and are willing to
gate access at the credential level rather than per package. Outgrow it → Private Packagist is the
upgrade. MIT licensed.

**Support posture.** Written for how Artisan Build uses it. Bugs get fixed; feature requests are a
fork away; client-specific features are not backfilled into the OSS release.

---

## Decisions (locked — do not re-litigate)

1. **Wrap Satis; do not reimplement Composer metadata.** Crate registers repos, generates a
   `satis.json` from its database, and shells out to Satis to produce the Composer v2 metadata and
   (mirrored) dist archives. Crate owns configuration, gating, and serving — Satis owns generation.
2. **Credential-level access, not per-package ACLs.** Access is all-or-nothing: a valid, unexpired
   credential grants `composer` access to **every** package the instance serves. There is no
   per-package or per-vendor scoping in v1. This is exactly the `built-for-cloud` token model
   (resolve-if-unexpired is the whole gate). "As simple as possible" is a product decision, not a
   gap to close later.
3. **Headless.** No app-specific web UI. Registry administration (add/remove served repos, trigger
   builds) is CLI + admin-token HTTP API; credential issue/revoke is HTTP API. The base stays
   `laravel-nodeless` for series consistency, but Crate ships **no Flux UI** and must **not** add
   `livewire/flux-pro` (free `livewire/flux` from the base is fine and unused) — so there is no Flux
   Pro CI-secret requirement.
4. **Issue/revoke lives in `built-for-cloud` (shared).** The HTTP credential-management surface is
   added to `built-for-cloud` over the existing `TokenRegistry`, so Sink/Matte inherit it. Crate
   consumes it; it does not re-implement token issuance.
5. **Served repos are DB-managed (API/CLI), not a committed `satis.json`.** The served-repo list
   lives in Postgres; add/remove with an Artisan command or an admin-token HTTP endpoint — no
   redeploy to add a package. Crate **generates** `satis.json` from the DB on each build. Each served
   repo carries its own encrypted source-read credential (GitHub token / deploy key).
6. **The gate covers metadata AND dist.** Composer fetches `packages.json` / `p2` provider files and
   then dist archive URLs; all of them are same-host routes through Crate and all are gated by the
   same credential check. Satis' `homepage` is set to the Crate host so mirrored dist URLs route back
   through the gate — never a public object-storage URL.
7. **Dist mirroring on, to object storage.** Satis runs with dist mirroring enabled so archives are
   served through the gate rather than Composer falling back to GitHub (which would require the
   customer to have GitHub access, defeating the purpose). Mirrored archives live on the Cloud object
   store; Crate streams them through the gated controller.
8. **Builds are incremental and queue-fit.** A single-repo change rebuilds just that package
   (`satis build … <package>`) so each job stays well under the managed-queue visibility timeout; a
   scheduled full rebuild reconciles periodically. Satis itself is installed as an **isolated tool**
   (its own dependency tree), invoked via Symfony Process — never `composer require`d into the app
   (its deps conflict).
9. **Two client surfaces in `crate-client`.** (a) A **consumer helper** that wires
   `auth.json` / `COMPOSER_AUTH` in a customer's Laravel app; (b) an **issuer SDK** an org pulls into
   its own app to call the built-for-cloud issue/revoke endpoint, around whatever payment/gating
   logic it wants. The issuer SDK is the point of the whole product.
10. **Storage/queue follow the series.** Postgres for the registry DB, Redis managed queue for
    builds, object storage for mirrored archives, all Cloud-injected (never set resource env vars
    yourself — see the laravel-cloud-deploy discipline).

---

## Architecture

```
  Org's own app (billing/gating)
    └─ crate-client (issuer SDK) ──► POST/DELETE /api/credentials  (admin-token auth)
                                          │  built-for-cloud ManageTokens over TokenRegistry
                                          ▼
                                    api_tokens (hashed)         ◄── credential resolves here
  Operator (CLI / admin API)
    └─ crate:repos:add vendor/pkg ──► served_repos (DB, encrypted source cred)
                                          │
                                          ▼
                                 generate satis.json ──► satis build (isolated tool, queued)
                                          │                    │ dist mirroring
                                          ▼                    ▼
                                 Composer metadata      dist archives ──► object storage
                                 (packages.json / p2)
                                          │
  Customer app (composer)                 ▼
    composer require vendor/pkg ──► GET /packages.json · /p2/… · /dist/…
       auth.json http-basic  ─────────────┤  GATE: http-basic password → TokenRegistry
       (password = credential)            │  valid+unexpired? → stream metadata/archive : 401
                                          ▼
                                    (served from object storage, gated)
```

- **One isolated environment per operator** on Laravel Cloud — its own compute, Postgres, Redis,
  object storage. Single-tenant by construction.

---

## Repository layout

Monorepo at `artisan-build/crate` (this repo), built out from the `laravel-nodeless` starter kit:

```
crate/                     slim app shell (headless; boots, gates, schedules builds)
  packages/
    crate-contracts/       shared DTOs + enums (served-repo, build status, credential response)
    crate-client/          consumer composer-auth helper + issuer SDK
    crate-server/          registry: served-repo model, satis generation/build, gated serving
  docs/crate-build-handover.md   (this file)
```

Released via `release.yml` on a `v*` tag → `php artisan kibble:split` to the read-only mirrors
`artisan-build/crate-{contracts,client,server}` (issues/projects/wiki already disabled).

`built-for-cloud` is a **separate repo** (`artisan-build/built-for-cloud`) consumed as a Composer
dependency; the credential HTTP endpoint (step 3) is a PR **there**, not in this monorepo.

---

## `crate-contracts` — the compatibility surface

Thin. Just enough that client and server agree without importing each other:

- `ServedRepo` DTO — `name` (vendor/package), `url`, `type` (`vcs`|`git`|`path`), `status`.
- `RepoStatus` enum — `pending`, `building`, `active`, `failed`, `disabled`.
- `BuildStatus` enum — `queued`, `running`, `succeeded`, `failed`.
- `Credential` DTO — the issue-response shape: `name`, `plaintext` (shown once), `expires_at`.

Additive-within-major discipline (no field removed/repurposed in a major); round-trip tests.

## `crate-client` — consumer helper + issuer SDK

**Consumer helper** (drop into a customer app that needs to pull private packages):
- Config `CRATE_URL`, `CRATE_TOKEN`.
- `Crate::composerAuth()` → returns/writes the `auth.json` `http-basic` fragment
  (`{ "<crate-host>": { "username": "token", "password": "<CRATE_TOKEN>" } }`) or sets
  `COMPOSER_AUTH`. A small Artisan command `crate:auth` writes it for CI/deploy environments.

**Issuer SDK** (drop into the org's own app — the product surface):
- `CrateIssuer` (config: base URL + an admin token) with:
  - `issue(string $name, ?CarbonInterface $expiresAt = null): Credential`
  - `revoke(string $name): void`
  - `list(): Collection<Credential-metadata>` (never returns plaintext)
- Thin HTTP client over the built-for-cloud endpoint (step 3). Typed, retryable, throws on non-2xx.
- No payment/billing logic here — that is the org's to build around these calls.

## `crate-server` — registry

- **Model + migration** `served_repos` (see Data model). Source credential stored **encrypted**.
- **Commands:** `crate:repos:add {name} {url} {--source-token=} {--type=vcs}`,
  `crate:repos:remove {name}`, `crate:repos:list`, `crate:build {package?}` (regenerate `satis.json`
  from DB, run Satis; optional single-package arg for incremental), `crate:install` (scaffold/provision).
- **Admin HTTP API** (admin-token auth via built-for-cloud): served-repo CRUD + `POST /api/build`.
- **Satis integration:** `SatisConfigGenerator` writes `satis.json` from `served_repos`
  (`require-dist-mirroring: true`, `archive` config, `homepage = CRATE_URL`, per-repo auth injected
  from the encrypted source creds into a scoped Composer `auth.json` for the build). `BuildSatis`
  queued job shells out to the isolated Satis tool via Symfony Process, writes output + mirrored
  archives to the object-storage disk, records a `builds` row.
- **Gated registry routes** (the whole point): `GET /packages.json`, `GET /p2/{vendor}/{package}.json`
  (+ `~dev` variants), `GET /dist/{path}` — all behind `EnsureValidCredential` middleware that reads
  the request's HTTP-Basic password, resolves it via `TokenRegistry`, and 401s on miss/expiry.
  Metadata + archives are streamed from the object-storage disk.
- **Rebuild triggers:** on served-repo mutation (dispatch incremental `BuildSatis`), plus a scheduled
  full rebuild (`crate:build` nightly). GitHub push webhook → rebuild is **deferred to v2**.

## `built-for-cloud` additions (separate PR in that repo)

- **Token abilities.** Add a nullable `abilities` (json) column to `api_tokens`. A token with the
  `admin` ability may manage tokens; tokens without it are registry-access-only. Default = no
  abilities (access only). Backfill-safe migration.
- **HTTP `ManageTokens` controller** over `TokenRegistry`, behind an `EnsureAdminToken` middleware
  (bearer/basic token that resolves AND carries the `admin` ability):
  - `POST /api/credentials` `{name, expires_at?}` → `201 {name, plaintext, expires_at}` (plaintext
    once; only the hash is persisted).
  - `DELETE /api/credentials/{name}` → `204` (revoke; records `revoked_at`).
  - `GET /api/credentials` → `200 [{name, last_used_at, expires_at, revoked_at}]` (no plaintext).
- Routes are opt-in (published/registered by the consuming app) so Sink/Matte are unaffected unless
  they mount them. Keep additive-within-major.

---

## Data model

**built-for-cloud** (existing `api_tokens`, hashed) — **+ `abilities` json nullable** (this build).

**crate-server**
- `served_repos`: `id`, `name` (unique, `vendor/package`), `url`, `type`, `source_credential`
  (encrypted, nullable for public), `status` (RepoStatus), `last_built_at` (nullable), timestamps.
- `builds`: `id`, `served_repo_id` (nullable = full build), `trigger` (`mutation`|`schedule`|`manual`),
  `status` (BuildStatus), `output` (text, tail of Satis output; **redact any injected source creds**),
  `started_at`, `finished_at`, timestamps.

No `null` columns where a row can simply omit the relationship; follow the repo's existing nullable
discipline.

---

## Configuration

App config only (never set Cloud-injected resource env — DB/QUEUE/CACHE/FILESYSTEM are injected):
- `CRATE_URL` — public base URL; becomes Satis `homepage` so dist URLs route back through the gate.
- `CRATE_ARCHIVE_DISK` — object-storage disk for Satis output + mirrored archives (default the
  Cloud-injected object store).
- `CRATE_SATIS_PATH` — path to the isolated Satis tool installed by the build step.
- `CRATE_ADMIN_TOKEN` — bootstrap admin credential for the issue/revoke API (or provision a
  built-for-cloud `admin`-ability token via `token:create`).
- Per-repo source creds live in the DB (encrypted), not env.

---

## Deploying on Laravel Cloud

```bash
# Build step (installs the isolated Satis tool + ensures git/composer present):
composer create-project composer/satis {CRATE_SATIS_PATH} --no-dev   # or a pinned release

# On the Crate environment (via built-for-cloud + laravel-cloud-deploy skill):
php artisan token:create ci --abilities=admin      # admin token for the issuer SDK
php artisan crate:repos:add acme/widget https://github.com/acme/widget.git --source-token=…
php artisan crate:build                            # first full build

# In the customer's Laravel app (via crate-client consumer helper):
#   composer config repositories.crate composer https://crate.example.com
#   CRATE_URL/CRATE_TOKEN → php artisan crate:auth   (writes http-basic auth.json)
#   composer require acme/widget
```

Provision Postgres + Redis (managed queue, scale-to-zero) + object storage per the
laravel-cloud-deploy discipline (no resource env vars set by hand). Ensure the build/queue runtime
has `git` and `composer` available for Satis.

---

## Build order (sequenced for the coordinator)

1. **Scaffold monorepo.** (Base nodeless app is seeded by the brain orchestrator.) Establish
   `packages/crate-{contracts,client,server}` with path repositories wired into the root app; add
   `.github/workflows/{tests.yml,lint.yml,release.yml}`; `composer ready` green at root. release.yml
   = `v*` tag → `kibble:split` to the three mirrors.
2. **crate-contracts.** DTOs + enums above, with round-trip tests.
3. **built-for-cloud additions** (PR in `artisan-build/built-for-cloud`): `abilities` column +
   `ManageTokens` HTTP controller + `EnsureAdminToken` middleware. Tag a release; bump the crate
   constraint.
4. **crate-server: served repos.** `served_repos` model/migration + `crate:repos:*` commands +
   admin-token HTTP CRUD. Encrypted source creds.
5. **crate-server: satis generation + build.** `SatisConfigGenerator` + `BuildSatis` job +
   `crate:build` (incremental + full) + dist mirroring to the object-storage disk + scheduled
   rebuild. Prove the isolated-Satis Process invocation on a Cloud worker early (the series'
   "does it run on Cloud" gate — low risk, pure PHP + git).
6. **crate-server: the gated registry routes.** `EnsureValidCredential` middleware (http-basic →
   TokenRegistry) + `GET /packages.json` `/p2/…` `/dist/…` streamed from object storage. End-to-end
   test: register a real private repo, issue a credential, `composer require` through the gate,
   revoke, confirm 401.
7. **crate-client.** Consumer composer-auth helper (`crate:auth`) + the issuer SDK (`CrateIssuer`)
   over the built-for-cloud endpoint.
8. **Cloud provisioning installer + docs.** `crate:install`, README (positioning mirrors the series),
   deploy guide, `crate-client` skill doc.

## Out of scope (v1 non-goals)

- Per-package / per-vendor access scoping (credential-level gate only — locked decision #2).
- A web admin UI (headless — decision #3).
- Mirroring packagist.org / proxying public packages.
- packagist.org-style search, download stats, team management, dashboards.
- Being feature-complete with Private Packagist / Anystack.

## Deferred (candidate v2)

- GitHub push **webhook → auto-rebuild** (v1 rebuilds on mutation + schedule).
- An **MCP** surface (list served packages, build status, credential audit) — the v1 consumer is
  `composer`, not an agent, so MCP is not on the critical path.
- Archive pruning of superseded dist versions; retention policy.
- Per-package scoping if a real client needs it.

## License

MIT.
