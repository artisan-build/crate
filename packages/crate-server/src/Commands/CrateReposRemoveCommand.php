<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\CrateServer\Actions\RemoveServedRepo;

final class CrateReposRemoveCommand extends SystemAuthorityCommand
{
    protected $signature = 'crate:repos:remove {name}';

    protected $description = 'Remove a served repository from the Crate registry.';

    public function handle(RemoveServedRepo $remove): int
    {
        $name = strtolower((string) $this->argument('name'));
        if (! $remove($name)) {
            $this->error("The repository [{$name}] does not exist.");

            return self::FAILURE;
        }

        $this->info("Removed repository [{$name}].");

        return self::SUCCESS;
    }
}
