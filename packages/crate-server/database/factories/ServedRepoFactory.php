<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Database\Factories;

use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServedRepo>
 */
final class ServedRepoFactory extends Factory
{
    protected $model = ServedRepo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2).'/'.fake()->unique()->slug(2),
            'url' => fake()->url(),
            'type' => RepoType::Vcs,
            'source_credential' => null,
            'status' => RepoStatus::Pending,
            'last_built_at' => null,
        ];
    }
}
