<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Actions\CreateOrReplayMcpBuild;
use ArtisanBuild\CrateServer\Actions\SettleDeletedBuildTarget;
use ArtisanBuild\CrateServer\Contracts\BuildDispatcher;
use ArtisanBuild\CrateServer\Mcp\CrateMcpTool;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Throwable;

#[Name('trigger_build')]
#[Description('Queue a full or repository-specific registry build. A caller-scoped idempotency key suppresses exact duplicates and conflicts on changed requests.')]
#[IsIdempotent]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Write)]
final class TriggerBuildTool extends CrateMcpTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    public function handle(Request $request, ActingPrincipalResolver $principals): Response
    {
        $input = $this->validated($request, ['idempotency_key', 'repository_name'], [
            'idempotency_key' => ['required', 'string', 'min:1', 'max:191'],
            'repository_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $repositoryName = $input['repository_name'] ?? null;
        $identifier = $principals->resolve()->identifier();
        $requestedBy = is_int($identifier) ? (string) $identifier : ($identifier ?? 'installation');
        $fingerprint = hash('sha256', json_encode([
            'repository_name' => $repositoryName,
        ], JSON_THROW_ON_ERROR));
        $build = Build::query()
            ->where('requested_by', $requestedBy)
            ->where('idempotency_key', $input['idempotency_key'])
            ->first();

        if ($build instanceof Build) {
            return $this->replay($build, $fingerprint);
        }

        $repository = is_string($repositoryName)
            ? ServedRepo::query()->where('name', $repositoryName)->first(['id', 'name'])
            : null;

        if (is_string($repositoryName) && ! $repository instanceof ServedRepo) {
            throw ValidationException::withMessages([
                'repository_name' => ['The selected repository name is invalid.'],
            ]);
        }

        $result = app(CreateOrReplayMcpBuild::class)->handle(
            $requestedBy,
            $input['idempotency_key'],
            $fingerprint,
            $repository,
        );
        $build = $result['build'];

        if (! $result['created']) {
            return $this->replay($build, $fingerprint);
        }

        $this->dispatch($build);

        return $this->response($build, false);
    }

    private function replay(Build $build, string $fingerprint): Response
    {
        if (! hash_equals((string) $build->request_fingerprint, $fingerprint)) {
            return Response::error('idempotency_key_conflict');
        }

        $build = app(SettleDeletedBuildTarget::class)->handle($build);

        if ($build->status === BuildStatus::Queued
            || ($build->status === BuildStatus::Running && $build->lease_expires_at?->isPast())) {
            $this->dispatch($build);
        }

        return $this->response($build, true);
    }

    private function dispatch(Build $build): void
    {
        try {
            app(BuildDispatcher::class)->dispatch($build);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function response(Build $build, bool $duplicate): Response
    {
        return Response::json([
            'build_id' => $build->getKey(),
            'status' => $build->status->value,
            'duplicate' => $duplicate,
            'status_handle' => [
                'tool' => 'build_history',
                'arguments' => ['build_ids' => [$build->getKey()]],
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idempotency_key' => $schema->string()->min(1)->max(191)->required(),
            'repository_name' => $schema->string()->max(255)->nullable(),
        ];
    }
}
