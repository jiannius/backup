# Backup Listing + Download URLs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `backup()->list()` and a `backup:list {--url}` command that enumerate this app's archives on the backup disk with time-limited download URLs, and migrate the existing `src/Services/` collaborators to an `src/Actions/` pattern.

**Architecture:** A new `ListBackups` action reads the backup disk (globbing `{app-slug}-*.zip`, the same filter `Pruner`/`PruneBackups` uses), returning a `Collection` of metadata arrays sorted newest-first, each with a disk-native `temporaryUrl()` (null when the driver can't make one). `Backup::list()` is a thin delegator; `backup:list` is a thin CLI wrapper. The three existing single-purpose services (`DatabaseDumper`, `Archiver`, `Pruner`) are renamed to verb-first actions (`DumpDatabase`, `CreateArchive`, `PruneBackups`) with a single `handle()` entry method — these are internal classes, so no consumer breaks.

**Tech Stack:** PHP 8.4 · Laravel 13 (via Orchestra Testbench v11) · Pest 4 · `Illuminate\Support\Collection` / `Number` / `Storage` · Laravel Pint.

**Conventions:** Run all artisan-equivalent commands via `vendor/bin/testbench`. Run `vendor/bin/pint --dirty` before every commit that touches PHP. No `make:` generators — create files manually. Tests live under `tests/Unit` or `tests/Feature` (both extend `Tests\TestCase`).

---

## File map

**Migrated (renamed) files:**
- `src/Services/DatabaseDumper.php` → `src/Actions/DumpDatabase.php` (`dump()` → `handle()`; `dumper()` kept)
- `src/Services/Archiver.php` → `src/Actions/CreateArchive.php` (`create()` → `handle()`)
- `src/Services/Pruner.php` → `src/Actions/PruneBackups.php` (`prune()` → `handle()`)
- `tests/Unit/DatabaseDumperTest.php` → `tests/Unit/DumpDatabaseTest.php`
- `tests/Unit/ArchiverTest.php` → `tests/Unit/CreateArchiveTest.php`
- `tests/Unit/PrunerTest.php` → `tests/Unit/PruneBackupsTest.php`

**New files:**
- `src/Actions/ListBackups.php` — disk enumeration + URL generation
- `src/Commands/BackupListCommand.php` — `backup:list {--url}` CLI wrapper
- `tests/Unit/ListBackupsTest.php`
- `tests/Feature/BackupListTest.php` — `backup()->list()` delegation
- `tests/Feature/BackupListCommandTest.php` — `backup:list` command

**Modified files:**
- `src/Backup.php` — swap imports/call sites to the actions; add `list()`
- `config/backup.php` — add `download.expiry`
- `src/BackupServiceProvider.php` — register `BackupListCommand`
- `CLAUDE.md` — architecture note (Services → Actions, mention `list()`)
- `README.md` — env row + "Listing backups" section

---

## Task 1: Migrate `DatabaseDumper` → `DumpDatabase`

**Files:**
- Rename: `src/Services/DatabaseDumper.php` → `src/Actions/DumpDatabase.php`
- Rename: `tests/Unit/DatabaseDumperTest.php` → `tests/Unit/DumpDatabaseTest.php`
- Modify: `src/Backup.php`

This is a pure rename — no behavior change. The existing (renamed) test is the safety net.

- [ ] **Step 1: Move the source file with git**

```bash
git mv src/Services/DatabaseDumper.php src/Actions/DumpDatabase.php
```

- [ ] **Step 2: Update namespace, class name, and entry method in `src/Actions/DumpDatabase.php`**

Apply these three exact replacements (the `dumper()` method and all bodies stay unchanged):

- `namespace Jiannius\Backup\Services;` → `namespace Jiannius\Backup\Actions;`
- `class DatabaseDumper` → `class DumpDatabase`
- `    public function dump(?string $connection, string $path): void` → `    public function handle(?string $connection, string $path): void`

- [ ] **Step 3: Move and update the test file**

```bash
git mv tests/Unit/DatabaseDumperTest.php tests/Unit/DumpDatabaseTest.php
```

In `tests/Unit/DumpDatabaseTest.php`:
- `use Jiannius\Backup\Services\DatabaseDumper;` → `use Jiannius\Backup\Actions\DumpDatabase;`
- Replace every `new DatabaseDumper` with `new DumpDatabase` (9 occurrences; the test only calls `->dumper(...)`, so no method rename is needed).

- [ ] **Step 4: Update `src/Backup.php` import and call site**

- `use Jiannius\Backup\Services\DatabaseDumper;` → `use Jiannius\Backup\Actions\DumpDatabase;`
- `app(DatabaseDumper::class)->dump($this->config('database.connection'), $dump);` → `app(DumpDatabase::class)->handle($this->config('database.connection'), $dump);`

- [ ] **Step 5: Run the migrated test and verify it passes**

Run: `vendor/bin/pest tests/Unit/DumpDatabaseTest.php`
Expected: PASS (all the existing `dumper()` mapping assertions, now against `DumpDatabase`).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "refactor: migrate DatabaseDumper service to DumpDatabase action"
```

---

## Task 2: Migrate `Archiver` → `CreateArchive`

**Files:**
- Rename: `src/Services/Archiver.php` → `src/Actions/CreateArchive.php`
- Rename: `tests/Unit/ArchiverTest.php` → `tests/Unit/CreateArchiveTest.php`
- Modify: `src/Backup.php`

- [ ] **Step 1: Move the source file**

```bash
git mv src/Services/Archiver.php src/Actions/CreateArchive.php
```

- [ ] **Step 2: Update namespace, class name, and entry method in `src/Actions/CreateArchive.php`**

- `namespace Jiannius\Backup\Services;` → `namespace Jiannius\Backup\Actions;`
- `class Archiver` → `class CreateArchive`
- `    public function create(string $zipPath, ?string $dumpPath, array $folders, array $excludes = []): void` → `    public function handle(string $zipPath, ?string $dumpPath, array $folders, array $excludes = []): void`

(The `addFolder()` / `isExcluded()` helper methods stay unchanged.)

- [ ] **Step 3: Move and update the test file**

```bash
git mv tests/Unit/ArchiverTest.php tests/Unit/CreateArchiveTest.php
```

In `tests/Unit/CreateArchiveTest.php`:
- `use Jiannius\Backup\Services\Archiver;` → `use Jiannius\Backup\Actions\CreateArchive;`
- Replace every `new Archiver` with `new CreateArchive` (4 occurrences).
- Replace every `->create(` with `->handle(` (4 occurrences).

- [ ] **Step 4: Update `src/Backup.php` import and call site**

- `use Jiannius\Backup\Services\Archiver;` → `use Jiannius\Backup\Actions\CreateArchive;`
- `app(Archiver::class)->create($zip, $dump, $include, $this->config('files.exclude', []));` → `app(CreateArchive::class)->handle($zip, $dump, $include, $this->config('files.exclude', []));`

- [ ] **Step 5: Run the migrated test and verify it passes**

Run: `vendor/bin/pest tests/Unit/CreateArchiveTest.php`
Expected: PASS (zip-at-root, exclude globs, files-only, missing-folder-throws).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "refactor: migrate Archiver service to CreateArchive action"
```

---

## Task 3: Migrate `Pruner` → `PruneBackups`

**Files:**
- Rename: `src/Services/Pruner.php` → `src/Actions/PruneBackups.php`
- Rename: `tests/Unit/PrunerTest.php` → `tests/Unit/PruneBackupsTest.php`
- Modify: `src/Backup.php`

- [ ] **Step 1: Move the source file**

```bash
git mv src/Services/Pruner.php src/Actions/PruneBackups.php
```

- [ ] **Step 2: Update namespace, class name, and entry method in `src/Actions/PruneBackups.php`**

- `namespace Jiannius\Backup\Services;` → `namespace Jiannius\Backup\Actions;`
- `class Pruner` → `class PruneBackups`
- `    public function prune(string $disk, string $path, int $days): void` → `    public function handle(string $disk, string $path, int $days): void`

- [ ] **Step 3: Move and update the test file**

```bash
git mv tests/Unit/PrunerTest.php tests/Unit/PruneBackupsTest.php
```

In `tests/Unit/PruneBackupsTest.php`:
- `use Jiannius\Backup\Services\Pruner;` → `use Jiannius\Backup\Actions\PruneBackups;`
- Replace `new Pruner` with `new PruneBackups` (1 occurrence).
- Replace `->prune(` with `->handle(` (1 occurrence).

- [ ] **Step 4: Update `src/Backup.php` import and call site**

- `use Jiannius\Backup\Services\Pruner;` → `use Jiannius\Backup\Actions\PruneBackups;`
- `app(Pruner::class)->prune($this->config('disk'), $this->config('path'), (int) $this->config('retention.days'));` → `app(PruneBackups::class)->handle($this->config('disk'), $this->config('path'), (int) $this->config('retention.days'));`

- [ ] **Step 5: Run the full suite to confirm the whole migration is green**

Run: `composer test`
Expected: PASS. `src/Services/` is now empty (git no longer tracks it).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "refactor: migrate Pruner service to PruneBackups action"
```

---

## Task 4: Add the `download.expiry` config

**Files:**
- Modify: `config/backup.php`

- [ ] **Step 1: Insert the `download` block before the `notifications` block**

Anchor on the existing `notifications` section header and prepend the new block. Replace:

```php
    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
```

with:

```php
    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | Lifetime (in minutes) of the temporary download URLs generated by
    | backup()->list() and `backup:list --url`. Only disks whose driver can
    | produce temporary URLs (e.g. S3) return a URL; other disks list with a
    | null URL.
    |
    */

    'download' => [
        'expiry' => (int) env('BACKUP_DOWNLOAD_EXPIRY', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
```

- [ ] **Step 2: Verify the merged config resolves**

Run: `vendor/bin/testbench config:show backup.download.expiry`
Expected: prints `1440`.

- [ ] **Step 3: Commit**

```bash
git add config/backup.php
git commit -m "feat: add backup.download.expiry config for download-link lifetime"
```

---

## Task 5: `ListBackups` action

**Files:**
- Create: `src/Actions/ListBackups.php`
- Test: `tests/Unit/ListBackupsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ListBackupsTest.php`:

```php
<?php

use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Actions\ListBackups;

it('lists this app\'s archives newest first with metadata', function () {
    Storage::fake('local');
    $disk = Storage::disk('local');

    // config('app.name') is "Laravel" in Testbench, so the slug is "laravel".
    $disk->put('backups/laravel-2026-01-01-000000.zip', 'old');
    $disk->put('backups/laravel-2026-06-01-000000.zip', 'newer');
    $disk->put('backups/other-2026-01-01-000000.zip', 'unrelated');
    $disk->put('backups/laravel-notes.txt', 'unrelated');

    touch($disk->path('backups/laravel-2026-01-01-000000.zip'), now()->subDays(40)->getTimestamp());
    touch($disk->path('backups/laravel-2026-06-01-000000.zip'), now()->subDays(1)->getTimestamp());

    $backups = (new ListBackups)->handle('local', 'backups', 1440);

    expect($backups)->toHaveCount(2);
    expect($backups->pluck('filename')->all())->toBe([
        'laravel-2026-06-01-000000.zip',
        'laravel-2026-01-01-000000.zip',
    ]);
    expect($backups->first())->toHaveKeys(['filename', 'path', 'size', 'date', 'url']);
    expect($backups->first()['size'])->toBeInt();
    expect($backups->first()['path'])->toBe('backups/laravel-2026-06-01-000000.zip');
});

it('returns a null url when the disk cannot make temporary urls', function () {
    Storage::fake('local');
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = (new ListBackups)->handle('local', 'backups', 1440);

    expect($backups->first()['url'])->toBeNull();
});

it('uses the disk temporary url when the driver supports it', function () {
    Storage::fake('local');
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => 'https://cdn.test/'.$path.'?expires='.$expiration->getTimestamp(),
    );
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = (new ListBackups)->handle('local', 'backups', 60);

    expect($backups->first()['url'])
        ->toContain('https://cdn.test/backups/laravel-2026-06-01-000000.zip');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/ListBackupsTest.php`
Expected: FAIL — `Class "Jiannius\Backup\Actions\ListBackups" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Actions/ListBackups.php`:

```php
<?php

namespace Jiannius\Backup\Actions;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ListBackups
{
    /**
     * List this app's backup archives on the disk, newest first, each with a
     * temporary download URL (null when the disk driver can't produce one).
     *
     * @return Collection<int, array{filename: string, path: string, size: int, date: \Illuminate\Support\Carbon, url: ?string}>
     */
    public function handle(string $disk, string $path, int $expiryMinutes): Collection
    {
        $storage = Storage::disk($disk);
        $pattern = Str::slug(config('app.name')).'-*.zip';
        $expiry = now()->addMinutes($expiryMinutes);

        return collect($storage->files($path))
            ->filter(fn (string $file): bool => fnmatch($pattern, basename($file)))
            ->map(fn (string $file): array => [
                'filename' => basename($file),
                'path' => $file,
                'size' => $storage->size($file),
                'date' => Carbon::createFromTimestamp($storage->lastModified($file)),
                'url' => $this->url($storage, $file, $expiry),
            ])
            ->sortByDesc(fn (array $entry): int => $entry['date']->getTimestamp())
            ->values();
    }

    /**
     * Build a temporary download URL, or null when the disk driver can't.
     */
    protected function url(FilesystemAdapter $storage, string $file, DateTimeInterface $expiry): ?string
    {
        try {
            return $storage->temporaryUrl($file, $expiry);
        } catch (RuntimeException) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/ListBackupsTest.php`
Expected: PASS (all three cases — sorted metadata, null URL, supported URL).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Actions/ListBackups.php tests/Unit/ListBackupsTest.php
git commit -m "feat: add ListBackups action listing disk archives with download URLs"
```

---

## Task 6: `Backup::list()` method

**Files:**
- Modify: `src/Backup.php`
- Test: `tests/Feature/BackupListTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackupListTest.php`:

```php
<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('backup.disk', 'local');
    config()->set('backup.path', 'backups');
});

it('lists backups via the backup() helper', function () {
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = backup()->list();

    expect($backups)->toHaveCount(1);
    expect($backups->first()['filename'])->toBe('laravel-2026-06-01-000000.zip');
});

it('resolves the configured expiry when none is passed', function () {
    Carbon::setTestNow('2026-06-04 12:00:00');
    config()->set('backup.download.expiry', 90);
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => 'url?expires='.$expiration->getTimestamp(),
    );
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = backup()->list();

    $expected = now()->addMinutes(90)->getTimestamp();
    expect($backups->first()['url'])->toBe('url?expires='.$expected);

    Carbon::setTestNow();
});

it('honors an explicit expiry argument over the config default', function () {
    Carbon::setTestNow('2026-06-04 12:00:00');
    config()->set('backup.download.expiry', 90);
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => 'url?expires='.$expiration->getTimestamp(),
    );
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $backups = backup()->list(5);

    $expected = now()->addMinutes(5)->getTimestamp();
    expect($backups->first()['url'])->toBe('url?expires='.$expected);

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/BackupListTest.php`
Expected: FAIL — `Call to undefined method Jiannius\Backup\Backup::list()`.

- [ ] **Step 3: Add the `list()` method to `src/Backup.php`**

Add these two imports alongside the existing `use` statements at the top of the file:

```php
use Illuminate\Support\Collection;
use Jiannius\Backup\Actions\ListBackups;
```

Add the method immediately after the `run()` method (before `protected function execute(...)`):

```php
    /**
     * List this app's backup archives on the disk, newest first, each with a
     * temporary download URL (null when the disk driver can't produce one).
     *
     * @param  int|null  $expiry  download-link lifetime in minutes (null = config default)
     * @return Collection<int, array{filename: string, path: string, size: int, date: \Illuminate\Support\Carbon, url: ?string}>
     */
    public function list(?int $expiry = null): Collection
    {
        return app(ListBackups::class)->handle(
            $this->config('disk'),
            $this->config('path'),
            $expiry ?? (int) $this->config('download.expiry'),
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/BackupListTest.php`
Expected: PASS (delegation, config-default expiry, explicit-arg override).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Backup.php tests/Feature/BackupListTest.php
git commit -m "feat: add Backup::list() delegating to the ListBackups action"
```

---

## Task 7: `backup:list` command

**Files:**
- Create: `src/Commands/BackupListCommand.php`
- Modify: `src/BackupServiceProvider.php`
- Test: `tests/Feature/BackupListCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackupListCommandTest.php`:

```php
<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('backup.disk', 'local');
    config()->set('backup.path', 'backups');
});

it('lists archives in a table', function () {
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $this->artisan('backup:list')
        ->expectsOutputToContain('laravel-2026-06-01-000000.zip')
        ->assertExitCode(0);
});

it('shows a message when there are no backups', function () {
    $this->artisan('backup:list')
        ->expectsOutputToContain('No backups found.')
        ->assertExitCode(0);
});

it('adds a url column with --url', function () {
    Storage::disk('local')->buildTemporaryUrlsUsing(fn (string $path, $expiration): string => 'DL-LINK');
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $this->artisan('backup:list --url')
        ->expectsOutputToContain('DL-LINK')
        ->assertExitCode(0);
});

it('omits the url column by default', function () {
    Storage::disk('local')->buildTemporaryUrlsUsing(fn (string $path, $expiration): string => 'DL-LINK');
    Storage::disk('local')->put('backups/laravel-2026-06-01-000000.zip', 'data');

    $this->artisan('backup:list')
        ->doesntExpectOutputToContain('DL-LINK')
        ->assertExitCode(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/BackupListCommandTest.php`
Expected: FAIL — the `backup:list` command is not registered (`Command "backup:list" is not defined`).

- [ ] **Step 3: Create the command**

Create `src/Commands/BackupListCommand.php`:

```php
<?php

namespace Jiannius\Backup\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupListCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'backup:list {--url : Include a temporary download URL column}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List the backup archives on the backup disk.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $backups = backup()->list();

        if ($backups->isEmpty()) {
            $this->info('No backups found.');

            return self::SUCCESS;
        }

        $withUrl = (bool) $this->option('url');
        $headers = $withUrl ? ['Date', 'Filename', 'Size', 'URL'] : ['Date', 'Filename', 'Size'];

        $rows = $backups->map(function (array $backup) use ($withUrl): array {
            $row = [
                $backup['date']->format('Y-m-d H:i:s'),
                $backup['filename'],
                Number::fileSize($backup['size']),
            ];

            if ($withUrl) {
                $row[] = $backup['url'] ?? '—';
            }

            return $row;
        })->all();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the command in `src/BackupServiceProvider.php`**

Add the import alongside the existing `use Jiannius\Backup\Commands\BackupCommand;`:

```php
use Jiannius\Backup\Commands\BackupListCommand;
```

Update the `commands([...])` call in `boot()` to register both:

```php
            $this->commands([
                BackupCommand::class,
                BackupListCommand::class,
            ]);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/BackupListCommandTest.php`
Expected: PASS (table rows, empty-state message, `--url` column present, default omits URL).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add src/Commands/BackupListCommand.php src/BackupServiceProvider.php tests/Feature/BackupListCommandTest.php
git commit -m "feat: add backup:list command with optional --url download links"
```

---

## Task 8: Documentation

**Files:**
- Modify: `CLAUDE.md`
- Modify: `README.md`

- [ ] **Step 1: Update the architecture note in `CLAUDE.md`**

Replace this sentence fragment (in the "Singleton entry-point" section):

```
`run(bool $database = true, bool $files = true): string` performs dump → zip → upload → prune and returns the archive filename; collaborator services live in `src/Services/` (`DatabaseDumper`, `Archiver`, `Pruner`). Add cross-cutting package methods here.
```

with:

```
`run(bool $database = true, bool $files = true): string` performs dump → zip → upload → prune and returns the archive filename; `list(?int $expiry = null): Collection` returns this app's disk archives (newest first) with temporary download URLs. Collaborator actions live in `src/Actions/` (`DumpDatabase`, `CreateArchive`, `PruneBackups`, `ListBackups`), each a single-purpose class with a `handle()` entry method. Add cross-cutting package methods here.
```

- [ ] **Step 2: Add the `BACKUP_DOWNLOAD_EXPIRY` env row in `README.md`**

In the Configuration env table, after the `BACKUP_NOTIFICATION_EMAIL` row, add:

```
| `BACKUP_DOWNLOAD_EXPIRY` | `1440` | Download-link lifetime in minutes (24h) for `backup:list --url` / `backup()->list()` |
```

- [ ] **Step 3: Add a "Listing backups" section in `README.md`**

Immediately before the line `Exit codes: \`0\` success ...`, insert:

```markdown
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

```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md README.md
git commit -m "docs: document backup:list, backup()->list(), and the Actions layout"
```

---

## Task 9: Final verification

- [ ] **Step 1: Run the full suite**

Run: `composer test`
Expected: PASS — all migrated tests plus the new `ListBackupsTest`, `BackupListTest`, `BackupListCommandTest`.

- [ ] **Step 2: Lint the whole package**

Run: `composer lint`
Expected: no style diffs left to apply.

- [ ] **Step 3: Smoke-test the command in the Testbench app**

Run: `vendor/bin/testbench backup:list`
Expected: `No backups found.` (the throwaway app's local disk has no archives) — confirms the command is wired and runs.

---

## Post-merge note (not a repo commit)

After this branch is squash-merged, update the roadmap memory at
`~/.claude/projects/-Users-tj-Projects-jiannius-backup/memory/backup-package-roadmap.md`:
remove item 1 ("Backup listing + download URLs") since it's now shipped, leaving
`backup:restore` and zip encryption as the remaining deferred features.

---

## Self-review notes

- **Spec coverage:** config `download.expiry` (Task 4) · `ListBackups` action with disk glob, `temporaryUrl()`, null fallback, newest-first (Task 5) · `Backup::list()` default-expiry resolution (Task 6) · `backup:list {--url}` table + empty state (Task 7) · Services→Actions migration incl. test renames and `dumper()` retention (Tasks 1–3) · README/CLAUDE.md (Task 8). All spec sections map to a task.
- **Type consistency:** `handle()` is the entry method on every action; `ListBackups::handle(string, string, int): Collection` matches `Backup::list()`'s call; the metadata array shape `{filename, path, size, date, url}` is identical across the action, the `Backup::list()` PHPDoc, the command, and every test.
- **No placeholders:** every code step shows complete content or an exact old→new replacement.
