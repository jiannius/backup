<?php

namespace Jiannius\Backup;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Jiannius\Backup\Commands\BackupCommand;

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
        // Routes — the host app can override by re-declaring the named route.
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Migrations — picked up by the host app's `php artisan migrate`.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Views — referenced as view('backup::...').
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backup');

        // Anonymous Blade components — usable as <x-backup::name />.
        Blade::anonymousComponentPath(__DIR__.'/../components', 'backup');

        // Translations — uncomment once a lang/ directory is added.
        // $this->loadTranslationsFrom(__DIR__.'/../lang', 'backup');

        if ($this->app->runningInConsole()) {
            // Let the host app publish + override the config file.
            $this->publishes([
                __DIR__.'/../config/backup.php' => config_path('backup.php'),
            ], 'backup-config');

            // Register the package's artisan commands.
            $this->commands([
                BackupCommand::class,
            ]);
        }
    }
}
