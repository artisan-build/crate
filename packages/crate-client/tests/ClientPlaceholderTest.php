<?php

use ArtisanBuild\CrateClient\ClientPlaceholder;

it('loads the client placeholder', function (): void {
    expect((new ClientPlaceholder)->name())->toBe('crate-client');
});
