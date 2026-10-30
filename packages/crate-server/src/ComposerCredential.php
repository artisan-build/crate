<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

/** The http-basic pair a redeemed exchange code hands to Composer. */
final readonly class ComposerCredential
{
    public function __construct(
        public string $username,
        public string $password,
    ) {}
}
