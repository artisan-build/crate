<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

use ArtisanBuild\CrateServer\Models\ServedRepo;

final class SatisConfigGenerator
{
    /**
     * @return array<string, mixed>
     */
    public function generate(?string $package = null): array
    {
        $repos = ServedRepo::query()->orderBy('name')->get();

        return [
            'name' => 'crate/registry',
            'homepage' => config('crate-server.url'),
            'repositories' => $repos->map(fn (ServedRepo $repo): array => [
                'type' => $repo->type->value,
                'url' => $repo->url,
            ])->values()->all(),
            'require' => $package === null
                ? $repos->pluck('name')->mapWithKeys(fn (string $name): array => [$name => '*'])->all()
                : [$package => '*'],
            'require-dependencies' => false,
            'archive' => [
                'directory' => 'dist',
                'format' => 'zip',
                'prefix-url' => config('crate-server.url'),
                'skip-dev' => false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function authConfig(): array
    {
        $auth = [];

        ServedRepo::query()
            ->whereNotNull('source_credential')
            ->orderBy('name')
            ->get()
            ->each(function (ServedRepo $repo) use (&$auth): void {
                if (blank($repo->source_credential)) {
                    return;
                }

                $host = parse_url($repo->url, PHP_URL_HOST);

                if (! is_string($host) || $host === '') {
                    return;
                }

                $key = $host === 'github.com' ? 'github-oauth' : 'bearer';

                $auth[$key][$host] = $repo->source_credential;
            });

        return $auth;
    }
}
