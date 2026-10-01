<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Models\Build;
use Symfony\Component\Process\Process;

it('proves concurrent PostgreSQL exact replay and changed reuse with a durable outbox winner', function (): void {
    $worker = __DIR__.'/Fixtures/PostgresTriggerWorker.php';
    $exactFingerprint = hash('sha256', '{"repository_name":null}');

    try {
        [$first, $second] = runConcurrentPostgresTriggers($worker, 'postgres-exact', $exactFingerprint, $exactFingerprint);
        $exactRows = Build::query()->where('idempotency_key', 'postgres-exact')->get();

        expect($first['build_id'])->toBe($second['build_id'])
            ->and(collect([$first, $second])->where('duplicate', false))->toHaveCount(1)
            ->and(collect([$first, $second])->where('duplicate', true))->toHaveCount(1)
            ->and($exactRows)->toHaveCount(1)
            ->and($exactRows->first()?->status)->toBe(BuildStatus::Queued)
            ->and($exactRows->first()?->claim_token)->toBeNull();

        $changedFingerprint = hash('sha256', '{"repository_name":"vendor/changed"}');
        [$firstChanged, $secondChanged] = runConcurrentPostgresTriggers($worker, 'postgres-changed', $exactFingerprint, $changedFingerprint);
        $changedRows = Build::query()->where('idempotency_key', 'postgres-changed')->get();

        expect(collect([$firstChanged, $secondChanged])->where('conflict', false))->toHaveCount(1)
            ->and(collect([$firstChanged, $secondChanged])->where('conflict', true))->toHaveCount(1)
            ->and($firstChanged['build_id'])->toBe($secondChanged['build_id'])
            ->and($changedRows)->toHaveCount(1)
            ->and($changedRows->first()?->status)->toBe(BuildStatus::Queued);
    } finally {
        Build::query()->where('requested_by', 'postgres-process')->delete();
    }
})->skip(getenv('CRATE_POSTGRES_TEST') !== '1', 'Set CRATE_POSTGRES_TEST=1 to run the PostgreSQL concurrency proof.');

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
function runConcurrentPostgresTriggers(string $worker, string $key, string $firstFingerprint, string $secondFingerprint): array
{
    $barrier = sys_get_temp_dir().'/crate-postgres-barrier-'.bin2hex(random_bytes(8));
    $environment = array_filter([
        'CRATE_DB_HOST' => getenv('CRATE_DB_HOST') ?: '127.0.0.1',
        'CRATE_DB_PORT' => getenv('CRATE_DB_PORT') ?: '5432',
        'CRATE_DB_DATABASE' => getenv('CRATE_DB_DATABASE') ?: 'crate_mcp_test',
        'CRATE_DB_USERNAME' => getenv('CRATE_DB_USERNAME') ?: 'root',
        'CRATE_DB_PASSWORD' => getenv('CRATE_DB_PASSWORD') ?: null,
        'CRATE_PG_BARRIER' => $barrier,
    ], static fn (?string $value): bool => $value !== null);
    $first = new Process([PHP_BINARY, $worker, $key, $firstFingerprint], env: $environment);
    $second = new Process([PHP_BINARY, $worker, $key, $secondFingerprint], env: $environment);

    $first->start();
    $second->start();
    touch($barrier);
    $first->wait();
    $second->wait();
    @unlink($barrier);

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

    return [
        json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR),
    ];
}
