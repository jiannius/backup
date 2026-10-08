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

it('ships safe defaults for the ui config', function () {
    $ui = require __DIR__.'/../../config/backup.php';
    $ui = $ui['ui'];

    expect($ui['enabled'])->toBeFalse();
    expect($ui['path'])->toBe('backups');
    expect($ui['middleware'])->toBe(['web', 'auth']);
    expect($ui['layout'])->toBeNull();
    expect($ui['queue']['timeout'])->toBe(1800);
});

it('reports the 0.3.0 version', function () {
    expect(backup()->version())->toBe('0.3.0');
});

it('merges the package config so config(backup.*) is available', function () {
    expect(config('backup.disk'))->toBe('local');
    expect(config('backup.path'))->toBe('backups');
    expect(config('backup.database.connection'))->toBeNull();
    expect(config('backup.files.include'))->toBe([]);
    expect(config('backup.retention.days'))->toBe(30);
    expect(config('backup.notifications.email'))->toBeNull();
});
