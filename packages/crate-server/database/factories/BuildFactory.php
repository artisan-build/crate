<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Database\Factories;

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Build>
 */
final class BuildFactory extends Factory
{
    protected $model = Build::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'served_repo_id' => null,
            'scope' => Build::SCOPE_FULL,
            'target_repo_id' => null,
            'target_repo_name' => null,
            'trigger' => 'manual',
            'status' => BuildStatus::Queued,
            'output' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function forServedRepo(ServedRepo $repo): self
    {
        return $this->state(fn (): array => [
            'served_repo_id' => $repo->getKey(),
            'scope' => Build::SCOPE_REPOSITORY,
            'target_repo_id' => $repo->getKey(),
            'target_repo_name' => $repo->name,
        ]);
    }
}
