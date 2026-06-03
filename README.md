# Jiannius Backup

[![tests](https://github.com/jiannius/backup/actions/workflows/tests.yml/badge.svg)](https://github.com/jiannius/backup/actions/workflows/tests.yml)
[![lint](https://github.com/jiannius/backup/actions/workflows/lint.yml/badge.svg)](https://github.com/jiannius/backup/actions/workflows/lint.yml)

Scheduled database + files backup for Laravel apps: dump the database, zip it together with your configured folders, upload the archive to any Laravel filesystem disk, prune old archives — and email you when a backup fails.

Supports **SQLite, MySQL, MariaDB, and PostgreSQL** via [spatie/db-dumper](https://github.com/spatie/db-dumper).

## How it works

Each run produces a single timestamped archive — `{app-slug}-2026-06-03-021500.zip` — containing:

```
db.sql                      # the database dump
files/<absolute-path>/...   # every configured folder, full paths preserved
```

The archive is uploaded to the configured disk + path, then archives older than the retention period are pruned. Only this app's own archives (matching `{app-slug}-*.zip`) are ever deleted — unrelated files in a shared path are never touched.

Every failure is logged, and emailed if a notification address is configured. The command exits non-zero so your scheduler marks the run as failed.

## Requirements

- PHP 8.3+ · Laravel 11+ host app
- The dump binary for your database on the server: `sqlite3`, `mysqldump`, `mariadb-dump`, or `pg_dump` (set `database.binary_path` if it's not in `PATH`)

## Installation

Until the package is on Packagist, require it via a VCS repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/jiannius/backup" }
    ],
    "require": {
        "jiannius/backup": "dev-main"
    }
}
```

The service provider auto-registers. Publish the config if you want to override it:

```bash
php artisan vendor:publish --tag=backup-config
```

## Configuration

The quick knobs are env vars:

| Env | Default | Purpose |
| --- | --- | --- |
| `BACKUP_DISK` | `local` | Destination disk (any disk in `config/filesystems.php`) |
| `BACKUP_PATH` | `backups` | Folder on that disk |
| `BACKUP_RETENTION_DAYS` | `30` | Archives older than this are pruned after each run |
| `BACKUP_NOTIFICATION_EMAIL` | — | Failure email recipient (unset = log only) |

Folders and database options live in `config/backup.php`:

```php
'database' => [
    'connection' => null,           // null = the app's default connection
    'binary_path' => null,          // dir containing mysqldump/pg_dump/sqlite3, null = PATH
],

'files' => [
    'include' => [
        storage_path('app/public'), // absolute folder paths to back up
    ],
    'exclude' => [
        '*.log',                    // glob patterns, matched relative to each folder
        'cache/*',
    ],
],
```

With no folders configured, runs produce a database-only archive — that's the zero-config default.

## Usage

```bash
php artisan backup:run               # database + files
php artisan backup:run --only-db     # database only
php artisan backup:run --only-files  # files only
```

Schedule it in `routes/console.php`:

```php
Schedule::command('backup:run')->daily();
```

Or run it programmatically — returns the uploaded archive filename, throws on failure:

```php
$filename = backup()->run();                  // full backup
$filename = backup()->run(database: false);   // files only
```

Exit codes: `0` success · `1` backup failed (already logged/emailed) · `2` invalid flag combination.

## Failure notifications

When a run fails for any reason — dump error, missing folder, upload rejected — the error is logged and a markdown email is sent to `BACKUP_NOTIFICATION_EMAIL` (if set), then the exception is rethrown. A broken mailer never masks the original failure.

## Development

This is a package, so there is no `artisan` — [Orchestra Testbench](https://github.com/orchestral/testbench) is the artisan:

```bash
composer install
composer test                              # Pest 4 + Testbench suite
composer lint                              # Pint
vendor/bin/testbench backup:run --only-db  # run the command in the throwaway app
```

End-to-end dump tests require the `sqlite3` binary and skip cleanly when it's absent. See `CLAUDE.md` for the package conventions and `docs/superpowers/specs/` for the design doc.

## License

MIT.
