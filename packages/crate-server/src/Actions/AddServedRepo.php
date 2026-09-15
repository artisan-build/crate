<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use InvalidArgumentException;

final class AddServedRepo
{
    public function __invoke(string $name, string $url, RepoType|string $type, ?string $sourceCredential = null): ServedRepo
    {
        $name = strtolower($name);

        if (preg_match('/^[a-z0-9]([a-z0-9._-]*)\/[a-z0-9]([a-z0-9._-]*)$/', $name) !== 1) {
            throw new InvalidArgumentException('The repository name must match vendor/package using lowercase letters, numbers, dots, underscores, or hyphens.');
        }

        $type = $type instanceof RepoType ? $type : RepoType::tryFrom($type);

        if (! $type instanceof RepoType) {
            throw new InvalidArgumentException('The repository type is invalid.');
        }

        if (ServedRepo::query()->where('name', $name)->exists()) {
            throw new InvalidArgumentException("The repository [{$name}] already exists.");
        }

        $repo = ServedRepo::query()->create([
            'name' => $name,
            'url' => $url,
            'type' => $type,
            'source_credential' => $sourceCredential,
            'status' => RepoStatus::Pending,
        ]);

        BuildSatis::dispatch($name, 'repository-added')->afterCommit();

        return $repo;
    }
}
