<?php

namespace Jiannius\Backup;

use Illuminate\Support\ServiceProvider;
use Jiannius\Backup\Commands\BackupCommand;
use Jiannius\Backup\Commands\BackupListCommand;

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
}
