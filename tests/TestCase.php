<?php

namespace Jiannius\Backup\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jiannius\Backup\BackupServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Register the package's service provider(s) into the test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BackupServiceProvider::class];
    }

    /**
     * Configure the Testbench environment (in-memory sqlite + app key).
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
