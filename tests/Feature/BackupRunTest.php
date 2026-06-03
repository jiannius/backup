<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Mail\BackupFailed;

beforeEach(function () {
    Storage::fake('local');

    $this->temp = sys_get_temp_dir().'/backup-run-test-'.uniqid();

    File::makeDirectory($this->temp.'/uploads', 0755, true);
    File::put($this->temp.'/uploads/photo.txt', 'photo');
    File::put($this->temp.'/uploads/app.log', 'log');

    // A file-based sqlite connection the dumper can actually read.
    File::put($this->temp.'/source.sqlite', '');
    config()->set('database.connections.source', [
        'driver' => 'sqlite',
        'database' => $this->temp.'/source.sqlite',
        'prefix' => '',
    ]);
    DB::connection('source')->statement('CREATE TABLE examples (id INTEGER PRIMARY KEY, name TEXT)');
    DB::connection('source')->table('examples')->insert(['name' => 'demo']);

    config()->set('backup.database.connection', 'source');
});

afterEach(function () {
    File::deleteDirectory($this->temp);
});

it('uploads a zip containing the dump and configured folders', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);
    config()->set('backup.files.exclude', ['*.log']);

    $filename = backup()->run();

    expect($filename)->toStartWith('laravel-')->toEndWith('.zip');

    $disk = Storage::disk('local');
    expect($disk->exists("backups/{$filename}"))->toBeTrue();

    $zip = new ZipArchive;
    $zip->open($disk->path("backups/{$filename}"));

    expect($zip->getFromName('db.sql'))->toContain('examples');
    expect($zip->locateName('files/'.ltrim($this->temp, '/').'/uploads/photo.txt'))->not->toBeFalse();
    expect($zip->locateName('files/'.ltrim($this->temp, '/').'/uploads/app.log'))->toBeFalse();

    $zip->close();
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('produces a database-only archive when no folders are configured', function () {
    $filename = backup()->run();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path("backups/{$filename}"));

    expect($zip->locateName('db.sql'))->not->toBeFalse();
    expect($zip->count())->toBe(1);

    $zip->close();
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('skips the database dump when database is disabled', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);

    $filename = backup()->run(database: false);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path("backups/{$filename}"));

    expect($zip->locateName('db.sql'))->toBeFalse();
    expect($zip->locateName('files/'.ltrim($this->temp, '/').'/uploads/photo.txt'))->not->toBeFalse();

    $zip->close();
});

it('throws when the archive upload fails', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);

    Storage::shouldReceive('disk')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('putFileAs')->andReturn(false);

    expect(fn () => backup()->run(database: false))
        ->toThrow(RuntimeException::class, 'Failed to upload');
});

it('throws when running files-only with no folders configured', function () {
    expect(fn () => backup()->run(database: false))
        ->toThrow(RuntimeException::class, 'Nothing to back up');
});

it('prunes old archives after a successful run', function () {
    $disk = Storage::disk('local');
    $disk->put('backups/laravel-2026-01-01-000000.zip', 'old');
    touch($disk->path('backups/laravel-2026-01-01-000000.zip'), now()->subDays(60)->getTimestamp());

    backup()->run();

    expect($disk->exists('backups/laravel-2026-01-01-000000.zip'))->toBeFalse();
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('emails the configured recipient when the backup fails', function () {
    Mail::fake();
    config()->set('backup.notifications.email', 'ops@example.com');
    config()->set('backup.database.connection', 'nope');

    expect(fn () => backup()->run())->toThrow(RuntimeException::class);

    Mail::assertSent(BackupFailed::class, fn (BackupFailed $mail) => $mail->hasTo('ops@example.com'));
});

it('sends no email on failure when none is configured', function () {
    Mail::fake();
    config()->set('backup.database.connection', 'nope');

    expect(fn () => backup()->run())->toThrow(RuntimeException::class);

    Mail::assertNothingSent();
});
