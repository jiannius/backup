<?php

namespace Jiannius\Backup\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jiannius\Backup\Actions\BackupRunStatus;
use Throwable;

class RunBackup implements ShouldQueue
{
    use Queueable;

    /**
     * A backup is never retried automatically.
     */
    public int $tries = 1;

    /**
     * Mark the job failed (and call failed()) when it exceeds the timeout.
     */
    public bool $failOnTimeout = true;

    /**
     * The seconds the job may run, taken from backup.ui.queue.timeout.
     */
    public int $timeout;

    /**
     * Build the job for one run, on the configured queue connection and queue name.
     */
    public function __construct(public string $runId)
    {
        $this->timeout = (int) config('backup.ui.queue.timeout', 1800);
        $this->onConnection(config('backup.ui.queue.connection'));
        $this->onQueue(config('backup.ui.queue.name'));
    }

    /**
     * Run a database-only backup. The hooks already ran when the run was
     * requested, so they are skipped here. A stale job, whose run no longer
     * owns the lock, does nothing.
     */
    public function handle(BackupRunStatus $status): void
    {
        if (! $status->running($this->runId)) {
            return;
        }

        $filename = backup()->run(database: true, files: false, runHooks: false);

        $status->completed($this->runId, $filename);
    }

    /**
     * Record the failure (also reached on a timeout); run() already sent the failure email.
     */
    public function failed(Throwable $exception): void
    {
        app(BackupRunStatus::class)->failed($this->runId, $exception->getMessage());
    }
}
