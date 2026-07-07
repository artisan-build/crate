<?php

use ArtisanBuild\CrateContracts\ContractsPlaceholder;

it('loads the contracts placeholder', function (): void {
    expect((new ContractsPlaceholder)->name())->toBe('crate-contracts');
});
