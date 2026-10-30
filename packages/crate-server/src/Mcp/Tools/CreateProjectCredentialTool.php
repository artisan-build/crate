<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\CrateServer\Actions\IssueExchangeCode;
use ArtisanBuild\CrateServer\Mcp\CrateMcpTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Issues a single-use exchange code for a project's CI or deployment Composer credential. The tool
 * never returns the credential itself: the agent redeems the code inside the same shell command that
 * stores the result, so the secret goes from Crate straight to the secret store.
 */
#[Name('create_project_credential')]
#[Description('Create a single-use exchange code for a Composer credential owned by a project, for CI or deployments. Redeem it inside the same shell command that stores the result (a GitHub Actions secret, a Laravel Cloud or Forge environment variable). Never print, echo or log the redeemed value. Scalpels admins only.')]
#[IsDestructive(false)]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Write)]
final class CreateProjectCredentialTool extends CrateMcpTool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use RespectsEffectCeiling;

    /** Issue the code for an admin, and explain how to redeem it without seeing the secret. */
    public function handle(Request $request, ActingPrincipalResolver $principals, IssueExchangeCode $issue): Response
    {
        $principal = $principals->require(request());

        if (! $principal->delegated || $principal->role !== ConsoleRole::Admin || $principal->identifier() === null) {
            return Response::error('Only a Scalpels admin can create project credentials.');
        }

        $input = $this->validated($request, ['project'], [
            'project' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/'],
        ]);
        $issued = $issue->forProject($input['project'], (string) $principal->identifier());
        $url = route('crate.exchange.composer-auth', $issued->code);
        $redeem = "curl -fsS -X POST {$url}";

        return Response::json([
            'project' => $input['project'],
            'expires_at' => $issued->expiresAt->toIso8601String(),
            'redeem' => ['method' => 'POST', 'url' => $url, 'returns' => 'A COMPOSER_AUTH JSON document.'],
            'store_examples' => [
                'github_actions_secret' => "gh secret set COMPOSER_AUTH --repo OWNER/REPO --body \"$({$redeem})\"",
                'environment_variable' => "COMPOSER_AUTH=\"$({$redeem})\"",
            ],
            'rules' => [
                'The code works once and expires at expires_at.',
                'Redeem it inside the command that stores the result. Do not run the redeem on its own, and never print, echo or log the result.',
                'Store it as COMPOSER_AUTH; Composer reads that environment variable in CI and during deploys.',
                'Revoke it from the installation credentials screen in Crate.',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->min(1)->max(100)->required()->description('The project the credential is for, such as the GitHub owner/repo. It names the credential.'),
        ];
    }
}
