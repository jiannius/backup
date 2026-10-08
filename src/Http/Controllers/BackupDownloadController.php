<?php

namespace Jiannius\Backup\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Actions\ListBackups;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupDownloadController
{
    /**
     * Hand out one of this app's archives: redirect to a fresh temporary URL, or
     * stream the file when the disk can't produce one.
     */
    public function __invoke(string $filename): RedirectResponse|StreamedResponse
    {
        // Resolving through list() limits the lookup to this app's own archives;
        // the link is short-lived because it is handed out right away.
        $archive = backup()->list(withUrls: false)->firstWhere('filename', $filename);

        abort_if($archive === null, 404);

        backup()->callHooks('downloading', $archive['filename']);

        $storage = Storage::disk(backup()->config('disk'));
        $expiry = now()->addMinutes((int) backup()->config('ui.download_expiry', 5));

        if ($url = app(ListBackups::class)->url($storage, $archive['path'], $expiry)) {
            return redirect()->away($url);
        }

        return $storage->download($archive['path'], $archive['filename']);
    }
}
