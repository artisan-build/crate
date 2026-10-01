<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateServer\Contracts\RepositoryBuildMutex;
use Closure;
use Illuminate\Support\Facades\Cache;

final class CacheRepositoryBuildMutex implements RepositoryBuildMutex
{
    public const int LOCK_SECONDS = 3600;

    public function synchronized(int $repositoryId, Closure $callback): mixed
    {
        return Cache::lock('crate-satis-repository:'.$repositoryId, self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, $callback);
    }
}
