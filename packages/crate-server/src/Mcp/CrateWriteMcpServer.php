<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp;

use ArtisanBuild\CrateServer\Mcp\Tools\TriggerBuildTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Crate Write')]
#[Version('1.0.0')]
#[Instructions('Trigger idempotency-keyed registry builds. Repository and credential mutation are not available.')]
final class CrateWriteMcpServer extends Server
{
    protected array $tools = [
        TriggerBuildTool::class,
    ];
}
