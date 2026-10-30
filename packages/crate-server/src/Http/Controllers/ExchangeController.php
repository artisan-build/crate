<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Http\Controllers;

use ArtisanBuild\CrateServer\Actions\RedeemExchangeCode;
use ArtisanBuild\CrateServer\ComposerCredential;
use ArtisanBuild\CrateServer\Models\ExchangeCode;
use ArtisanBuild\CrateServer\RegistryAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The unauthenticated end of an exchange code: the code itself is the only authority. Every failure
 * answers the same 404, so a caller learns nothing about why a code did not work.
 */
final class ExchangeController
{
    private const array NO_STORE = ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'];

    /** Redeem into a COMPOSER_AUTH document, ready to store as a CI secret or environment variable. */
    public function composerAuth(string $code, RedeemExchangeCode $redeem, RegistryAddress $registry): JsonResponse
    {
        $credential = $this->redeemOrFail($code, $redeem);

        return new JsonResponse([
            'http-basic' => [
                $registry->authHost() => [
                    'username' => $credential->username,
                    'password' => $credential->password,
                ],
            ],
        ], 200, self::NO_STORE, JSON_UNESCAPED_SLASHES);
    }

    /** Redeem into the bare username and password, space-separated, for the connect script to hand to Composer. */
    public function basic(string $code, RedeemExchangeCode $redeem): Response
    {
        $credential = $this->redeemOrFail($code, $redeem);

        return response("{$credential->username} {$credential->password}\n", 200, [
            ...self::NO_STORE,
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /** The shell script behind a person's connect one-liner; serving it does not redeem the code. */
    public function script(string $code, RegistryAddress $registry): Response
    {
        abort_unless(ExchangeCode::query()->pending()->where('code_hash', hash('sha256', $code))->exists(), 404);

        $basicUrl = route('crate.exchange.basic', $code);
        $host = $registry->authHost();
        $script = <<<SH
            #!/bin/sh
            # Crate: saves a personal Composer credential for {$host} to your global auth.json.
            set -eu
            command -v composer >/dev/null 2>&1 || { echo "Crate: Composer is not installed." >&2; exit 1; }
            credential=\$(curl -fsS -X POST "{$basicUrl}")
            set -- \$credential
            composer config --global --auth "http-basic.{$host}" "\$1" "\$2"
            echo "Crate: this machine can now install packages from {$host}."

            SH;

        return response($script, 200, [
            ...self::NO_STORE,
            'Content-Type' => 'text/x-shellscript; charset=UTF-8',
        ]);
    }

    /** The redeemed credential, or a 404 for any code that cannot be redeemed. */
    private function redeemOrFail(string $code, RedeemExchangeCode $redeem): ComposerCredential
    {
        $credential = $redeem->handle($code);
        abort_unless($credential instanceof ComposerCredential, 404);

        return $credential;
    }
}
