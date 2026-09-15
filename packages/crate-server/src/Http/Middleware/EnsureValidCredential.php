<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Http\Middleware;

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\CrateServer\CrateCredentialDeclaration;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureValidCredential
{
    public function __construct(private AppPurposeRegistry $purposes) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard((string) config('built-for-cloud.credentials.guard', 'bfc'));

        if (! $guard instanceof CredentialGuard) {
            return $this->unauthorized();
        }

        try {
            $credential = $guard->credentialForPurposes([
                $this->purposes->purpose(CrateCredentialDeclaration::COMPOSER_PURPOSE),
            ]);
        } catch (AuthorizationException) {
            return $this->unauthorized();
        }

        if ($credential === null || $credential->kind !== CredentialKind::Basic) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response('', Response::HTTP_UNAUTHORIZED, [
            'WWW-Authenticate' => 'Basic realm="Crate"',
        ]);
    }
}
