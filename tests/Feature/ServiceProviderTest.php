<?php

use Jiannius\Backup\Backup;

it('binds the backup singleton and alias', function () {
    expect(app('backup'))->toBeInstanceOf(Backup::class);
    expect(app(Backup::class))->toBe(app('backup'));
});

it('exposes the backup() helper returning the singleton', function () {
    expect(backup())->toBeInstanceOf(Backup::class);
    expect(backup()->version())->toBeString()->not->toBeEmpty();
});

it('merges the package config so config(backup.*) is available', function () {
    expect(config('backup.disk'))->toBe('local');
    expect(config('backup.path'))->toBe('backups');
    expect(config('backup.database.connection'))->toBeNull();
    expect(config('backup.files.include'))->toBe([]);
    expect(config('backup.retention.days'))->toBe(30);
    expect(config('backup.notifications.email'))->toBeNull();
});
