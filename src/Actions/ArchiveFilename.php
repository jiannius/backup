<?php

namespace Jiannius\Backup\Actions;

use Illuminate\Support\Str;

class ArchiveFilename
{
    /**
     * Build the archive filename for this app at the current time: {app-slug}-Y-m-d-His.zip.
     */
    public function make(): string
    {
        return $this->slug().'-'.now()->format('Y-m-d-His').'.zip';
    }

    /**
     * Whether a filename is exactly one of this app's archives, so that app
     * "acme" never claims the archives of app "acme-staging".
     */
    public function matches(string $filename): bool
    {
        return preg_match('/^'.preg_quote($this->slug(), '/').'-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $filename) === 1;
    }

    /**
     * The app name as a slug.
     */
    protected function slug(): string
    {
        return Str::slug(config('app.name'));
    }
}
