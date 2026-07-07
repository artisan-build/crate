<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateContracts;

enum BuildStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
