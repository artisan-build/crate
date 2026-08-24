<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateClient\Tests;

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use ArtisanBuild\CrateClient\CrateClientServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BfcClientServiceProvider::class,
            CrateClientServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('crate-client.url', 'https://crate.example.com');
        $app['config']->set('crate-client.token', 'ctok_secret');
        $app['config']->set('crate-client.issuer.base_url', 'https://crate.example.com');
        $app['config']->set('crate-client.issuer.admin_token', 'admin_secret');
        $app['config']->set('crate-client.issuer.retries', 2);
        $app['config']->set('crate-client.issuer.retry_sleep_ms', 100);
        $app['config']->set('bfc-client.identity', 'crate-install-abc123');
    }
}
