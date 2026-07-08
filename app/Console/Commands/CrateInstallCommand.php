<?php

declare(strict_types=1);

namespace App\Console\Commands;

use ArtisanBuild\BuiltForCloud\Commands\Concerns\WritesInstallEnv;
use Illuminate\Console\Command;

final class CrateInstallCommand extends Command
{
    use WritesInstallEnv;

    protected $signature = 'crate:install
        {--url= : Public Crate base URL}
        {--archive-disk= : Crate archive filesystem disk name}
        {--satis-path= : Path to the isolated Satis binary}
        {--credential-api= : Enable the credential API (true or false)}
        {--force : Overwrite existing values without prompting}
        {--path= : Path to the env file to write}';

    protected $description = 'Configure Crate application environment values.';

    /**
     * @var array<string, array{option: string, prompt: string, default: string}>
     */
    private const array KEYS = [
        'CRATE_URL' => [
            'option' => 'url',
            'prompt' => 'Crate public URL',
            'default' => '',
        ],
        'CRATE_ARCHIVE_DISK' => [
            'option' => 'archive-disk',
            'prompt' => 'Crate archive disk',
            'default' => '',
        ],
        'CRATE_SATIS_PATH' => [
            'option' => 'satis-path',
            'prompt' => 'Satis binary path',
            'default' => 'vendor/bin/satis',
        ],
        'BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED' => [
            'option' => 'credential-api',
            'prompt' => 'Enable credential API',
            'default' => 'true',
        ],
    ];

    public function handle(): int
    {
        $path = $this->resolveEnvPath();
        $current = $this->parseEnvValues(is_file($path) ? (string) file_get_contents($path) : '');
        $changes = [];

        foreach (self::KEYS as $key => $definition) {
            $desired = $this->desiredValue($key, $definition, $current[$key] ?? null);

            if ($desired === null || $desired === ($current[$key] ?? null)) {
                continue;
            }

            if (! $this->mayOverwrite($key, $current[$key] ?? null)) {
                continue;
            }

            $changes[$key] = $desired;
        }

        if ($changes === []) {
            $this->info('Crate is already configured; no changes.');

            return self::SUCCESS;
        }

        $this->writeEnvFile($path, $changes);
        $this->summarize($changes);

        return self::SUCCESS;
    }

    private function resolveEnvPath(): string
    {
        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            return $path;
        }

        return $this->laravel->environmentFilePath();
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvValues(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $values[$matches[1]] = $this->unquoteEnvValue($matches[2]);
        }

        return $values;
    }

    private function unquoteEnvValue(string $value): string
    {
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return str_replace(['\\=', '\\n', '\\"', '\\\\'], ['=', "\n", '"', '\\'], substr($value, 1, -1));
        }

        return $value;
    }

    /**
     * @param  array{option: string, prompt: string, default: string}  $definition
     */
    private function desiredValue(string $key, array $definition, ?string $current): ?string
    {
        $option = $this->option($definition['option']);

        if (is_string($option)) {
            return $this->normalizeValue($key, $option);
        }

        if (! $this->input->isInteractive()) {
            return $current;
        }

        $default = $current ?? $this->defaultValue($definition);

        if ($key === 'BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED') {
            return $this->confirm($definition['prompt'], $this->truthy($default)) ? 'true' : 'false';
        }

        return $this->normalizeValue($key, (string) $this->ask($definition['prompt'], $default));
    }

    /**
     * @param  array{option: string, prompt: string, default: string}  $definition
     */
    private function defaultValue(array $definition): string
    {
        if ($definition['option'] === 'satis-path') {
            return base_path($definition['default']);
        }

        return $definition['default'];
    }

    private function normalizeValue(string $key, string $value): string
    {
        if ($key === 'BUILT_FOR_CLOUD_CREDENTIAL_API_ENABLED') {
            return $this->truthy($value) ? 'true' : 'false';
        }

        return $value;
    }

    private function truthy(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function mayOverwrite(string $key, ?string $current): bool
    {
        if ($current === null || $current === '') {
            return true;
        }

        if ($this->input->isInteractive()) {
            return $this->confirm("Overwrite {$key} (current: {$current})?");
        }

        if ((bool) $this->option('force')) {
            return true;
        }

        $this->warn("Kept existing {$key}; pass --force to overwrite.");

        return false;
    }
}
