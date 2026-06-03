<?php

use Jiannius\Backup\Traits\Enum;

enum BackupStatus: string
{
    use Enum;

    case ACTIVE = 'active';
    case PENDING = 'pending';
    case TRASHED = 'trashed';
}

it('lists cases, excluding TRASHED by default', function () {
    expect(BackupStatus::all()->pluck('value')->all())->toBe(['active', 'pending']);
    expect(BackupStatus::all(false))->toHaveCount(3);
});

it('builds an option array and a humanized label', function () {
    expect(BackupStatus::ACTIVE->option())->toBe(['value' => 'active', 'label' => 'Active']);
    expect(BackupStatus::PENDING->label())->toBe('Pending');
});

it('resolves a case from a name or value with get()', function () {
    expect(BackupStatus::get('active'))->toBe(BackupStatus::ACTIVE);
    expect(BackupStatus::get('ACTIVE'))->toBe(BackupStatus::ACTIVE);
    expect(BackupStatus::get(BackupStatus::PENDING))->toBe(BackupStatus::PENDING);
});

it('matches with is()/isNot()', function () {
    expect(BackupStatus::ACTIVE->is('active'))->toBeTrue();
    expect(BackupStatus::ACTIVE->is('active', 'pending'))->toBeTrue();
    expect(BackupStatus::ACTIVE->isNot('pending'))->toBeTrue();
});
