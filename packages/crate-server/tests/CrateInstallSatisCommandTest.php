<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\Commands\CrateInstallSatisCommand;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->installDirectory = sys_get_temp_dir().'/crate-satis-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    File::deleteDirectory($this->installDirectory);
});

function fakeSatisInstall(string $directory, int $exitCode = 0): Closure
{
    return function (PendingProcess $process) use ($directory, $exitCode) {
        if ($exitCode === 0) {
            @mkdir($directory.'/bin', 0755, true);
            file_put_contents($directory.'/bin/satis', "#!/usr/bin/env php\n");
            chmod($directory.'/bin/satis', 0755);
        }

        return Process::result(output: 'composer output', exitCode: $exitCode);
    };
}

it('installs satis into the install directory and leaves an executable at bin/satis', function (): void {
    Process::fake(fakeSatisInstall($this->installDirectory));

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory])->assertSuccessful();

    expect($this->installDirectory.'/bin/satis')->toBeFile()
        ->and(is_executable($this->installDirectory.'/bin/satis'))->toBeTrue();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'composer',
        'create-project',
        'composer/satis:dev-main',
        $this->installDirectory,
        '--no-dev',
        '--no-interaction',
    ]);
});

it('pins satis to dev-main because the latest stable requires php 7', function (): void {
    expect(CrateInstallSatisCommand::PACKAGE)->toBe('composer/satis:dev-main');
});

it('passes the install directory as the create-project target, never the executable path', function (): void {
    Process::fake(fakeSatisInstall($this->installDirectory));

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && ! in_array($this->installDirectory.'/bin/satis', $process->command, true));
});

it('reports an existing install and does not reinstall', function (): void {
    mkdir($this->installDirectory.'/bin', 0755, true);
    file_put_contents($this->installDirectory.'/bin/satis', "#!/usr/bin/env php\n");
    chmod($this->installDirectory.'/bin/satis', 0755);

    Process::fake();

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory])
        ->expectsOutputToContain('already installed')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('reinstalls an existing install when forced', function (): void {
    mkdir($this->installDirectory.'/bin', 0755, true);
    file_put_contents($this->installDirectory.'/bin/satis', "#!/usr/bin/env php\n");
    chmod($this->installDirectory.'/bin/satis', 0755);

    Process::fake(fakeSatisInstall($this->installDirectory));

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory, '--force' => true])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && in_array('create-project', $process->command, true));
});

it('fails loudly when composer fails', function (): void {
    Process::fake(fakeSatisInstall($this->installDirectory, exitCode: 1));

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory])
        ->expectsOutputToContain('Installing Satis failed')
        ->assertFailed();
});

it('fails when composer succeeds but no executable lands at bin/satis', function (): void {
    Process::fake(function (): mixed {
        return Process::result(output: 'composer output', exitCode: 0);
    });

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory])
        ->expectsOutputToContain('no Satis executable exists')
        ->assertFailed();
});

it('defaults the install directory to satis-tool in the application base path', function (): void {
    expect(CrateInstallSatisCommand::INSTALL_DIRECTORY)->toBe('satis-tool');
});

it('defaults the configured satis path to the executable the installer produces', function (): void {
    $config = require __DIR__.'/../config/crate-server.php';

    expect($config['satis_path'])->toBe(base_path(CrateInstallSatisCommand::INSTALL_DIRECTORY.'/bin/satis'));
});

it('clears a stale install directory before reinstalling so create-project has an empty target', function (): void {
    mkdir($this->installDirectory.'/bin', 0755, true);
    file_put_contents($this->installDirectory.'/bin/satis', "#!/usr/bin/env php\n");
    chmod($this->installDirectory.'/bin/satis', 0755);
    file_put_contents($this->installDirectory.'/stale.txt', 'left over from a previous install');

    $staleAtRunTime = null;
    $directory = $this->installDirectory;

    Process::fake(function (PendingProcess $process) use ($directory, &$staleAtRunTime) {
        $staleAtRunTime = file_exists($directory.'/stale.txt');

        @mkdir($directory.'/bin', 0755, true);
        file_put_contents($directory.'/bin/satis', "#!/usr/bin/env php\n");
        chmod($directory.'/bin/satis', 0755);

        return Process::result(output: 'composer output', exitCode: 0);
    });

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory, '--force' => true])
        ->assertSuccessful();

    expect($staleAtRunTime)->toBeFalse();
});

it('makes the installed satis executable when composer leaves it unexecutable', function (): void {
    $directory = $this->installDirectory;

    Process::fake(function (PendingProcess $process) use ($directory) {
        @mkdir($directory.'/bin', 0755, true);
        file_put_contents($directory.'/bin/satis', "#!/usr/bin/env php\n");
        chmod($directory.'/bin/satis', 0644);

        return Process::result(output: 'composer output', exitCode: 0);
    });

    $this->artisan('crate:install-satis', ['--dir' => $this->installDirectory])->assertSuccessful();

    clearstatcache(true, $this->installDirectory.'/bin/satis');

    expect(is_executable($this->installDirectory.'/bin/satis'))->toBeTrue();
});
