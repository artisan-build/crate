<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;

final class SettleDeletedBuildTarget
{
    public function handle(Build $candidate): bool
    {
        return $candidate->getConnection()->transaction(function () use ($candidate): bool {
            $build = Build::query()->lockForUpdate()->find($candidate->getKey());

            if (! $build instanceof Build
                || $build->trigger !== 'mcp'
                || $build->scope !== Build::SCOPE_REPOSITORY
                || ! $this->isRecoverable($build)
                || $this->targetExists($build)) {
                return false;
            }

            $build->update([
                'status' => BuildStatus::TargetDeleted,
                'output' => 'The requested repository target was deleted.',
                'finished_at' => now(),
                'claim_token' => null,
                'lease_expires_at' => null,
            ]);

            return true;
        });
    }

    private function isRecoverable(Build $build): bool
    {
        return $build->status === BuildStatus::Queued
            || ($build->status === BuildStatus::Running && $build->lease_expires_at?->isPast());
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
