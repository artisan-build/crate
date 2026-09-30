<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Contracts\BuildDispatcher;
use ArtisanBuild\CrateServer\Models\Build;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final readonly class RecoverBuildDispatches
{
    public function __construct(private BuildDispatcher $dispatcher) {}

    public function handle(): int
    {
        $failures = 0;

        Build::query()
            ->where('trigger', 'mcp')
            ->where(function (Builder $query): void {
                $query->where('status', BuildStatus::Queued)
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', BuildStatus::Running)
                            ->where('lease_expires_at', '<=', now());
                    });
            })
            ->orderBy('id')
            ->eachById(function (Build $build) use (&$failures): void {
                try {
                    $this->dispatcher->dispatch($build);
                } catch (Throwable $throwable) {
                    report($throwable);
                    $failures++;
                }
            });

        return $failures;
    }
}
