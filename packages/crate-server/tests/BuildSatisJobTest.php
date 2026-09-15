<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use ArtisanBuild\CrateServer\SatisConfigGenerator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('records a succeeded full build and mirrors satis output', function (): void {
    Storage::fake('crate-archive');
    $repo = ServedRepo::factory()->create(['name' => 'vendor/package']);

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
        ->and($build->output)->toContain('satis built')
        ->and($repo->refresh()->status)->toBe(RepoStatus::Active)
        ->and($repo->last_built_at)->not->toBeNull();

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

it('prunes objects omitted by a successful full build', function (): void {
    Storage::fake('crate-archive');
    Storage::disk('crate-archive')->put('satis/packages.json', '{"packages":{"removed/package":[]}}');
    Storage::disk('crate-archive')->put('satis/p2/removed/package.json', 'stale-provider');
    Storage::disk('crate-archive')->put('satis/dist/removed/package/archive.zip', 'stale-archive');
    Storage::disk('crate-archive')->put('outside-satis.txt', 'preserved');

    Process::fake(function (PendingProcess $process) {
        File::put($process->path.'/output/packages.json', '{"packages":[]}');

        return Process::result('satis built');
    });

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));

    Storage::disk('crate-archive')->assertExists('satis/packages.json');
    Storage::disk('crate-archive')->assertMissing('satis/p2/removed/package.json');
    Storage::disk('crate-archive')->assertMissing('satis/dist/removed/package/archive.zip');
    Storage::disk('crate-archive')->assertExists('outside-satis.txt');
});

it('records a failed build for a failed process result', function (): void {
    Storage::fake('crate-archive');
    $repo = ServedRepo::factory()->create(['name' => 'vendor/package']);

    Process::fake([Process::result('satis failed', '', 1)]);

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));

    $build = Build::query()->firstOrFail();

    expect($build->status)->toBe(BuildStatus::Failed)
        ->and($build->finished_at)->not->toBeNull()
        ->and($build->output)->toContain('satis failed')
        ->and($repo->refresh()->status)->toBe(RepoStatus::Failed);
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

it('redacts the invocation credential when it changes while satis is running', function (string $mutation): void {
    Storage::fake('crate-archive');
    $repo = ServedRepo::factory()->create([
        'name' => 'vendor/package',
        'source_credential' => 'ghp_invocation_secret',
    ]);

    Process::fake(function () use ($mutation, $repo) {
        if ($mutation === 'replace') {
            $repo->update(['source_credential' => 'ghp_replacement_secret']);
        } else {
            $repo->delete();
        }

        return Process::result('failed with token ghp_invocation_secret', '', 1);
    });

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));

    $build = Build::query()->firstOrFail();

    expect($build->output)->not->toContain('ghp_invocation_secret')
        ->and($build->output)->toContain('***');
})->with(['replace', 'delete']);

it('queues every same-archive build and serializes processing without dispatch-time uniqueness', function (): void {
    Queue::fake();

    BuildSatis::dispatch('vendor/package', 'source-credential-replaced')->afterCommit();
    BuildSatis::dispatch('vendor/package', 'source-credential-cleared')->afterCommit();
    BuildSatis::dispatch(trigger: 'repository-removed')->afterCommit();

    Queue::assertPushed(BuildSatis::class, 3);

    $packageJob = new BuildSatis('vendor/package');
    $fullJob = new BuildSatis;
    $packageMiddleware = $packageJob->middleware();
    $fullMiddleware = $fullJob->middleware();

    expect($packageJob)->not->toBeInstanceOf(ShouldBeUnique::class)
        ->and($packageJob->tries)->toBe(0)
        ->and($packageMiddleware)->toHaveCount(1)
        ->and($packageMiddleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($packageMiddleware[0]->key)->toBe('crate-satis-archive')
        ->and($packageMiddleware[0]->releaseAfter)->toBe(5)
        ->and($packageMiddleware[0]->expiresAfter)->toBe(600)
        ->and($fullMiddleware[0]->key)->toBe($packageMiddleware[0]->key);
});

it('writes empty Composer authentication as an object', function (): void {
    Storage::fake('crate-archive');
    ServedRepo::factory()->create(['name' => 'vendor/package']);

    Process::fake(function (PendingProcess $process) {
        expect(trim(File::get($process->path.'/auth.json')))->toBe('{}');

        return Process::result('satis built');
    });

    app(BuildSatis::class)->handle(app(SatisConfigGenerator::class));
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
        ->and(File::exists($authPath))->toBeFalse()
        ->and(File::exists(dirname($authPath)))->toBeFalse();
})->with([0, 1]);
