<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Contracts\RepositoryBuildMutex;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;

final readonly class SettleDeletedBuildTarget
{
    public function __construct(private RepositoryBuildMutex $mutex) {}

    public function handle(Build $candidate): Build
    {
        $build = $this->freshBuild($candidate);

        if ($build->scope !== Build::SCOPE_REPOSITORY
            || $build->target_repo_id === null
            || ! in_array($build->status, [BuildStatus::Queued, BuildStatus::Running], true)
            || $this->targetExists($build)) {
            return $build;
        }

        return $this->mutex->synchronized(
            (int) $build->target_repo_id,
            fn (): Build => $this->settle($build),
        );
    }

    private function freshBuild(Build $candidate): Build
    {
        return $candidate->getConnection()->transaction(
            fn (): Build => Build::query()->lockForUpdate()->findOrFail($candidate->getKey()),
        );
    }

    private function settle(Build $candidate): Build
    {
        return $candidate->getConnection()->transaction(function () use ($candidate): Build {
            $build = Build::query()->lockForUpdate()->findOrFail($candidate->getKey());

            if ($build->trigger !== 'mcp'
                || $build->scope !== Build::SCOPE_REPOSITORY
                || ! in_array($build->status, [BuildStatus::Queued, BuildStatus::Running], true)
                || $this->targetExists($build)) {
                return $build;
            }

            $build->update([
                'status' => BuildStatus::TargetDeleted,
                'output' => 'The requested repository target was deleted.',
                'finished_at' => now(),
                'claim_token' => null,
                'lease_expires_at' => null,
            ]);

            return $build;
        });
    }

    private function targetExists(Build $build): bool
    {
        return $build->target_repo_id !== null
            && is_string($build->target_repo_name)
            && ServedRepo::query()
                ->whereKey($build->target_repo_id)
                ->where('name', $build->target_repo_name)
                ->exists();
    }
}
