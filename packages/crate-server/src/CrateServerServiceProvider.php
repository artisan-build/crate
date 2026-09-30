<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer;

use ArtisanBuild\CrateServer\Commands\CrateBuildCommand;
use ArtisanBuild\CrateServer\Commands\CrateInstallSatisCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposAddCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposListCommand;
use ArtisanBuild\CrateServer\Commands\CrateReposRemoveCommand;
use ArtisanBuild\CrateServer\Http\Middleware\EnsureValidCredential;
use ArtisanBuild\CrateServer\Mcp\CrateReadMcpServer;
use ArtisanBuild\CrateServer\Mcp\CrateWriteMcpServer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class CrateServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crate-server.php', CrateServer::CONFIG_KEY);

        $this->declareMcpSurface();
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
        Route::middleware(['web', 'bfc.auth'])->group(__DIR__.'/../routes/crate-server-management.php');

        $this->app->booted(function (): void {
            Mcp::web((string) config('crate-server.mcp.read_path', '/mcp'), CrateReadMcpServer::class)
                ->middleware('bfc.mcp:product,read');
            Mcp::web((string) config('crate-server.mcp.write_path', '/mcp/write'), CrateWriteMcpServer::class)
                ->middleware('bfc.mcp:product,write');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CrateBuildCommand::class,
                CrateInstallSatisCommand::class,
                CrateReposAddCommand::class,
                CrateReposListCommand::class,
                CrateReposRemoveCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            Schedule::command('crate:build --trigger=schedule')->daily();
        });
    }

    private function declareMcpSurface(): void
    {
        config([
            'built-for-cloud.mcp.path' => config('crate-server.mcp.read_path', '/mcp'),
            'built-for-cloud.mcp.write_path' => config('crate-server.mcp.write_path', '/mcp/write'),
            'built-for-cloud.mcp.destructive_path' => null,
            'built-for-cloud.mcp.delegated' => true,
        ]);
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
