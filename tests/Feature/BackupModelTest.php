<?php

use Jiannius\Backup\Models\Backup;

it('creates a backup with a ULID id and json data', function () {
    $backup = Backup::factory()->create([
        'name' => 'Example',
        'data' => ['key' => 'value'],
    ]);

    expect($backup->id)->toBeString()->toHaveLength(26);
    expect($backup->name)->toBe('Example');
    expect($backup->data)->toBe(['key' => 'value']);
    expect(Backup::count())->toBe(1);
});
