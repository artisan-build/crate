# Crate Contracts

Framework-free value objects shared by Crate's client and server packages.

This package keeps the wire shapes stable without making either side depend on the other implementation package.

## DTOs

- `ArtisanBuild\CrateContracts\ServedRepo`: `name`, `url`, `type`, and `status` for a repository served by Crate.

The repository DTO supports:

- `make(...)`
- `toArray()`
- `fromArray(...)`
- `toJson()`
- `fromJson(...)`

Invalid payloads throw package exceptions instead of silently accepting malformed data.

Credential wire shapes are owned by `artisan-build/built-for-cloud`'s unified credential store. Crate does not define a second credential DTO or lifecycle. Unified summaries carry stable IDs, kind, purpose, subject, lifecycle, rotation, and presentation metadata; issue and rotation responses carry reveal-once delivery separately.

## Enums

- `RepoStatus`: `pending`, `building`, `active`, `failed`, `disabled`.
- `BuildStatus`: `queued`, `running`, `succeeded`, `failed`.
- `RepoType`: `vcs`, `git`, `path`.

## Compatibility

Contracts are additive within a major version. Existing fields are not removed or repurposed inside the same major line, so client and server releases can round-trip known payloads safely.
