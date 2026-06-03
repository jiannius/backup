# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`jiannius/backup` is a **Laravel backup package** — database (and optional files) backup for jiannius Laravel projects: dump → upload to a filesystem disk → prune, with a failure email (composer `type: library`, PSR-4 `Jiannius\Backup\`). It is consumed by host Laravel apps via `composer require`; this repo is the library itself, not a host app.

Because a package has no `artisan` binary, all dev/test/AI tooling runs through **Orchestra Testbench**: `vendor/bin/testbench` is the artisan equivalent, booting a throwaway Laravel 13 app (configured by `testbench.yaml`) with `BackupServiceProvider` registered.

Main stack — abide by these versions: php 8.4 (constraint `^8.3`) · laravel/framework v13 (via Testbench) · orchestra/testbench v11 · pestphp/pest v4 · phpunit v12 · laravel/pint v1 · laravel/boost v2.

## Common commands

```bash
composer install                                   # install dependencies
composer test                                      # Pest 4 + Testbench suite
vendor/bin/pest tests/Feature/RouteTest.php        # single test file
vendor/bin/pest --filter='binds the backup singleton'  # single test
composer lint                                      # vendor/bin/pint
vendor/bin/testbench boost:mcp                     # start the Boost MCP server (used by editors)
vendor/bin/testbench boost:install                 # merge Boost core + .ai guidelines (review output)
vendor/bin/testbench serve                         # boot the throwaway Testbench app for manual checks
```

## Laravel Boost

Laravel Boost is installed (dev) and runs through Testbench. Editors connect via `.mcp.json` / `.cursor/mcp.json`, which invoke `vendor/bin/testbench boost:mcp`.

- Use Boost's `search-docs` tool before changing code that touches Laravel APIs — it returns version-specific docs for the installed packages.
- Searching: use multiple broad, topic-based queries (`['validation rules', 'custom validation']`); use `"quoted phrases"` for exact position matching; don't put package names in queries (package info is already shared).
- Custom guidelines for this repo live in `.ai/guidelines/*.blade.php`. `vendor/bin/testbench boost:install` can merge them with Boost's core guidelines — but Boost's core rules assume a host app with `artisan`, so this CLAUDE.md carries the package-adapted versions (see Development guidelines below); review any regenerated block before committing it.

## Testbench is the artisan

- Run artisan commands as `vendor/bin/testbench <command>` (e.g. `vendor/bin/testbench route:list`, `vendor/bin/testbench tinker --execute='...'`). There is no `php artisan` here.
- Do NOT use `make:` generators — they scaffold into the throwaway Testbench app, not this package. Create package files manually under `src/`, following the existing structure and namespaces (check sibling files first).

## Two guideline surfaces

1. **This `CLAUDE.md`** guides work *on the package itself*.
2. **`resources/boost/guidelines/core.blade.php`** ships *to consuming apps*: when a host app installs this package and lists it in its own `boost.json` `packages`, Boost merges that file into the host's `CLAUDE.md`. Keep it as usage guidance for someone building *with* the package.

## Architecture

### Service-provider wiring (`src/BackupServiceProvider.php`)

`register()` merges `config/backup.php` and binds the `Backup` singleton (aliased `app('backup')`). `boot()` loads `routes/web.php`, `database/migrations/`, `resources/views/` (view namespace `backup`), and the anonymous Blade components in `components/` (`<x-backup::name>`). Console-only: publishes the config (tag `backup-config`) and registers `backup:example`. Read this file first when something seems to come from nowhere.

### Singleton entry-point (`src/Backup.php` → `app('backup')` / `backup()`)

The package's public API object, resolvable via the container alias `backup` or the autoloaded `backup()` helper (`src/Helpers.php`). Add cross-cutting package methods here.

## Development guidelines

Curated from the jiannius app skeleton (`skeleton-project`) — the subset that applies to package development.

### Conventions

- Follow the existing code conventions; when creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods (`isRegisteredForDiscounts`, not `discount()`).
- Stick to the existing directory structure — don't create new base folders without approval.
- Don't change the package's dependencies without approval.
- Only create documentation files if explicitly requested.
- Be concise in explanations — focus on what's important rather than obvious details.

### PHP style

- Always use curly braces for control structures, even single-line bodies.
- Use PHP 8 constructor property promotion (`public function __construct(public GitHub $github) {}`); no empty zero-parameter constructors unless private.
- Explicit return types and type hints on all parameters: `function isAccessible(User $user, ?string $path = null): bool`.
- Prefer PHPDoc over inline comments; every public/private method gets a one-line PHPDoc. Use array-shape definitions in PHPDoc where useful.
- Backed enums mix in `Jiannius\Backup\Traits\Enum`; cases are `FULL_UPPERCASE`. The trait provides `all()`, `option()`, `label()`, `get()`, `is()`/`isNot()`.

### Models & data

- Main tables use ULID primary keys (`HasUlids` + `$table->ulid('id')->primary()`) plus a nullable `data` json column for metadata. Plain auto-increment ids are fine for pivot tables.
- When adding a model, add its factory (and a seeder if useful) and wire `newFactory()` — package factory namespaces aren't auto-discovered by Laravel's convention.
- Use named routes and `route()` when generating links.

### Testing (Pest 4 + Testbench)

- Every change must be programmatically tested: write or update a Pest test in `tests/Feature` or `tests/Unit` (both extend `Tests\TestCase` — Orchestra Testbench, in-memory sqlite), then run the affected tests.
- Don't write one-off verification scripts or tinker probes when a test can prove the behavior — tests are the source of truth.
- Run the minimum tests needed: `vendor/bin/pest --filter='name'` or a single file; `composer test` for the full suite.
- In tests, build models with factories; check for custom factory states before configuring manually. Use `fake()->word()`-style faker calls.
- There is no `make:test` — create test files manually under `tests/Feature` or `tests/Unit` (Pest auto-binds `Tests\TestCase` via `tests/Pest.php`).
- Do NOT delete tests without approval.

### Code style (Pint)

- After modifying PHP files, run `vendor/bin/pint --dirty` (or `composer lint`) before finalizing changes — run it to fix, not just `--test` to check.

### Workflow

- Always squash-merge when exiting a worktree, then remove the worktree.
- Plan mode: no need to use the superpowers skills.

## Working Guidelines

Behavioral guidelines to reduce common LLM coding mistakes. For trivial tasks, use judgment.

### 1. Think Before Coding

Don't assume. Don't hide confusion. Surface tradeoffs. State assumptions explicitly; if multiple interpretations exist, present them rather than picking silently. If something is unclear, stop, name what's confusing, and ask.

### 2. Simplicity First

Minimum code that solves the problem. No features beyond what was asked, no abstractions for single-use code, no "configurability" that wasn't requested, no error handling for impossible scenarios.

### 3. Surgical Changes

Touch only what you must. Don't "improve" adjacent code or refactor things that aren't broken. Match existing style. Remove imports/variables your change orphaned; leave pre-existing dead code unless asked.

### 4. Goal-Driven Execution

Transform the task into a verifiable goal ("write a test that reproduces the bug, then make it pass") and loop until verified.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

</laravel-boost-guidelines>
