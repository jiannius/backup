<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->temp = sys_get_temp_dir().'/backup-command-test-'.uniqid();

    File::makeDirectory($this->temp.'/uploads', 0755, true);
    File::put($this->temp.'/uploads/photo.txt', 'photo');

    File::put($this->temp.'/source.sqlite', '');
    config()->set('database.connections.source', [
        'driver' => 'sqlite',
        'database' => $this->temp.'/source.sqlite',
        'prefix' => '',
    ]);
    DB::connection('source')->statement('CREATE TABLE examples (id INTEGER PRIMARY KEY, name TEXT)');

    config()->set('backup.database.connection', 'source');
});

afterEach(function () {
    File::deleteDirectory($this->temp);
});

it('runs a full backup with exit code 0', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);

    $this->artisan('backup:run')->assertExitCode(0);

    expect(Storage::disk('local')->files('backups'))->toHaveCount(1);
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('backs up files only with --only-files', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);

    $this->artisan('backup:run --only-files')
        ->expectsOutputToContain('Backup uploaded:')
        ->assertExitCode(0);

    expect(Storage::disk('local')->files('backups'))->toHaveCount(1);
});

it('backs up the database only with --only-db', function () {
    $this->artisan('backup:run --only-db')->assertExitCode(0);

    expect(Storage::disk('local')->files('backups'))->toHaveCount(1);
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('rejects --only-db combined with --only-files', function () {
    $this->artisan('backup:run --only-db --only-files')->assertExitCode(2);
});

it('returns a failure exit code when the backup errors', function () {
    config()->set('backup.database.connection', 'nope');

    $this->artisan('backup:run')
        ->expectsOutputToContain('Backup failed:')
        ->assertExitCode(1);
});
