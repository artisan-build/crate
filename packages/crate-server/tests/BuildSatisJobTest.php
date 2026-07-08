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

it('seeds incremental builds from the existing archive output before mirroring', function (): void {
    Storage::fake('crate-archive');
    Storage::disk('crate-archive')->put('satis/packages.json', json_encode([
        'packages' => [
            'other/pkg' => [],
        ],
    ], JSON_THROW_ON_ERROR));
    ServedRepo::factory()->create(['name' => 'vendor/package']);

    Process::fake(function (PendingProcess $process) {
        $path = $process->path.'/output/packages.json';
        $packages = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        $packages['packages']['vendor/package'] = [];

        File::put($path, json_encode($packages, JSON_THROW_ON_ERROR));

        return Process::result('satis built');
    });

    (new BuildSatis('vendor/package'))->handle(app(SatisConfigGenerator::class));

    $packages = json_decode(Storage::disk('crate-archive')->get('satis/packages.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($packages['packages'])->toHaveKeys(['other/pkg', 'vendor/package']);
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

it('deletes temporary auth json after successful and failed builds', function (int $exitCode): void {
    Storage::fake('crate-archive');
    ServedRepo::factory()->create([
        'name' => 'vendor/package',
        'source_credential' => 'ghp_secretvalue',
    ]);
    $authPath = null;

    Process::fake(function (PendingProcess $process) use (&$authPath, $exitCode) {
        $authPath = $process->path.'/auth.json';

        expect(File::get($authPath))->toContain('ghp_secretvalue');

        return Process::result('satis output', '', $exitCode);
    });

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));

    expect($authPath)->toBeString()
        ->and(File::exists($authPath))->toBeFalse();
})->with([0, 1]);
