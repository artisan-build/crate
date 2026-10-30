<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\DomainIdentityContext;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\BuiltForCloud\MintResult;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\CrateServer\ComposerCredential;
use ArtisanBuild\CrateServer\CrateCredentialDeclaration;
use ArtisanBuild\CrateServer\ExchangeCodeAudiences;
use ArtisanBuild\CrateServer\Models\ExchangeCode;
use Carbon\CarbonImmutable;

/**
 * Burns an exchange code and mints the Composer credential it stands for. The code is claimed
 * before anything is minted, so two concurrent redemptions can never both succeed.
 */
final readonly class RedeemExchangeCode
{
    public function __construct(
        private MintCredential $mint,
        private AppPurposeRegistry $purposes,
    ) {}

    /** The new credential, or null when the code is unknown, used, expired, or its person can no longer use Crate. */
    public function handle(string $code): ?ComposerCredential
    {
        $hash = hash('sha256', $code);
        $claimed = ExchangeCode::query()
            ->pending()
            ->where('code_hash', $hash)
            ->update(['redeemed_at' => CarbonImmutable::now()]);

        if ($claimed !== 1) {
            return null;
        }

        $exchange = ExchangeCode::query()->where('code_hash', $hash)->sole();
        $result = match ($exchange->audience) {
            ExchangeCodeAudiences::Personal => $this->mintPersonal($exchange),
            ExchangeCodeAudiences::Project => $this->mintProject($exchange),
        };

        if (! $result instanceof MintResult || $result->secret === null || $result->basicUsername === null) {
            return null;
        }

        $exchange->forceFill(['credential_id' => $result->summary->id])->save();

        return new ComposerCredential($result->basicUsername, $result->secret->reveal());
    }

    /** A credential bound to the person who asked for it, as long as they can still use Crate. */
    private function mintPersonal(ExchangeCode $exchange): ?MintResult
    {
        $user = User::query()->find($exchange->user_id);

        if (! $user instanceof User
            || $user->status !== 'active'
            || ! DomainIdentityContext::forUser($user, InstallationAuthority::current())->canUseProduct()) {
            return null;
        }

        $userId = (string) $user->getKey();

        return ($this->mint)(
            new Subject(SubjectType::UserPrincipal, 'crate-user:'.$userId),
            $this->options('Connected machine, '.CarbonImmutable::now()->toDateString(), $userId),
            AuditActor::boundUser($userId),
        );
    }

    /** A credential owned by the project, not by whoever requested it. */
    private function mintProject(ExchangeCode $exchange): MintResult
    {
        return ($this->mint)(
            new Subject(SubjectType::Installation, 'crate-project:'.$exchange->project),
            $this->options((string) $exchange->project),
            AuditActor::operatorIntegration((string) $exchange->requested_by),
        );
    }

    /** Basic credentials for the Composer consumption purpose, which the registry gate admits. */
    private function options(string $name, ?string $userId = null): MintOptions
    {
        return new MintOptions(
            kind: CredentialKind::Basic,
            purpose: $this->purposes->purpose(CrateCredentialDeclaration::COMPOSER_PURPOSE),
            name: $name,
            userId: $userId,
        );
    }
}
