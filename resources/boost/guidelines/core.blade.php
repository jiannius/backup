## Backup

`jiannius/backup` is a Laravel package providing scheduled database + files backup. Its service provider auto-registers and loads views (`backup::*`) into this app.

### The `backup:run` command

Backs up the database (sqlite, mysql, pgsql — via spatie/db-dumper) and the configured folders into a single timestamped zip, uploads it to the backup disk, prunes archives older than the retention period, and emails the configured address on failure.

@verbatim
<code-snippet name="Run and schedule backups" lang="php">
// routes/console.php
Schedule::command('backup:run')->daily();

// partial runs
// php artisan backup:run --only-db
// php artisan backup:run --only-files
</code-snippet>
@endverbatim

### Public API — the `backup()` helper

@verbatim
<code-snippet name="Using the backup singleton" lang="php">
backup()->run();                  // run a backup programmatically, returns the archive filename
backup()->version();              // package version
backup()->config('disk');         // read config('backup.disk')
</code-snippet>
@endverbatim

### Config

Publish and override the package config:

@verbatim
<code-snippet name="Publish config" lang="bash">
php artisan vendor:publish --tag=backup-config
</code-snippet>
@endverbatim

Key values (all under `config('backup.*')`): `disk` + `path` (destination), `database.connection` (null = default), `files.include` + `files.exclude` (folder paths and exclude globs), `retention.days`, `notifications.email` (failure email, null = off). The dump binaries (`mysqldump`, `pg_dump`, `sqlite3`) must be installed on the server; set `database.binary_path` when they are not in PATH.

### Enums

Backed enums in this app may mix in `Jiannius\Backup\Traits\Enum`; cases are `FULL_UPPERCASE`. The trait provides `all()`, `option()`, `label()`, `get()`, and `is()`/`isNot()`.

@verbatim
<code-snippet name="Backup-backed enum" lang="php">
<?php
namespace App\Enums;

use Jiannius\Backup\Traits\Enum;

enum Status: string
{
    use Enum;

    case ACTIVE = 'active';
    case PENDING = 'pending';
}

Status::all()->map->option()->all();   // select options
</code-snippet>
@endverbatim
