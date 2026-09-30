<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateServer\Contracts\RepositoryBuildMutex;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\ServedRepo;

final readonly class RemoveServedRepo
{
    public function __construct(private RepositoryBuildMutex $mutex) {}

    public function __invoke(string $name): bool
    {
        $repository = ServedRepo::query()->where('name', strtolower($name))->first();

        if (! $repository instanceof ServedRepo) {
            return false;
        }

        $deleted = (bool) $this->mutex->synchronized(
            (int) $repository->getKey(),
            static fn (): bool => ServedRepo::query()
                ->whereKey($repository->getKey())
                ->where('name', $repository->name)
                ->delete() === 1,
        );

        if ($deleted) {
            BuildSatis::dispatch(trigger: 'repository-removed')->afterCommit();
        }

        return $deleted;
    }
}
