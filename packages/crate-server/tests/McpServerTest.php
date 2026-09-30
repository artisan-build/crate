<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\DelegatedClaims;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Mcp\CrateReadMcpServer;
use ArtisanBuild\CrateServer\Mcp\CrateWriteMcpServer;
use ArtisanBuild\CrateServer\Mcp\Tools\BuildHistoryTool;
use ArtisanBuild\CrateServer\Mcp\Tools\ServedRepositoriesTool;
use ArtisanBuild\CrateServer\Mcp\Tools\TriggerBuildTool;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

it('advertises only delegated effect-scoped read and write doors', function (): void {
    $metadata = $this->getJson('/bfc/meta')->assertOk();

    expect($metadata->json('capabilities'))->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped')
        ->and($metadata->json('endpoints'))->toMatchArray([
            'mcp' => '/mcp',
            'mcp_write' => '/mcp/write',
        ])
        ->and($metadata->json('endpoints'))->not->toHaveKey('mcp_destructive');
});

it('conforms exactly the three effect-scoped tools', function (): void {
    McpDelegatedTools::assertConforms(CrateReadMcpServer::class);
    McpDelegatedTools::assertConforms(CrateWriteMcpServer::class);

    expect(McpDelegatedTools::discover(CrateReadMcpServer::class))->toBe([
        'tools' => [BuildHistoryTool::class, ServedRepositoriesTool::class],
        'violations' => [],
    ])->and(McpDelegatedTools::discover(CrateWriteMcpServer::class))->toBe([
        'tools' => [TriggerBuildTool::class],
        'violations' => [],
    ]);
});

it('exposes exact tool sets per HTTP door and refuses foreign or cross-door calls without effects', function (): void {
    Bus::fake();
    $token = crateMcpCredential();
    ServedRepo::factory()->create();

    $readTools = crateMcpPost('/mcp', crateMcpPayload('tools/list'), $token)
        ->assertOk()->json('result.tools.*.name');
    $writeTools = crateMcpPost('/mcp/write', crateMcpPayload('tools/list'), $token)
        ->assertOk()->json('result.tools.*.name');
    sort($readTools);
    sort($writeTools);

    expect($readTools)->toBe(['build_history', 'served_repositories'])
        ->and($writeTools)->toBe(['trigger_build']);

    crateMcpPost('/mcp', crateMcpCall('served_repositories'), $token)->assertOk();
    crateMcpPost('/mcp', crateMcpCall('build_history'), $token)->assertOk();

    foreach ([
        ['/mcp', 'trigger_build'],
        ['/mcp', 'delete_repository'],
        ['/mcp/write', 'served_repositories'],
        ['/mcp/write', 'purge'],
    ] as [$path, $tool]) {
        crateMcpPost($path, crateMcpCall($tool), $token)->assertStatus(400);
    }

    foreach (['served_repositories', 'build_history'] as $tool) {
        crateMcpPost('/mcp', crateMcpCall($tool), 'foreign-installation-token')->assertUnauthorized();
    }
    crateMcpPost('/mcp/write', crateMcpCall('trigger_build', [
        'idempotency_key' => 'foreign-key',
    ]), 'foreign-installation-token')->assertUnauthorized();

    expect(Build::query()->count())->toBe(0);
    Bus::assertNothingDispatched();

    $triggerPayload = crateMcpCall('trigger_build', [
        'idempotency_key' => 'local-key',
    ]);
    $triggerResponse = crateMcpPost('/mcp/write', $triggerPayload, $token)->assertOk();
    $triggerResponse->assertJsonPath('result.isError', false);
    expect(Build::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(BuildSatis::class, 1);
});

it('paginates more than one hundred repositories without skips or credential disclosure', function (): void {
    $sentinel = 'crate-source-credential-sentinel';
    ServedRepo::factory()->count(105)->sequence(
        fn ($sequence): array => [
            'name' => sprintf('vendor/package-%03d', $sequence->index),
            'source_credential' => $sequence->index === 52 ? $sentinel : null,
        ],
    )->create();

    $first = crateToolJson(app(ServedRepositoriesTool::class)->handle(new Request(['limit' => 100])));
    $second = crateToolJson(app(ServedRepositoriesTool::class)->handle(new Request([
        'cursor' => $first['next_cursor'],
        'limit' => 100,
    ])));
    $rows = [...$first['repositories'], ...$second['repositories']];

    expect($first['repositories'])->toHaveCount(100)
        ->and($second['repositories'])->toHaveCount(5)
        ->and(array_column($rows, 'id'))->toBe(range(1, 105))
        ->and(array_unique(array_column($rows, 'id')))->toHaveCount(105)
        ->and(json_encode([$first, $second]))->not->toContain($sentinel)
        ->and($rows[52]['has_source_credential'])->toBeTrue();
});

it('paginates and filters more than one hundred builds with a stable descending cursor', function (): void {
    $alpha = ServedRepo::factory()->create(['name' => 'vendor/alpha']);
    $beta = ServedRepo::factory()->create(['name' => 'vendor/beta']);

    Build::factory()->count(105)->sequence(
        fn ($sequence): array => [
            'served_repo_id' => $sequence->index % 2 === 0 ? $alpha->getKey() : $beta->getKey(),
            'status' => $sequence->index % 3 === 0 ? BuildStatus::Failed : BuildStatus::Succeeded,
        ],
    )->create();

    $first = crateToolJson(app(BuildHistoryTool::class)->handle(new Request(['limit' => 100])));
    $second = crateToolJson(app(BuildHistoryTool::class)->handle(new Request([
        'cursor' => $first['next_cursor'],
        'limit' => 100,
    ])));
    $ids = array_column([...$first['builds'], ...$second['builds']], 'id');
    $filtered = crateToolJson(app(BuildHistoryTool::class)->handle(new Request([
        'repository_names' => ['vendor/alpha'],
        'statuses' => ['failed'],
        'limit' => 100,
    ])));

    expect($first['builds'])->toHaveCount(100)
        ->and($second['builds'])->toHaveCount(5)
        ->and($ids)->toBe(range(105, 1))
        ->and(array_unique($ids))->toHaveCount(105)
        ->and($filtered['builds'])->not->toBeEmpty();

    foreach ($filtered['builds'] as $build) {
        expect($build['repository']['name'])->toBe('vendor/alpha')
            ->and($build['status'])->toBe('failed');
    }
});

it('closes wire schemas and rejects malformed runtime arguments', function (): void {
    $repositorySchema = app(ServedRepositoriesTool::class)->toArray()['inputSchema'];
    $historySchema = app(BuildHistoryTool::class)->toArray()['inputSchema'];
    $triggerSchema = app(TriggerBuildTool::class)->toArray()['inputSchema'];

    expect($repositorySchema['additionalProperties'])->toBeFalse()
        ->and($historySchema['additionalProperties'])->toBeFalse()
        ->and($triggerSchema['additionalProperties'])->toBeFalse()
        ->and($historySchema['properties']['repository_names']['type'])->toBe('array')
        ->and($historySchema['properties']['statuses']['items']['enum'])->toBe(array_column(BuildStatus::cases(), 'value'));

    $invalidRepositories = [
        ['unknown' => true],
        [0 => 'numeric-key'],
        ['limit' => 0],
        ['limit' => 101],
        ['cursor' => 'invalid!'],
    ];
    foreach ($invalidRepositories as $arguments) {
        expect(fn () => app(ServedRepositoriesTool::class)->handle(new Request($arguments)))
            ->toThrow(ValidationException::class);
    }

    $invalidHistory = [
        ['repository_names' => ['name' => 'vendor/alpha']],
        ['statuses' => ['status' => 'failed']],
        ['statuses' => ['unknown']],
        ['build_ids' => ['id' => 1]],
        ['cursor' => base64_encode('{"v":1,"scope":"served_repositories","id":1}')],
    ];
    foreach ($invalidHistory as $arguments) {
        expect(fn () => app(BuildHistoryTool::class)->handle(new Request($arguments)))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => app(TriggerBuildTool::class)->handle(
        new Request(['idempotency_key' => 'key', 'extra' => true]),
        app(ActingPrincipalResolver::class),
    ))->toThrow(ValidationException::class);
});

it('rejects malformed argument shapes through the HTTP wire', function (): void {
    $token = crateMcpCredential();

    foreach ([
        ['/mcp', 'served_repositories', ['limit' => 0]],
        ['/mcp', 'served_repositories', ['limit' => 101]],
        ['/mcp', 'served_repositories', ['unknown' => true]],
        ['/mcp', 'build_history', ['repository_names' => ['name' => 'vendor/alpha']]],
        ['/mcp', 'build_history', ['statuses' => ['unknown']]],
        ['/mcp', 'build_history', ['cursor' => 'invalid!']],
        ['/mcp/write', 'trigger_build', ['idempotency_key' => 'wire', 'unknown' => true]],
    ] as [$path, $tool, $arguments]) {
        crateMcpPost($path, crateMcpCall($tool, $arguments), $token)
            ->assertOk()
            ->assertJsonPath('result.isError', true);
    }

    expect(Build::query()->count())->toBe(0);
});

it('atomically suppresses exact duplicate trigger requests and conflicts on changed reuse', function (): void {
    Bus::fake();
    $alpha = ServedRepo::factory()->create(['name' => 'vendor/alpha']);
    ServedRepo::factory()->create(['name' => 'vendor/beta']);
    $tool = app(TriggerBuildTool::class);
    $principals = app(ActingPrincipalResolver::class);

    $first = crateToolJson($tool->handle(new Request([
        'idempotency_key' => 'build-key',
        'repository_name' => $alpha->name,
    ]), $principals));
    $duplicate = crateToolJson($tool->handle(new Request([
        'idempotency_key' => 'build-key',
        'repository_name' => $alpha->name,
    ]), $principals));
    $conflict = $tool->handle(new Request([
        'idempotency_key' => 'build-key',
        'repository_name' => 'vendor/beta',
    ]), $principals);

    expect($first['duplicate'])->toBeFalse()
        ->and($duplicate['duplicate'])->toBeTrue()
        ->and($duplicate['build_id'])->toBe($first['build_id'])
        ->and($first['status_handle'])->toBe([
            'tool' => 'build_history',
            'arguments' => ['build_ids' => [$first['build_id']]],
        ])
        ->and((string) $conflict->content())->toBe('idempotency_key_conflict')
        ->and($conflict->isError())->toBeTrue()
        ->and(Build::query()->count())->toBe(1);

    Bus::assertDispatchedTimes(BuildSatis::class, 1);

    expect(fn () => Build::query()->create([
        'served_repo_id' => $alpha->getKey(),
        'trigger' => 'mcp',
        'status' => BuildStatus::Queued,
        'requested_by' => 'installation',
        'idempotency_key' => 'build-key',
        'request_fingerprint' => str_repeat('a', 64),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('preserves the class-qualified delegated actor on triggered builds', function (): void {
    Bus::fake();
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('https://scalpels.test', 'operator-1'),
        'issuer' => 'https://scalpels.test',
        'subject' => 'operator-1',
        'last_handoff_display_name' => 'Operator',
        'last_handoff_role' => ConsoleRole::Member,
    ]);
    $principal = ActingPrincipal::delegatedRequest($actor, new DelegatedClaims(
        displayName: 'Operator',
        role: ConsoleRole::Member,
        onBehalfOf: 'Team',
    ));
    app('request')->attributes->set('bfc.request_assertion_principal', $principal);

    app(TriggerBuildTool::class)->handle(
        new Request(['idempotency_key' => 'delegated-key']),
        app(ActingPrincipalResolver::class),
    );

    expect(Build::query()->firstOrFail()->requested_by)->toBe($actor->getAuthIdentifier())
        ->and($actor->getAuthIdentifier())->toStartWith(DelegatedActor::IDENTIFIER_PREFIX);
});

it('passes the framework MCP product-admission conformance helper', function (): void {
    McpProductAdmission::assert();
});

function crateMcpCredential(): string
{
    $plaintext = 'crate-mcp-'.bin2hex(random_bytes(16));
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'crate-installation',
        'name' => 'Crate MCP',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $plaintext),
    ]);

    return $plaintext;
}

/** @return array<string, mixed> */
function crateMcpPayload(string $method): array
{
    return ['jsonrpc' => '2.0', 'id' => str()->random(8), 'method' => $method];
}

/** @param array<string, mixed> $arguments */
function crateMcpCall(string $name, array $arguments = []): array
{
    return crateMcpPayload('tools/call') + [
        'params' => ['name' => $name, 'arguments' => $arguments],
    ];
}

/** @param array<string, mixed> $payload */
function crateMcpPost(string $path, array $payload, string $token): TestResponse
{
    return test()->postJson($path, $payload, ['Authorization' => 'Bearer '.$token]);
}

/** @return array<string, mixed> */
function crateToolJson(Response $response): array
{
    return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
}
