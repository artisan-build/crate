<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Console\Command;

final class CrateReposListCommand extends Command
{
    protected $signature = 'crate:repos:list';

    protected $description = 'List served repositories in the Crate registry.';

    public function handle(): int
    {
        $rows = ServedRepo::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ServedRepo $repo): array => [
                'name' => $repo->name,
                'url' => $repo->url,
                'type' => $repo->type->value,
                'status' => $repo->status->value,
                'last_built_at' => $repo->last_built_at?->toDateTimeString(),
            ])
            ->all();

        $this->table(['name', 'url', 'type', 'status', 'last_built_at'], $rows);

        return self::SUCCESS;
    }
}
