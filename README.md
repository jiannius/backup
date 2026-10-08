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

The archive is uploaded to the configured disk + path, then archives older than the retention period are pruned. Only this app's own archives (named exactly `{app-slug}-YYYY-MM-DD-HHMMSS.zip`) are ever listed, downloaded or deleted — unrelated files in a shared path, and archives of another app whose slug merely starts with yours (`myapp` vs `myapp-staging`), are never touched.

Every failure is logged, and emailed if a notification address is configured. The command exits non-zero so your scheduler marks the run as failed.

## Requirements

- PHP 8.3+ · Laravel 13 host app (`illuminate/support ^13`)
- The dump binary for your database on the server: `sqlite3`, `mysqldump`, `mariadb-dump`, or `pg_dump` (set `database.binary_path` if it's not in `PATH`)
- [jiannius/atom](https://packagist.org/packages/jiannius/atom) ^3 (installed automatically, brings Livewire 4) — only used by the optional [built-in UI](#built-in-ui)

## Installation

```bash
composer require jiannius/backup
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
| `BACKUP_DOWNLOAD_EXPIRY` | `1440` | Download-link lifetime in minutes (24h) for `backup:list --url` / `backup()->list()` |
| `BACKUP_UI_ENABLED` | `false` | Turn on the [built-in UI](#built-in-ui) |
| `BACKUP_UI_PATH` | `backups` | URL path of the UI page (empty falls back to `backups`) |
| `BACKUP_UI_DOWNLOAD_EXPIRY` | `5` | Lifetime in minutes of the temporary link a UI download redirects to |
| `BACKUP_UI_QUEUE_CONNECTION` | — | Queue connection for UI backups (unset = app default) |
| `BACKUP_UI_QUEUE` | — | Queue name for UI backups (unset = app default) |
| `BACKUP_UI_TIMEOUT` | `1800` | Seconds a UI backup job may run |

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

List the archives already on the disk — each with a temporary download URL when the disk driver supports it (e.g. S3; local disks list with a `null` URL):

```bash
php artisan backup:list          # Date / Filename / Size table
php artisan backup:list --url    # also print a temporary download URL column
```

Or programmatically — returns a `Collection` of `['filename', 'path', 'size', 'date', 'url']`, newest first:

```php
backup()->list();      // download URLs use the configured expiry (default 24h)
backup()->list(60);    // override: URLs valid for 60 minutes
```

Exit codes: `0` success · `1` backup failed (already logged/emailed) · `2` invalid flag combination.

## Built-in UI

An optional page to start a database backup, see the archives and download one. It is built with [jiannius/atom](https://packagist.org/packages/jiannius/atom) on Livewire and is **off by default**.

```env
BACKUP_UI_ENABLED=true
```

The page is then served at `/backups` (change it with `BACKUP_UI_PATH`). In `config/backup.php`:

```php
'ui' => [
    'enabled' => env('BACKUP_UI_ENABLED', false),
    'path' => env('BACKUP_UI_PATH', 'backups'),
    'middleware' => ['web', 'auth'],   // wraps the routes; the package's own access check is always added on top
    'layout' => null,                  // a Blade layout view name; null = your app's Livewire default layout
    'download_expiry' => (int) env('BACKUP_UI_DOWNLOAD_EXPIRY', 5), // minutes a UI download link stays valid
    'queue' => [
        'connection' => env('BACKUP_UI_QUEUE_CONNECTION'),
        'name' => env('BACKUP_UI_QUEUE'),
        'timeout' => (int) env('BACKUP_UI_TIMEOUT', 1800),
    ],
],
```

How it behaves:

- **Database only.** The page runs a database backup (no files option yet). Run it from the command line for a full backup.
- **Queued.** A backup can take longer than a web request, so it runs as a queued job. You need a queue worker, and the queue connection's `retry_after` must be greater than `BACKUP_UI_TIMEOUT`. One backup runs at a time; the page shows `queued` / `running` / `completed` / `failed` and refreshes the status (not the archive table) every few seconds while a run is active. A completed banner disappears after an hour; a failed one after a day.
- **Errors stay short.** A failed run shows only the first line of the error (up to 200 characters), never raw process output. The full detail is in your logs and the failure email. If the worker kills a job for exceeding the timeout, the page shows the run as failed but no failure email is sent (the email only comes from errors raised inside the backup itself).
- **Downloads.** The page never contains a download link. Each archive has a `POST` form; the server checks access, then redirects to a fresh temporary URL that is valid for `BACKUP_UI_DOWNLOAD_EXPIRY` minutes (5 by default, independent of `BACKUP_DOWNLOAD_EXPIRY`), or streams the file on disks that can't make one.

### What the host app needs

- **A shared cache.** The run lock and status live in the cache. The web app and the queue worker must use the same cache store (Redis, database, file on one server) — not `array` or `null`, or the page never sees the worker's progress.
- **A real queue worker.** With `QUEUE_CONNECTION=sync` the dump runs inside the web request: there is no timeout and a gateway or PHP may kill it half way. Use a real queue (`database`, `redis`, ...) and run a worker.
- **Tailwind that scans the views.** The page uses Tailwind classes and Atom components. Your Tailwind build must scan Atom's components and this package's views, or the page renders unstyled. With Tailwind 4, in your app CSS:

```css
@source '../../vendor/jiannius/atom/components';
@source '../../vendor/jiannius/backup/resources/views';
```

- **A layout.** Set `ui.layout` to a Blade layout view, or leave it `null` to use your Livewire default layout (`livewire.component_layout`). If that layout uses `<atom:html>`, don't add another `<body>` of your own — `<atom:html>` already writes the document.
- **A named `login` route.** The default `auth` middleware redirects guests to `route('login')`. Without one, guests get an error instead of a redirect.

### Who can use it, and what gets recorded

The package does not decide who may open the page or what is logged. You register **hooks**, usually in `AppServiceProvider::boot()`:

```php
use Illuminate\Http\Request;

// Who can reach the page, the run action and downloads. Return false for a 403.
// With nothing registered, only the "local" environment is allowed.
backup()->auth(fn (Request $request): bool => in_array(
    $request->user()?->email,
    ['admin@example.com'],
));

// Before every backup: the page, `backup:run`, the scheduler, or backup()->run().
// Receives ['database' => bool, 'files' => bool]. Throw or abort() to block it.
backup()->beforeRunning(function (array $options): void {
    AuditLog::create([
        'event' => 'backup.run',
        'user_id' => auth()->id(),
        'source' => app()->runningInConsole() ? 'console' : 'ui',
        'options' => $options,
    ]);
});

// Before an archive download is handed out. Throw or abort() to block it.
backup()->beforeDownloading(function (string $filename): void {
    AuditLog::create([
        'event' => 'backup.download',
        'user_id' => auth()->id(),
        'filename' => $filename,
    ]);
});
```

- `AuditLog` above is your own model — the package has no audit dependency; log however you like.
- Each hook can be registered more than once; they run in order. Several `auth` hooks must all allow the request.
- `beforeRunning` runs at request time for UI backups (so `auth()->user()` is the person who clicked), not inside the queue worker. A hook that blocks a UI run shows an error to that user and sends no email.
- For `backup:run`, the scheduler and `backup()->run()`, a `beforeRunning` hook that throws blocks the backup and is logged and emailed like any other failure, so an unattended run never fails silently.
- The `auth` hook is checked on the page, the run action, every Livewire update request and every download. The `middleware` config can add to the checks but never remove it. With `BACKUP_UI_ENABLED` off, the routes answer 404.
- **Decide by the user, not by the request.** The page is a Livewire component: its follow-up requests (run, status refresh) go to Livewire's own update URL, not to `/backups`. An `auth` callback that checks `$request->path()` or the route name will pass on the page and fail on every update. Check `$request->user()` instead.

## Restoring a copy locally

An archive is a plain zip — `db.sql` is a normal SQL dump. To inspect or recover data, restore it into a **separate local database**, never into the live one and never into a remote host.

1. Download the archive and unzip it somewhere private: `unzip myapp-2026-06-03-021500.zip -d backup-copy`.
2. Create a new local database with a name that can't be mistaken for the real one, e.g. `myapp_prod_copy`, then import `db.sql` into that database only:

```bash
# MySQL / MariaDB
mysql -u root -p -e 'CREATE DATABASE myapp_prod_copy'
mysql -u root -p myapp_prod_copy < backup-copy/db.sql

# PostgreSQL
createdb myapp_prod_copy
psql -d myapp_prod_copy -f backup-copy/db.sql

# SQLite
sqlite3 myapp_prod_copy.sqlite < backup-copy/db.sql
```

3. Point a local checkout at the copy (`DB_DATABASE=myapp_prod_copy`) to look around.
4. When you're done, delete the zip and `db.sql`, and drop the database:

```bash
rm -rf backup-copy myapp-2026-06-03-021500.zip
mysql -u root -p -e 'DROP DATABASE myapp_prod_copy'   # or: dropdb myapp_prod_copy / rm myapp_prod_copy.sqlite
```

The dump contains your real data. Keep it off shared drives and chat, and don't leave copies lying around.

## Failure notifications

When a run fails for any reason — dump error, missing folder, upload rejected, a `beforeRunning` hook that throws — the error is logged and a markdown email is sent to `BACKUP_NOTIFICATION_EMAIL` (if set), then the exception is rethrown. A broken mailer never masks the original failure.

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
