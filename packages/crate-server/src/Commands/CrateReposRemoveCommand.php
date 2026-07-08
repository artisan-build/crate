<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Console\Command;

final class CrateReposRemoveCommand extends Command
{
    protected $signature = 'crate:repos:remove {name}';

    protected $description = 'Remove a served repository from the Crate registry.';

    public function handle(): int
    {
        $name = strtolower((string) $this->argument('name'));
        $deleted = ServedRepo::query()->where('name', $name)->delete();

        if ($deleted === 0) {
            $this->error("The repository [{$name}] does not exist.");

            return self::FAILURE;
        }

        $this->info("Removed repository [{$name}].");

        return self::SUCCESS;
    }
}
