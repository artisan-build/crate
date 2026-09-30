<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\BuildStatus;
use ArtisanBuild\CrateServer\Jobs\BuildSatis;
use ArtisanBuild\CrateServer\Models\Build;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

it('redispatches queued and expired MCP build outbox rows', function (): void {
    Bus::fake();
    $queued = Build::factory()->create(['trigger' => 'mcp', 'status' => BuildStatus::Queued]);
    $expired = Build::factory()->create([
        'trigger' => 'mcp',
        'status' => BuildStatus::Running,
        'lease_expires_at' => now()->subMinute(),
    ]);
    Build::factory()->create([
        'trigger' => 'mcp',
        'status' => BuildStatus::Running,
        'lease_expires_at' => now()->addHour(),
    ]);
    Build::factory()->create(['trigger' => 'manual', 'status' => BuildStatus::Queued]);

    $this->artisan('crate:recover-builds')->assertSuccessful();

    Bus::assertDispatchedTimes(BuildSatis::class, 2);
    Bus::assertDispatched(BuildSatis::class, fn (BuildSatis $job): bool => $job->buildId === $queued->getKey());
    Bus::assertDispatched(BuildSatis::class, fn (BuildSatis $job): bool => $job->buildId === $expired->getKey());
});

it('schedules MCP build recovery every minute', function (): void {
    $event = collect(Schedule::events())->first(
        fn (Event $event): bool => str_contains($event->command ?? '', 'crate:recover-builds'),
    );

    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('* * * * *');
});
