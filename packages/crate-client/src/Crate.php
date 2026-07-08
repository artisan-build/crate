<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateClient;

use RuntimeException;

final class Crate
{
    /**
     * @return array<string, array{username: string, password: string}>
     */
    public static function composerAuthFragment(): array
    {
        $url = (string) config('crate-client.url');
        $token = (string) config('crate-client.token');

        if (blank($url)) {
            throw new RuntimeException('The crate-client.url config value is required to build Composer auth.');
        }

        if (blank($token)) {
            throw new RuntimeException('The crate-client.token config value is required to build Composer auth.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || blank($host)) {
            throw new RuntimeException('The crate-client.url config value must include a valid host.');
        }

        return [
            $host => [
                'username' => 'token',
                'password' => $token,
            ],
        ];
    }

    public static function composerAuthJson(): string
    {
        $json = json_encode([
            'http-basic' => self::composerAuthFragment(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode Composer auth JSON.');
        }

        return $json;
    }
}
