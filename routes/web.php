<?php

use Illuminate\Support\Facades\Route;

// Example package route. Rename or remove for your package.
Route::get('/backup', fn () => response()->json([
    'package' => 'backup',
    'version' => backup()->version(),
]))->name('backup.index');
