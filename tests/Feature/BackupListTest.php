<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('backup.disk', 'local');
    config()->set('backup.path', 'backups');
});

// Reset frozen time even if an assertion throws mid-test, so it never leaks.
afterEach(fn () => Carbon::setTestNow());

it('lists backups via the backup() helper', function () {
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = backup()->list();

    expect($backups)->toHaveCount(1);
    expect($backups->first()['filename'])->toBe('laravel-2026-06-01-000000.zip');
});

it('resolves the configured expiry when none is passed', function () {
    Carbon::setTestNow('2026-06-04 12:00:00');
    config()->set('backup.download.expiry', 90);
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => 'url?expires='.$expiration->getTimestamp(),
    );
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = backup()->list();

    $expected = now()->addMinutes(90)->getTimestamp();
    expect($backups->first()['url'])->toBe('url?expires='.$expected);
});

it('honors an explicit expiry argument over the config default', function () {
    Carbon::setTestNow('2026-06-04 12:00:00');
    config()->set('backup.download.expiry', 90);
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => 'url?expires='.$expiration->getTimestamp(),
    );
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = backup()->list(5);

    $expected = now()->addMinutes(5)->getTimestamp();
    expect($backups->first()['url'])->toBe('url?expires='.$expected);
});
