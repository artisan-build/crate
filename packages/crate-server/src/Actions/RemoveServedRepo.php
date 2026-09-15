<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\ServedRepo;

final class RemoveServedRepo
{
    public function __invoke(string $name): bool
    {
        $deleted = ServedRepo::query()->where('name', strtolower($name))->delete();

        if ($deleted === 1) {
            BuildSatis::dispatch(trigger: 'repository-removed')->afterCommit();
        }

        return $deleted === 1;
    }
}
