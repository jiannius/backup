<?php

namespace Jiannius\Backup;

use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * The package version.
     */
    public function version(): string
    {
        // Keep in sync with the version in composer.json.
        return '0.1.0';
    }

    /**
     * Read a package config value (dot notation, scoped to "backup").
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        return config($key ? "backup.{$key}" : 'backup', $default);
    }

    /**
     * Run a backup: dump the database, zip it with the configured folders,
     * upload the archive to the backup disk, then prune old archives.
     * Every failure is notified (logged + emailed) before rethrowing.
     *
     * @return string the uploaded archive filename
     */
    public function run(bool $database = true, bool $files = true): string
    {
        try {
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
     * @return Collection<int, array{filename: string, path: string, size: int, date: Carbon, url: ?string}>
     */
    public function list(?int $expiry = null): Collection
    {
        return app(ListBackups::class)->handle(
            $this->config('disk'),
            $this->config('path'),
            $expiry ?? (int) $this->config('download.expiry', 1440),
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

            $filename = Str::slug(config('app.name')).'-'.now()->format('Y-m-d-His').'.zip';
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
