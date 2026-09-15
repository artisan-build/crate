# Crate C1 live verification

This coordinator-run harness exercises the C1-24 product flow from a committed Crate checkout in a disposable copy. It uses PostgreSQL, shared Redis cache/session/queue state, private MinIO/S3 storage, log-only mail, a local Scalpels authority stub, two HTTP nodes, a real queue worker and scheduler invocation, real git/Satis, and a disposable Composer consumer.

The harness never targets a current installation, Laravel Cloud, an external Scalpels service, or real credentials. The installation boundary is the installation-local credential store; installation credential `subject_ref` values are automation routing identities, not installation IDs. State-changing Built for Cloud commands must be invoked through `bfc_artisan`, which rejects calls missing `--local`.

## Prerequisites

- PHP and Composer compatible with the committed lock file
- `git`, `curl`, `psql`, and `redis-cli`
- Disposable PostgreSQL, Redis, and MinIO endpoints reachable from the host
- An empty, disposable MinIO access key pair; never provide production credentials

The coordinator may use the pre-started local services on ports 32790, 32791, and 32792:

```bash
CRATE_C1_PG_PORT=32790 \
CRATE_C1_PG_USER=crate_c1 \
CRATE_C1_PG_PASSWORD=crate_c1 \
CRATE_C1_REDIS_PORT=32791 \
CRATE_C1_S3_PORT=32792 \
CRATE_C1_S3_ACCESS_KEY=cratec1access \
CRATE_C1_S3_SECRET_KEY=cratec1secretkey \
tests/live/CrateC1/run.sh
```

These credentials are the known disposable `crate-c1-live-*` service credentials, not production defaults. All coordinates are overrideable with `CRATE_C1_*` variables documented by `tests/live/CrateC1/run.sh --help`. The script refuses non-loopback hosts unless `CRATE_C1_ALLOW_NON_LOOPBACK=1` is explicitly set. It creates a unique database, Redis key prefix, bucket, working tree archive, authority state file, fixture git repository, and Composer consumer. Cleanup removes only those exact resources.

## Verdicts

Successful observations are emitted as `PASS <name>`. An exact contract case that cannot be exercised through the released public host hooks is emitted as `VERIFIER_BLOCKED <name> <reason>` and is not counted as a pass. Any unexpected status, any denial returning 5xx, leaked fixture secret, or domain mutation after denial fails the run.

The freshness cases are named individually:

- `freshness-4m59-cached`
- `freshness-5m00-shared-refresh`
- `freshness-29m59-transient-grace`
- `freshness-30m00-denial-session-end`
- `freshness-explicit-removal`
- `freshness-stale-response-ordering`

Where the public package surface cannot deterministically set authority time or order responses, the corresponding case remains `VERIFIER_BLOCKED`; the harness does not fake a host-owned freshness boundary.

The installed Built for Cloud managed-authority fixture serves real TLS and monotonic confirmation responses. Crate drives the 4:59, 5:00, 29:59, 30:00, and explicit-removal cases through real HTTP nodes against shared PostgreSQL/Redis state. The fixture is intentionally serial and cannot release an older confirmation after a newer response, so `freshness-stale-response-ordering` is reported as `VERIFIER_BLOCKED` rather than promoted from package-level evidence.

## Safety

The shell runs with `set -euo pipefail` and never enables command tracing. Fixture secrets are generated for the run, are never printed, and are checked against retained build output and logs before cleanup. Set `CRATE_C1_KEEP=1` only when investigating a failed disposable run; the final summary then prints the non-secret workspace path and exact disposable resource names.

The implementer does not run this harness. The coordinator runs it from a clean committed candidate and retains the emitted PASS/VERIFIER_BLOCKED stamp.
