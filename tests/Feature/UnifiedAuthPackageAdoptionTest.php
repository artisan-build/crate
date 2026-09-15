<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\ManagedAuthConnection;
use ArtisanBuild\BuiltForCloud\ManagedAuthExchange;
use ArtisanBuild\BuiltForCloud\ManagedIdentityUpsert;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\DB;

function crateSetAuthority(AuthorityMode $mode): void
{
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => $mode->value,
        'generation' => 7,
        'issuer' => 'https://issuer.example.test',
        'connection_id' => 'crate-connection',
        'organization_id' => 'crate-organization',
        'installation_id' => 'crate-installation',
        'authority_base_url' => 'https://authority.example.test',
        'managed_connection_status' => 'active',
    ]);
}

function crateManagedConnection(): ManagedAuthConnection
{
    return new ManagedAuthConnection(
        'https://issuer.example.test',
        'crate-connection',
        'crate-organization',
        'crate-installation',
        7,
        'https://authority.example.test',
        'test-created-client-secret',
        null,
    );
}

function crateManagedExchange(string $subject, string $email, string $name = 'Managed Member'): ManagedAuthExchange
{
    return new ManagedAuthExchange(
        $subject,
        'membership-'.$subject,
        'active',
        'active',
        UserRole::Member->value,
        $name,
        $email,
        true,
        11,
        12,
        new DateTimeImmutable('2026-09-15T12:00:00+00:00'),
    );
}

it('denies every standalone authentication and membership entry point in managed mode without mutation', function (string $method, string $uri): void {
    $owner = User::query()->create(['name' => 'Managed Owner', 'email' => 'managed-owner@example.test']);
    $owner->forceFill(['role' => UserRole::Owner->value, 'status' => 'active'])->save();
    crateSetAuthority(AuthorityMode::Managed);
    $before = $owner->fresh()->getAttributes();

    $this->actingAsVersioned($owner, 'web')->call($method, $uri)->assertNotFound();

    expect($owner->fresh()->getAttributes())->toBe($before)
        ->and(User::query()->count())->toBe(1);
})->with([
    ['GET', '/bfc/login'],
    ['POST', '/bfc/login'],
    ['GET', '/bfc/forgot-password'],
    ['POST', '/bfc/forgot-password'],
    ['GET', '/bfc/reset-password?token=test-created-token'],
    ['POST', '/bfc/reset-password'],
    ['GET', '/bfc/invitations/test-created-token'],
    ['GET', '/bfc/invitations/accept'],
    ['POST', '/bfc/invitations/accept'],
    ['GET', '/bfc/members'],
    ['POST', '/bfc/members/invitations'],
    ['PUT', '/bfc/members/test-created-user/role'],
    ['DELETE', '/bfc/members/test-created-user'],
]);

it('keeps standalone authority active when delayed managed connection fields arrive', function (): void {
    $owner = User::query()->create(['name' => 'Standalone Owner', 'email' => 'standalone-owner@example.test']);
    $owner->forceFill(['role' => UserRole::Owner->value, 'status' => 'active'])->save();
    crateSetAuthority(AuthorityMode::Standalone);

    $this->get(route('bfc.login'))->assertOk();
    $this->actingAsVersioned($owner, 'web')->get(route('bfc.members.index'))->assertOk();

    expect(InstallationAuthority::current()->mode)->toBe(AuthorityMode::Standalone)
        ->and($owner->fresh()->getKey())->toBe($owner->getKey());
});

it('converges exact managed subjects without same-email linking and retains collision provenance', function (): void {
    $upsert = app(ManagedIdentityUpsert::class);
    $connection = crateManagedConnection();
    $contact = 'shared-managed-contact@example.test';

    $first = $upsert->upsert($connection, crateManagedExchange('subject-one', $contact));
    $repeat = $upsert->upsert(
        $connection,
        crateManagedExchange('subject-one', 'renewed-contact@example.test', 'Renewed Member'),
    );
    $collision = $upsert->upsert($connection, crateManagedExchange('subject-two', $contact));

    expect($repeat->getKey())->toBe($first->getKey())
        ->and($repeat->name)->toBe('Renewed Member')
        ->and($repeat->email)->toBe($contact)
        ->and($repeat->original_contact_email)->toBe('renewed-contact@example.test')
        ->and($collision->getKey())->not->toBe($first->getKey())
        ->and($collision->email)->toBe('shared-managed-contact+bfc@example.test')
        ->and($collision->original_contact_email)->toBe($contact)
        ->and($collision->email_is_generated)->toBeTrue()
        ->and($collision->email_conflict_at)->not->toBeNull()
        ->and($collision->email_conflict_source)->toBe($contact);
});

it('mounts the guided transition surface for the owner without mutating local identities or deployment credentials', function (): void {
    $owner = User::query()->create(['name' => 'Transition Owner', 'email' => 'transition-owner@example.test']);
    $owner->forceFill(['role' => UserRole::Owner->value, 'status' => 'active'])->save();
    crateSetAuthority(AuthorityMode::Standalone);
    $credential = Credential::query()->create([
        'kind' => CredentialKind::Basic,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'transition-deployment',
        'name' => 'Transition deployment credential',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', 'test-created-deployment-secret'),
    ]);
    $identityBefore = $owner->fresh()->getAttributes();
    $credentialBefore = $credential->fresh()->getAttributes();

    $this->actingAsVersioned($owner, 'web')
        ->get(route('bfc.transitions.index', ['direction' => 'adopt']))
        ->assertOk()
        ->assertSeeHtml('data-testid="transition-proposal"')
        ->assertSeeHtml('data-testid="transition-preparation"')
        ->assertSee('This prepares a complete authority roster and local identity proposal for review.')
        ->assertSee('Prepare proposal');

    expect($owner->fresh()->getAttributes())->toBe($identityBefore)
        ->and($credential->fresh()->getAttributes())->toBe($credentialBefore);
});
