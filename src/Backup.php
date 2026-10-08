<?php

namespace Jiannius\Backup;

use Closure;
use Illuminate\Http\File as HttpFile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jiannius\Backup\Actions\ArchiveFilename;
use Jiannius\Backup\Actions\CreateArchive;
use Jiannius\Backup\Actions\DumpDatabase;
use Jiannius\Backup\Actions\ListBackups;
use Jiannius\Backup\Actions\PruneBackups;
use Jiannius\Backup\Mail\BackupFailed;
use RuntimeException;
use Throwable;

class Backup
{
    /**
     * The registered hook callbacks, keyed by hook name.
     *
     * @var array{auth: array<int, Closure>, running: array<int, Closure>, downloading: array<int, Closure>}
     */
    protected array $hooks = [
        'auth' => [],
        'running' => [],
        'downloading' => [],
    ];

    /**
     * The package version.
     */
    public function version(): string
    {
        // Keep in sync with the git release tag.
        return '0.3.0';
    }

    /**
     * Read a package config value (dot notation, scoped to "backup").
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        return config($key ? "backup.{$key}" : 'backup', $default);
    }

    /**
     * Register a callback deciding who can reach the UI routes. It receives the
     * request and returns a bool; with several registered, all must allow.
     * With none registered, access is limited to the "local" environment.
     *
     * @param  Closure(Request): bool  $callback
     */
    public function auth(Closure $callback): static
    {
        $this->hooks['auth'][] = $callback;

        return $this;
    }

    /**
     * Register a callback run before every backup (UI, backup:run, scheduler or
     * programmatic). It receives ['database' => bool, 'files' => bool]; throwing
     * or aborting blocks the backup.
     *
     * @param  Closure(array{database: bool, files: bool}): mixed  $callback
     */
    public function beforeRunning(Closure $callback): static
    {
        $this->hooks['running'][] = $callback;

        return $this;
    }

    /**
     * Register a callback run before an archive download is handed out. It
     * receives the archive filename; throwing or aborting blocks the download.
     *
     * @param  Closure(string): mixed  $callback
     */
    public function beforeDownloading(Closure $callback): static
    {
        $this->hooks['downloading'][] = $callback;

        return $this;
    }

    /**
     * Whether the request may reach the UI: every registered auth callback must
     * allow it, or (none registered) the app must be running locally.
     */
    public function authorize(Request $request): bool
    {
        if (empty($this->hooks['auth'])) {
            return app()->environment('local');
        }

        foreach ($this->hooks['auth'] as $callback) {
            if (! $callback($request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run the callbacks registered for a hook ("running" or "downloading"), in
     * registration order.
     */
    public function callHooks(string $hook, mixed ...$arguments): void
    {
        foreach ($this->hooks[$hook] ?? [] as $callback) {
            $callback(...$arguments);
        }
    }

    /**
     * Run a backup: dump the database, zip it with the configured folders,
     * upload the archive to the backup disk, then prune old archives.
     * Every failure, including a beforeRunning hook that throws or aborts, is
     * notified (logged + emailed) before rethrowing, so an unattended run
     * (scheduler, cron) never fails silently.
     *
     * @param  bool  $runHooks  fire the beforeRunning hooks (false when the caller already did)
     * @return string the uploaded archive filename
     */
    public function run(bool $database = true, bool $files = true, bool $runHooks = true): string
    {
        try {
            if ($runHooks) {
                $this->callHooks('running', ['database' => $database, 'files' => $files]);
            }

            return $this->execute($database, $files);
        } catch (Throwable $e) {
            $this->notifyFailure($e);

            throw $e;
        }
    }

    /**
     * List this app's backup archives on the disk, newest first, each with a
     * temporary download URL (null when the disk driver can't produce one).
     *
     * @param  int|null  $expiry  download-link lifetime in minutes (null = config default)
     * @param  bool  $withUrls  false skips building the URLs (every url is null)
     * @return Collection<int, array{filename: string, path: string, size: int, date: Carbon, url: ?string}>
     */
    public function list(?int $expiry = null, bool $withUrls = true): Collection
    {
        return app(ListBackups::class)->handle(
            $this->config('disk'),
            $this->config('path'),
            $expiry ?? (int) $this->config('download.expiry', 1440),
            $withUrls,
        );
    }

    /**
     * The backup pipeline: dump → zip → upload → prune.
     */
    protected function execute(bool $database, bool $files): string
    {
        $include = $files ? $this->config('files.include', []) : [];

        if (! $database && empty($include)) {
            throw new RuntimeException('Nothing to back up: no folders configured in backup.files.include.');
        }

        $temp = sys_get_temp_dir().'/backup-'.Str::lower(Str::random(8));
        File::makeDirectory($temp, 0755, true);

        try {
            $dump = null;

            if ($database) {
                $dump = $temp.'/db.sql';
                app(DumpDatabase::class)->handle($this->config('database.connection'), $dump);
            }

            $filename = app(ArchiveFilename::class)->make();
            $zip = $temp.'/'.$filename;

            app(CreateArchive::class)->handle($zip, $dump, $include, $this->config('files.exclude', []));

            $stored = Storage::disk($this->config('disk'))
                ->putFileAs($this->config('path'), new HttpFile($zip), $filename);

            if ($stored === false) {
                throw new RuntimeException("Failed to upload backup archive to disk [{$this->config('disk')}].");
            }

            app(PruneBackups::class)->handle($this->config('disk'), $this->config('path'), (int) $this->config('retention.days'));

            return $filename;
        } finally {
            File::deleteDirectory($temp);
        }
    }

    /**
     * Log the failure and email the configured recipient, if any.
     */
    protected function notifyFailure(Throwable $e): void
    {
        logger()->error("Backup failed: {$e->getMessage()}", ['exception' => $e]);

        $email = $this->config('notifications.email');

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new BackupFailed($e));
        } catch (Throwable $mailError) {
            // Never let a broken mailer mask the original backup failure.
            logger()->error("Backup failure email could not be sent: {$mailError->getMessage()}");
        }
    }
}
