<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateServer\Contracts\BuildDispatcher;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\Build;
use Illuminate\Support\Facades\Bus;

final class QueueBuildDispatcher implements BuildDispatcher
{
    public function dispatch(Build $build): void
    {
        Bus::dispatch(new BuildSatis(
            trigger: 'mcp',
            buildId: (int) $build->getKey(),
            servedRepoId: $build->served_repo_id === null ? null : (int) $build->served_repo_id,
        ));
    }
}
