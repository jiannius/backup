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

### The `backup:list` command

Lists this app's archives already on the backup disk (newest first), with a temporary download URL per archive when the disk driver supports it (e.g. S3; plain `local` disks list with a `null` URL). Reads the disk — there is no database tracking.

@verbatim
<code-snippet name="List backups" lang="bash">
php artisan backup:list          # Date / Filename / Size table
php artisan backup:list --url    # also print a temporary download URL column
</code-snippet>
@endverbatim

### Built-in UI and hooks

An optional page (jiannius/atom on Livewire) to run a queued database backup, list the archives and download one. Off by default: set `BACKUP_UI_ENABLED=true`; it is served at `/backups` (`BACKUP_UI_PATH`). It needs a real queue worker (`retry_after` greater than `BACKUP_UI_TIMEOUT`; not `QUEUE_CONNECTION=sync`), a cache store shared by the web app and the worker (not `array`/`null`), a named `login` route for the default `auth` middleware, and a Tailwind build that scans Atom's components and `vendor/jiannius/backup/resources/views`. Configure it under `config('backup.ui.*')`: `enabled`, `path`, `middleware` (default `['web', 'auth']`; the package's access check is always added), `layout` (null = the app's Livewire default layout), `download_expiry` (minutes a UI download link lives, default 5), `queue.connection`, `queue.name`, `queue.timeout`.

The package does not decide who may use the UI or what is recorded. Register hooks (usually in `AppServiceProvider::boot()`):

- `backup()->auth(fn (Request $request): bool => ...)` — who can reach the page, the run action and downloads; with none registered only the `local` environment is allowed.
- `backup()->beforeRunning(fn (array $options) => ...)` — before every backup (UI, `backup:run`, scheduler, `backup()->run()`); `$options` is `['database' => bool, 'files' => bool]`. Use `app()->runningInConsole()` to tell CLI from UI and `auth()->user()` for the user.
- `backup()->beforeDownloading(fn (string $filename) => ...)` — before a download is handed out.

Hooks can be registered more than once and run in order; throwing or calling `abort()` blocks the action (a blocked `backup:run`/scheduler run is logged and emailed as a failure). Make `auth` callbacks decide by the user (`$request->user()`), never by the request path or route name: the page's Livewire update requests hit a different URL. Use hooks for audit logging or extra checks. Prefer a short `BACKUP_DOWNLOAD_EXPIRY` (e.g. 10 minutes).

@verbatim
<code-snippet name="Register backup hooks" lang="php">
use Illuminate\Http\Request;

backup()->auth(fn (Request $request): bool => in_array($request->user()?->email, ['admin@example.com']));

backup()->beforeRunning(function (array $options): void {
    logger()->info('backup started', [
        'user' => auth()->id(),
        'console' => app()->runningInConsole(),
        'options' => $options,
    ]);
});

backup()->beforeDownloading(fn (string $filename) => logger()->info('backup downloaded', ['file' => $filename]));
</code-snippet>
@endverbatim

To inspect an archive, restore `db.sql` into a separate local database (e.g. `myapp_prod_copy`) — never into a remote host — and delete the zip, the dump and the database afterwards.

### Public API — the `backup()` helper

@verbatim
<code-snippet name="Using the backup singleton" lang="php">
backup()->run();                  // run a backup programmatically (fires beforeRunning hooks), returns the archive filename
backup()->list();                 // Collection of archives: ['filename','path','size','date','url'], newest first
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

Key values (all under `config('backup.*')`): `disk` + `path` (destination), `database.connection` (null = default), `files.include` + `files.exclude` (folder paths and exclude globs), `retention.days`, `notifications.email` (failure email, null = off), `download.expiry` (download-link lifetime in minutes for `backup()->list()`, default 1440), `ui.*` (built-in UI, see above). The dump binaries (`mysqldump`, `pg_dump`, `sqlite3`) must be installed on the server; set `database.binary_path` when they are not in PATH.

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
