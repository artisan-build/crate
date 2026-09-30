<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Contracts;

use ArtisanBuild\CrateServer\Models\Build;

interface BuildDispatcher
{
    public function dispatch(Build $build): void;
}
