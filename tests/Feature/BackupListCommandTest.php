<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('backup.disk', 'local');
    config()->set('backup.path', 'backups');
});

it('lists archives in a table', function () {
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $this->artisan('backup:list')
        ->expectsOutputToContain('laravel-2026-06-01-000000.zip')
        ->assertExitCode(0);
});

it('shows a message when there are no backups', function () {
    $this->artisan('backup:list')
        ->expectsOutputToContain('No backups found.')
        ->assertExitCode(0);
});

it('adds a url column with --url', function () {
    Storage::disk('local')->buildTemporaryUrlsUsing(fn (string $path, $expiration): string => 'DL-LINK');
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $this->artisan('backup:list --url')
        ->expectsOutputToContain('DL-LINK')
        ->assertExitCode(0);
});

it('omits the url column by default', function () {
    Storage::disk('local')->buildTemporaryUrlsUsing(fn (string $path, $expiration): string => 'DL-LINK');
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $this->artisan('backup:list')
        ->doesntExpectOutputToContain('DL-LINK')
        ->assertExitCode(0);
});

it('shows an em-dash in the url column when the disk has no temporary url', function () {
    Storage::disk('local')->buildTemporaryUrlsUsing(function (): never {
        throw new RuntimeException('This driver does not support creating temporary URLs.');
    });
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $this->artisan('backup:list --url')
        ->expectsOutputToContain('—')
        ->assertExitCode(0);
});
