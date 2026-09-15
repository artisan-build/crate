<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\SubmissionNonce;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateContracts\RepoStatus;
use ArtisanBuild\CrateServer\CrateCredentialDeclaration;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

function crateProductUser(UserRole|string $role): User
{
    $role = $role instanceof UserRole ? $role->value : $role;
    $user = User::query()->create([
        'name' => 'Product '.$role,
        'email' => $role.'-'.bin2hex(random_bytes(6)).'@example.test',
    ]);
    $user->forceFill([
        'role' => $role,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

function crateSubmissionNonce(TestResponse $response, string $action): string
{
    $matched = preg_match(
        '/<form[^>]+action="'.preg_quote($action, '/').'".*?name="submission_nonce" value="([a-f0-9]{64})"/s',
        (string) $response->getContent(),
        $matches,
    );

    expect($matched)->toBe(1);

    if (str_contains($action, '/credentials/installation')) {
        test()->withCookie((string) config('session.cookie'), resolve('session')->getId());
    }

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
}

function crateDeliveredPassword(TestResponse $response): string
{
    $matched = preg_match(
        '/<strong>password<\/strong>:\s*<code>([^<]+)<\/code>/',
        (string) $response->getContent(),
        $matches,
    );

    expect($matched)->toBe(1);

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
}

function crateBasicCredential(array $attributes = []): array
{
    $secret = 'crate-product-'.bin2hex(random_bytes(16));
    $credential = Credential::query()->create($attributes + [
        'kind' => CredentialKind::Basic,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'deployment-automation',
        'name' => 'Product flow credential',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $secret),
    ]);

    return [$credential, $secret];
}

function crateProductRepo(array $attributes = []): ServedRepo
{
    return ServedRepo::query()->create($attributes + [
        'name' => 'vendor/'.bin2hex(random_bytes(4)),
        'url' => 'https://example.test/repository.git',
        'type' => 'vcs',
        'status' => RepoStatus::Pending,
    ]);
}

function assertCrateProductUnauthorized(TestResponse $response): void
{
    $response->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Basic realm="Crate"');
    expect($response->baseResponse)->not->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getContent())->toBe('');
}

beforeEach(function (): void {
    if (! Schema::connection('crate')->hasTable('served_repos')) {
        (require base_path('packages/crate-server/database/migrations/2026_07_08_000001_create_served_repos_table.php'))->up();
        (require base_path('packages/crate-server/database/migrations/2026_07_08_000002_create_builds_table.php'))->up();
    }

    Storage::fake('local');
    Storage::disk('local')->put('satis/packages.json', '{"packages":[]}');
    Storage::disk('local')->put('satis/p2/vendor/package.json', '{"packages":{"vendor/package":[]}}');
    Storage::disk('local')->put('satis/dist/vendor/package/1.0.0.zip', 'test-created-zip');
});

it('gives each role the whole repository through personal Basic consumption lifecycle', function (UserRole $role): void {
    $repo = crateProductRepo([
        'name' => 'vendor/package',
        'status' => RepoStatus::Active,
        'last_built_at' => now(),
    ]);
    Build::query()->create([
        'served_repo_id' => $repo->getKey(),
        'trigger' => 'manual',
        'status' => BuildStatus::Succeeded,
    ]);
    $user = crateProductUser($role);

    $this->actingAsVersioned($user, 'web')
        ->getJson(route('crate.repositories.index'))
        ->assertOk()
        ->assertJsonPath('repositories.0.name', 'vendor/package')
        ->assertJsonMissingPath('repositories.0.source_credential');
    $this->getJson(route('crate.builds.index'))
        ->assertOk()
        ->assertJsonPath('builds.0.status', BuildStatus::Succeeded->value);

    $page = $this->get(route('bfc.ui.personal-credentials.index'))
        ->assertOk()
        ->assertSeeHtml('data-testid="personal-credentials"')
        ->assertSee(CrateCredentialDeclaration::COMPOSER_PURPOSE)
        ->assertSee(CredentialKind::Basic->value);
    $name = 'personal-'.$role->value;
    $issue = $this->post(route('bfc.ui.personal-credentials.store'), [
        SubmissionNonce::FIELD => crateSubmissionNonce($page, route('bfc.ui.personal-credentials.store')),
        'app_purpose' => CrateCredentialDeclaration::COMPOSER_PURPOSE,
        'kind' => CredentialKind::Basic->value,
        'name' => $name,
    ])->assertCreated()->assertSeeHtml('data-testid="personal-credentials-delivery"');
    $credential = Credential::query()->where('name', $name)->sole();
    $secret = crateDeliveredPassword($issue);

    expect($credential->subject_type)->toBe(SubjectType::UserPrincipal)
        ->and($credential->subject_ref)->toBe('crate-user:'.$user->getKey())
        ->and((string) $credential->user_id)->toBe((string) $user->getKey())
        ->and($credential->purpose)->toBe(CredentialPurpose::Consumption);
    $this->get(route('bfc.ui.personal-credentials.index'))
        ->assertOk()->assertSee($name)->assertDontSee($secret);

    $authPath = sys_get_temp_dir().'/crate-product-auth-'.bin2hex(random_bytes(6)).'.json';
    config(['crate-client.url' => 'https://localhost', 'crate-client.token' => $secret]);
    try {
        $this->artisan('crate:auth', ['--path' => $authPath])->assertSuccessful();
        expect(json_decode((string) file_get_contents($authPath), true))->toMatchArray([
            'http-basic' => ['localhost' => ['username' => 'token', 'password' => $secret]],
        ]);
    } finally {
        if (file_exists($authPath)) {
            unlink($authPath);
        }
    }

    expect($this->withBasicAuth('token', $secret)->get('/packages.json')->streamedContent())->toBe('{"packages":[]}')
        ->and($this->withBasicAuth('token', $secret)->get('/p2/vendor/package.json')->streamedContent())->toBe('{"packages":{"vendor/package":[]}}')
        ->and($this->withBasicAuth('token', $secret)->get('/dist/vendor/package/1.0.0.zip')->streamedContent())->toBe('test-created-zip');

    $rotateAction = route('bfc.ui.personal-credentials.rotate', $credential->id);
    $rotate = $this->post($rotateAction, [
        SubmissionNonce::FIELD => crateSubmissionNonce($issue, $rotateAction),
    ])->assertCreated()->assertSeeHtml('data-testid="personal-credentials-delivery"');
    $replacement = Credential::query()->where('name', $name)->whereKeyNot($credential->id)->sole();
    $replacementSecret = crateDeliveredPassword($rotate);
    expect($credential->refresh()->rotated_at)->not->toBeNull();
    $this->withBasicAuth('token', $replacementSecret)->get('/packages.json')->assertOk();

    $this->delete(route('bfc.ui.personal-credentials.destroy', $replacement->id))
        ->assertStatus(303)
        ->assertRedirect(route('bfc.ui.personal-credentials.index'));
    assertCrateProductUnauthorized($this->withBasicAuth('token', $replacementSecret)->get('/packages.json'));
})->with(UserRole::cases());

it('lets every role manage an installation credential issued by another member', function (UserRole $role): void {
    $issuer = crateProductUser(UserRole::Member);
    $actor = crateProductUser($role);
    $name = 'cross-issuer-'.$role->value;

    $page = $this->actingAsVersioned($issuer, 'web')->get(route('bfc.ui.installation-credentials.index'))->assertOk();
    $issue = $this->post(route('bfc.ui.installation-credentials.store'), [
        SubmissionNonce::FIELD => crateSubmissionNonce($page, route('bfc.ui.installation-credentials.store')),
        'app_purpose' => CrateCredentialDeclaration::COMPOSER_PURPOSE,
        'kind' => CredentialKind::Basic->value,
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'automation-'.$role->value,
        'name' => $name,
    ])->assertCreated();
    $credential = Credential::query()->where('name', $name)->sole();
    $secret = crateDeliveredPassword($issue);
    $this->withBasicAuth('token', $secret)->get('/packages.json')->assertOk();

    $actorPage = $this->actingAsVersioned($actor, 'web')
        ->get(route('bfc.ui.installation-credentials.index'))
        ->assertOk()->assertSee($name)->assertDontSee($secret);
    $rotateAction = route('bfc.ui.installation-credentials.rotate', $credential->id);
    $rotate = $this->post($rotateAction, [
        SubmissionNonce::FIELD => crateSubmissionNonce($actorPage, $rotateAction),
    ])->assertCreated();
    $replacement = Credential::query()->where('name', $name)->whereKeyNot($credential->id)->sole();
    $replacementSecret = crateDeliveredPassword($rotate);
    $this->withBasicAuth('token', $replacementSecret)->get('/packages.json')->assertOk();

    $this->actingAsVersioned($actor, 'web')
        ->delete(route('bfc.ui.installation-credentials.destroy', $replacement->id))
        ->assertStatus(303);
    assertCrateProductUnauthorized($this->withBasicAuth('token', $replacementSecret)->get('/packages.json'));
})->with(UserRole::cases());

it('allows only owners and admins to add and remove repositories', function (UserRole $role): void {
    Bus::fake();
    $user = crateProductUser($role);

    $this->actingAsVersioned($user, 'web')->postJson(route('crate.repositories.store'), [
        'name' => 'vendor/'.$role->value,
        'url' => 'https://example.test/'.$role->value.'.git',
    ])->assertCreated();
    expect(ServedRepo::query()->where('name', 'vendor/'.$role->value)->exists())->toBeTrue();
    $this->deleteJson(route('crate.repositories.destroy', ['name' => 'vendor/'.$role->value]))
        ->assertNoContent();
    expect(ServedRepo::query()->where('name', 'vendor/'.$role->value)->exists())->toBeFalse();
})->with([UserRole::Owner, UserRole::Admin]);

it('denies member add and remove without mutation', function (): void {
    Bus::fake();
    $repo = crateProductRepo(['name' => 'vendor/existing']);
    $user = crateProductUser(UserRole::Member);
    $before = ServedRepo::query()->count();

    $this->actingAsVersioned($user, 'web')->postJson(route('crate.repositories.store'), [
        'name' => 'vendor/forbidden',
        'url' => 'https://example.test/forbidden.git',
    ])->assertForbidden();
    $this->deleteJson(route('crate.repositories.destroy', ['name' => $repo->name]))->assertForbidden();

    expect(ServedRepo::query()->count())->toBe($before)
        ->and($repo->fresh())->not->toBeNull();
    Bus::assertNothingDispatched();
});

it('denies unknown roles from repository and build reads with exact statuses', function (): void {
    crateProductRepo(['name' => 'vendor/hidden']);
    Build::query()->create(['trigger' => 'manual', 'status' => BuildStatus::Queued]);
    $user = crateProductUser('unknown');

    $this->actingAsVersioned($user, 'web')
        ->getJson(route('crate.repositories.index'))
        ->assertForbidden();
    $this->getJson(route('crate.builds.index'))->assertForbidden();
});

it('denies guests unknown roles and credential contexts without repository mutation', function (string $context): void {
    Bus::fake();
    $repo = crateProductRepo(['name' => 'vendor/existing']);
    $before = ServedRepo::query()->count();
    $request = fn (): TestResponse => $this->postJson(route('crate.repositories.store'), [
        'name' => 'vendor/forbidden',
        'url' => 'https://example.test/forbidden.git',
    ]);

    $response = match ($context) {
        'guest' => $request(),
        'unknown role' => (function () use ($request): TestResponse {
            $this->actingAsVersioned(crateProductUser('unknown'), 'web');

            return $request();
        })(),
        'personal consumption' => (function (): TestResponse {
            $user = crateProductUser(UserRole::Member);
            [, $secret] = crateBasicCredential([
                'subject_type' => SubjectType::UserPrincipal,
                'subject_ref' => 'crate-user:'.$user->getKey(),
                'user_id' => (string) $user->getKey(),
            ]);

            return $this->withBasicAuth('token', $secret)->postJson(route('crate.repositories.store'), [
                'name' => 'vendor/forbidden', 'url' => 'https://example.test/forbidden.git',
            ]);
        })(),
        'deployment consumption' => (function (): TestResponse {
            [, $secret] = crateBasicCredential();

            return $this->withBasicAuth('token', $secret)->postJson(route('crate.repositories.store'), [
                'name' => 'vendor/forbidden', 'url' => 'https://example.test/forbidden.git',
            ]);
        })(),
        'wrong purpose' => (function (): TestResponse {
            [, $secret] = crateBasicCredential(['purpose' => CredentialPurpose::SystemDeployment]);

            return $this->withBasicAuth('token', $secret)->postJson(route('crate.repositories.store'), [
                'name' => 'vendor/forbidden', 'url' => 'https://example.test/forbidden.git',
            ]);
        })(),
        default => throw new LogicException('Unknown context.'),
    };

    $response->assertStatus($context === 'unknown role' ? 403 : 401);
    expect($response->getStatusCode())->toBeLessThan(500)
        ->and(ServedRepo::query()->count())->toBe($before)
        ->and($repo->fresh())->not->toBeNull();
    Bus::assertNothingDispatched();
})->with(['guest', 'unknown role', 'personal consumption', 'deployment consumption', 'wrong purpose']);

it('denies unknown roles and credential contexts from source-secret mutation', function (string $context): void {
    Bus::fake();
    $repo = crateProductRepo([
        'name' => 'vendor/source-denied',
        'source_credential' => 'unchanged-secret',
    ]);
    $before = (string) DB::connection('crate')->table('served_repos')->where('id', $repo->getKey())->value('source_credential');

    $response = match ($context) {
        'unknown role' => (function () use ($repo): TestResponse {
            $this->actingAsVersioned(crateProductUser('unknown'), 'web');

            return $this->putJson(route('crate.repositories.source.replace', ['name' => $repo->name]), [
                'source_credential' => 'forbidden-replacement',
            ]);
        })(),
        'personal consumption' => (function () use ($repo): TestResponse {
            $user = crateProductUser(UserRole::Member);
            [, $secret] = crateBasicCredential([
                'subject_type' => SubjectType::UserPrincipal,
                'subject_ref' => 'crate-user:'.$user->getKey(),
                'user_id' => (string) $user->getKey(),
            ]);

            return $this->withBasicAuth('token', $secret)->putJson(
                route('crate.repositories.source.replace', ['name' => $repo->name]),
                ['source_credential' => 'forbidden-replacement'],
            );
        })(),
        'wrong purpose' => (function () use ($repo): TestResponse {
            [, $secret] = crateBasicCredential(['purpose' => CredentialPurpose::SystemDeployment]);

            return $this->withBasicAuth('token', $secret)->deleteJson(
                route('crate.repositories.source.clear', ['name' => $repo->name]),
            );
        })(),
        default => throw new LogicException('Unknown context.'),
    };

    $response->assertStatus($context === 'unknown role' ? 403 : 401);
    expect($response->getStatusCode())->toBeLessThan(500)
        ->and((string) DB::connection('crate')->table('served_repos')->where('id', $repo->getKey())->value('source_credential'))->toBe($before)
        ->and($repo->refresh()->source_credential)->toBe('unchanged-secret');
    Bus::assertNothingDispatched();
})->with(['unknown role', 'personal consumption', 'wrong purpose']);

it('denies Basic credentials from credential and transition control surfaces without mutation', function (string $routeName): void {
    [$credential, $secret] = crateBasicCredential();
    $before = Credential::query()->count();

    $parameters = $routeName === 'bfc.transitions.index' ? ['direction' => 'adopt'] : [];
    $response = $this->withBasicAuth('token', $secret)->getJson(route($routeName, $parameters));

    $response->assertUnauthorized();
    expect($response->getStatusCode())->toBeLessThan(500)
        ->and(Credential::query()->count())->toBe($before)
        ->and($credential->fresh()->revoked_at)->toBeNull();
})->with([
    'personal credentials' => 'bfc.ui.personal-credentials.index',
    'installation credentials' => 'bfc.ui.installation-credentials.index',
    'transitions' => 'bfc.transitions.index',
]);

it('lets every role replace and clear source credentials without readback', function (UserRole $role): void {
    Bus::fake();
    $repo = crateProductRepo(['name' => 'vendor/source']);
    $user = crateProductUser($role);
    $secret = 'source-'.bin2hex(random_bytes(16));

    $replace = $this->actingAsVersioned($user, 'web')->putJson(
        route('crate.repositories.source.replace', ['name' => $repo->name]),
        ['source_credential' => $secret],
    )->assertOk()->assertJsonPath('repository.has_source_credential', true);
    $raw = DB::connection('crate')->table('served_repos')->where('id', $repo->getKey())->value('source_credential');
    expect($replace->getContent())->not->toContain($secret)
        ->and($repo->refresh()->source_credential)->toBe($secret)
        ->and($raw)->not->toBe($secret);

    $this->deleteJson(route('crate.repositories.source.clear', ['name' => $repo->name]))->assertNoContent();
    expect($repo->refresh()->source_credential)->toBeNull();
})->with(UserRole::cases());
