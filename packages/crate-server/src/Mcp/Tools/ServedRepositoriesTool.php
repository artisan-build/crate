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
use ArtisanBuild\CrateServer\Mcp\CrateMcpTool;
use ArtisanBuild\CrateServer\Mcp\OpaqueCursor;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('served_repositories')]
#[Description('List served repositories with stable cursor pagination. Returns credential presence only, never credential values.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class ServedRepositoriesTool extends CrateMcpTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    private const string CURSOR_SCOPE = 'served_repositories';

    public function handle(Request $request): Response
    {
        $input = $this->validated($request, ['cursor', 'limit'], [
            'cursor' => ['sometimes', 'nullable', 'string'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $limit = (int) ($input['limit'] ?? 25);
        $afterId = OpaqueCursor::decode($input['cursor'] ?? null, self::CURSOR_SCOPE);
        $query = ServedRepo::query()
            ->select(['id', 'name', 'url', 'type', 'status', 'last_built_at'])
            ->selectRaw('source_credential IS NOT NULL AS has_source_credential')
            ->orderBy('id');

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $repositories = $query->limit($limit + 1)->get();
        $hasMore = $repositories->count() > $limit;
        $repositories = $repositories->take($limit);

        return Response::json([
            'repositories' => $repositories->map(static fn (ServedRepo $repo): array => [
                'id' => $repo->getKey(),
                'name' => $repo->name,
                'url' => $repo->url,
                'type' => $repo->type->value,
                'status' => $repo->status->value,
                'has_source_credential' => (bool) $repo->getAttribute('has_source_credential'),
                'last_built_at' => $repo->last_built_at?->toIso8601String(),
            ])->values()->all(),
            'next_cursor' => $hasMore
                ? OpaqueCursor::encode(self::CURSOR_SCOPE, (int) $repositories->last()?->getKey())
                : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->description('Opaque cursor returned by the previous page.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}
