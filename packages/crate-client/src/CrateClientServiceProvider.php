<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateClient;

use ArtisanBuild\CrateClient\Commands\CrateAuthCommand;
use Illuminate\Support\ServiceProvider;

final class CrateClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crate-client.php', 'crate-client');

        $this->app->bind(CrateIssuer::class, fn (): CrateIssuer => CrateIssuer::fromConfig());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/crate-client.php' => config_path('crate-client.php'),
        ], 'crate-client-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CrateAuthCommand::class,
            ]);
        }
    }
}
