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
    expect(config('backup.name'))->toBe('Backup');
});
