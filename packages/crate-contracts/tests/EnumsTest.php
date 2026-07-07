<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateContracts\RepoType;

it('defines repo status values', function (): void {
    expect(RepoStatus::cases())->toEqual([
        RepoStatus::Pending,
        RepoStatus::Building,
        RepoStatus::Active,
        RepoStatus::Failed,
        RepoStatus::Disabled,
    ])
        ->and(array_map(fn (RepoStatus $status): string => $status->value, RepoStatus::cases()))->toBe([
            'pending',
            'building',
            'active',
            'failed',
            'disabled',
        ]);
});

it('defines build status values', function (): void {
    expect(BuildStatus::cases())->toEqual([
        BuildStatus::Queued,
        BuildStatus::Running,
        BuildStatus::Succeeded,
        BuildStatus::Failed,
    ])
        ->and(array_map(fn (BuildStatus $status): string => $status->value, BuildStatus::cases()))->toBe([
            'queued',
            'running',
            'succeeded',
            'failed',
        ]);
});

it('defines repo type values', function (): void {
    expect(RepoType::cases())->toEqual([
        RepoType::Vcs,
        RepoType::Git,
        RepoType::Path,
    ])
        ->and(array_map(fn (RepoType $type): string => $type->value, RepoType::cases()))->toBe([
            'vcs',
            'git',
            'path',
        ]);
});
