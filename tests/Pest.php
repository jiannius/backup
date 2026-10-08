<?php

use Jiannius\Backup\Actions\BackupRunStatus;
use Jiannius\Backup\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Whether the sqlite3 CLI binary is available for real dump tests.
 */
function sqlite3Available(): bool
{
    return trim((string) shell_exec('command -v sqlite3')) !== '';
}

/**
 * Start a run the way the UI does: take the lock and record it as queued. Returns the run id.
 */
function queuedRun(?string $user = null): string
{
    $status = app(BackupRunStatus::class);
    $runId = $status->acquire();
    $status->queued($runId, $user);

    return $runId;
}
