<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Http\Controllers;

use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\CrateContracts\RepoType;
use ArtisanBuild\CrateServer\Actions\AddServedRepo;
use ArtisanBuild\CrateServer\Actions\RemoveServedRepo;
use ArtisanBuild\CrateServer\Actions\SetSourceCredential;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class RepositoryController
{
    public function index(Request $request): JsonResponse
    {
        $this->member($request);

        return response()->json([
            'repositories' => ServedRepo::query()->orderBy('name')->get()->map(fn (ServedRepo $repo): array => $this->serialize($repo))->all(),
        ]);
    }

    public function store(Request $request, AddServedRepo $add): JsonResponse
    {
        $this->administrator($request);

        /** @var array{name: string, url: string, type: string, source_credential?: string|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string'],
            'url' => ['required', 'url:http,https'],
            'type' => ['sometimes', 'string', Rule::enum(RepoType::class)],
            'source_credential' => ['sometimes', 'nullable', 'string'],
        ]);

        try {
            $repo = $add(
                $input['name'],
                $input['url'],
                $input['type'] ?? RepoType::Vcs->value,
                $input['source_credential'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['repository' => $this->serialize($repo)], 201);
    }

    public function destroy(Request $request, RemoveServedRepo $remove, string $name): Response
    {
        $this->administrator($request);

        return $remove($name) ? response()->noContent() : abort(404);
    }

    public function replaceSourceCredential(Request $request, SetSourceCredential $set, string $name): JsonResponse
    {
        $this->member($request);

        /** @var array{source_credential: string} $input */
        $input = $request->validate(['source_credential' => ['required', 'string', 'min:1']]);
        $repo = ServedRepo::query()->where('name', strtolower($name))->firstOrFail();

        return response()->json(['repository' => $this->serialize($set($repo, $input['source_credential']))]);
    }

    public function clearSourceCredential(Request $request, SetSourceCredential $set, string $name): Response
    {
        $this->member($request);
        $repo = ServedRepo::query()->where('name', strtolower($name))->firstOrFail();
        $set($repo, null);

        return response()->noContent();
    }

    private function member(Request $request): DomainIdentityContext
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $identity = DomainIdentityContext::forUser($user, InstallationAuthority::current());

        if (! $identity->canUseProduct()) {
            abort(403);
        }

        return $identity;
    }

    private function administrator(Request $request): DomainIdentityContext
    {
        $identity = $this->member($request);

        if (! $identity->canManageMembers()) {
            abort(403);
        }

        return $identity;
    }

    /** @return array<string, mixed> */
    private function serialize(ServedRepo $repo): array
    {
        return [
            'id' => $repo->getKey(),
            'name' => $repo->name,
            'url' => $repo->url,
            'type' => $repo->type->value,
            'status' => $repo->status->value,
            'has_source_credential' => filled($repo->source_credential),
            'last_built_at' => $repo->last_built_at?->toIso8601String(),
        ];
    }
}
