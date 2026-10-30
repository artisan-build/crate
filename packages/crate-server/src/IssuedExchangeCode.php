<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

use Carbon\CarbonImmutable;

/** A freshly issued exchange code: the plaintext, shown once, and when it stops working. */
final readonly class IssuedExchangeCode
{
    public function __construct(
        public string $code,
        public CarbonImmutable $expiresAt,
    ) {}
}
