<?php

use ArtisanBuild\CrateServer\ServerPlaceholder;

it('loads the server placeholder', function (): void {
    expect((new ServerPlaceholder)->name())->toBe('crate-server');
});
