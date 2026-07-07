# Workflow — crate

Project profile for the `multi-agent-build` skill. The coordinator agent reads this FIRST.

crate is a **monorepo** in the "Built for Cloud" series (mirrors `artisan-build/hone`, `matte`,
`sink` — read their READMEs first). It is a **fork-and-deploy private Composer registry**: a thin
Laravel wrapper around **Satis** that serves private packages from your own Laravel Cloud account,
with **API-issued / revocable credentials** and no metering. The upgrade path is Private Packagist /
Anystack; crate is the self-hosted floor.

Built out from the `artisan-build/laravel-nodeless` starter kit: a slim Crate app at the root +
(target) `packages/crate-contracts`, `packages/crate-client`, `packages/crate-server`. The
authoritative build spec lives at `docs/crate-build-handover.md` (to be written before the build).

## Product shape (load-bearing constraints — RESOLVED with Ed 2026-07-08)
- **Headless.** No app-specific web UI / Flux surface. Credential admin is via the Cloud CLI
  (built-for-cloud's `token:*` commands); the org drives issue/revoke via the issuer SDK. Base stays
  `laravel-nodeless` for series consistency, but nodeless requires only **free Flux** (`livewire/flux`,
  public) — **NOT flux-pro** — so there is **no Flux Pro CI-secret blocker**.
- **Credentials, not package ACLs.** Access is all-or-nothing: a valid, unexpired token = access to
  every package the instance serves. No per-package scoping. This is exactly the `built-for-cloud`
  token model (resolve-if-unexpired is the whole gate) exposed over an HTTP issue/revoke endpoint.
- **Issue/revoke HTTP endpoint lives in `built-for-cloud` (shared).** A reusable HTTP surface over the
  existing `TokenRegistry`, so sink/matte get it too. crate consumes it; it does not re-implement it.
- **Served repos are DB-managed (API/CLI), not a committed satis.json.** The served-repo list lives in
  the DB; add/remove via an Artisan command and/or an admin-token HTTP endpoint — no redeploy to add a
  package. crate **generates** `satis.json` from the DB, then runs `satis build`. Each served repo
  carries its source-read credential (GitHub token / deploy key). The API issues **credentials**,
  never packages.
- **Satis is a static generator.** Adding a served repo, a package, or a new version = regenerate
  `satis.json` from the DB and re-run `satis build` (on-demand after a mutation + scheduled + optional
  webhook). The deployed runtime must run `satis build` (php + composer + git + source creds).
- **The auth gate is the real work.** Middleware validates the presented Composer HTTP-Basic
  credential against the token registry, in front of two routes: `packages.json` (metadata) and the
  **dist archives** (zips — turn on Satis dist-mirroring to object storage; serve gated, streamed or
  signed-URL). Not feature-complete with Private Packagist by design.
- **Two client surfaces in `crate-client`:** (a) consumer helper to wire `auth.json` /
  `COMPOSER_AUTH` in a customer app, and (b) the **issuer SDK** an org pulls into its own Laravel app
  to call the built-for-cloud issue/revoke endpoint around whatever payment/gating logic it wants.

## Phase & mode
- phase: pre-launch (greenfield)
- default mode: A-autonomous
- merge_policy: **merge when CI is green; no human PR code review** (gate on green)
- merge method: `gh pr merge --squash --auto`

## Hard gate (must be green before review; coordinator verifies on the committed SHA, clean tree)
- command: `composer ready` at the repo root (ide-helper → rector → pint → phpstan → full Pest →
  `composer audit`).
- extra suites: once packages exist, for EACH touched `packages/crate-<pkg>`: its own
  `composer lint:test` / `composer test`. The root app gate always runs even for package-only PRs
  (the app autoloads packages via path repository and must boot).
- monorepo: yes (target) — packages: crate-contracts, crate-client, crate-server (+ slim root app).
  Until PR #1 establishes the monorepo, the gate is the root `composer ready` only.

## CI (the merge gate for Mode A)
- minimum bar: **testing + static analysis** — both required before Mode A is allowed to merge.
- expected workflows (PR #1 establishes these, mirroring sink/matte):
  - `tests.yml` (push + PR to main): `composer stan` (PHPStan) + Pest, PHP 8.4 + 8.5 matrix.
  - `lint.yml` (push + PR to main): `composer lint` (Pint).
  - `release.yml` (`v*` tag → `php artisan kibble:split` to the three read-only package mirrors
    `artisan-build/crate-{contracts,client,server}`).
- NO Flux Pro CI secret needed: crate is headless and its base (`laravel-nodeless`) requires only free
  `livewire/flux` (public). Do NOT add `livewire/flux-pro` — doing so would (re)introduce the
  `secrets.FLUX_USERNAME` / `secrets.FLUX_LICENSE_KEY` CI blocker for no UI benefit.

## Dependency install (fresh worktree)
- command: `composer install --no-interaction` at the root AND inside every touched
  `packages/crate-<pkg>`.
- post-install: copy `.env` from `.env.example`, `php artisan key:generate`,
  `touch database/database.sqlite`, `php artisan migrate --graceful`.
- if Flux Pro is used: `composer config http-basic.composer.fluxui.dev <user> <key>` (or global
  `auth.json`) must be present or `composer install` fails.
- NEVER symlink or `cp -R` `vendor/` (root or package) — Composer resolves the wrong checkout and
  produces phantom framework-boot/test failures. Real install only.

## Concurrency
- ≥2 concurrent CODE agents MUST each get their own git worktree (shared checkout commingles
  changes / fights over HEAD). One worktree per PR.

## Harness map (role → runtime; decorrelate by ROLE/FRAMING, not model lineage)
- Only **Claude (agent_tool_id 3)** and **OpenCode (agent_tool_id 2)** run reliably in this Solo env.
- implementer: OpenCode (`agent_tool_id 2`) — persistent agent in the PR worktree (`extra_args` sets cwd).
- quality reviewer: Claude (`agent_tool_id 3`), one-shot, ADVERSARIAL — "find what's wrong; default to reject."
- acceptance judge: Claude (`agent_tool_id 3`) — judges vs the PR's acceptance criteria; reads REAL
  `composer ready` / test output, not the implementer's claims.

## Ship details
- branch naming: `feat/<slug>`
- PR target repo: `artisan-build/crate` (branch `main`)
- release / split: `release.yml` on `v*` tag once PR #1 adds it. `crate-{contracts,client,server}`
  are read-only mirrors (issues/projects/wiki already disabled).
- repo visibility: **private during build; flips public at launch** (Ed's call).

## Plan & coordination
- plan location: `docs/crate-build-handover.md` (to be written and locked before the build).
- Solo project: crate (id resolved by name at spawn — ids change on re-add).
- needs: built-for-cloud (consumed for tokens, create-admin, invitations, UI auth gate, cloud
  provisioning installer; the credential issue/revoke HTTP endpoint likely ADDS to that repo).
- run-log: a dedicated append-only Solo scratchpad per the coordinator; brain mirrors outcomes to
  `brain/projects/crate/log.md`.

## Stack notes / quirks (inherited from the series)
- Base kit = `laravel-nodeless`: Laravel + Livewire 4 + Flux 2 + Fortify, NO Node/npm/Vite. Prebuilt
  Tailwind/Flux assets are checked into `public/build` — do not add a JS build step.
- `composer ready` runs ide-helper FIRST so phpstan resolves types after schema changes; a stale
  committed `_ide_helper_models.php` gives FALSE phpstan errors — regenerate + commit it in the PR.
- The deployed runtime must be able to run `satis build` (php + composer + git + a source-read
  GitHub token) — a scheduled command / build step, not request-path. Validate this runs on a
  Laravel Cloud worker early (the series' "does it run on Cloud" gate — low risk here, pure PHP).
- crate sits on an auth path serving package artifacts — security is load-bearing: Basic-auth gate
  on the registry routes, no credential/token leakage into retained output, dist served only through
  the gate (never a public object-storage URL).
