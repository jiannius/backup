# Jiannius Backup

Database (and optional files) backup for jiannius Laravel projects: dump → upload to a filesystem disk → prune old archives, with a failure email. Supports SQLite, MySQL, and Postgres.

> **Status:** package scaffolded from `jiannius/skeleton-package`; implementation pending. See `docs/superpowers/specs/2026-06-03-backup-package-design.md` for the design handoff.

## Requirements

- PHP 8.3+ and Composer
- Everything else (Laravel 13, Testbench 11, Pest 4, Pint, Boost 2) installs as dependencies

## Development

### There is no `artisan` — Testbench is the artisan

A package is not an app, so it has no `artisan` binary. Orchestra Testbench's `vendor/bin/testbench` boots a throwaway Laravel app (configured by `testbench.yaml`, which registers this package's service provider) — so artisan commands, tests, and Laravel Boost all run inside a real app context.

```bash
composer test                                   # full Pest suite
vendor/bin/pest tests/Feature/RouteTest.php     # one file
vendor/bin/pest --filter='binds the backup'   # one test
composer lint                                   # Pint (vendor/bin/pint)
vendor/bin/testbench route:list                 # any artisan command
vendor/bin/testbench tinker --execute='backup()->version();'
vendor/bin/testbench serve                      # boot the throwaway app in a browser
```

Don't use `make:` generators — they scaffold into the throwaway Testbench app, not your package. Create files by copying the example files (see the how-to recipes below); they are the templates.

### Tests

Tests live in `tests/Feature` and `tests/Unit`, run on Pest 4, and extend `Tests\TestCase` (Orchestra Testbench + in-memory sqlite — wired automatically via `tests/Pest.php`). `RefreshDatabase` runs the package's own migrations, so model tests work with zero setup.

### Code style

Pint with Laravel defaults. Run `composer lint` (or `vendor/bin/pint --dirty`) after changing PHP files. CI runs both the suite (`.github/workflows/tests.yml`) and style check (`.github/workflows/lint.yml`) on pushes/PRs to `main`.

## Laravel Boost

Boost is installed as a dev dependency and runs through Testbench:

- **MCP server** — `.mcp.json` (Claude Code) and `.cursor/mcp.json` (Cursor) launch `vendor/bin/testbench boost:mcp`. Your editor will ask once to approve the `laravel-boost` MCP server; after that, tools like `search-docs` (version-specific Laravel docs), `database-schema`, and `tinker` are available while working on the package.
- **`vendor/bin/testbench boost:install`** — merges Boost's core guidelines with this repo's `.ai/guidelines/`. Note that Boost's core rules assume a host *app* with `artisan`, so `CLAUDE.md` carries package-adapted versions; review any regenerated block before committing it.

## AI guidelines — two surfaces

1. **`CLAUDE.md`** — guidance for agents working **on this package**: Testbench-as-artisan rules, PHP/test/style conventions (curated from `skeleton-project`), and the architecture map. The same content lives in `.ai/guidelines/backup.blade.php` so `boost:install` can merge it.
2. **`resources/boost/guidelines/core.blade.php`** — guidance shipped **to consuming apps**. When a host app installs your package and lists it in its own `boost.json` `"packages"` array, Boost merges this file into the host app's `CLAUDE.md`. Keep it as copy-paste-ready usage guidance for someone building *with* your package (the included file shows the format — `@verbatim` + `<code-snippet>` blocks).

## How-to recipes

The example files are deliberately minimal and conventional — copy them as templates.

### Add a config value

Add the key to `config/backup.php`. It's merged in `register()` so `config('backup.*')` always works; host apps can override after `php artisan vendor:publish --tag=backup-config`. Read it anywhere via `backup()->config('key')`.

### Add a model (+ migration + factory)

Copy the trio: `src/Models/Backup.php`, `database/migrations/0001_01_01_000000_create_backups_table.php`, `database/factories/BackupFactory.php`. Conventions: ULID primary key (`HasUlids` + `$table->ulid('id')->primary()`), a nullable `data` json column for metadata, and a `newFactory()` override (package factory namespaces aren't auto-discovered). Migrations are auto-loaded — host apps pick them up with plain `php artisan migrate`.

### Add an artisan command

Copy `src/Commands/BackupCommand.php`, then register the class in the `commands([...])` call in `BackupServiceProvider::boot()`. Prefix signatures with your package slug (`backup:example`).

### Add a route

Declare it in `routes/web.php` with a named route (`->name('backup.…')`). Routes load automatically in every host app, so keep them namespaced and prefixed — or delete the file and the `loadRoutesFrom` line if your package has none.

### Add a Blade component

Drop an anonymous component into `components/` — `components/card.blade.php` becomes `<x-backup::card>` in host apps (registered via `Blade::anonymousComponentPath`). `components/example.blade.php` shows the `@props` + `$attributes->merge` pattern.

### Add views or translations

Views go in `resources/views/` and render as `view('backup::name')`. For translations, create `lang/`, uncomment the `loadTranslationsFrom` line in the service provider, and use `__('backup::file.key')`.

### Add to the public API

Add methods to `src/Backup.php` — the singleton behind `app('backup')` and the autoloaded `backup()` helper. This is the package's front door; keep cross-cutting operations here rather than scattering static helpers.

### Add an enum

Mix `Jiannius\Backup\Traits\Enum` into a backed enum with `FULL_UPPERCASE` cases — you get `all()`, `option()`, `label()`, `get()`, `is()`/`isNot()` (see `tests/Unit/EnumTest.php` for the full surface).

### Add a test

Create a file under `tests/Feature` or `tests/Unit` — Pest binds `Tests\TestCase` automatically, no class boilerplate needed:

```php
it('does the thing', function () {
    expect(backup()->config('name'))->toBe('Backup');
});
```

## Using your package in a host app

Until it's on Packagist, require it via a VCS or path repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/jiannius/<your-package>" }
    ],
    "require": {
        "jiannius/<your-package>": "dev-main"
    }
}
```

The service provider auto-registers (`extra.laravel.providers`), migrations run with `php artisan migrate`, config publishes with `php artisan vendor:publish --tag=backup-config`, and components/views/routes are immediately available. To pull the package's AI guidelines into the app's `CLAUDE.md`, add the package name to the app's `boost.json` `"packages"` array and re-run `php artisan boost:install`.

## What's inside

| Path | Purpose |
| --- | --- |
| `src/BackupServiceProvider.php` | Wires routes, migrations, views, components, command, config |
| `src/Backup.php` | Singleton entry-point — `app('backup')` / `backup()` |
| `src/Helpers.php` | Autoloaded `backup()` helper |
| `src/Traits/Enum.php` | `FULL_UPPERCASE` backed-enum trait |
| `src/Models/Backup.php` | ULID example model with a `data` json column |
| `src/Commands/BackupCommand.php` | Example artisan command (`backup:example`) |
| `config/backup.php` | Publishable config (tag `backup-config`) |
| `routes/web.php` | Example route (`GET /backup`) |
| `components/example.blade.php` | Anonymous Blade component (`<x-backup::example>`) |
| `resources/boost/guidelines/core.blade.php` | Consumer-facing Boost guidelines |
| `.ai/guidelines/backup.blade.php` | Package-dev guidelines (Boost-merge source for `CLAUDE.md`) |
| `.mcp.json` / `.cursor/mcp.json` / `boost.json` | Laravel Boost wiring (via Testbench) |
| `testbench.yaml` | Registers the provider into the Testbench app |
| `tests/` | Pest 4 + Testbench suite |
| `configure.php` | One-shot rename script (deletes itself) |

## License

MIT.
