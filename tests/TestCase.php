<?php

namespace Jiannius\Backup\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jiannius\Atom\AtomServiceProvider;
use Jiannius\Backup\BackupServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * Whether the built-in UI is enabled for this test class.
     */
    protected bool $uiEnabled = true;

    /**
     * Register the package's service provider(s) into the test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, AtomServiceProvider::class, BackupServiceProvider::class];
    }

    /**
     * Configure the Testbench environment (in-memory sqlite + app key + UI).
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // The UI routes are registered at boot, so the flag must be set here.
        $app['config']->set('backup.ui.enabled', $this->uiEnabled);

        // Fixture layout standing in for the host app's Livewire layout.
        $app['view']->addNamespace('backup-test', __DIR__.'/Fixtures/views');
        $app['config']->set('livewire.component_layout', 'backup-test::layout');
    }
}
