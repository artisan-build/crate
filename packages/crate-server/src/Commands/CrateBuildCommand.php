<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;

final class CrateBuildCommand extends SystemAuthorityCommand
{
    protected $signature = 'crate:build {package?} {--trigger=manual}';

    protected $description = 'Dispatch a Satis build for the Crate registry.';

    public function handle(): int
    {
        $package = $this->argument('package');

        BuildSatis::dispatch(
            is_string($package) && $package !== '' ? $package : null,
            (string) $this->option('trigger'),
        );

        $this->info('Dispatched Crate Satis build.');

        return self::SUCCESS;
    }
}
