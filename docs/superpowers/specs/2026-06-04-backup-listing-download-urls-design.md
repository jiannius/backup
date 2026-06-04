# Design: backup listing + download URLs (and Services → Actions migration)

**Date:** 2026-06-04
**Status:** Draft — awaiting review

## Purpose

Give host apps a way to enumerate the backup archives already on the backup
disk — with a time-limited, signed download URL for each — without adding a
database table, routes, or a controller. The disk listing *is* the backup
history (same principle as `Pruner` in v1).

This pass also migrates the existing collaborator classes from a `Services/`
folder to an `Actions/` pattern, so the package ships one consistent
convention rather than two.

## Decisions

| Topic | Decision |
|---|---|
| Surface | `backup()->list()` method **+** `backup:list` console command |
| Source of truth | Disk listing — glob `{app-slug}-*.zip` in `config('backup.path')`; **no DB table** |
| Download URLs | Disk-native `temporaryUrl()` only; signed + time-limited; **no package route** |
| Unsupported disks | Disks whose driver can't make temp URLs (e.g. plain `local`) → `url = null` |
| Link expiry | Config `backup.download.expiry` minutes, default **1440 (24h)**; overridable per call |
| Sort order | Newest first (by `lastModified`) |
| Code pattern | Migrate `Services/` → `Actions/` with a single `handle()` entry method |
| Out of scope | No routes/controller, no DB tracking, no `backup:restore`, no encryption |

## Public surface

- **Method:** `backup()->list(?int $expiry = null): Illuminate\Support\Collection`
  - `$expiry` in **minutes**; `null` resolves to `config('backup.download.expiry')`.
  - Returns a collection (newest first) of:
    ```
    array{filename: string, path: string, size: int, date: Carbon\CarbonInterface, url: ?string}
    ```
- **Command:** `backup:list {--url}`
  - Prints a table — **Date / Filename / Size** (size via `Illuminate\Support\Number::fileSize()`), newest first.
  - `--url` appends a **URL** column (links are long, so off by default).
  - Empty disk → `No backups found.`

## Config (`config/backup.php`) — new section

```php
'download' => [
    'expiry' => (int) env('BACKUP_DOWNLOAD_EXPIRY', 1440), // download-link lifetime, minutes
],
```

## Services → Actions migration (in scope)

Move `src/Services/` → `src/Actions/`, namespace `Jiannius\Backup\Actions`,
entry method renamed to `handle()`. Behavior unchanged.

| Was (`Services/`) | Becomes (`Actions/`) | Method change |
|---|---|---|
| `DatabaseDumper::dump()` | `DumpDatabase::handle()` | `dump()` → `handle()`; **`dumper()` builder kept as-is (public, still tested)** |
| `Archiver::create()` | `CreateArchive::handle()` | `create()` → `handle()` |
| `Pruner::prune()` | `PruneBackups::handle()` | `prune()` → `handle()` |
| — | `ListBackups::handle()` | new |

- `src/Backup.php` — update imports + call sites (`app(DumpDatabase::class)->handle(...)`, etc.); add `list()`.
- These classes are **internal** — public API is `backup()->run()` / `backup()->list()` — so no consumer breaks.
- `CLAUDE.md` architecture note updated: "collaborator services live in `src/Services/`" → Actions, with new class names.

## Components & data flow

```
backup:list ──► Backup::list(expiry): Collection
                  │
                  └─ ListBackups::handle(disk, path, expiryMinutes): Collection
                       ├─ Storage::disk(disk)->files(path)
                       ├─ keep basenames matching {app-slug}-*.zip   (same filter as PruneBackups)
                       ├─ per file: size, lastModified → date
                       ├─ url = disk->temporaryUrl(path, now()->addMinutes(expiry))
                       │         RuntimeException (driver unsupported) → null
                       └─ sortByDesc(date)->values()
```

- `src/Actions/ListBackups.php` — disk enumeration + URL generation.
- `src/Backup.php` — `list()` resolves expiry default, delegates to `ListBackups`.
- `src/Commands/BackupListCommand.php` — thin CLI wrapper: calls `backup()->list()`,
  renders the table, handles `--url` and the empty state.
- `BackupListCommand` registered in `BackupServiceProvider::boot()`'s `commands([...])`.

## Error handling

- `list()` is read-only: it never throws on an empty/missing path — an empty
  collection is the valid "no backups yet" result.
- A disk driver that can't produce temporary URLs is **not** an error — the
  archive is still listed with `url = null`. (Caught `RuntimeException` from
  `temporaryUrl()`.)
- A genuinely misconfigured disk name surfaces as the underlying Storage
  exception — fail loudly, don't swallow.

## Testing (Pest 4 + Testbench)

**New tests**

- `ListBackupsTest` (`tests/Unit`) — `Storage::fake()`:
  - seed matching `{app-slug}-*.zip` + non-matching files → only matching returned, newest first, correct shape.
  - **url null path:** fake disk with no temp-URL support → `url === null`.
  - **url present path:** `Storage::disk(...)->buildTemporaryUrlsUsing(fn ($path, $exp) => "...{$path}...")` → `url` matches the known string (no mocking needed).
- `BackupListCommandTest` (`tests/Feature`) — `Storage::fake()`:
  - table rows for seeded archives; `--url` adds the URL column; empty disk → `No backups found.`

**Migrated tests** (renamed to track the class under test; same assertions)

- `DatabaseDumperTest` → `DumpDatabaseTest` (uses `DumpDatabase`; `dumper()` unchanged, `dump()` → `handle()`)
- `ArchiverTest` → `CreateArchiveTest` (`create()` → `handle()`)
- `PrunerTest` → `PruneBackupsTest` (`prune()` → `handle()`)

No tests deleted — existing v1 tests are adapted in place and are the safety
net proving the migration changed nothing behavioral.

## Documentation

- `README.md` — add a short "Listing backups" section (`backup()->list()` shape + `backup:list`/`--url`).
- Update `BACKUP_DOWNLOAD_EXPIRY` in the env/config reference.
- Remove the listing item from the v2 roadmap memory once shipped.
