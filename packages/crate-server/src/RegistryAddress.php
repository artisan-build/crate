<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

/**
 * Where Composer reaches this registry: the repository URL a project's composer.json names, and
 * the host key its auth.json files the http-basic credential under.
 */
final readonly class RegistryAddress
{
    /** The repository URL, without a trailing slash. */
    public function url(): string
    {
        return rtrim((string) config('crate-server.url'), '/');
    }

    /** The http-basic key Composer matches against the repository URL: the host, plus any non-default port. */
    public function authHost(): string
    {
        $host = (string) parse_url($this->url(), PHP_URL_HOST);
        $port = parse_url($this->url(), PHP_URL_PORT);
        $defaultPort = parse_url($this->url(), PHP_URL_SCHEME) === 'http' ? 80 : 443;

        return is_int($port) && $port !== $defaultPort ? "{$host}:{$port}" : $host;
    }
}
