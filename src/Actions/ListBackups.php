<?php

namespace Jiannius\Backup\Actions;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ListBackups
{
    /**
     * List this app's backup archives on the disk, newest first, each with a
     * temporary download URL (null when the disk driver can't produce one).
     *
     * @return Collection<int, array{filename: string, path: string, size: int, date: Carbon, url: ?string}>
     */
    public function handle(string $disk, string $path, int $expiryMinutes): Collection
    {
        $storage = Storage::disk($disk);
        $pattern = Str::slug(config('app.name')).'-*.zip';
        $expiry = now()->addMinutes($expiryMinutes);

        return collect($storage->files($path))
            ->filter(fn (string $file): bool => fnmatch($pattern, basename($file)))
            ->map(fn (string $file): array => [
                'filename' => basename($file),
                'path' => $file,
                'size' => $storage->size($file),
                'date' => Carbon::createFromTimestamp($storage->lastModified($file)),
                'url' => $this->url($storage, $file, $expiry),
            ])
            ->sortByDesc(fn (array $entry): int => $entry['date']->getTimestamp())
            ->values();
    }

    /**
     * Build a temporary download URL, or null when the disk driver can't.
     */
    protected function url(FilesystemAdapter $storage, string $file, DateTimeInterface $expiry): ?string
    {
        try {
            return $storage->temporaryUrl($file, $expiry);
        } catch (RuntimeException) {
            return null;
        }
    }
}
