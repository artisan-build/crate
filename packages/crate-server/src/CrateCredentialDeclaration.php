<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresSelfServiceMintPolicy;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\Request;

final class CrateCredentialDeclaration implements CredentialDeclaration, DeclaresSelfServiceMintPolicy
{
    public const string COMPOSER_PURPOSE = 'crate.composer.consume';

    public function resolveSubject(Request $request): ?Subject
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status !== 'active') {
            return null;
        }

        return new Subject(SubjectType::UserPrincipal, 'crate-user:'.$user->getAuthIdentifier());
    }

    public function authorize(Credential $credential, ?string $ability, Request $request): bool
    {
        if (! $request->is('packages.json', 'p2/*', 'dist/*')) {
            return true;
        }

        if ($credential->kind !== CredentialKind::Basic
            || $credential->purpose !== CredentialPurpose::Consumption) {
            return false;
        }

        return match ($credential->ownership()) {
            CredentialOwnership::Account => $this->allowsAccountCredential($credential),
            CredentialOwnership::Installation => $credential->subject_type === SubjectType::Installation
                && $credential->subject_ref !== '',
        };
    }

    public function selfServiceAbilities(Subject $subject): array
    {
        return [];
    }

    public function selfServiceKinds(Subject $subject): array
    {
        return [CredentialKind::Basic];
    }

    private function allowsAccountCredential(Credential $credential): bool
    {
        if ($credential->user_id === null || $credential->subject_type !== SubjectType::UserPrincipal) {
            return false;
        }

        $user = User::query()->find($credential->user_id);

        return $user instanceof User
            && hash_equals('crate-user:'.$user->getKey(), $credential->subject_ref)
            && DomainIdentityContext::forUser($user, InstallationAuthority::current())->canUseProduct();
    }
}
