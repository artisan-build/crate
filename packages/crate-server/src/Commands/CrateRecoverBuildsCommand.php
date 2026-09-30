<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\CrateServer\Actions\RecoverBuildDispatches;

final class CrateRecoverBuildsCommand extends SystemAuthorityCommand
{
    protected $signature = 'crate:recover-builds';

    protected $description = 'Redispatch queued or expired MCP builds from the durable build outbox.';

    public function handle(RecoverBuildDispatches $recover): int
    {
        $failures = $recover->handle();

        if ($failures > 0) {
            $this->error("Failed to dispatch {$failures} recoverable build(s).");

            return self::FAILURE;
        }

        $this->info('Recoverable MCP builds dispatched.');

        return self::SUCCESS;
    }
}
