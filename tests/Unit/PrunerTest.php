<?php

use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Services\Pruner;

it('deletes only this app\'s archives older than the retention period', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    // config('app.name') is "Laravel" in Testbench, so the slug is "laravel".
    $disk->put('backups/laravel-2026-01-01-000000.zip', 'old');
    $disk->put('backups/laravel-2026-06-01-000000.zip', 'new');
    $disk->put('backups/other-2026-01-01-000000.zip', 'unrelated');
    $disk->put('backups/laravel-notes.txt', 'unrelated');

    $stale = now()->subDays(40)->getTimestamp();
    touch($disk->path('backups/laravel-2026-01-01-000000.zip'), $stale);
    touch($disk->path('backups/other-2026-01-01-000000.zip'), $stale);
    touch($disk->path('backups/laravel-notes.txt'), $stale);

    (new Pruner)->prune('local', 'backups', 30);

    expect($disk->exists('backups/laravel-2026-01-01-000000.zip'))->toBeFalse();
    expect($disk->exists('backups/laravel-2026-06-01-000000.zip'))->toBeTrue();
    expect($disk->exists('backups/other-2026-01-01-000000.zip'))->toBeTrue();
    expect($disk->exists('backups/laravel-notes.txt'))->toBeTrue();
});
