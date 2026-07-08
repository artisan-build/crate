<?php

declare(strict_types=1);

function crateTempEnv(string $contents = ''): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'crate-env-');
    file_put_contents($path, $contents);

    return $path;
}

function crateReadEnv(string $path): array
{
    $values = [];

    foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches) === 1) {
            $values[$matches[1]] = $matches[2];
        }
    }

    return $values;
}

it('writes only crate app env values in non-interactive mode', function (): void {
    $path = crateTempEnv();

    $this->artisan('crate:install', [
        '--no-interaction' => true,
        '--path' => $path,
        '--url' => 'https://crate.example.com',
        '--archive-disk' => 'crate-archive',
        '--satis-path' => '/app/vendor/bin/satis/bin/satis',
        '--credential-api' => 'true',
    ])->assertSuccessful();

    expect(crateReadEnv($path))->toBe([
        'CRATE_URL' => 'https://crate.example.com',
        'CRATE_ARCHIVE_DISK' => 'crate-archive',
        'CRATE_SATIS_PATH' => '/app/vendor/bin/satis/bin/satis',
        'BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED' => 'true',
    ])->not->toHaveKeys([
        'DB_CONNECTION',
        'QUEUE_CONNECTION',
        'CACHE_STORE',
        'FILESYSTEM_DISK',
    ]);
});

it('is idempotent when the desired values are already configured', function (): void {
    $path = crateTempEnv();

    $arguments = [
        '--no-interaction' => true,
        '--path' => $path,
        '--url' => 'https://crate.example.com',
        '--archive-disk' => 'crate-archive',
        '--satis-path' => '/app/vendor/bin/satis/bin/satis',
        '--credential-api' => 'true',
    ];

    $this->artisan('crate:install', $arguments)->assertSuccessful();
    touch($path, time() - 60);
    $modifiedAt = filemtime($path);

    $this->artisan('crate:install', $arguments)
        ->expectsOutput('Crate is already configured; no changes.')
        ->assertSuccessful();

    expect(filemtime($path))->toBe($modifiedAt);
});

it('does not clobber existing values non-interactively without force', function (): void {
    $path = crateTempEnv('CRATE_URL=https://old.example.com'.PHP_EOL);

    $this->artisan('crate:install', [
        '--no-interaction' => true,
        '--path' => $path,
        '--url' => 'https://new.example.com',
    ])
        ->expectsOutput('Kept existing CRATE_URL; pass --force to overwrite.')
        ->expectsOutput('Crate is already configured; no changes.')
        ->assertSuccessful();

    expect(crateReadEnv($path)['CRATE_URL'])->toBe('https://old.example.com');
});

it('clobbers existing values non-interactively with force', function (): void {
    $path = crateTempEnv('CRATE_URL=https://old.example.com'.PHP_EOL);

    $this->artisan('crate:install', [
        '--no-interaction' => true,
        '--force' => true,
        '--path' => $path,
        '--url' => 'https://new.example.com',
    ])->assertSuccessful();

    expect(crateReadEnv($path)['CRATE_URL'])->toBe('https://new.example.com');
});

it('writes answered values interactively', function (): void {
    $path = crateTempEnv();

    $this->artisan('crate:install', ['--path' => $path])
        ->expectsQuestion('Crate public URL', 'https://crate.example.com')
        ->expectsQuestion('Crate archive disk', 'crate-archive')
        ->expectsQuestion('Satis binary path', '/app/vendor/bin/satis/bin/satis')
        ->expectsQuestion('Enable credential API', true)
        ->assertSuccessful();

    expect(crateReadEnv($path))->toBe([
        'CRATE_URL' => 'https://crate.example.com',
        'CRATE_ARCHIVE_DISK' => 'crate-archive',
        'CRATE_SATIS_PATH' => '/app/vendor/bin/satis/bin/satis',
        'BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED' => 'true',
    ]);
});

it('preserves unrelated existing env keys', function (): void {
    $path = crateTempEnv(implode(PHP_EOL, [
        'APP_KEY=base64:existing',
        'DB_CONNECTION=pgsql',
        'QUEUE_CONNECTION=redis',
        'CACHE_STORE=redis',
        'FILESYSTEM_DISK=s3',
        '',
    ]));

    $this->artisan('crate:install', [
        '--no-interaction' => true,
        '--path' => $path,
        '--url' => 'https://crate.example.com',
    ])->assertSuccessful();

    $values = crateReadEnv($path);

    expect($values)->toMatchArray([
        'APP_KEY' => 'base64:existing',
        'DB_CONNECTION' => 'pgsql',
        'QUEUE_CONNECTION' => 'redis',
        'CACHE_STORE' => 'redis',
        'FILESYSTEM_DISK' => 's3',
        'CRATE_URL' => 'https://crate.example.com',
    ]);
});

it('round-trips a backslash-bearing value idempotently', function (): void {
    $path = crateTempEnv();

    $arguments = [
        '--no-interaction' => true,
        '--path' => $path,
        '--satis-path' => 'C:\\newdir\\satis',
    ];

    $this->artisan('crate:install', $arguments)->assertSuccessful();

    // The second run must recognise the stored value as unchanged (proving the escaped
    // backslash round-trips), not warn about keeping an existing value.
    $this->artisan('crate:install', $arguments)
        ->doesntExpectOutput('Kept existing CRATE_SATIS_PATH; pass --force to overwrite.')
        ->expectsOutput('Crate is already configured; no changes.')
        ->assertSuccessful();
});

it('writes a false credential api toggle', function (): void {
    $path = crateTempEnv();

    $this->artisan('crate:install', [
        '--no-interaction' => true,
        '--path' => $path,
        '--credential-api' => 'false',
    ])->assertSuccessful();

    expect(crateReadEnv($path)['BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED'])->toBe('false');
});
