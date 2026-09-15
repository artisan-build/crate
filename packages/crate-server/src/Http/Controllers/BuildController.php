<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\CrateServer\Models\Build;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BuildController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! DomainIdentityContext::forUser($user, InstallationAuthority::current())->canUseProduct()) {
            abort(403);
        }

        return response()->json([
            'builds' => Build::query()->latest('id')->get()->map(static fn (Build $build): array => [
                'id' => $build->getKey(),
                'served_repo_id' => $build->served_repo_id,
                'trigger' => $build->trigger,
                'status' => $build->status->value,
                'started_at' => $build->started_at?->toIso8601String(),
                'finished_at' => $build->finished_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
