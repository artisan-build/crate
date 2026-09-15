<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @return array{0: Credential, 1: string} */
function crateServerBasicCredential(array $attributes = []): array
{
    $secret = 'crate-test-'.bin2hex(random_bytes(16));
    $credential = Credential::query()->create($attributes + [
        'kind' => CredentialKind::Basic,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'deployment-automation',
        'name' => 'Composer access',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $secret),
    ]);

    return [$credential, $secret];
}

function crateServerUser(UserRole $role = UserRole::Member): User
{
    $user = User::query()->create([
        'name' => 'Registry '.$role->value,
        'email' => 'registry-'.$role->value.'-'.bin2hex(random_bytes(4)).'@example.test',
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

function crateServerAuthenticatedGet(string $uri, string $plaintext): TestResponse
{
    return test()->withBasicAuth('presentation-only', $plaintext)->get($uri);
}

function assertCrateRegistryRefusal(TestResponse $response): void
{
    $response->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Crate"');

    expect($response->baseResponse)->not->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getContent())->toBe('');
}

beforeEach(function (): void {
    Storage::fake('crate-archive');
    Storage::disk('crate-archive')->put('satis/packages.json', '{"packages":[]}');
    Storage::disk('crate-archive')->put('satis/p2/vendor/package.json', '{"packages":{"vendor/package":[]}}');
    Storage::disk('crate-archive')->put('satis/dist/vendor/package/1.0.0.zip', 'zip-bytes');
});

it('serves metadata and dist archives for installation and account Basic credentials', function (string $ownership): void {
    $attributes = [];

    if ($ownership === 'account') {
        $user = crateServerUser();
        $attributes = [
            'subject_type' => SubjectType::UserPrincipal,
            'subject_ref' => 'crate-user:'.$user->getKey(),
            'user_id' => (string) $user->getKey(),
        ];
    }

    [, $secret] = crateServerBasicCredential($attributes);

    $packages = crateServerAuthenticatedGet('/packages.json', $secret)->assertOk();
    $provider = crateServerAuthenticatedGet('/p2/vendor/package.json', $secret)->assertOk();
    $dist = crateServerAuthenticatedGet('/dist/vendor/package/1.0.0.zip', $secret)->assertOk();

    expect($packages->streamedContent())->toBe('{"packages":[]}')
        ->and($provider->streamedContent())->toBe('{"packages":{"vendor/package":[]}}')
        ->and($dist->streamedContent())->toBe('zip-bytes');
})->with(['installation', 'account']);

it('returns indistinguishable 401 responses without streaming for invalid credentials', function (string $case): void {
    $response = match ($case) {
        'missing' => $this->get('/packages.json'),
        'malformed' => $this->withHeader('Authorization', 'Basic !!!')->get('/packages.json'),
        'unknown' => crateServerAuthenticatedGet('/packages.json', 'unknown-secret'),
        'wrong purpose' => (function (): TestResponse {
            [, $secret] = crateServerBasicCredential(['purpose' => CredentialPurpose::SystemDeployment]);

            return crateServerAuthenticatedGet('/packages.json', $secret);
        })(),
        'expired' => (function (): TestResponse {
            [, $secret] = crateServerBasicCredential(['expires_at' => now()->subMinute()]);

            return crateServerAuthenticatedGet('/packages.json', $secret);
        })(),
        'revoked' => (function (): TestResponse {
            [, $secret] = crateServerBasicCredential(['revoked_at' => now()]);

            return crateServerAuthenticatedGet('/packages.json', $secret);
        })(),
        'inactive account' => (function (): TestResponse {
            $user = crateServerUser();
            $user->forceFill(['status' => 'inactive'])->save();
            [, $secret] = crateServerBasicCredential([
                'subject_type' => SubjectType::UserPrincipal,
                'subject_ref' => 'crate-user:'.$user->getKey(),
                'user_id' => (string) $user->getKey(),
            ]);

            return crateServerAuthenticatedGet('/packages.json', $secret);
        })(),
        default => throw new LogicException('Unknown fixture.'),
    };

    assertCrateRegistryRefusal($response);
})->with(['missing', 'malformed', 'unknown', 'wrong purpose', 'expired', 'revoked', 'inactive account']);

it('keeps installation Basic access independent of creator and authority mode', function (): void {
    $creator = crateServerUser();
    [, $secret] = crateServerBasicCredential(['name' => 'creator-independent']);

    $creator->forceFill(['status' => 'inactive'])->save();
    expect(InstallationAuthority::change(InstallationAuthority::current(), AuthorityMode::Managed))->not->toBeNull();

    crateServerAuthenticatedGet('/packages.json', $secret)->assertOk();
});

it('returns a 404 for a missing file with a valid credential', function (): void {
    [, $secret] = crateServerBasicCredential();

    crateServerAuthenticatedGet('/p2/vendor/missing.json', $secret)->assertNotFound();
});

it('rejects traversal attempts before reading registry storage paths', function (string $uri): void {
    [, $secret] = crateServerBasicCredential();

    crateServerAuthenticatedGet($uri, $secret)->assertNotFound();
})->with(['/dist/../../secret', '/p2/../../secret']);
