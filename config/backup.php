<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Destination
    |--------------------------------------------------------------------------
    |
    | Archives are uploaded to this filesystem disk (any disk defined in the
    | host app's config/filesystems.php) inside the given folder path.
    |
    */

    'disk' => env('BACKUP_DISK', 'local'),
    'path' => env('BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The connection to dump (null = the app's default connection) and an
    | optional directory containing the dump binaries (mysqldump, pg_dump,
    | sqlite3). Leave binary_path null when the binaries are in PATH.
    |
    */

    'database' => [
        'connection' => null,
        'binary_path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | Absolute folder paths to include in the archive, and glob patterns
    | (matched against paths relative to each included folder) to exclude.
    | e.g. 'include' => [storage_path('app/public')], 'exclude' => ['*.log'].
    |
    */

    'files' => [
        'include' => [],
        'exclude' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Archives older than this many days are deleted from the destination
    | after each successful backup run.
    |
    */

    'retention' => [
        'days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Email address notified when a backup run fails. Null disables the email
    | (failures are always logged).
    |
    */

    'notifications' => [
        'email' => env('BACKUP_NOTIFICATION_EMAIL'),
    ],
];
