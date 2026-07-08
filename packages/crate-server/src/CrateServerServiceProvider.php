<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

use ArtisanBuild\CrateServer\Commands\CrateBuildCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposAddCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposListCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposRemoveCommand;
use ArtisanBuild\CrateServer\Http\Middleware\EnsureValidCredential;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CrateServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crate-server.php', CrateServer::CONFIG_KEY);

        $this->registerCrateConnection();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/crate-server.php' => config_path('crate-server.php'),
        ], 'crate-server-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->bound('router')) {
            $this->app['router']->aliasMiddleware('crate-server.credential', EnsureValidCredential::class);
        }

        Route::middleware(['crate-server.credential'])->group(__DIR__.'/../routes/crate-server.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CrateBuildCommand::class,
                CrateReposAddCommand::class,
                CrateReposListCommand::class,
                CrateReposRemoveCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('crate:build --trigger=schedule')->daily();
        });
    }

    private function registerCrateConnection(): void
    {
        $crateDatabase = config('crate-server.database.database');
        $crateHost = config('crate-server.database.host');
        $crateUsername = config('crate-server.database.username');

        if (blank($crateDatabase) && blank($crateHost) && blank($crateUsername)) {
            config(['database.connections.crate' => config('database.connections.'.config('database.default'))]);

            return;
        }

        config(['database.connections.crate' => [
            'driver' => 'pgsql',
            'host' => config('crate-server.database.host'),
            'port' => config('crate-server.database.port'),
            'database' => config('crate-server.database.database'),
            'username' => config('crate-server.database.username'),
            'password' => config('crate-server.database.password'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'timezone' => 'UTC',
        ]]);
    }
}
