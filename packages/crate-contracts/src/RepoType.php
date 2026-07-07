<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateContracts;

enum RepoType: string
{
    case Vcs = 'vcs';
    case Git = 'git';
    case Path = 'path';
}
