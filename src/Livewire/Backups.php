<?php

namespace Jiannius\Backup\Livewire;

use Illuminate\Contracts\View\View;
use Jiannius\Atom\Traits\AtomComponent;
use Jiannius\Backup\Actions\BackupRunStatus;
use Jiannius\Backup\Jobs\RunBackup;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class Backups extends Component
{
    use AtomComponent;

    /**
     * Gate the page itself.
     */
    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * The latest run status record, or null when nothing has run yet.
     *
     * @return array{status: string, run_id: ?string, filename: ?string, error: ?string, user: ?string, updated_at: string}|null
     */
    #[Computed]
    public function status(): ?array
    {
        return app(BackupRunStatus::class)->get();
    }

    /**
     * Whether a run is queued or running (drives the polling).
     */
    #[Computed]
    public function isRunning(): bool
    {
        return app(BackupRunStatus::class)->isActive();
    }

    /**
     * The poll target while a run is active: re-renders only this component
     * (never the archive table) and, once the run has ended, tells the table to reload.
     */
    public function pollStatus(): void
    {
        $this->authorizeAccess();

        $this->announceIfFinished();
    }

    /**
     * Queue a database backup: one run at a time, hooks first, then dispatch.
     */
    public function run(): void
    {
        $this->authorizeAccess();
        $this->resetErrorBag('run');

        $status = app(BackupRunStatus::class);

        if (! $runId = $status->acquire()) {
            $this->addError('run', 'A backup is already running.');

            return;
        }

        try {
            backup()->callHooks('running', ['database' => true, 'files' => false]);
        } catch (Throwable $e) {
            // A hook that blocks the run must not leave the lock held.
            $status->release($runId);

            throw $e;
        }

        $status->queued($runId, $this->userLabel());

        try {
            RunBackup::dispatch($runId);
        } catch (Throwable $e) {
            // With the sync queue the job has already recorded (and run() already logged and emailed) its own failure.
            if (! $status->hasFailed($runId)) {
                report($e);

                $status->failed($runId, $e->getMessage());
            }
        }

        $this->announceIfFinished();
    }

    /**
     * Render the page, on the configured layout when one is set.
     */
    public function render(): View
    {
        $view = view('backup::livewire.backups');

        return ($layout = config('backup.ui.layout')) ? $view->layout($layout) : $view;
    }

    /**
     * Dispatch the event that makes the archive table reload, once no run is active.
     * Reads the memoised isRunning so the event and the rendered poll share one value per request.
     */
    protected function announceIfFinished(): void
    {
        if (! $this->isRunning) {
            $this->dispatch('backup-run-finished');
        }
    }

    /**
     * Abort with a 403 unless backup()->authorize() allows the current request.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(backup()->authorize(request()), 403);
    }

    /**
     * An identifier for whoever started the run (the user's id, not their name).
     */
    protected function userLabel(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}
