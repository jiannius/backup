<?php

namespace Jiannius\Backup\Actions;

use Illuminate\Support\Facades\Storage;

class PruneBackups
{
    /**
     * Delete this app's backup archives older than the retention period.
     */
    public function handle(string $disk, string $path, int $days): void
    {
        $storage = Storage::disk($disk);
        $cutoff = now()->subDays($days)->getTimestamp();
        $archiveFilename = app(ArchiveFilename::class);

        foreach ($storage->files($path) as $file) {
            if (! $archiveFilename->matches(basename($file))) {
                continue;
            }

            if ($storage->lastModified($file) < $cutoff) {
                $storage->delete($file);
            }
        }
    }
}
