<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Mcp\CrateMcpTool;
use ArtisanBuild\CrateServer\Mcp\OpaqueCursor;
use ArtisanBuild\CrateServer\Models\Build;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('build_history')]
#[Description('List build status history with stable cursor pagination and optional repository/status filters. Build output is not returned.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class BuildHistoryTool extends CrateMcpTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    private const string CURSOR_SCOPE = 'build_history';

    public function handle(Request $request): Response
    {
        $input = $this->validated($request, ['cursor', 'limit', 'build_ids', 'repository_names', 'statuses'], [
            'cursor' => ['sometimes', 'nullable', 'string'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'build_ids' => ['sometimes', 'list', 'max:100'],
            'build_ids.*' => ['integer', 'min:1'],
            'repository_names' => ['sometimes', 'list', 'max:100'],
            'repository_names.*' => ['string', 'max:255'],
            'statuses' => ['sometimes', 'list', 'max:5'],
            'statuses.*' => ['string', Rule::enum(BuildStatus::class)],
        ]);
        $limit = (int) ($input['limit'] ?? 25);
        $beforeId = OpaqueCursor::decode($input['cursor'] ?? null, self::CURSOR_SCOPE);
        $query = Build::query()->orderByDesc('id');

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        if (($input['build_ids'] ?? []) !== []) {
            $query->whereKey($input['build_ids']);
        }

        if (($input['repository_names'] ?? []) !== []) {
            $query->whereIn('target_repo_name', $input['repository_names']);
        }

        if (($input['statuses'] ?? []) !== []) {
            $query->whereIn('status', $input['statuses']);
        }

        $builds = $query->limit($limit + 1)->get();
        $hasMore = $builds->count() > $limit;
        $builds = $builds->take($limit);

        return Response::json([
            'builds' => $builds->map(static fn (Build $build): array => [
                'id' => $build->getKey(),
                'repository' => $build->scope === Build::SCOPE_FULL ? null : [
                    'id' => $build->target_repo_id,
                    'name' => $build->target_repo_name,
                ],
                'trigger' => $build->trigger,
                'status' => $build->status->value,
                'started_at' => $build->started_at?->toIso8601String(),
                'finished_at' => $build->finished_at?->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $hasMore
                ? OpaqueCursor::encode(self::CURSOR_SCOPE, (int) $builds->last()?->getKey())
                : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->description('Opaque cursor returned by the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
            'build_ids' => $schema->array()->items($schema->integer()->min(1))->max(100),
            'repository_names' => $schema->array()->items($schema->string())->max(100),
            'statuses' => $schema->array()->items($schema->string()->enum(BuildStatus::class))->max(5),
        ];
    }
}
