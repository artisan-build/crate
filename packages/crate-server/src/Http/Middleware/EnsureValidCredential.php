<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Http\Middleware;

use ArtisanBuild\BuiltForCloud\TokenRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureValidCredential
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $password = $request->getPassword();

        if ($password === null || $password === '' || app(TokenRegistry::class)->resolve($password) === null) {
            return response('', Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Basic realm="Crate"',
            ]);
        }

        return $next($request);
    }
}
