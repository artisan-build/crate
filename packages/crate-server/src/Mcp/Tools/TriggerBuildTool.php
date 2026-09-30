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
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Mcp\CrateMcpTool;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

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
            'repository_name' => ['sometimes', 'nullable', 'string', 'max:255', 'exists:crate.served_repos,name'],
        ]);
        $repositoryName = $input['repository_name'] ?? null;
        $repository = is_string($repositoryName)
            ? ServedRepo::query()->where('name', $repositoryName)->firstOrFail(['id', 'name'])
            : null;
        $identifier = $principals->resolve()->identifier();
        $requestedBy = is_int($identifier) ? (string) $identifier : ($identifier ?? 'installation');
        $fingerprint = hash('sha256', json_encode([
            'repository_name' => $repositoryName,
        ], JSON_THROW_ON_ERROR));
        $created = false;

        try {
            $build = DB::connection('crate')->transaction(function () use (
                $input,
                $repository,
                $requestedBy,
                $fingerprint,
                &$created,
            ): Build {
                $created = true;

                return Build::query()->create([
                    'served_repo_id' => $repository?->getKey(),
                    'trigger' => 'mcp',
                    'status' => BuildStatus::Queued,
                    'requested_by' => $requestedBy,
                    'idempotency_key' => $input['idempotency_key'],
                    'request_fingerprint' => $fingerprint,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $created = false;
            $build = Build::query()
                ->where('requested_by', $requestedBy)
                ->where('idempotency_key', $input['idempotency_key'])
                ->firstOrFail();
        }

        if (! hash_equals((string) $build->request_fingerprint, $fingerprint)) {
            return Response::error('idempotency_key_conflict');
        }

        if ($created) {
            BuildSatis::dispatch($repositoryName, 'mcp', (int) $build->getKey())->afterCommit();
        }

        return Response::json([
            'build_id' => $build->getKey(),
            'status' => $build->status->value,
            'duplicate' => ! $created,
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
