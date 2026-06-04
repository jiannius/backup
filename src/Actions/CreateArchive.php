<?php

namespace Jiannius\Backup\Actions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class CreateArchive
{
    /**
     * Create a zip archive containing the database dump and the given folders.
     *
     * @param  list<string>  $folders  absolute folder paths to include
     * @param  list<string>  $excludes  glob patterns matched against paths relative to each folder
     */
    public function handle(string $zipPath, ?string $dumpPath, array $folders, array $excludes = []): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip archive at [{$zipPath}].");
        }

        if ($dumpPath) {
            $zip->addFile($dumpPath, 'db.sql');
        }

        foreach ($folders as $folder) {
            $this->addFolder($zip, rtrim($folder, '/'), $excludes);
        }

        if (! $zip->close()) {
            throw new RuntimeException("Could not write zip archive at [{$zipPath}].");
        }
    }

    /**
     * Recursively add a folder's files under files/<full-path> in the zip.
     *
     * @param  list<string>  $excludes
     */
    protected function addFolder(ZipArchive $zip, string $folder, array $excludes): void
    {
        if (! is_dir($folder)) {
            throw new RuntimeException("Backup folder [{$folder}] does not exist.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($folder)), '/');

            if ($this->isExcluded($relative, $excludes)) {
                continue;
            }

            $zip->addFile($file->getPathname(), 'files/'.ltrim($file->getPathname(), '/'));
        }
    }

    /**
     * Whether a folder-relative path matches any exclude glob pattern.
     *
     * @param  list<string>  $excludes
     */
    protected function isExcluded(string $relative, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }
}
