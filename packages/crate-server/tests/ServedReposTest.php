<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the served repos table with expected columns', function (): void {
    expect(Schema::connection('crate')->hasColumns('served_repos', [
        'id',
        'name',
        'url',
        'type',
        'source_credential',
        'status',
        'last_built_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('casts model fields and encrypts source credentials at rest', function (): void {
    $repo = ServedRepo::query()->create([
        'name' => 'vendor/package',
        'url' => 'https://github.com/vendor/package',
        'type' => RepoType::Vcs,
        'source_credential' => 'ghp_secretvalue',
        'status' => RepoStatus::Pending,
        'last_built_at' => now(),
    ]);

    $fresh = $repo->fresh();
    $raw = DB::connection('crate')->table('served_repos')->where('id', $repo->getKey())->first();

    expect($fresh->status)->toBeInstanceOf(RepoStatus::class)
        ->and($fresh->status)->toBe(RepoStatus::Pending)
        ->and($fresh->type)->toBeInstanceOf(RepoType::class)
        ->and($fresh->type)->toBe(RepoType::Vcs)
        ->and($fresh->last_built_at)->not->toBeNull()
        ->and($fresh->source_credential)->toBe('ghp_secretvalue')
        ->and($raw->source_credential)->not->toBe('ghp_secretvalue');
});

it('adds a served repo from the console command', function (): void {
    $this->artisan('crate:repos:add', [
        'name' => 'vendor/package',
        'url' => 'https://github.com/vendor/package',
        '--source-token' => 'ghp_secretvalue',
        '--type' => 'git',
    ])->assertSuccessful();

    $repo = ServedRepo::query()->where('name', 'vendor/package')->firstOrFail();
    $raw = DB::connection('crate')->table('served_repos')->where('id', $repo->getKey())->first();

    expect($repo->url)->toBe('https://github.com/vendor/package')
        ->and($repo->type)->toBe(RepoType::Git)
        ->and($repo->status)->toBe(RepoStatus::Pending)
        ->and($repo->source_credential)->toBe('ghp_secretvalue')
        ->and($raw->source_credential)->not->toBe('ghp_secretvalue');
});

it('rejects duplicate served repo names', function (): void {
    ServedRepo::factory()->create(['name' => 'vendor/package']);

    $this->artisan('crate:repos:add', [
        'name' => 'vendor/package',
        'url' => 'https://github.com/vendor/package',
    ])->assertFailed();
});

it('rejects invalid served repo types', function (): void {
    $this->artisan('crate:repos:add', [
        'name' => 'vendor/package',
        'url' => 'https://github.com/vendor/package',
        '--type' => 'invalid',
    ])->assertFailed();
});

it('rejects malformed served repo names', function (): void {
    $this->artisan('crate:repos:add', [
        'name' => 'not-a-package',
        'url' => 'https://github.com/vendor/package',
    ])->assertFailed();
});

it('removes an existing served repo from the console command', function (): void {
    ServedRepo::factory()->create(['name' => 'vendor/package']);

    $this->artisan('crate:repos:remove', [
        'name' => 'vendor/package',
    ])->assertSuccessful();

    expect(ServedRepo::query()->where('name', 'vendor/package')->exists())->toBeFalse();
});

it('fails when removing a missing served repo', function (): void {
    $this->artisan('crate:repos:remove', [
        'name' => 'vendor/package',
    ])->assertFailed();
});

it('lists served repos without leaking source credentials', function (): void {
    ServedRepo::factory()->create([
        'name' => 'vendor/package',
        'url' => 'https://github.com/vendor/package',
        'source_credential' => 'ghp_secretvalue',
        'status' => RepoStatus::Pending,
    ]);

    $this->artisan('crate:repos:list')
        ->expectsTable(['name', 'url', 'type', 'status', 'last_built_at'], [
            ['vendor/package', 'https://github.com/vendor/package', 'vcs', 'pending', null],
        ])
        ->doesntExpectOutputToContain('ghp_secretvalue')
        ->assertSuccessful();
});
