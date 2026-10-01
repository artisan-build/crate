<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp;

use ArtisanBuild\CrateServer\Mcp\Tools\BuildHistoryTool;
use ArtisanBuild\CrateServer\Mcp\Tools\ServedRepositoriesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Crate Read')]
#[Version('1.0.0')]
#[Instructions('Read served repository metadata and build status. Source credentials and build output are never returned.')]
final class CrateReadMcpServer extends Server
{
    protected array $tools = [
        ServedRepositoriesTool::class,
        BuildHistoryTool::class,
    ];
}
