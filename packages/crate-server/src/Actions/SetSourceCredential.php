<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Actions;

use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\ServedRepo;

final class SetSourceCredential
{
    public function __invoke(ServedRepo $repo, ?string $credential): ServedRepo
    {
        $repo->update(['source_credential' => $credential]);
        BuildSatis::dispatch($repo->name, $credential === null ? 'source-credential-cleared' : 'source-credential-replaced')
            ->afterCommit();

        return $repo->refresh();
    }
}
