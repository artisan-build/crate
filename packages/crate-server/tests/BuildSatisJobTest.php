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

it('claims and completes the durable queued build supplied by the MCP trigger', function (): void {
    Storage::fake('crate-archive');
    $repo = ServedRepo::factory()->create(['name' => 'vendor/package']);
    $build = Build::factory()->create([
        'served_repo_id' => $repo->getKey(),
        'trigger' => 'mcp',
        'status' => BuildStatus::Queued,
        'requested_by' => 'bfc-console:42',
        'idempotency_key' => 'durable-handle',
        'request_fingerprint' => hash('sha256', 'request'),
    ]);
    Process::fake([Process::result('satis built')]);

    (new BuildSatis(null, 'mcp', (int) $build->getKey(), (int) $repo->getKey()))
        ->handle(app(SatisConfigGenerator::class));

    expect(Build::query()->count())->toBe(1)
        ->and($build->refresh()->status)->toBe(BuildStatus::Succeeded)
        ->and($build->started_at)->not->toBeNull()
        ->and($build->finished_at)->not->toBeNull();
});

it('terminally fails a durable build when post-claim preflight throws', function (): void {
    $build = Build::factory()->create([
        'trigger' => 'mcp',
        'status' => BuildStatus::Queued,
        'requested_by' => 'installation',
        'idempotency_key' => 'preflight-failure',
        'request_fingerprint' => hash('sha256', 'request'),
    ]);
    File::partialMock()->shouldReceive('ensureDirectoryExists')->once()->andThrow(new RuntimeException('preflight failed'));

    (new BuildSatis(buildId: (int) $build->getKey()))->handle(app(SatisConfigGenerator::class));

    expect($build->refresh()->status)->toBe(BuildStatus::Failed)
        ->and($build->finished_at)->not->toBeNull()
        ->and($build->claim_token)->toBeNull()
        ->and($build->lease_expires_at)->toBeNull()
        ->and($build->output)->toContain('preflight failed');
});

it('reclaims an expired durable lease and completes the same handle', function (): void {
    Storage::fake('crate-archive');
    $repository = ServedRepo::factory()->create(['name' => 'vendor/reclaimed']);
    $build = Build::factory()->create([
        'served_repo_id' => $repository->getKey(),
        'trigger' => 'mcp',
        'status' => BuildStatus::Running,
        'started_at' => now()->subHours(2),
        'claim_token' => 'a5b505f7-8b09-466e-a7f0-076462624255',
        'lease_expires_at' => now()->subMinute(),
        'requested_by' => 'installation',
        'idempotency_key' => 'expired-lease',
        'request_fingerprint' => hash('sha256', 'request'),
    ]);
    Process::fake([Process::result('satis built')]);

    (new BuildSatis(buildId: (int) $build->getKey(), servedRepoId: (int) $repository->getKey()))
        ->handle(app(SatisConfigGenerator::class));

    expect($build->refresh()->status)->toBe(BuildStatus::Succeeded)
        ->and($build->claim_token)->toBeNull()
        ->and($build->lease_expires_at)->toBeNull()
        ->and(Build::query()->count())->toBe(1);
});

it('fails rather than executing against a replacement repository with the same name', function (): void {
    Storage::fake('crate-archive');
    $original = ServedRepo::factory()->create(['name' => 'vendor/replaced']);
    $build = Build::factory()->create([
        'served_repo_id' => $original->getKey(),
        'trigger' => 'mcp',
        'status' => BuildStatus::Queued,
        'requested_by' => 'installation',
        'idempotency_key' => 'target-race',
        'request_fingerprint' => hash('sha256', 'request'),
    ]);
    $job = new BuildSatis(buildId: (int) $build->getKey(), servedRepoId: (int) $original->getKey());
    $original->delete();
    $replacement = ServedRepo::factory()->create(['name' => 'vendor/replaced']);
    Process::fake();

    $job->handle(app(SatisConfigGenerator::class));

    expect($build->refresh()->status)->toBe(BuildStatus::Failed)
        ->and($build->finished_at)->not->toBeNull()
        ->and($replacement->refresh()->status)->toBe(RepoStatus::Pending);
    Process::assertNothingRan();
});

it('ignores duplicate dispatches after one job claims and completes the durable row', function (): void {
    Storage::fake('crate-archive');
    $repository = ServedRepo::factory()->create(['name' => 'vendor/duplicate']);
    $build = Build::factory()->create([
        'served_repo_id' => $repository->getKey(),
        'trigger' => 'mcp',
        'status' => BuildStatus::Queued,
        'requested_by' => 'installation',
        'idempotency_key' => 'duplicate-dispatch',
        'request_fingerprint' => hash('sha256', 'request'),
    ]);
    $first = new BuildSatis(buildId: (int) $build->getKey(), servedRepoId: (int) $repository->getKey());
    $duplicate = new BuildSatis(buildId: (int) $build->getKey(), servedRepoId: (int) $repository->getKey());
    Process::fake([Process::result('satis built')]);

    $first->handle(app(SatisConfigGenerator::class));
    $duplicate->handle(app(SatisConfigGenerator::class));

    expect($build->refresh()->status)->toBe(BuildStatus::Succeeded);
    Process::assertRanTimes(fn (): bool => true, 1);
});

it('refuses a duplicate dispatch while another worker holds the durable lease', function (): void {
    $repository = ServedRepo::factory()->create(['name' => 'vendor/active-claim']);
    $build = Build::factory()->create([
        'served_repo_id' => $repository->getKey(),
        'trigger' => 'mcp',
        'status' => BuildStatus::Running,
        'claim_token' => 'bcb45323-e4d4-45e7-9772-e48174360c5b',
        'lease_expires_at' => now()->addHour(),
        'requested_by' => 'installation',
        'idempotency_key' => 'active-lease',
        'request_fingerprint' => hash('sha256', 'request'),
    ]);
    Process::fake();

    (new BuildSatis(buildId: (int) $build->getKey(), servedRepoId: (int) $repository->getKey()))
        ->handle(app(SatisConfigGenerator::class));

    expect($build->refresh()->status)->toBe(BuildStatus::Running)
        ->and($build->claim_token)->toBe('bcb45323-e4d4-45e7-9772-e48174360c5b');
    Process::assertNothingRan();
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

it('ignores a stale package build that runs after its removal build', function (): void {
    Storage::fake('crate-archive');
    Storage::disk('crate-archive')->put('satis/packages.json', '{"packages":{"review/remaining":[],"review/removed":[]}}');
    Storage::disk('crate-archive')->put('satis/p2/review/removed.json', 'stale-provider');
    Storage::disk('crate-archive')->put('satis/dist/review/removed/archive.zip', 'stale-archive');

    $remaining = ServedRepo::factory()->create([
        'name' => 'review/remaining',
        'status' => RepoStatus::Active,
    ]);
    $removed = ServedRepo::factory()->create(['name' => 'review/removed']);
    $stalePackageBuild = new BuildSatis($removed->name, 'repository-added');
    $removalBuild = new BuildSatis(trigger: 'repository-removed');
    $requirements = [];

    $removed->delete();

    Process::fake(function (PendingProcess $process) use (&$requirements) {
        $config = json_decode(File::get($process->path.'/satis.json'), true, flags: JSON_THROW_ON_ERROR);
        $requirements[] = $config['require'];
        File::put($process->path.'/output/packages.json', '{"packages":{"review/remaining":[]}}');
        File::ensureDirectoryExists($process->path.'/output/p2/review');
        File::put($process->path.'/output/p2/review/remaining.json', 'remaining-provider');

        return Process::result('satis built');
    });

    $removalBuild->handle(app(SatisConfigGenerator::class));
    $latestBuild = Build::query()->latest('id')->firstOrFail();
    $stalePackageBuild->handle(app(SatisConfigGenerator::class));

    expect($requirements)->toBe([['review/remaining' => '*']])
        ->and(Build::query()->count())->toBe(1)
        ->and(Build::query()->latest('id')->firstOrFail()->is($latestBuild))->toBeTrue()
        ->and($latestBuild->status)->toBe(BuildStatus::Succeeded)
        ->and($remaining->refresh()->status)->toBe(RepoStatus::Active);

    Storage::disk('crate-archive')->assertMissing('satis/p2/review/removed.json');
    Storage::disk('crate-archive')->assertMissing('satis/dist/review/removed/archive.zip');
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
        ->and($packageJob->timeout)->toBe(3300)
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
