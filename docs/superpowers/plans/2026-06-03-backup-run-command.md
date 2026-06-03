# `backup:run` Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `backup:run` artisan command that dumps the host app's database (sqlite/mysql/pgsql via spatie/db-dumper), zips it together with configured folders, uploads the archive to a configurable Laravel disk, prunes archives older than N days, and emails a configured address on failure.

**Architecture:** Thin command delegating to `Backup::run()` (the package singleton, public API per package convention), which orchestrates three focused services: `DatabaseDumper` (Laravel connection → spatie dumper), `Archiver` (ZipArchive with exclude globs), `Pruner` (retention cleanup on the destination disk). Failure path sends a `BackupFailed` markdown mailable. The unused skeleton scaffold (model/factory/migration/route/component) is removed.

**Tech Stack:** PHP 8.4 · Laravel 13 via Orchestra Testbench 11 · Pest 4 · spatie/db-dumper ^3 · laravel/pint

**Spec:** `docs/superpowers/specs/2026-06-03-backup-run-command-design.md`

**Conventions for every task:**
- This is a package: there is NO `php artisan`. Artisan equivalent is `vendor/bin/testbench`. Never use `make:` generators — create files manually.
- Before each commit run `vendor/bin/pint --dirty` to fix style.
- All test files auto-extend `Jiannius\Backup\Tests\TestCase` (Orchestra Testbench, in-memory sqlite default connection named `testing`) via `tests/Pest.php`. `config('app.name')` in tests is `Laravel`, so archive slugs are `laravel`.

---

### Task 1: Add the spatie/db-dumper dependency

**Files:**
- Modify: `composer.json` (via composer CLI)

- [ ] **Step 1: Require the package**

Run: `composer require spatie/db-dumper`
Expected: resolves to `^3.x` and updates `composer.json` + `composer.lock` without conflicts.

- [ ] **Step 2: Verify the suite still passes**

Run: `composer test`
Expected: all existing tests PASS.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: add spatie/db-dumper"
```

---

### Task 2: Replace the sample config with the backup config

**Files:**
- Modify: `config/backup.php` (full replacement)
- Modify: `tests/Feature/ServiceProviderTest.php` (the config test only)

- [ ] **Step 1: Update the failing test**

In `tests/Feature/ServiceProviderTest.php`, replace the existing `it('merges the package config so config(backup.*) is available', ...)` test with:

```php
it('merges the package config so config(backup.*) is available', function () {
    expect(config('backup.disk'))->toBe('local');
    expect(config('backup.path'))->toBe('backups');
    expect(config('backup.database.connection'))->toBeNull();
    expect(config('backup.files.include'))->toBe([]);
    expect(config('backup.retention.days'))->toBe(30);
    expect(config('backup.notifications.email'))->toBeNull();
});
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php`
Expected: FAIL — `config('backup.disk')` is null (old sample config only has `name`).

- [ ] **Step 3: Replace `config/backup.php` entirely**

```php
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/ServiceProviderTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add config/backup.php tests/Feature/ServiceProviderTest.php
git commit -m "feat: backup config (disk, path, database, files, retention, notifications)"
```

---

### Task 3: DatabaseDumper service

**Files:**
- Create: `src/Services/DatabaseDumper.php`
- Test: `tests/Unit/DatabaseDumperTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/DatabaseDumperTest.php`:

```php
<?php

use Jiannius\Backup\Services\DatabaseDumper;
use Spatie\DbDumper\Databases\MariaDb;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\Databases\Sqlite;

it('maps sqlite connections to the sqlite dumper', function () {
    config()->set('database.connections.source', [
        'driver' => 'sqlite',
        'database' => '/tmp/db.sqlite',
    ]);

    $dumper = (new DatabaseDumper)->dumper('source');

    expect($dumper)->toBeInstanceOf(Sqlite::class);
    expect($dumper->getDbName())->toBe('/tmp/db.sqlite');
});

it('maps mysql connections to the mysql dumper', function () {
    config()->set('database.connections.source', [
        'driver' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3307,
        'database' => 'app',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $dumper = (new DatabaseDumper)->dumper('source');

    expect($dumper)->toBeInstanceOf(MySql::class);
    expect($dumper)->not->toBeInstanceOf(MariaDb::class);
    expect($dumper->getDbName())->toBe('app');
    expect($dumper->getHost())->toBe('db.example.com');
});

it('maps mariadb connections to the mariadb dumper', function () {
    // spatie/db-dumper v4's MariaDb dumper uses the mariadb-dump binary,
    // which is the one actually shipped on modern MariaDB installs.
    config()->set('database.connections.source', [
        'driver' => 'mariadb',
        'host' => 'db.example.com',
        'port' => 3307,
        'database' => 'app',
        'username' => 'root',
        'password' => 'secret',
    ]);

    $dumper = (new DatabaseDumper)->dumper('source');

    expect($dumper)->toBeInstanceOf(MariaDb::class);
    expect($dumper->getDbName())->toBe('app');
    expect($dumper->getHost())->toBe('db.example.com');
});

it('maps pgsql connections to the postgres dumper', function () {
    config()->set('database.connections.source', [
        'driver' => 'pgsql',
        'host' => 'pg.example.com',
        'port' => 5433,
        'database' => 'app',
        'username' => 'postgres',
        'password' => 'secret',
    ]);

    $dumper = (new DatabaseDumper)->dumper('source');

    expect($dumper)->toBeInstanceOf(PostgreSql::class);
    expect($dumper->getDbName())->toBe('app');
    expect($dumper->getHost())->toBe('pg.example.com');
});

it('uses the default connection when none is configured', function () {
    // The Testbench default connection ("testing") is in-memory sqlite.
    $dumper = (new DatabaseDumper)->dumper();

    expect($dumper)->toBeInstanceOf(Sqlite::class);
    expect($dumper->getDbName())->toBe(':memory:');
});

it('applies the configured dump binary path', function () {
    config()->set('database.connections.source', [
        'driver' => 'sqlite',
        'database' => '/tmp/db.sqlite',
    ]);
    config()->set('backup.database.binary_path', '/usr/local/bin/');

    $dumper = (new DatabaseDumper)->dumper('source');

    expect($dumper->getDumpCommand('/tmp/out.sql'))->toContain('/usr/local/bin/sqlite3');
});

it('throws for unsupported database drivers', function () {
    config()->set('database.connections.source', [
        'driver' => 'sqlsrv',
        'database' => 'app',
    ]);

    expect(fn () => (new DatabaseDumper)->dumper('source'))
        ->toThrow(RuntimeException::class, 'Unsupported database driver [sqlsrv]');
});

it('throws for unknown connections', function () {
    expect(fn () => (new DatabaseDumper)->dumper('nope'))
        ->toThrow(RuntimeException::class, 'Database connection [nope] is not configured');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/DatabaseDumperTest.php`
Expected: FAIL — `Class "Jiannius\Backup\Services\DatabaseDumper" not found`.

- [ ] **Step 3: Implement the service**

Create `src/Services/DatabaseDumper.php`:

```php
<?php

namespace Jiannius\Backup\Services;

use RuntimeException;
use Spatie\DbDumper\Databases\MariaDb;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\Databases\Sqlite;
use Spatie\DbDumper\DbDumper;

class DatabaseDumper
{
    /**
     * Dump the given Laravel connection to a file.
     */
    public function dump(?string $connection, string $path): void
    {
        $this->dumper($connection)->dumpToFile($path);
    }

    /**
     * Build the spatie/db-dumper instance for a Laravel connection.
     */
    public function dumper(?string $connection = null): DbDumper
    {
        $name = $connection ?? config('database.default');
        $config = config("database.connections.{$name}");

        if (! $config) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
        }

        $dumper = match ($config['driver']) {
            'sqlite' => Sqlite::create()
                ->setDbName($config['database']),
            'mysql' => MySql::create()
                ->setHost($config['host'] ?? '127.0.0.1')
                ->setPort((int) ($config['port'] ?? 3306))
                ->setDbName($config['database'])
                ->setUserName($config['username'] ?? '')
                ->setPassword($config['password'] ?? ''),
            'mariadb' => MariaDb::create()
                ->setHost($config['host'] ?? '127.0.0.1')
                ->setPort((int) ($config['port'] ?? 3306))
                ->setDbName($config['database'])
                ->setUserName($config['username'] ?? '')
                ->setPassword($config['password'] ?? ''),
            'pgsql' => PostgreSql::create()
                ->setHost($config['host'] ?? '127.0.0.1')
                ->setPort((int) ($config['port'] ?? 5432))
                ->setDbName($config['database'])
                ->setUserName($config['username'] ?? '')
                ->setPassword($config['password'] ?? ''),
            default => throw new RuntimeException("Unsupported database driver [{$config['driver']}] for backup."),
        };

        if ($binaryPath = config('backup.database.binary_path')) {
            $dumper->setDumpBinaryPath($binaryPath);
        }

        return $dumper;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/DatabaseDumperTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Services/DatabaseDumper.php tests/Unit/DatabaseDumperTest.php
git commit -m "feat: DatabaseDumper maps Laravel connections to spatie dumpers"
```

---

### Task 4: Archiver service

**Files:**
- Create: `src/Services/Archiver.php`
- Test: `tests/Unit/ArchiverTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ArchiverTest.php`:

```php
<?php

use Illuminate\Support\Facades\File;
use Jiannius\Backup\Services\Archiver;

beforeEach(function () {
    $this->temp = sys_get_temp_dir().'/archiver-test-'.uniqid();

    File::makeDirectory($this->temp.'/source/cache', 0755, true);
    File::put($this->temp.'/source/one.txt', 'one');
    File::put($this->temp.'/source/app.log', 'log');
    File::put($this->temp.'/source/cache/two.txt', 'two');
    File::put($this->temp.'/db.sql', 'CREATE TABLE examples;');
});

afterEach(function () {
    File::deleteDirectory($this->temp);
});

it('zips the dump at the root and folder contents under files/', function () {
    $zipPath = $this->temp.'/backup.zip';
    $source = $this->temp.'/source';

    (new Archiver)->create($zipPath, $this->temp.'/db.sql', [$source]);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->getFromName('db.sql'))->toBe('CREATE TABLE examples;');
    expect($zip->locateName('files/'.ltrim($source, '/').'/one.txt'))->not->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/cache/two.txt'))->not->toBeFalse();
});

it('honors exclude glob patterns against folder-relative paths', function () {
    $zipPath = $this->temp.'/backup.zip';
    $source = $this->temp.'/source';

    (new Archiver)->create($zipPath, $this->temp.'/db.sql', [$source], ['*.log', 'cache/*']);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->locateName('files/'.ltrim($source, '/').'/one.txt'))->not->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/app.log'))->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/cache/two.txt'))->toBeFalse();
});

it('creates a files-only archive when no dump is given', function () {
    $zipPath = $this->temp.'/backup.zip';
    $source = $this->temp.'/source';

    (new Archiver)->create($zipPath, null, [$source]);

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->locateName('db.sql'))->toBeFalse();
    expect($zip->locateName('files/'.ltrim($source, '/').'/one.txt'))->not->toBeFalse();
});

it('throws when an included folder does not exist', function () {
    expect(fn () => (new Archiver)->create($this->temp.'/backup.zip', null, [$this->temp.'/missing']))
        ->toThrow(RuntimeException::class, 'does not exist');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/ArchiverTest.php`
Expected: FAIL — `Class "Jiannius\Backup\Services\Archiver" not found`.

- [ ] **Step 3: Implement the service**

Create `src/Services/Archiver.php`:

```php
<?php

namespace Jiannius\Backup\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class Archiver
{
    /**
     * Create a zip archive containing the database dump and the given folders.
     *
     * @param  list<string>  $folders  absolute folder paths to include
     * @param  list<string>  $excludes  glob patterns matched against paths relative to each folder
     */
    public function create(string $zipPath, ?string $dumpPath, array $folders, array $excludes = []): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create zip archive at [{$zipPath}].");
        }

        if ($dumpPath) {
            $zip->addFile($dumpPath, 'db.sql');
        }

        foreach ($folders as $folder) {
            $this->addFolder($zip, rtrim($folder, '/'), $excludes);
        }

        $zip->close();
    }

    /**
     * Recursively add a folder's files under files/<full-path> in the zip.
     *
     * @param  list<string>  $excludes
     */
    protected function addFolder(ZipArchive $zip, string $folder, array $excludes): void
    {
        if (! is_dir($folder)) {
            throw new RuntimeException("Backup folder [{$folder}] does not exist.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($folder)), '/');

            if ($this->isExcluded($relative, $excludes)) {
                continue;
            }

            $zip->addFile($file->getPathname(), 'files/'.ltrim($file->getPathname(), '/'));
        }
    }

    /**
     * Whether a folder-relative path matches any exclude glob pattern.
     *
     * @param  list<string>  $excludes
     */
    protected function isExcluded(string $relative, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/ArchiverTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Services/Archiver.php tests/Unit/ArchiverTest.php
git commit -m "feat: Archiver zips dump and folders with exclude globs"
```

---

### Task 5: Pruner service

**Files:**
- Create: `src/Services/Pruner.php`
- Test: `tests/Unit/PrunerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PrunerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Services\Pruner;

it('deletes only this app\'s archives older than the retention period', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    // config('app.name') is "Laravel" in Testbench, so the slug is "laravel".
    $disk->put('backups/laravel-2026-01-01-000000.zip', 'old');
    $disk->put('backups/laravel-2026-06-01-000000.zip', 'new');
    $disk->put('backups/other-2026-01-01-000000.zip', 'unrelated');
    $disk->put('backups/laravel-notes.txt', 'unrelated');

    $stale = now()->subDays(40)->getTimestamp();
    touch($disk->path('backups/laravel-2026-01-01-000000.zip'), $stale);
    touch($disk->path('backups/other-2026-01-01-000000.zip'), $stale);
    touch($disk->path('backups/laravel-notes.txt'), $stale);

    (new Pruner)->prune('local', 'backups', 30);

    expect($disk->exists('backups/laravel-2026-01-01-000000.zip'))->toBeFalse();
    expect($disk->exists('backups/laravel-2026-06-01-000000.zip'))->toBeTrue();
    expect($disk->exists('backups/other-2026-01-01-000000.zip'))->toBeTrue();
    expect($disk->exists('backups/laravel-notes.txt'))->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/PrunerTest.php`
Expected: FAIL — `Class "Jiannius\Backup\Services\Pruner" not found`.

- [ ] **Step 3: Implement the service**

Create `src/Services/Pruner.php`:

```php
<?php

namespace Jiannius\Backup\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Pruner
{
    /**
     * Delete this app's backup archives older than the retention period.
     */
    public function prune(string $disk, string $path, int $days): void
    {
        $storage = Storage::disk($disk);
        $cutoff = now()->subDays($days)->getTimestamp();
        $pattern = Str::slug(config('app.name')).'-*.zip';

        foreach ($storage->files($path) as $file) {
            if (! fnmatch($pattern, basename($file))) {
                continue;
            }

            if ($storage->lastModified($file) < $cutoff) {
                $storage->delete($file);
            }
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/PrunerTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Services/Pruner.php tests/Unit/PrunerTest.php
git commit -m "feat: Pruner removes archives older than the retention period"
```

---

### Task 6: BackupFailed mailable + view

**Files:**
- Create: `src/Mail/BackupFailed.php`
- Create: `resources/views/mail/failed.blade.php`
- Test: `tests/Feature/BackupFailedMailTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackupFailedMailTest.php`:

```php
<?php

use Jiannius\Backup\Mail\BackupFailed;

it('renders the failure email with the subject and error message', function () {
    $mailable = new BackupFailed(new RuntimeException('Connection refused'));

    $mailable->assertHasSubject('[Laravel] Backup failed');
    $mailable->assertSeeInHtml('Connection refused');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/BackupFailedMailTest.php`
Expected: FAIL — `Class "Jiannius\Backup\Mail\BackupFailed" not found`.

- [ ] **Step 3: Implement the mailable and view**

Create `src/Mail/BackupFailed.php`:

```php
<?php

namespace Jiannius\Backup\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Throwable;

class BackupFailed extends Mailable
{
    public function __construct(public Throwable $exception) {}

    /**
     * The message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Backup failed',
        );
    }

    /**
     * The message content.
     */
    public function content(): Content
    {
        // NOTE: 'message' is a reserved mail view variable — use 'error'.
        return new Content(
            markdown: 'backup::mail.failed',
            with: ['error' => $this->exception->getMessage()],
        );
    }
}
```

Create `resources/views/mail/failed.blade.php`:

```blade
<x-mail::message>
# Backup failed

The scheduled backup for **{{ config('app.name') }}** failed with the following error:

<x-mail::panel>
{{ $error }}
</x-mail::panel>

Please check the application logs for details.
</x-mail::message>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/BackupFailedMailTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Mail/BackupFailed.php resources/views/mail/failed.blade.php tests/Feature/BackupFailedMailTest.php
git commit -m "feat: BackupFailed failure mailable"
```

---

### Task 7: `Backup::run()` orchestrator

**Files:**
- Modify: `src/Backup.php` (add `run()` + `notifyFailure()`, keep `version()`/`config()`)
- Modify: `tests/Pest.php` (add `sqlite3Available()` helper)
- Test: `tests/Feature/BackupRunTest.php`

- [ ] **Step 1: Add the sqlite3 availability helper**

Replace `tests/Pest.php` with:

```php
<?php

use Jiannius\Backup\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Whether the sqlite3 CLI binary is available for real dump tests.
 */
function sqlite3Available(): bool
{
    return trim((string) shell_exec('command -v sqlite3')) !== '';
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/BackupRunTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Mail\BackupFailed;

beforeEach(function () {
    Storage::fake('local');

    $this->temp = sys_get_temp_dir().'/backup-run-test-'.uniqid();

    File::makeDirectory($this->temp.'/uploads', 0755, true);
    File::put($this->temp.'/uploads/photo.txt', 'photo');
    File::put($this->temp.'/uploads/app.log', 'log');

    // A file-based sqlite connection the dumper can actually read.
    File::put($this->temp.'/source.sqlite', '');
    config()->set('database.connections.source', [
        'driver' => 'sqlite',
        'database' => $this->temp.'/source.sqlite',
        'prefix' => '',
    ]);
    DB::connection('source')->statement('CREATE TABLE examples (id INTEGER PRIMARY KEY, name TEXT)');
    DB::connection('source')->table('examples')->insert(['name' => 'demo']);

    config()->set('backup.database.connection', 'source');
});

afterEach(function () {
    File::deleteDirectory($this->temp);
});

it('uploads a zip containing the dump and configured folders', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);
    config()->set('backup.files.exclude', ['*.log']);

    $filename = backup()->run();

    expect($filename)->toStartWith('laravel-')->toEndWith('.zip');

    $disk = Storage::disk('local');
    expect($disk->exists("backups/{$filename}"))->toBeTrue();

    $zip = new ZipArchive;
    $zip->open($disk->path("backups/{$filename}"));

    expect($zip->getFromName('db.sql'))->toContain('examples');
    expect($zip->locateName('files/'.ltrim($this->temp, '/').'/uploads/photo.txt'))->not->toBeFalse();
    expect($zip->locateName('files/'.ltrim($this->temp, '/').'/uploads/app.log'))->toBeFalse();
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('produces a database-only archive when no folders are configured', function () {
    $filename = backup()->run();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path("backups/{$filename}"));

    expect($zip->locateName('db.sql'))->not->toBeFalse();
    expect($zip->count())->toBe(1);
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('skips the database dump when database is disabled', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);

    $filename = backup()->run(database: false);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path("backups/{$filename}"));

    expect($zip->locateName('db.sql'))->toBeFalse();
    expect($zip->locateName('files/'.ltrim($this->temp, '/').'/uploads/photo.txt'))->not->toBeFalse();
});

it('throws when running files-only with no folders configured', function () {
    expect(fn () => backup()->run(database: false))
        ->toThrow(RuntimeException::class, 'Nothing to back up');
});

it('prunes old archives after a successful run', function () {
    $disk = Storage::disk('local');
    $disk->put('backups/laravel-2026-01-01-000000.zip', 'old');
    touch($disk->path('backups/laravel-2026-01-01-000000.zip'), now()->subDays(60)->getTimestamp());

    backup()->run();

    expect($disk->exists('backups/laravel-2026-01-01-000000.zip'))->toBeFalse();
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('emails the configured recipient when the backup fails', function () {
    Mail::fake();
    config()->set('backup.notifications.email', 'ops@example.com');
    config()->set('backup.database.connection', 'nope');

    expect(fn () => backup()->run())->toThrow(RuntimeException::class);

    Mail::assertSent(BackupFailed::class, fn (BackupFailed $mail) => $mail->hasTo('ops@example.com'));
});

it('sends no email on failure when none is configured', function () {
    Mail::fake();
    config()->set('backup.database.connection', 'nope');

    expect(fn () => backup()->run())->toThrow(RuntimeException::class);

    Mail::assertNothingSent();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/BackupRunTest.php`
Expected: FAIL — `Call to undefined method Jiannius\Backup\Backup::run()` (skipped tests may show as skipped if sqlite3 is absent; the non-dump tests must fail).

- [ ] **Step 4: Implement `run()` on the singleton**

Replace `src/Backup.php` with:

```php
<?php

namespace Jiannius\Backup;

use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jiannius\Backup\Mail\BackupFailed;
use Jiannius\Backup\Services\Archiver;
use Jiannius\Backup\Services\DatabaseDumper;
use Jiannius\Backup\Services\Pruner;
use RuntimeException;
use Throwable;

class Backup
{
    /**
     * The package version.
     */
    public function version(): string
    {
        // Keep in sync with the version in composer.json.
        return '0.1.0';
    }

    /**
     * Read a package config value (dot notation, scoped to "backup").
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        return config($key ? "backup.{$key}" : 'backup', $default);
    }

    /**
     * Run a backup: dump the database, zip it with the configured folders,
     * upload the archive to the backup disk, then prune old archives.
     * Every failure is notified (logged + emailed) before rethrowing.
     *
     * @return string the uploaded archive filename
     */
    public function run(bool $database = true, bool $files = true): string
    {
        try {
            return $this->execute($database, $files);
        } catch (Throwable $e) {
            $this->notifyFailure($e);

            throw $e;
        }
    }

    /**
     * The backup pipeline: dump → zip → upload → prune.
     */
    protected function execute(bool $database, bool $files): string
    {
        $include = $files ? $this->config('files.include', []) : [];

        if (! $database && empty($include)) {
            throw new RuntimeException('Nothing to back up: no folders configured in backup.files.include.');
        }

        $temp = sys_get_temp_dir().'/backup-'.Str::lower(Str::random(8));
        File::makeDirectory($temp, 0755, true);

        try {
            $dump = null;

            if ($database) {
                $dump = $temp.'/db.sql';
                app(DatabaseDumper::class)->dump($this->config('database.connection'), $dump);
            }

            $filename = Str::slug(config('app.name')).'-'.now()->format('Y-m-d-His').'.zip';
            $zip = $temp.'/'.$filename;

            app(Archiver::class)->create($zip, $dump, $include, $this->config('files.exclude', []));

            Storage::disk($this->config('disk'))
                ->putFileAs($this->config('path'), new HttpFile($zip), $filename);

            app(Pruner::class)->prune($this->config('disk'), $this->config('path'), (int) $this->config('retention.days'));

            return $filename;
        } finally {
            File::deleteDirectory($temp);
        }
    }

    /**
     * Log the failure and email the configured recipient, if any.
     */
    protected function notifyFailure(Throwable $e): void
    {
        logger()->error("Backup failed: {$e->getMessage()}", ['exception' => $e]);

        $email = $this->config('notifications.email');

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new BackupFailed($e));
        } catch (Throwable $mailError) {
            // Never let a broken mailer mask the original backup failure.
            logger()->error("Backup failure email could not be sent: {$mailError->getMessage()}");
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/BackupRunTest.php`
Expected: PASS (7 tests; 3 of them skipped instead if no `sqlite3` binary).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add src/Backup.php tests/Pest.php tests/Feature/BackupRunTest.php
git commit -m "feat: Backup::run orchestrates dump, zip, upload, prune, failure email"
```

---

### Task 8: `backup:run` command

**Files:**
- Modify: `src/Commands/BackupCommand.php` (full replacement)
- Modify: `tests/Feature/CommandTest.php` (full replacement)

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/CommandTest.php` with:

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->temp = sys_get_temp_dir().'/backup-command-test-'.uniqid();

    File::makeDirectory($this->temp.'/uploads', 0755, true);
    File::put($this->temp.'/uploads/photo.txt', 'photo');

    File::put($this->temp.'/source.sqlite', '');
    config()->set('database.connections.source', [
        'driver' => 'sqlite',
        'database' => $this->temp.'/source.sqlite',
        'prefix' => '',
    ]);
    DB::connection('source')->statement('CREATE TABLE examples (id INTEGER PRIMARY KEY, name TEXT)');

    config()->set('backup.database.connection', 'source');
});

afterEach(function () {
    File::deleteDirectory($this->temp);
});

it('runs a full backup with exit code 0', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);

    $this->artisan('backup:run')->assertExitCode(0);

    expect(Storage::disk('local')->files('backups'))->toHaveCount(1);
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('backs up files only with --only-files', function () {
    config()->set('backup.files.include', [$this->temp.'/uploads']);

    $this->artisan('backup:run --only-files')->assertExitCode(0);

    expect(Storage::disk('local')->files('backups'))->toHaveCount(1);
});

it('backs up the database only with --only-db', function () {
    $this->artisan('backup:run --only-db')->assertExitCode(0);

    expect(Storage::disk('local')->files('backups'))->toHaveCount(1);
})->skip(fn () => ! sqlite3Available(), 'sqlite3 binary not available');

it('rejects --only-db combined with --only-files', function () {
    $this->artisan('backup:run --only-db --only-files')->assertExitCode(2);
});

it('returns a failure exit code when the backup errors', function () {
    config()->set('backup.database.connection', 'nope');

    $this->artisan('backup:run')->assertExitCode(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/CommandTest.php`
Expected: FAIL — `There are no commands defined in the "backup" namespace` / command "backup:run" is not defined.

- [ ] **Step 3: Implement the command**

Replace `src/Commands/BackupCommand.php` with:

```php
<?php

namespace Jiannius\Backup\Commands;

use Illuminate\Console\Command;
use Throwable;

class BackupCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'backup:run
        {--only-db : Back up the database only}
        {--only-files : Back up the configured folders only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Back up the database and configured folders to the backup disk.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('only-db') && $this->option('only-files')) {
            $this->error('The --only-db and --only-files options are mutually exclusive.');

            return self::INVALID;
        }

        try {
            $filename = backup()->run(
                database: ! $this->option('only-files'),
                files: ! $this->option('only-db'),
            );
        } catch (Throwable $e) {
            $this->error("Backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Backup uploaded: {$filename}");

        return self::SUCCESS;
    }
}
```

(The service provider already registers `BackupCommand::class`; no provider change needed.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/CommandTest.php`
Expected: PASS (5 tests; 2 skipped instead if no `sqlite3` binary).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Commands/BackupCommand.php tests/Feature/CommandTest.php
git commit -m "feat: backup:run command with --only-db and --only-files"
```

---

### Task 9: Remove unused skeleton scaffold

**Files:**
- Delete: `src/Models/Backup.php`
- Delete: `database/factories/BackupFactory.php`
- Delete: `database/migrations/0001_01_01_000000_create_backups_table.php`
- Delete: `routes/web.php`
- Delete: `components/example.blade.php`
- Delete: `tests/Feature/BackupModelTest.php`
- Delete: `tests/Feature/RouteTest.php`
- Delete: `tests/Feature/ComponentTest.php`
- Modify: `src/BackupServiceProvider.php`
- Modify: `composer.json` (remove factories autoload namespace)

Approved in the spec: the Backup model, factory, migration, example route, and example component are unused scaffold and are removed along with their tests. `Traits\Enum` + `EnumTest` stay (skeleton convention). View loading stays (the mailable needs it).

- [ ] **Step 1: Delete the scaffold files**

```bash
git rm src/Models/Backup.php \
    database/factories/BackupFactory.php \
    database/migrations/0001_01_01_000000_create_backups_table.php \
    routes/web.php \
    components/example.blade.php \
    tests/Feature/BackupModelTest.php \
    tests/Feature/RouteTest.php \
    tests/Feature/ComponentTest.php
```

- [ ] **Step 2: Update the service provider**

Replace `src/BackupServiceProvider.php` with:

```php
<?php

namespace Jiannius\Backup;

use Illuminate\Support\ServiceProvider;
use Jiannius\Backup\Commands\BackupCommand;

class BackupServiceProvider extends ServiceProvider
{
    /**
     * Register package bindings and merge config.
     */
    public function register(): void
    {
        // Merge package config so config('backup.*') is always available,
        // even before the host app publishes the file.
        $this->mergeConfigFrom(__DIR__.'/../config/backup.php', 'backup');

        // Bind the package singleton and expose it as app('backup').
        $this->app->singleton(Backup::class, fn (): Backup => new Backup);
        $this->app->alias(Backup::class, 'backup');
    }

    /**
     * Boot package resources into the host application.
     */
    public function boot(): void
    {
        // Views — referenced as view('backup::...'), used by the failure mailable.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'backup');

        if ($this->app->runningInConsole()) {
            // Let the host app publish + override the config file.
            $this->publishes([
                __DIR__.'/../config/backup.php' => config_path('backup.php'),
            ], 'backup-config');

            // Register the package's artisan commands.
            $this->commands([
                BackupCommand::class,
            ]);
        }
    }
}
```

- [ ] **Step 3: Remove the factories autoload namespace**

In `composer.json`, change the `autoload.psr-4` block from:

```json
"psr-4": {
    "Jiannius\\Backup\\": "src/",
    "Jiannius\\Backup\\Database\\Factories\\": "database/factories/"
},
```

to:

```json
"psr-4": {
    "Jiannius\\Backup\\": "src/"
},
```

Then run: `composer dump-autoload`
Expected: regenerates without errors (the `post-autoload-dump` Testbench discover hook runs).

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: PASS — no test references the deleted scaffold anymore.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "refactor: remove unused skeleton scaffold (model, migration, route, component)"
```

---

### Task 10: Update consumer guidelines and CLAUDE.md

**Files:**
- Modify: `resources/boost/guidelines/core.blade.php` (full replacement)
- Modify: `CLAUDE.md` (architecture + commands sections)

The consumer guideline ships to host apps via Laravel Boost; it must describe how to *use* the package and no longer claim that routes/migrations/components are loaded. `tests/Feature/ConsumerGuidelinesTest.php` requires the rendered file to contain `Backup` and `backup()` — the content below satisfies it.

- [ ] **Step 1: Replace `resources/boost/guidelines/core.blade.php`**

```blade
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
```

- [ ] **Step 2: Update CLAUDE.md's architecture section**

In `CLAUDE.md`, replace the `### Service-provider wiring (...)` paragraph body with:

```markdown
`register()` merges `config/backup.php` and binds the `Backup` singleton (aliased `app('backup')`). `boot()` loads `resources/views/` (view namespace `backup`, used by the failure mailable). Console-only: publishes the config (tag `backup-config`) and registers `backup:run`. Read this file first when something seems to come from nowhere.
```

And replace the `### Singleton entry-point (...)` paragraph body with:

```markdown
The package's public API object, resolvable via the container alias `backup` or the autoloaded `backup()` helper (`src/Helpers.php`). `run(bool $database = true, bool $files = true): string` performs dump → zip → upload → prune and returns the archive filename; collaborator services live in `src/Services/` (`DatabaseDumper`, `Archiver`, `Pruner`). Add cross-cutting package methods here.
```

- [ ] **Step 3: Run the guideline test + full suite**

Run: `vendor/bin/pest tests/Feature/ConsumerGuidelinesTest.php`
Expected: PASS.

Run: `composer test`
Expected: PASS (full suite).

- [ ] **Step 4: Commit**

```bash
git add resources/boost/guidelines/core.blade.php CLAUDE.md
git commit -m "docs: update consumer guidelines and CLAUDE.md for backup:run"
```

---

### Task 11: Final verification

- [ ] **Step 1: Lint everything**

Run: `composer lint`
Expected: no style fixes left (clean output or fixes already applied; if it fixes files, re-run `composer test` and amend the previous commit with the style fixes).

- [ ] **Step 2: Full suite**

Run: `composer test`
Expected: PASS. Note how many tests were skipped (sqlite3-dependent E2E tests skip only when the binary is missing — on this Mac, `sqlite3` exists, so expect 0 skips).

- [ ] **Step 3: Manual smoke check via Testbench**

Run: `vendor/bin/testbench backup:run --only-db 2>&1 | head -5`
Expected: either a success line (`Backup uploaded: laravel-....zip`) or a clear failure about the throwaway app's `:memory:` database — both prove command registration and wiring. Do not debug `:memory:` dump failures; the Pest suite is the source of truth.

- [ ] **Step 4: Commit any stragglers**

```bash
git status --short
# if anything is dirty from lint:
git add -A && git commit -m "style: pint fixes"
```
