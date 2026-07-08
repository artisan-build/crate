<?php

declare(strict_types=1);

use ArtisanBuild\CrateClient\Crate;

it('builds the composer auth fragment', function (): void {
    expect(Crate::composerAuthFragment())->toBe([
        'crate.example.com' => [
            'username' => 'token',
            'password' => 'ctok_secret',
        ],
    ]);
});

it('builds the composer auth json value', function (): void {
    expect(json_decode(Crate::composerAuthJson(), true))->toBe([
        'http-basic' => [
            'crate.example.com' => [
                'username' => 'token',
                'password' => 'ctok_secret',
            ],
        ],
    ]);
});

it('prints composer auth json for environment usage', function (): void {
    $this->artisan('crate:auth --print')
        ->expectsOutput(Crate::composerAuthJson())
        ->assertSuccessful();
});

it('only prints secrets with the dedicated --print flag, never by default', function (): void {
    $path = sys_get_temp_dir().'/crate-client-auth-'.bin2hex(random_bytes(8)).'.json';

    try {
        // Without --print the command writes the file and must NOT emit the secret JSON to stdout,
        // so it can never leak the token when invoked with framework globals like --env=production.
        $this->artisan('crate:auth', ['--path' => $path])
            ->doesntExpectOutput(Crate::composerAuthJson())
            ->assertSuccessful();

        expect(file_exists($path))->toBeTrue();
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('writes composer auth json to disk', function (): void {
    $path = sys_get_temp_dir().'/crate-client-auth-'.bin2hex(random_bytes(8)).'.json';

    try {
        file_put_contents($path, json_encode([
            'http-basic' => [
                'existing.example.com' => [
                    'username' => 'token',
                    'password' => 'existing_secret',
                ],
            ],
        ], JSON_PRETTY_PRINT));

        $this->artisan('crate:auth', ['--path' => $path])
            ->expectsOutputToContain($path)
            ->assertSuccessful();

        expect(json_decode((string) file_get_contents($path), true))->toMatchArray([
            'http-basic' => [
                'existing.example.com' => [
                    'username' => 'token',
                    'password' => 'existing_secret',
                ],
                'crate.example.com' => [
                    'username' => 'token',
                    'password' => 'ctok_secret',
                ],
            ],
        ]);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
