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
use ArtisanBuild\CrateServer\Mcp\CrateMcpTool;
use ArtisanBuild\CrateServer\RegistryAddress;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The composer.json repository entry that points a project at this registry. Every package Crate
 * serves comes from the one repository, so the entry is the same for each; naming a package also
 * confirms it is served and adds the matching require command.
 */
#[Name('composer_repository')]
#[Description('Return the composer.json repository entry for this Crate registry and the commands that add it to a project. Pass a package (vendor/name) to confirm Crate serves it and get its require command. Apply the entry to the project yourself; this tool never returns credentials.')]
#[IsReadOnly]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class ComposerRepositoryTool extends CrateMcpTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    public const string REPOSITORY_KEY = 'crate';

    /** Build the entry, and the package check when one is named. */
    public function handle(Request $request, RegistryAddress $registry): Response
    {
        app(ActingPrincipalResolver::class)->require(request());

        $input = $this->validated($request, ['package'], [
            'package' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/'],
        ]);
        $url = $registry->url();
        $package = $input['package'] ?? null;
        $key = self::REPOSITORY_KEY;
        $commands = ["composer config repositories.{$key} composer {$url}"];

        if (is_string($package)) {
            $commands[] = "composer require {$package}";
        }

        return Response::json([
            'repository_key' => $key,
            'repository' => ['type' => 'composer', 'url' => $url],
            'composer_json' => [
                'repositories' => [$key => ['type' => 'composer', 'url' => $url]],
            ],
            'commands' => $commands,
            'package' => is_string($package) ? ['name' => $package, 'served' => $this->serves($package)] : null,
            'auth' => [
                'http_basic_host' => $registry->authHost(),
                'developer' => 'Ask the person to open '.route('crate.connect').' and run the one-line command it gives them; it saves a personal credential to their global Composer auth.json.',
                'ci' => 'Call create_project_credential on the write server for a CI or deployment credential.',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()->max(255)->description('A Composer package name, vendor/name, to check and require.'),
        ];
    }

    /** Whether the last build published Composer metadata for the package. */
    private function serves(string $package): bool
    {
        $prefix = trim((string) config('crate-server.output_dir'), '/');
        $path = ($prefix === '' ? '' : $prefix.'/')."p2/{$package}.json";

        return Storage::disk((string) config('crate-server.archive_disk'))->exists($path);
    }
}
