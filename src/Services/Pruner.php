<?php

namespace Jiannius\Backup\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Pruner
{
    /**
     * Delete this app's backup archives older than the retention period.
     */
    public function prune(string $disk, string $path, int $days): void
    {
        $storage = Storage::disk($disk);
        $cutoff = now()->subDays($days)->getTimestamp();
        $pattern = Str::slug(config('app.name')).'-*.zip';

        foreach ($storage->files($path) as $file) {
            if (! fnmatch($pattern, basename($file))) {
                continue;
            }

            if ($storage->lastModified($file) < $cutoff) {
                $storage->delete($file);
            }
        }
    }
}
