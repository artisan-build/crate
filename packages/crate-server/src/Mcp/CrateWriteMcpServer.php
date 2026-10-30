<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp;

use ArtisanBuild\CrateServer\Mcp\Tools\CreateProjectCredentialTool;
use ArtisanBuild\CrateServer\Mcp\Tools\TriggerBuildTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Crate Write')]
#[Version('1.0.0')]
#[Instructions('Trigger idempotency-keyed registry builds, and create single-use exchange codes for project Composer credentials. Tools never return credential values; redeem a code inside the command that stores it. Repository changes are not available.')]
final class CrateWriteMcpServer extends Server
{
    protected array $tools = [
        TriggerBuildTool::class,
        CreateProjectCredentialTool::class,
    ];
}
