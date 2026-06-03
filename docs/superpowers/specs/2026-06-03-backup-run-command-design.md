# Design: `backup:run` — database + files backup command

**Date:** 2026-06-03
**Status:** Approved

## Purpose

Give jiannius Laravel host apps a single scheduled command that backs up the
database and configured folders to a Laravel filesystem disk, prunes old
archives, and emails on failure.

## Decisions

| Topic | Decision |
|---|---|
| Dump engine | `spatie/db-dumper` (new production dependency) |
| Databases | sqlite, mysql/mariadb, pgsql — mapped from the Laravel connection driver |
| Destination | Configurable Laravel disk + path (`BACKUP_DISK`, `BACKUP_PATH`) |
| Packaging | One timestamped zip per run: db dump + folder contents (PHP `ZipArchive`) |
| Retention | Delete archives older than N days (`BACKUP_RETENTION_DAYS`, default 30) |
| Notifications | Email on failure only (`BACKUP_NOTIFICATION_EMAIL`; unset = log only) |
| DB tracking | None — the disk listing is the history; skeleton model/migration removed |
| Scheduling | Host app schedules `backup:run` itself; no auto-scheduling |
| Extras (v1) | `--only-db` / `--only-files` flags; file exclude glob patterns |
| Deferred (v2) | `backup:restore`, zip encryption |

## Public surface

- **Command:** `backup:run {--only-db} {--only-files}` (replaces `backup:example`)
- **Programmatic:** `backup()->run(database: bool, files: bool): string` — returns
  the uploaded archive filename
- **Archive name:** `{app-slug}-{Y-m-d-His}.zip` (slug from `config('app.name')`)

## Config (`config/backup.php`)

```php
return [
    'disk' => env('BACKUP_DISK', 'local'),    // any disk from filesystems.php
    'path' => env('BACKUP_PATH', 'backups'),  // folder on that disk

    'database' => [
        'connection' => null,   // null = default connection
        'binary_path' => null,  // dir containing mysqldump/pg_dump/sqlite3; null = PATH
    ],

    'files' => [
        'include' => [],        // absolute folder paths, e.g. [storage_path('app/public')]
        'exclude' => [],        // glob patterns, e.g. ['*.log', 'cache/*']
    ],

    'retention' => [
        'days' => env('BACKUP_RETENTION_DAYS', 30),
    ],

    'notifications' => [
        'email' => env('BACKUP_NOTIFICATION_EMAIL'),  // null = no failure email
    ],
];
```

## Components & data flow

```
backup:run ──► Backup::run(database: bool, files: bool): string
                 │
                 ├─ 1. make unique temp dir (sys temp)
                 ├─ 2. DatabaseDumper::dump(connection, "$tmp/db.sql")
                 │      driver → spatie dumper: sqlite → Sqlite,
                 │      mysql/mariadb → MySql, pgsql → PostgreSql
                 │      credentials from config("database.connections.{name}")
                 │      unsupported driver → throws
                 ├─ 3. Archiver::create(zipPath, dump, includeFolders, excludePatterns)
                 │      ZipArchive; db.sql at root; folders stored under
                 │      files/<full-path> (collision-free, restore-unambiguous)
                 ├─ 4. upload → Storage::disk(disk)->putFileAs(path, zip, filename)
                 ├─ 5. Pruner::prune(disk, path, days)
                 │      deletes files matching {app-slug}-*.zip in the backup
                 │      path older than N days (by lastModified); unrelated
                 │      files in a shared path are never touched
                 └─ finally: delete temp dir
```

- `src/Backup.php` — singleton gains `run()`, the orchestrator (public API per
  package convention)
- `src/Services/DatabaseDumper.php` — connection → spatie dumper mapping + dump
- `src/Services/Archiver.php` — zip creation with exclude globs
- `src/Services/Pruner.php` — retention cleanup on the destination disk
- `src/Commands/BackupCommand.php` — thin CLI wrapper, flag handling, exit codes
- `src/Mail/BackupFailed.php` + `resources/views/mail/failed.blade.php` —
  failure mailable (markdown, view namespace `backup::`)

## Error handling

- Any `Throwable` in `run()`: send `BackupFailed` mailable to the configured
  email (if set), log the error, rethrow. The command catches it and returns
  `FAILURE` so the scheduler registers the failed run.
- Fail loudly on config errors: unsupported driver throws; a configured include
  folder that does not exist throws; `--only-files` with empty `include` throws.
  A backup that silently skips content is worse than one that fails.
- A default run with empty `files.include` is NOT an error — it produces a
  db-only archive (the common case before a host app configures folders).
- `--only-db --only-files` together: invalid, command errors out.

## Skeleton cleanup (in scope)

Remove unused scaffold pieces and their tests:

- `src/Models/Backup.php`, `database/factories/BackupFactory.php`,
  `database/migrations/0001_01_01_000000_create_backups_table.php`,
  `loadMigrationsFrom` in the provider, `tests/Feature/BackupModelTest.php`
- `routes/web.php` example route + `loadRoutesFrom`, `tests/Feature/RouteTest.php`
- `components/example.blade.php` + `anonymousComponentPath`,
  `tests/Feature/ComponentTest.php`

Keep: view loading (mailable view), `Traits\Enum` + `EnumTest` (skeleton
convention for future use), config publishing, `backup` singleton + helper.

## Testing (Pest 4 + Testbench)

- `DatabaseDumperTest` — driver→dumper mapping and credential wiring asserted
  without dump binaries; unsupported driver throws
- `ArchiverTest` — real zip from temp fixtures: entries present, exclude globs
  honored, db.sql at root
- `PrunerTest` — `Storage::fake()`; age files via `touch()` on the underlying
  paths; only old archives matching the `{app-slug}-*.zip` pattern removed;
  non-matching and fresh files untouched
- `CommandTest` — end-to-end `backup:run` against a file-based sqlite db
  (skipped when the `sqlite3` binary is unavailable); `--only-db` /
  `--only-files`; both-flags error; non-zero exit on failure
- `BackupFailedMailTest` — `Mail::fake()`; forced failure sends to the
  configured address; nothing sent when email unset
