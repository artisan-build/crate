<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Models\Build;
use Illuminate\Database\UniqueConstraintViolationException;

final class CreateOrReplayMcpBuild
{
    /** @return array{build: Build, created: bool} */
    public function handle(
        string $requestedBy,
        string $idempotencyKey,
        string $fingerprint,
        ?int $servedRepoId,
    ): array {
        try {
            $build = (new Build)->getConnection()->transaction(fn (): Build => Build::query()->create([
                'served_repo_id' => $servedRepoId,
                'trigger' => 'mcp',
                'status' => BuildStatus::Queued,
                'requested_by' => $requestedBy,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
            ]));

            return ['build' => $build, 'created' => true];
        } catch (UniqueConstraintViolationException) {
            return [
                'build' => Build::query()
                    ->where('requested_by', $requestedBy)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail(),
                'created' => false,
            ];
        }
    }
}
