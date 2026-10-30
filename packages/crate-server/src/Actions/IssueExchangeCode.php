<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\CrateServer\ExchangeCodeAudiences;
use ArtisanBuild\CrateServer\IssuedExchangeCode;
use ArtisanBuild\CrateServer\Models\ExchangeCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Issues a single-use exchange code for a person's machine or for a project's CI and deployments. */
final readonly class IssueExchangeCode
{
    public const int TTL_MINUTES = 10;

    public const string PREFIX = 'crx_';

    /** A code that redeems into a personal credential bound to this signed-in user. */
    public function forPerson(User $user): IssuedExchangeCode
    {
        return $this->issue([
            'audience' => ExchangeCodeAudiences::Personal,
            'user_id' => (string) $user->getKey(),
            'requested_by' => 'user:'.$user->getKey(),
        ]);
    }

    /** A code that redeems into a credential owned by the named project, requested over MCP. */
    public function forProject(string $project, string $requestedBy): IssuedExchangeCode
    {
        return $this->issue([
            'audience' => ExchangeCodeAudiences::Project,
            'project' => $project,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * Store the hash of a new random code and hand back the plaintext.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function issue(array $attributes): IssuedExchangeCode
    {
        $code = self::PREFIX.Str::random(40);
        $expiresAt = CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);

        ExchangeCode::query()->create([
            ...$attributes,
            'code_hash' => hash('sha256', $code),
            'expires_at' => $expiresAt,
        ]);

        return new IssuedExchangeCode($code, $expiresAt);
    }
}
