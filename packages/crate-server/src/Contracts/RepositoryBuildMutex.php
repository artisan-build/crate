<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Contracts;

use Closure;

interface RepositoryBuildMutex
{
    public function synchronized(int $repositoryId, Closure $callback): mixed;
}
