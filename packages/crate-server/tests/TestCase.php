<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Tests;

use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\CrateServer\CrateServerServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['crate'];

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BuiltForCloudServiceProvider::class,
            CrateServerServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'crate');
        $app['config']->set('database.connections.crate', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('crate-server.database', [
            'connection' => 'crate',
            'host' => null,
            'port' => null,
            'database' => null,
            'username' => null,
            'password' => null,
        ]);
        $app['config']->set('crate-server.url', 'https://crate.test');
        $app['config']->set('crate-server.archive_disk', 'crate-archive');
        $app['config']->set('crate-server.satis_path', '/fake/vendor/bin/satis');
        $app['config']->set('crate-server.output_dir', 'satis');
    }
}
