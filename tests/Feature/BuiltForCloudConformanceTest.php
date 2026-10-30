<?php

declare(strict_types=1);

use App\Console\Commands\CrateInstallCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\FreshCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\PruneCredentialAuthorizationsCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootEnsureCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedEnforcement;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\CrateClient\Commands\CrateAuthCommand;
use ArtisanBuild\CrateServer\Commands\CrateBuildCommand;
use ArtisanBuild\CrateServer\Commands\CrateInstallSatisCommand;
use ArtisanBuild\CrateServer\Commands\CrateRecoverBuildsCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposAddCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposListCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposRemoveCommand;
use ArtisanBuild\CrateServer\CrateCredentialDeclaration;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Mcp\CrateReadMcpServer;
use ArtisanBuild\CrateServer\Mcp\CrateWriteMcpServer;
use ArtisanBuild\CrateServer\Mcp\Tools\BuildHistoryTool;
use ArtisanBuild\CrateServer\Mcp\Tools\ComposerRepositoryTool;
use ArtisanBuild\CrateServer\Mcp\Tools\CreateProjectCredentialTool;
use ArtisanBuild\CrateServer\Mcp\Tools\ServedRepositoriesTool;
use ArtisanBuild\CrateServer\Mcp\Tools\TriggerBuildTool;
use ArtisanBuild\CrateServer\Models\Build;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Response;

uses(ContractAssertions::class);

it('passes the unified auth consumer conformance spec', function (): void {
    Bus::fake();

    $packageRoot = InstalledVersions::getInstallPath('artisan-build/built-for-cloud');
    expect($packageRoot)->toBeString();

    // Package issue #123: scanning bootstrap/cache reports generated cache files.
    // Omit that directory while retaining bootstrap/app.php as a provider file.
    $sourceRoots = [
        app_path(),
        config_path(),
        base_path('packages/crate-client/src'),
        base_path('packages/crate-contracts/src'),
        base_path('packages/crate-server/src'),
        base_path('routes'),
    ];
    sort($sourceRoots);
    $providerFiles = [
        app_path('Providers/AppServiceProvider.php'),
        base_path('bootstrap/app.php'),
        base_path('packages/crate-client/src/CrateClientServiceProvider.php'),
        base_path('packages/crate-server/src/CrateServerServiceProvider.php'),
        $packageRoot.'/src/BuiltForCloudServiceProvider.php',
    ];
    sort($providerFiles);

    $sorted = static function (array $members): array {
        sort($members);

        return $members;
    };
    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sorted([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment+CompleteAsymmetricEnrollment+AsymmetricVerificationKeys',
            'path:device|Http\Controllers\DeviceAuthorizations+Actions\StartDeviceAuthorization/DecideDeviceAuthorization/PollDeviceAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:loopback|Http\Controllers\LoopbackAuthorizations+Actions\StartLoopbackAuthorization/DecideLoopbackAuthorization/ExchangeLoopbackAuthorization+BoundBearerCredentialAuthenticator+ContainCredentialAuthorizations',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sorted([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sorted([
            ConsoleReKeyCommand::class,
            ConsoleRetireKeyCommand::class,
            CreateAdminCommand::class,
            CredentialActivateCommand::class,
            CredentialListCommand::class,
            CredentialMintCommand::class,
            CredentialRevokeCommand::class,
            CredentialRotateCommand::class,
            FreshCommand::class,
            HmacRewrapCommand::class,
            InstallOperatorCredentialCommand::class,
            OutboxDrainCommand::class,
            OwnershipMintClaimCommand::class,
            OwnershipRemintOwnerTokenCommand::class,
            PruneCredentialAuthorizationsCommand::class,
            SigningRootEnsureCommand::class,
            SigningRootProvisionCommand::class,
            SubjectOffboardCommand::class,
            WarnExpiringCredentialsCommand::class,
            DeliverOwnershipWebhook::class,
            CrateInstallCommand::class,
            CrateAuthCommand::class,
            CrateBuildCommand::class,
            CrateInstallSatisCommand::class,
            CrateRecoverBuildsCommand::class,
            CrateReposAddCommand::class,
            CrateReposListCommand::class,
            CrateReposRemoveCommand::class,
            BuildSatis::class,
            'Closure@package/src/SystemAuthoritySchedule.php:27',
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sorted([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationStore|built-for-cloud.manifest.slug|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => $sorted([
            BuildHistoryTool::class,
            ComposerRepositoryTool::class,
            ServedRepositoriesTool::class,
        ]),
    ];

    McpDelegatedEnforcement::assertHonors(
        CrateReadMcpServer::class,
        [ServedRepositoriesTool::class, BuildHistoryTool::class, ComposerRepositoryTool::class],
    );
    McpDelegatedEnforcement::assertHonors(
        CrateWriteMcpServer::class,
        [TriggerBuildTool::class, CreateProjectCredentialTool::class],
        negativeAuthorization: static function (
            ActingPrincipal $first,
            ActingPrincipal $second,
            Closure $invoke,
        ): void {
            $idempotencyKey = 'x';
            $firstIdentifier = (string) $first->identifier();
            $secondIdentifier = (string) $second->identifier();
            $firstBuild = Build::query()
                ->where('requested_by', $firstIdentifier)
                ->where('idempotency_key', $idempotencyKey)
                ->sole();

            $collidingUser = new User;
            $collidingUser->forceFill([
                'id' => $first->delegatedActor?->getKey(),
                'name' => 'Numeric Collision',
                'email' => 'numeric-collision@example.test',
            ])->save();

            $response = $invoke(TriggerBuildTool::class, [
                'idempotency_key' => $idempotencyKey,
            ]);
            expect($response)->toBeInstanceOf(Response::class);
            $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
            $secondBuild = Build::query()->findOrFail($payload['build_id']);

            expect($firstIdentifier)->toStartWith('bfc-console:')
                ->and($secondIdentifier)->toStartWith('bfc-console:')
                ->and($first->delegatedActor?->getKey())->toBe($collidingUser->getKey())
                ->and($firstIdentifier)->not->toBe((string) $collidingUser->getAuthIdentifier())
                ->and($payload['duplicate'])->toBeFalse()
                ->and($secondBuild->getKey())->not->toBe($firstBuild->getKey())
                ->and($secondBuild->requested_by)->toBe($secondIdentifier)
                ->and($firstBuild->requested_by)->toBe($firstIdentifier)
                ->and(Build::query()->where('idempotency_key', $idempotencyKey)->count())->toBe(2)
                ->and(Build::query()->where('requested_by', (string) $collidingUser->getAuthIdentifier())->exists())
                ->toBeFalse();
        },
    );

    $report = (new FleetConformance($this))->assert(new ConsumerConformance(
        consumer: 'crate',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['credentials', 'mcp-delegated', 'mcp-effect-scoped', 'mcp-serve', 'tokens'],
        purposeMappings: [CrateCredentialDeclaration::COMPOSER_PURPOSE => CredentialPurpose::Consumption],
        mcpServer: CrateReadMcpServer::class,
        expected: $expected,
    ));

    expect($report->passed)->toBeTrue()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES);
});
