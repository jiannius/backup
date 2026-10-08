<?php

use Illuminate\Support\Facades\Route;
use Jiannius\Backup\Http\Controllers\BackupDownloadController;
use Jiannius\Backup\Http\Middleware\Authorize;
use Jiannius\Backup\Livewire\Backups;

// The package's own access check always runs, whatever the host configures.
$middleware = array_values(array_unique([...(array) config('backup.ui.middleware'), Authorize::class]));

Route::middleware($middleware)
    ->prefix(trim((string) config('backup.ui.path'), '/') ?: 'backups')
    ->name('backup.ui.')
    ->group(function () {
        Route::get('/', Backups::class)->name('index');

        Route::post('/download/{filename}', BackupDownloadController::class)
            ->where('filename', '[A-Za-z0-9._-]+\.zip')
            ->name('download');
    });
