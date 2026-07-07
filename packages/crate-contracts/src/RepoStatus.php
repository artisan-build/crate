<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateContracts;

enum RepoStatus: string
{
    case Pending = 'pending';
    case Building = 'building';
    case Active = 'active';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
