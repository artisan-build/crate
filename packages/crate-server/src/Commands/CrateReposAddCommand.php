<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Console\Command;

final class CrateReposAddCommand extends Command
{
    protected $signature = 'crate:repos:add {name} {url} {--source-token=} {--type=vcs}';

    protected $description = 'Add a served repository to the Crate registry.';

    public function handle(): int
    {
        $name = strtolower((string) $this->argument('name'));
        $url = (string) $this->argument('url');
        $typeValue = (string) $this->option('type');

        if (! preg_match('/^[a-z0-9]([a-z0-9._-]*)\/[a-z0-9]([a-z0-9._-]*)$/', $name)) {
            $this->error('The repository name must match vendor/package using lowercase letters, numbers, dots, underscores, or hyphens.');

            return self::FAILURE;
        }

        $type = RepoType::tryFrom($typeValue);

        if (! $type instanceof RepoType) {
            $this->error("The repository type [{$typeValue}] is invalid.");

            return self::FAILURE;
        }

        if (ServedRepo::query()->where('name', $name)->exists()) {
            $this->error("The repository [{$name}] already exists.");

            return self::FAILURE;
        }

        ServedRepo::query()->create([
            'name' => $name,
            'url' => $url,
            'type' => $type,
            'source_credential' => $this->option('source-token'),
            'status' => RepoStatus::Pending,
        ]);

        $this->info("Added repository [{$name}].");

        return self::SUCCESS;
    }
}
