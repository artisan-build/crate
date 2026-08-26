<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Installs Satis as an isolated tool inside the application directory.
 *
 * This is a BUILD-step command. On Laravel Cloud only build-time filesystem
 * writes persist into the deploy artifact shipped to every instance; deploy
 * and post-deploy writes touch one ephemeral disk and are gone next deploy.
 */
final class CrateInstallSatisCommand extends Command
{
    /**
     * The install directory, relative to the application base path. The Satis
     * EXECUTABLE lands inside it at bin/satis — Composer does not link a root
     * package's bin into vendor/bin. Keep in sync with the crate-server.satis_path
     * config default.
     */
    public const string INSTALL_DIRECTORY = 'satis-tool';

    /**
     * Satis has no recent stable tag; an unpinned create-project resolves to
     * satis 1.0.0, which requires php ^5.6 || ^7.0 and fails on any modern runtime.
     */
    public const string PACKAGE = 'composer/satis:dev-main';

    protected $signature = 'crate:install-satis
        {--dir= : Install directory (defaults to the satis-tool directory in the application base path)}
        {--composer=composer : The Composer executable to run}
        {--force : Reinstall even if Satis is already present}';

    protected $description = 'Install an isolated Satis into the application directory.';

    public function handle(): int
    {
        $directory = $this->installDirectory();
        $executable = $directory.'/bin/satis';

        if (! (bool) $this->option('force') && is_file($executable) && is_executable($executable)) {
            $this->components->info("Satis is already installed at {$executable}.");

            return self::SUCCESS;
        }

        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }

        File::ensureDirectoryExists(dirname($directory));

        $this->components->info('Installing '.self::PACKAGE." into {$directory}...");

        $result = Process::timeout(600)
            ->run([
                (string) $this->option('composer'),
                'create-project',
                self::PACKAGE,
                $directory,
                '--no-dev',
                '--no-interaction',
            ], function (string $type, string $output): void {
                $this->output->write($output);
            });

        if ($result->failed()) {
            $this->components->error('Installing Satis failed (exit code '.$result->exitCode().'). See the Composer output above.');

            return self::FAILURE;
        }

        if (! is_file($executable)) {
            $this->components->error("Composer reported success but no Satis executable exists at {$executable}. The create-project target must be the install DIRECTORY, never the executable path.");

            return self::FAILURE;
        }

        if (! is_executable($executable)) {
            chmod($executable, 0755);
        }

        $this->components->info("Satis installed at {$executable}.");

        return self::SUCCESS;
    }

    private function installDirectory(): string
    {
        $directory = $this->option('dir');

        if (is_string($directory) && $directory !== '') {
            return rtrim($directory, '/');
        }

        return base_path(self::INSTALL_DIRECTORY);
    }
}
