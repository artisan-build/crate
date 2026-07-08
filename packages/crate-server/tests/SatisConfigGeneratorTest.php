<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use ArtisanBuild\CrateServer\SatisConfigGenerator;

it('generates full and incremental satis config with dist mirroring enabled', function (): void {
    ServedRepo::factory()->create([
        'name' => 'vendor/package-a',
        'url' => 'https://github.com/vendor/package-a',
        'type' => RepoType::Vcs,
        'source_credential' => 'ghp_secretvalue',
    ]);
    ServedRepo::factory()->create([
        'name' => 'vendor/package-b',
        'url' => 'https://example.com/vendor/package-b.git',
        'type' => RepoType::Git,
    ]);

    $generator = app(SatisConfigGenerator::class);

    $full = $generator->generate();
    $incremental = $generator->generate('vendor/package-a');

    expect($full)->toMatchArray([
        'name' => 'crate/registry',
        'homepage' => 'https://crate.test',
        'repositories' => [
            ['type' => 'vcs', 'url' => 'https://github.com/vendor/package-a'],
            ['type' => 'git', 'url' => 'https://example.com/vendor/package-b.git'],
        ],
        'require' => [
            'vendor/package-a' => '*',
            'vendor/package-b' => '*',
        ],
        'require-dependencies' => false,
        'archive' => [
            'directory' => 'dist',
            'format' => 'zip',
            'prefix-url' => 'https://crate.test',
            'skip-dev' => false,
        ],
    ])->and($incremental['require'])->toBe(['vendor/package-a' => '*'])
        ->and(json_encode($full))->not->toContain('ghp_secretvalue');
});

it('generates scoped build auth from decrypted source credentials', function (): void {
    ServedRepo::factory()->create([
        'name' => 'vendor/package-a',
        'url' => 'https://github.com/vendor/package-a',
        'source_credential' => 'ghp_secretvalue',
    ]);
    ServedRepo::factory()->create([
        'name' => 'vendor/package-b',
        'url' => 'https://github.com/vendor/package-b',
        'source_credential' => null,
    ]);

    $generator = app(SatisConfigGenerator::class);

    expect($generator->authConfig())->toBe([
        'github-oauth' => [
            'github.com' => 'ghp_secretvalue',
        ],
    ])->and(json_encode($generator->generate()))->not->toContain('ghp_secretvalue');
});
