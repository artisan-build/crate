<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Actions\AddServedRepo;
use InvalidArgumentException;

final class CrateReposAddCommand extends SystemAuthorityCommand
{
    protected $signature = 'crate:repos:add {name} {url} {--source-token=} {--type=vcs}';

    protected $description = 'Add a served repository to the Crate registry.';

    public function handle(AddServedRepo $add): int
    {
        $name = strtolower((string) $this->argument('name'));
        $url = (string) $this->argument('url');
        $typeValue = (string) $this->option('type');

        try {
            $add($name, $url, RepoType::tryFrom($typeValue) ?? $typeValue, $this->sourceCredential());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Added repository [{$name}].");

        return self::SUCCESS;
    }

    private function sourceCredential(): ?string
    {
        $credential = $this->option('source-token');

        return is_string($credential) && $credential !== '' ? $credential : null;
    }
}
