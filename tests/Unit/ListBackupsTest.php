<?php

use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Actions\ListBackups;

it('lists this app\'s archives newest first with metadata', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    // config('app.name') is "Laravel" in Testbench, so the slug is "laravel".
    $disk->put('backups/laravel-2026-01-01-000000.zip', 'old');
    $disk->put('backups/laravel-2026-06-01-000000.zip', 'newer');
    $disk->put('backups/other-2026-01-01-000000.zip', 'unrelated');
    $disk->put('backups/laravel-notes.txt', 'unrelated');
    $disk->put('backups/laravel-staging-2026-06-01-000000.zip', 'another app whose slug starts with ours');

    touch($disk->path('backups/laravel-2026-01-01-000000.zip'), now()->subDays(40)->getTimestamp());
    touch($disk->path('backups/laravel-2026-06-01-000000.zip'), now()->subDays(1)->getTimestamp());

    $backups = (new ListBackups)->handle('local', 'backups', 1440);

    expect($backups)->toHaveCount(2);
    expect($backups->pluck('filename')->all())->toBe([
        'laravel-2026-06-01-000000.zip',
        'laravel-2026-01-01-000000.zip',
    ]);
    expect($backups->first())->toHaveKeys(['filename', 'path', 'size', 'date', 'url']);
    expect($backups->first()['size'])->toBeInt();
    expect($backups->first()['path'])->toBe('backups/laravel-2026-06-01-000000.zip');
});

it('returns a null url when the disk cannot make temporary urls', function () {
    Storage::fake('local');

    // A driver that can't produce temporary URLs (e.g. plain local in production)
    // throws a RuntimeException from temporaryUrl(); the action must swallow it.
    Storage::disk('local')->buildTemporaryUrlsUsing(function (): never {
        throw new RuntimeException('This driver does not support creating temporary URLs.');
    });
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = (new ListBackups)->handle('local', 'backups', 1440);

    expect($backups->first()['url'])->toBeNull();
});

it('uses the disk temporary url when the driver supports it', function () {
    Storage::fake('local');
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => 'https://cdn.test/'.$path.'?expires='.$expiration->getTimestamp(),
    );
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = (new ListBackups)->handle('local', 'backups', 60);

    expect($backups->first()['url'])
        ->toContain('https://cdn.test/backups/laravel-2026-06-01-000000.zip');
});
