<?php

namespace Jiannius\Backup\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BackupRunStatus
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /**
     * Seconds a queued run may wait for a worker before its lock lapses.
     */
    protected const QUEUED_SECONDS = 3600;

    /**
     * Seconds the status of a run in flight is kept.
     */
    protected const IN_FLIGHT_RETENTION_SECONDS = 86400;

    /**
     * Seconds a completed status stays visible, so the banner doesn't linger.
     */
    protected const COMPLETED_RETENTION_SECONDS = 3600;

    /**
     * Seconds a failed status stays visible.
     */
    protected const FAILED_RETENTION_SECONDS = 86400;

    /**
     * Longest error message kept for display, in characters.
     */
    protected const ERROR_LIMIT = 200;

    /**
     * Take the single-run lock and return the new run id, or null when a run
     * is already queued or running. The lock value is the run id, so only that
     * run can refresh or release it; it lapses after the queued TTL so a run
     * that never reaches a worker can't block runs forever.
     */
    public function acquire(): ?string
    {
        $runId = (string) Str::ulid();

        return Cache::add($this->key('lock'), $runId, self::QUEUED_SECONDS) ? $runId : null;
    }

    /**
     * Whether a run is currently queued or running.
     */
    public function isActive(): bool
    {
        return Cache::has($this->key('lock'));
    }

    /**
     * The latest run status, or null when nothing has run. A run whose lock has
     * expired without finishing is reported as failed.
     *
     * @return array{status: string, run_id: ?string, filename: ?string, error: ?string, user: ?string, updated_at: string}|null
     */
    public function get(): ?array
    {
        $status = Cache::get($this->key('status'));

        if ($status && in_array($status['status'], [self::QUEUED, self::RUNNING]) && ! $this->isActive()) {
            return [...$status, 'status' => self::FAILED, 'error' => 'The backup did not finish — the worker stopped, timed out, or the job waited too long in the queue.'];
        }

        return $status;
    }

    /**
     * Record that a run was queued (by the given user label, if any).
     */
    public function queued(string $runId, ?string $user = null): void
    {
        $this->put(self::QUEUED, $runId, user: $user);
    }

    /**
     * Record that the worker started the run and give it the full job timeout
     * (so time spent waiting in the queue doesn't eat into it). Returns false,
     * and records nothing, when the run no longer owns the lock.
     */
    public function running(string $runId): bool
    {
        if (! $this->owns($runId)) {
            return false;
        }

        // Compare-then-act is non-atomic by design: no portable CAS across cache stores, and the window is negligible.
        Cache::put($this->key('lock'), $runId, $this->lockSeconds());

        $this->put(self::RUNNING, $runId, user: $this->recordedUser($runId));

        return true;
    }

    /**
     * Record a finished run and release its lock. Ignored when a newer run has taken over.
     */
    public function completed(string $runId, string $filename): void
    {
        if ($this->recordedRunId() === $runId) {
            $this->put(self::COMPLETED, $runId, filename: $filename, user: $this->recordedUser($runId));
        }

        $this->release($runId);
    }

    /**
     * Record a failed run (first line of the error, truncated) and release its
     * lock. Ignored when a newer run has taken over or the run is already recorded as failed.
     */
    public function failed(string $runId, string $error): void
    {
        if ($this->recordedRunId() === $runId && ! $this->hasFailed($runId)) {
            $this->put(self::FAILED, $runId, error: $this->sanitize($error), user: $this->recordedUser($runId));
        }

        $this->release($runId);
    }

    /**
     * Whether the stored status is a failure of the given run.
     */
    public function hasFailed(string $runId): bool
    {
        $status = Cache::get($this->key('status'));

        return ($status['run_id'] ?? null) === $runId && $status['status'] === self::FAILED;
    }

    /**
     * Release the single-run lock, only if the given run still owns it.
     */
    public function release(string $runId): void
    {
        // Compare-then-act is non-atomic by design: no portable CAS across cache stores, and the window is negligible.
        if ($this->owns($runId)) {
            Cache::forget($this->key('lock'));
        }
    }

    /**
     * Whether the given run currently holds the lock.
     */
    protected function owns(string $runId): bool
    {
        return Cache::get($this->key('lock')) === $runId;
    }

    /**
     * Reduce an error to something safe to show: its first line, truncated.
     * The full detail lives in the log and the failure email.
     */
    protected function sanitize(string $error): string
    {
        return Str::limit(trim(Str::before(trim($error), "\n")), self::ERROR_LIMIT);
    }

    /**
     * The run id of the stored status, if any.
     */
    protected function recordedRunId(): ?string
    {
        return Cache::get($this->key('status'))['run_id'] ?? null;
    }

    /**
     * The user label stored with the given run, if it is the recorded one.
     */
    protected function recordedUser(string $runId): ?string
    {
        $status = Cache::get($this->key('status'));

        return ($status['run_id'] ?? null) === $runId ? $status['user'] : null;
    }

    /**
     * Store the status record, kept for a time that depends on its state.
     */
    protected function put(string $status, string $runId, ?string $filename = null, ?string $error = null, ?string $user = null): void
    {
        $retention = match ($status) {
            self::COMPLETED => self::COMPLETED_RETENTION_SECONDS,
            self::FAILED => self::FAILED_RETENTION_SECONDS,
            default => self::IN_FLIGHT_RETENTION_SECONDS,
        };

        Cache::put($this->key('status'), [
            'status' => $status,
            'run_id' => $runId,
            'filename' => $filename,
            'error' => $error,
            'user' => $user,
            'updated_at' => now()->toIso8601String(),
        ], now()->addSeconds($retention));
    }

    /**
     * The cache key for this app (one status per app slug).
     */
    protected function key(string $name): string
    {
        return 'backup:run:'.Str::slug(config('app.name')).':'.$name;
    }

    /**
     * Lock lifetime in seconds: the job timeout plus a small grace period.
     */
    protected function lockSeconds(): int
    {
        return (int) config('backup.ui.queue.timeout', 1800) + 60;
    }
}
