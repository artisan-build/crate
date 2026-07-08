<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use Illuminate\Support\Facades\Bus;

it('dispatches a full satis build', function (): void {
    Bus::fake();

    $this->artisan('crate:build')->assertSuccessful();

    Bus::assertDispatched(BuildSatis::class, fn (BuildSatis $job): bool => $job->package === null && $job->trigger === 'manual');
});

it('dispatches an incremental satis build', function (): void {
    Bus::fake();

    $this->artisan('crate:build', ['package' => 'vendor/package'])->assertSuccessful();

    Bus::assertDispatched(BuildSatis::class, fn (BuildSatis $job): bool => $job->package === 'vendor/package' && $job->trigger === 'manual');
});
