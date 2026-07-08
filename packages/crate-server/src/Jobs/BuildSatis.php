<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Jobs;

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use ArtisanBuild\CrateServer\SatisConfigGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class BuildSatis implements ShouldQueue
{
    use FoundationQueueable;

    public function __construct(
        public readonly ?string $package = null,
        public readonly string $trigger = 'manual',
    ) {}

    public function handle(SatisConfigGenerator $generator): void
    {
        $servedRepo = $this->package === null
            ? null
            : ServedRepo::query()->where('name', $this->package)->first();

        $build = Build::query()->create([
            'served_repo_id' => $servedRepo?->getKey(),
            'trigger' => $this->trigger,
            'status' => BuildStatus::Running,
            'started_at' => now(),
        ]);

        $workingDir = storage_path('framework/cache/crate-satis');
        File::ensureDirectoryExists($workingDir);

        $tempDir = $workingDir.'/'.uniqid('build-', true);
        File::makeDirectory($tempDir, 0755, true, true);

        $authPath = $tempDir.'/auth.json';

        try {
            $configPath = $tempDir.'/satis.json';
            $outputDir = $tempDir.'/output';

            File::put($configPath, json_encode($generator->generate($this->package), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            File::put($authPath, json_encode($generator->authConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            File::ensureDirectoryExists($outputDir);

            if ($this->package !== null) {
                $this->seedOutputFromArchive($outputDir);
            }

            $result = Process::path($tempDir)
                ->env(['COMPOSER_HOME' => $tempDir])
                ->run([
                    (string) config('crate-server.satis_path'),
                    'build',
                    $configPath,
                    $outputDir,
                ]);

            $output = $this->redactedTail($result->output().$result->errorOutput());

            if ($result->successful()) {
                $this->mirrorOutput($outputDir);

                $build->update([
                    'status' => BuildStatus::Succeeded,
                    'output' => $output,
                    'finished_at' => now(),
                ]);

                return;
            }

            $build->update([
                'status' => BuildStatus::Failed,
                'output' => $output,
                'finished_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            $build->update([
                'status' => BuildStatus::Failed,
                'output' => $this->redactedTail($throwable->getMessage()),
                'finished_at' => now(),
            ]);
        } finally {
            File::delete($authPath);
            File::deleteDirectory($tempDir);
        }
    }

    private function seedOutputFromArchive(string $outputDir): void
    {
        $disk = Storage::disk((string) config('crate-server.archive_disk'));
        $prefix = trim((string) config('crate-server.output_dir'), '/');

        foreach ($disk->allFiles($prefix) as $path) {
            $relativePath = $prefix === '' ? $path : preg_replace('#^'.preg_quote($prefix, '#').'/#', '', $path);

            if (! is_string($relativePath) || $relativePath === '') {
                continue;
            }

            $target = $outputDir.'/'.$relativePath;

            File::ensureDirectoryExists(dirname($target));
            File::put($target, $disk->get($path));
        }
    }

    private function mirrorOutput(string $outputDir): void
    {
        $disk = Storage::disk((string) config('crate-server.archive_disk'));
        $prefix = trim((string) config('crate-server.output_dir'), '/');

        foreach (File::allFiles($outputDir) as $file) {
            $relativePath = $file->getRelativePathname();
            $target = $prefix === '' ? $relativePath : $prefix.'/'.$relativePath;

            $disk->put($target, File::get($file->getPathname()));
        }
    }

    private function redactedTail(string $output): string
    {
        $redacted = $output;

        ServedRepo::query()
            ->whereNotNull('source_credential')
            ->get()
            ->each(function (ServedRepo $repo) use (&$redacted): void {
                if (blank($repo->source_credential)) {
                    return;
                }

                $redacted = str_replace($repo->source_credential, '***', $redacted);
            });

        return mb_substr($redacted, -10000);
    }
}
