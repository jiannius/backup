<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Actions\BackupRunStatus;
use Jiannius\Backup\Actions\DumpDatabase;
use Jiannius\Backup\Jobs\RunBackup;
use Jiannius\Backup\Mail\BackupFailed;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(12, 0));

    Storage::fake('local');

    // Stand in for the dump binary so the job's database-only run needs no sqlite3/mysqldump.
    app()->instance(DumpDatabase::class, new class extends DumpDatabase
    {
        /**
         * Write a stub dump instead of calling a binary.
         */
        public function handle(?string $connection, string $path): void
        {
            file_put_contents($path, '-- stub dump');
        }
    });
});

it('runs a database-only backup, skips the hooks and ends completed with the filename', function () {
    $calls = 0;
    backup()->beforeRunning(function () use (&$calls) {
        $calls++;
    });

    $status = app(BackupRunStatus::class);
    $runId = queuedRun('Jane');

    (new RunBackup($runId))->handle($status);

    $result = $status->get();

    expect($calls)->toBe(0);
    expect($result['status'])->toBe('completed');
    expect($result['filename'])->toBe('laravel-2026-03-01-120000.zip');
    expect($result['user'])->toBe('Jane');
    expect($status->isActive())->toBeFalse();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path('backups/laravel-2026-03-01-120000.zip'));
    expect($zip->locateName('db.sql'))->not->toBeFalse();
    $zip->close();
});

it('ends failed with the error and emails the failure when the backup throws', function () {
    Mail::fake();
    config()->set('backup.notifications.email', 'ops@example.com');
    config()->set('backup.database.connection', 'nope');

    // The real dump action rejects an unknown connection.
    app()->forgetInstance(DumpDatabase::class);

    $status = app(BackupRunStatus::class);
    $job = new RunBackup(queuedRun());

    try {
        $job->handle($status);
    } catch (Throwable $e) {
        $job->failed($e);
    }

    $result = $status->get();

    expect($result['status'])->toBe('failed');
    expect($result['error'])->toContain('nope');
    expect($status->isActive())->toBeFalse();

    Mail::assertSent(BackupFailed::class);
});

it('truncates a long failure message', function () {
    $status = app(BackupRunStatus::class);
    $status->failed(queuedRun(), str_repeat('x', 2000));

    // 200 characters plus the ellipsis.
    expect(strlen($status->get()['error']))->toBeLessThanOrEqual(203);
});

it('keeps only the first line of a failure, never the raw process output', function () {
    $status = app(BackupRunStatus::class);
    $status->failed(queuedRun(), "The dump process failed with exitcode 2.\nmysqldump: Got error: 1045 Access denied\n/usr/bin/mysqldump --host=db.example.com");

    expect($status->get()['error'])->toBe('The dump process failed with exitcode 2.');
});

it('is configured from backup.ui.queue and never retried', function () {
    config()->set('backup.ui.queue', ['connection' => 'redis', 'name' => 'backups', 'timeout' => 900]);

    $job = new RunBackup('run-id');

    expect($job->tries)->toBe(1);
    expect($job->timeout)->toBe(900);
    expect($job->failOnTimeout)->toBeTrue();
    expect($job->connection)->toBe('redis');
    expect($job->queue)->toBe('backups');
});

it('can be dispatched onto the queue', function () {
    Queue::fake();

    RunBackup::dispatch('run-id');

    Queue::assertPushed(RunBackup::class, fn (RunBackup $job): bool => $job->runId === 'run-id');
});

it('allows only one active run at a time and reports a stale run as failed', function () {
    $status = app(BackupRunStatus::class);

    $runId = $status->acquire();

    expect($runId)->toBeString();
    expect($status->acquire())->toBeNull();

    $status->queued($runId);
    expect($status->get()['status'])->toBe('queued');

    // The lock lapses (worker killed): the unfinished run is reported as failed.
    $this->travel(3600 + 60)->seconds();

    expect($status->isActive())->toBeFalse();
    expect($status->get()['status'])->toBe('failed');
    expect($status->get()['error'])->toContain('waited too long in the queue');
    expect($status->acquire())->toBeString();
});

it('gives a run the full job timeout again when the worker starts it, however long it queued', function () {
    $status = app(BackupRunStatus::class);
    $runId = queuedRun();

    // Waits 50 minutes in the queue: the 60 minute queued lock is almost spent.
    $this->travel(3000)->seconds();
    expect($status->running($runId))->toBeTrue();

    // Past the original 60 minute lock, still within the job timeout (1800s) + grace of the start.
    $this->travel(1000)->seconds();
    expect($status->isActive())->toBeTrue();
    expect($status->get()['status'])->toBe('running');

    $this->travel(900)->seconds();
    expect($status->isActive())->toBeFalse();
});

it('keeps a stale job from releasing or overwriting the run that replaced it', function () {
    $status = app(BackupRunStatus::class);

    $staleRunId = queuedRun('Jane');

    // Its lock lapses, a second run starts...
    $this->travel(3600 + 60)->seconds();
    $currentRunId = queuedRun('John');
    $status->running($currentRunId);

    // ...and the first job finally finishes, or fails.
    $status->completed($staleRunId, 'laravel-2026-03-01-120000.zip');
    $status->failed($staleRunId, 'Late failure');

    expect($status->isActive())->toBeTrue();
    expect($status->acquire())->toBeNull();
    expect($status->get())->toMatchArray(['status' => 'running', 'run_id' => $currentRunId, 'user' => 'John', 'filename' => null, 'error' => null]);

    $status->completed($currentRunId, 'laravel-2026-03-01-120100.zip');

    expect($status->isActive())->toBeFalse();
    expect($status->get())->toMatchArray(['status' => 'completed', 'filename' => 'laravel-2026-03-01-120100.zip']);
});

it('does nothing when a stale job is started by the worker', function () {
    $staleRunId = queuedRun();
    $this->travel(3600 + 60)->seconds();
    $currentRunId = queuedRun();

    $status = app(BackupRunStatus::class);
    $job = new RunBackup($staleRunId);

    $job->handle($status);

    expect($status->get())->toMatchArray(['status' => 'queued', 'run_id' => $currentRunId]);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('lets a completed run fade after an hour but keeps a failed one for a day', function () {
    $status = app(BackupRunStatus::class);

    $status->completed(queuedRun(), 'laravel-2026-03-01-120000.zip');
    $this->travel(61)->minutes();
    expect($status->get())->toBeNull();

    $status->failed(queuedRun(), 'Disk is on fire');
    $this->travel(23)->hours();
    expect($status->get()['status'])->toBe('failed');

    $this->travel(2)->hours();
    expect($status->get())->toBeNull();
});
