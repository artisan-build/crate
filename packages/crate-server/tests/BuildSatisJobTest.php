<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use ArtisanBuild\CrateServer\SatisConfigGenerator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

it('records a succeeded full build and mirrors satis output', function (): void {
    Storage::fake('crate-archive');
    ServedRepo::factory()->create(['name' => 'vendor/package']);

    Process::fake(function (PendingProcess $process) {
        File::put($process->path.'/output/packages.json', '{"packages":[]}');
        File::ensureDirectoryExists($process->path.'/output/dist/vendor/package');
        File::put($process->path.'/output/dist/vendor/package/archive.zip', 'zip-bytes');

        return Process::result('satis built');
    });

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));

    $build = Build::query()->firstOrFail();

    expect($build->status)->toBe(BuildStatus::Succeeded)
        ->and($build->served_repo_id)->toBeNull()
        ->and($build->started_at)->not->toBeNull()
        ->and($build->finished_at)->not->toBeNull()
        ->and($build->output)->toContain('satis built');

    Storage::disk('crate-archive')->assertExists('satis/packages.json');
    Storage::disk('crate-archive')->assertExists('satis/dist/vendor/package/archive.zip');

    Process::assertRan(fn (PendingProcess $process): bool => $process->path !== null);
});

it('records an incremental build against the served repo', function (): void {
    Storage::fake('crate-archive');
    $repo = ServedRepo::factory()->create(['name' => 'vendor/package']);

    Process::fake([Process::result('satis built')]);

    (new BuildSatis('vendor/package', 'manual'))->handle(app(SatisConfigGenerator::class));

    $build = Build::query()->firstOrFail();

    expect($build->status)->toBe(BuildStatus::Succeeded)
        ->and($build->served_repo_id)->toBe($repo->getKey());
});

it('records a failed build for a failed process result', function (): void {
    Storage::fake('crate-archive');
    ServedRepo::factory()->create(['name' => 'vendor/package']);

    Process::fake([Process::result('satis failed', '', 1)]);

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));

    $build = Build::query()->firstOrFail();

    expect($build->status)->toBe(BuildStatus::Failed)
        ->and($build->finished_at)->not->toBeNull()
        ->and($build->output)->toContain('satis failed');
});

it('redacts source credentials before persisting process output', function (): void {
    Storage::fake('crate-archive');
    ServedRepo::factory()->create([
        'name' => 'vendor/package',
        'source_credential' => 'ghp_secretvalue',
    ]);

    Process::fake([Process::result('failed with token ghp_secretvalue', '', 1)]);

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));

    $build = Build::query()->firstOrFail();

    expect($build->output)->not->toContain('ghp_secretvalue')
        ->and($build->output)->toContain('***');
});
