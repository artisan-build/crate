<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\Exceptions\InvalidServedRepo;
use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateContracts\ServedRepo;

it('round trips a minimal served repo through arrays and JSON', function (): void {
    $repo = ServedRepo::make(
        name: 'artisan-build/crate',
        url: 'https://github.com/artisan-build/crate.git',
    );

    $fromArray = ServedRepo::fromArray($repo->toArray());
    $fromJson = ServedRepo::fromJson($repo->toJson());

    expect($repo->toArray())->toBe([
        'name' => 'artisan-build/crate',
        'url' => 'https://github.com/artisan-build/crate.git',
        'type' => 'vcs',
        'status' => 'pending',
    ])
        ->and($fromArray)->toEqual($repo)
        ->and($fromJson)->toEqual($repo);
});

it('round trips a full served repo through arrays and JSON', function (): void {
    $repo = ServedRepo::make(
        name: 'artisan-build/toolkit',
        url: '/srv/repos/toolkit',
        type: RepoType::Path,
        status: RepoStatus::Active,
    );

    $fromArray = ServedRepo::fromArray($repo->toArray());
    $fromJson = ServedRepo::fromJson($repo->toJson());

    expect($fromArray->name)->toBe($repo->name)
        ->and($fromArray->url)->toBe($repo->url)
        ->and($fromArray->type)->toBe($repo->type)
        ->and($fromArray->status)->toBe($repo->status)
        ->and($fromJson)->toEqual($repo);
});

it('emits exact served repo wire keys and enum values', function (): void {
    $repo = ServedRepo::make(
        name: 'artisan-build/crate',
        url: 'git@github.com:artisan-build/crate.git',
        type: RepoType::Git,
        status: RepoStatus::Building,
    );

    expect(array_keys($repo->toArray()))->toBe(['name', 'url', 'type', 'status'])
        ->and($repo->toArray()['type'])->toBe(RepoType::Git->value)
        ->and($repo->toArray()['status'])->toBe(RepoStatus::Building->value);
});

it('ignores unknown served repo keys and defaults optional fields', function (): void {
    $repo = ServedRepo::fromArray([
        'name' => 'artisan-build/crate',
        'url' => 'https://github.com/artisan-build/crate.git',
        'unknown' => 'ignored',
    ]);

    expect($repo->type)->toBe(RepoType::Vcs)
        ->and($repo->status)->toBe(RepoStatus::Pending);
});

it('rejects malformed served repo arrays', function (array $data): void {
    ServedRepo::fromArray($data);
})->throws(InvalidServedRepo::class)->with('malformed served repos');

it('rejects malformed served repo JSON', function (string $json): void {
    ServedRepo::fromJson($json);
})->throws(InvalidServedRepo::class)->with('malformed served repo JSON');

dataset('malformed served repos', [
    'missing name' => [['url' => 'https://github.com/artisan-build/crate.git']],
    'empty name' => [['name' => '', 'url' => 'https://github.com/artisan-build/crate.git']],
    'wrong-type name' => [['name' => 5, 'url' => 'https://github.com/artisan-build/crate.git']],
    'missing url' => [['name' => 'artisan-build/crate']],
    'empty url' => [['name' => 'artisan-build/crate', 'url' => '']],
    'wrong-type url' => [['name' => 'artisan-build/crate', 'url' => false]],
    'unrecognized type' => [['name' => 'artisan-build/crate', 'url' => 'https://github.com/artisan-build/crate.git', 'type' => 'svn']],
    'wrong-type type' => [['name' => 'artisan-build/crate', 'url' => 'https://github.com/artisan-build/crate.git', 'type' => 5]],
    'unrecognized status' => [['name' => 'artisan-build/crate', 'url' => 'https://github.com/artisan-build/crate.git', 'status' => 'archived']],
    'wrong-type status' => [['name' => 'artisan-build/crate', 'url' => 'https://github.com/artisan-build/crate.git', 'status' => []]],
]);

dataset('malformed served repo JSON', [
    'invalid JSON' => ['{'],
    'empty list' => ['[]'],
    'list' => ['[1,2]'],
    'scalar' => ['5'],
]);
