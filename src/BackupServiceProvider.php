<?php

namespace Jiannius\Backup;

use Illuminate\Support\ServiceProvider;
use Jiannius\Backup\Commands\BackupCommand;
use Jiannius\Backup\Commands\BackupListCommand;
use Jiannius\Backup\Http\Middleware\Authorize;
use Jiannius\Backup\Livewire\BackupArchives;
use Jiannius\Backup\Livewire\Backups;
use Livewire\Livewire;

class BackupServiceProvider extends ServiceProvider
{
    /**
     * Register package bindings and merge config.
     */
    public function register(): void
    {
        // Merge package config so config('backup.*') is always available,
        // even before the host app publishes the file.
        $this->mergeConfigFrom(__DIR__.'/../config/backup.php', 'backup');

        // Bind the package singleton and expose it as app('backup').
        $this->app->singleton(Backup::class, fn (): Backup => new Backup);
        $this->app->alias(Backup::class, 'backup');
    }

    /**
     * Boot package resources into the host application.
     */
    public function boot(): void
    {
        // Views — referenced as view('backup::...'), used by the failure mailable.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backup');

        if (config('backup.ui.enabled')) {
            $this->bootUi();
        }

        if ($this->app->runningInConsole()) {
            // Let the host app publish + override the config file.
            $this->publishes([
                __DIR__.'/../config/backup.php' => config_path('backup.php'),
            ], 'backup-config');

            // Register the package's artisan commands.
            $this->commands([
                BackupCommand::class,
                BackupListCommand::class,
            ]);
        }
    }

    /**
     * Register the opt-in UI: routes, the Livewire page and its access check.
     */
    protected function bootUi(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Livewire::component('backup-backups', Backups::class);
        Livewire::component('backup-archives', BackupArchives::class);

        // Livewire update requests bypass the page route, so re-apply the access check there.
        Livewire::addPersistentMiddleware([Authorize::class]);
    }
}
