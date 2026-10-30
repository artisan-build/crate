<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

/** Who a redeemed exchange code's Composer credential belongs to: one person, or a project's CI and deployments. */
enum ExchangeCodeAudiences: string
{
    case Personal = 'personal';
    case Project = 'project';
}
