<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Jobs;

use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateServer\Contracts\RepositoryBuildMutex;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use ArtisanBuild\CrateServer\SatisConfigGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class BuildSatis implements ShouldQueue, SystemAuthorityQueueEntry
{
    use FoundationQueueable;

    private const int LEASE_SECONDS = 3600;

    /** Allow lock-contention releases to outlive a worker's --tries setting. */
    public int $tries = 0;

    /** Keep the worker timeout below the durable claim lease. */
    public int $timeout = 3300;

    public function __construct(
        public readonly ?string $package = null,
        public readonly string $trigger = 'manual',
        public readonly ?int $buildId = null,
    ) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('crate-satis-archive'))
                ->releaseAfter(5)
                ->expireAfter(self::LEASE_SECONDS),
        ];
    }

    public function handle(SatisConfigGenerator $generator): void
    {
        if ($this->buildId !== null) {
            $this->handleDurableBuild($generator);

            return;
        }

        if ($this->package !== null) {
            $servedRepo = ServedRepo::query()->where('name', $this->package)->first();

            if (! $servedRepo instanceof ServedRepo) {
                return;
            }

            $this->executeRepositoryBuild(
                $generator,
                (int) $servedRepo->getKey(),
                $servedRepo->name,
            );

            return;
        }

        $build = Build::query()->create([
            'served_repo_id' => null,
            'scope' => Build::SCOPE_FULL,
            'target_repo_id' => null,
            'target_repo_name' => null,
            'trigger' => $this->trigger,
            'status' => BuildStatus::Running,
            'started_at' => now(),
        ]);

        $this->executeBuild($generator, $build, null, null);
    }

    private function handleDurableBuild(SatisConfigGenerator $generator): void
    {
        $claimToken = $this->claimDurableBuild();

        if ($claimToken === null) {
            return;
        }

        try {
            $build = Build::query()->findOrFail($this->buildId);

            if ($build->scope === Build::SCOPE_REPOSITORY) {
                $this->executeRepositoryBuild(
                    $generator,
                    (int) $build->target_repo_id,
                    (string) $build->target_repo_name,
                    $build,
                    $claimToken,
                );

                return;
            }

            $this->executeBuild($generator, $build, null, null, $claimToken);
        } catch (Throwable $throwable) {
            $this->finishBuild([
                'status' => BuildStatus::Failed,
                'output' => $this->redactedTail($throwable->getMessage(), []),
                'finished_at' => now(),
            ], $claimToken);
        }
    }

    private function claimDurableBuild(): ?string
    {
        return DB::connection('crate')->transaction(function (): ?string {
            $build = Build::query()->lockForUpdate()->find($this->buildId);

            if (! $build instanceof Build
                || ! in_array($build->status, [BuildStatus::Queued, BuildStatus::Running], true)) {
                return null;
            }

            if ($build->status === BuildStatus::Running && $build->lease_expires_at?->isFuture()) {
                return null;
            }

            if ($build->trigger !== 'mcp' || ! $this->hasValidIntent($build)) {
                $build->update([
                    'status' => BuildStatus::Failed,
                    'output' => 'The durable build intent is invalid.',
                    'finished_at' => now(),
                    'claim_token' => null,
                    'lease_expires_at' => null,
                ]);

                return null;
            }

            if ($build->scope === Build::SCOPE_REPOSITORY && ! $this->targetRepository($build) instanceof ServedRepo) {
                $build->update([
                    'status' => BuildStatus::TargetDeleted,
                    'output' => 'The requested repository target was deleted.',
                    'finished_at' => now(),
                    'claim_token' => null,
                    'lease_expires_at' => null,
                ]);

                return null;
            }

            $claimToken = (string) Str::uuid();
            $build->update([
                'status' => BuildStatus::Running,
                'started_at' => $build->started_at ?? now(),
                'finished_at' => null,
                'claim_token' => $claimToken,
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ]);

            return $claimToken;
        });
    }

    private function hasValidIntent(Build $build): bool
    {
        if ($build->scope === Build::SCOPE_FULL) {
            return $build->target_repo_id === null && $build->target_repo_name === null;
        }

        return $build->scope === Build::SCOPE_REPOSITORY
            && $build->target_repo_id !== null
            && is_string($build->target_repo_name)
            && $build->target_repo_name !== '';
    }

    private function targetRepository(Build $build): ?ServedRepo
    {
        return ServedRepo::query()
            ->whereKey($build->target_repo_id)
            ->where('name', $build->target_repo_name)
            ->first();
    }

    private function executeRepositoryBuild(
        SatisConfigGenerator $generator,
        int $repositoryId,
        string $repositoryName,
        ?Build $build = null,
        ?string $claimToken = null,
    ): void {
        // Queue middleware acquires the archive lock first; removal releases this lock before dispatching archive work.
        app(RepositoryBuildMutex::class)->synchronized(
            $repositoryId,
            function () use ($generator, $repositoryId, $repositoryName, $build, $claimToken): void {
                $servedRepo = ServedRepo::query()
                    ->whereKey($repositoryId)
                    ->where('name', $repositoryName)
                    ->first();
                $build ??= Build::query()->create([
                    'served_repo_id' => $servedRepo?->getKey(),
                    'scope' => Build::SCOPE_REPOSITORY,
                    'target_repo_id' => $repositoryId,
                    'target_repo_name' => $repositoryName,
                    'trigger' => $this->trigger,
                    'status' => BuildStatus::Running,
                    'started_at' => now(),
                ]);

                if (! $servedRepo instanceof ServedRepo) {
                    $this->finishBuild([
                        'status' => BuildStatus::TargetDeleted,
                        'output' => 'The requested repository target was deleted.',
                        'finished_at' => now(),
                    ], $claimToken, $build);

                    return;
                }

                $this->executeBuild(
                    $generator,
                    $build,
                    $servedRepo,
                    $repositoryName,
                    $claimToken,
                );
            },
        );
    }

    private function executeBuild(
        SatisConfigGenerator $generator,
        Build $build,
        ?ServedRepo $servedRepo,
        ?string $package,
        ?string $claimToken = null,
    ): void {
        $tempDir = null;
        $authPath = null;
        $sourceCredentials = [];

        try {
            $this->repositoryQuery($servedRepo, $package)->update(['status' => RepoStatus::Building]);

            $workingDir = storage_path('framework/cache/crate-satis');
            File::ensureDirectoryExists($workingDir);

            $tempDir = $workingDir.'/'.uniqid('build-', true);
            File::makeDirectory($tempDir, 0755, true, true);

            $authPath = $tempDir.'/auth.json';
            $configPath = $tempDir.'/satis.json';
            $outputDir = $tempDir.'/output';

            File::put($configPath, json_encode($generator->generate($package), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $auth = $generator->authConfig();
            $sourceCredentials = $this->sourceCredentials($auth);
            File::put($authPath, json_encode($auth === [] ? (object) [] : $auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            File::ensureDirectoryExists($outputDir);

            if ($package !== null) {
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

            $output = $this->redactedTail($result->output().$result->errorOutput(), $sourceCredentials);

            if ($result->successful()) {
                $this->mirrorOutput($outputDir);

                $finished = $this->finishBuild([
                    'status' => BuildStatus::Succeeded,
                    'output' => $output,
                    'finished_at' => now(),
                ], $claimToken, $build);

                if ($finished) {
                    $this->repositoryQuery($servedRepo, $package)->update([
                        'status' => RepoStatus::Active,
                        'last_built_at' => now(),
                    ]);
                }

                return;
            }

            $finished = $this->finishBuild([
                'status' => BuildStatus::Failed,
                'output' => $output,
                'finished_at' => now(),
            ], $claimToken, $build);

            if ($finished) {
                $this->repositoryQuery($servedRepo, $package)->update(['status' => RepoStatus::Failed]);
            }
        } catch (Throwable $throwable) {
            $finished = $this->finishBuild([
                'status' => BuildStatus::Failed,
                'output' => $this->redactedTail($throwable->getMessage(), $sourceCredentials),
                'finished_at' => now(),
            ], $claimToken, $build);

            if ($finished) {
                $this->repositoryQuery($servedRepo, $package)->update(['status' => RepoStatus::Failed]);
            }
        } finally {
            if ($authPath !== null) {
                File::delete($authPath);
            }

            if ($tempDir !== null) {
                File::deleteDirectory($tempDir);
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private function finishBuild(array $attributes, ?string $claimToken, ?Build $build = null): bool
    {
        $attributes['claim_token'] = null;
        $attributes['lease_expires_at'] = null;

        if ($claimToken === null) {
            $build?->update($attributes);

            return true;
        }

        return Build::query()
            ->whereKey($this->buildId)
            ->where('claim_token', $claimToken)
            ->update($attributes) === 1;
    }

    /** @return Builder<ServedRepo> */
    private function repositoryQuery(?ServedRepo $servedRepo, ?string $package): Builder
    {
        return $package === null
            ? ServedRepo::query()
            : ServedRepo::query()->whereKey($servedRepo?->getKey());
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
        $existing = $disk->allFiles($prefix);
        $published = [];

        foreach (File::allFiles($outputDir) as $file) {
            $relativePath = $file->getRelativePathname();
            $target = $prefix === '' ? $relativePath : $prefix.'/'.$relativePath;

            $disk->put($target, File::get($file->getPathname()));
            $published[] = $target;
        }

        $stale = array_values(array_diff($existing, $published));

        if ($stale !== []) {
            $disk->delete($stale);
        }
    }

    /**
     * @param  array<string, array<string, string>>  $auth
     * @return list<string>
     */
    private function sourceCredentials(array $auth): array
    {
        $credentials = [];

        foreach ($auth as $hosts) {
            foreach ($hosts as $credential) {
                if ($credential !== '') {
                    $credentials[] = $credential;
                }
            }
        }

        return array_values(array_unique($credentials));
    }

    /** @param list<string> $sourceCredentials */
    private function redactedTail(string $output, array $sourceCredentials): string
    {
        $redacted = $output;

        foreach ($sourceCredentials as $credential) {
            $redacted = str_replace($credential, '***', $redacted);
        }

        return mb_substr($redacted, -10000);
    }
}
