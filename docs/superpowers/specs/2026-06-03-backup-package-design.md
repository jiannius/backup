# jiannius/backup — Design (handoff draft)

**Date:** 2026-06-03
**Status:** Draft — requirements decided in a brainstorming session inside skeleton-project; detailed design not yet presented/approved. Resume brainstorming from "Proposed shape" below.

## Goal

A small dedicated backup package for the jiannius project family — the `jiannius/filesystem` model, not a fork of spatie/laravel-backup. Most projects need DB backup; some also need files backup.

## Consumers

| Project | Stack | Note |
| --- | --- | --- |
| skeleton-project | Laravel 13 + atom 3 | add to composer.json + schedule entry once this package ships |
| firmhive | Laravel 13 + atom 3 | |
| humblebear | **Laravel 12** + atom 1 | drives the `illuminate/support ^12 || ^13` constraint |

## Decisions made (with TJ, 2026-06-03)

1. **Scope:** dump → upload → prune + **failure email**. Explicitly rejected the full spatie/laravel-backup feature set (multi-tier retention, health monitoring, Slack/Discord notifications) — too heavy for the family's needs.
2. **Engines:** SQLite + MySQL + Postgres.
   - SQLite via `VACUUM INTO` through PDO — safe live copy, no `sqlite3` binary needed.
   - MySQL/Postgres via **spatie/db-dumper** (the small framework-agnostic lib spatie/laravel-backup uses internally) — handles `mysqldump`/`pg_dump` with temp credential files so passwords don't leak into the process list.
3. **Files backup:** config-driven paths array, **empty by default**. Projects that need it add paths in config — no code changes, no flags.
4. **Placement:** this separate package (chosen over shipping inside atom 3, which would exclude humblebear until its Laravel 13 upgrade, and over copy-paste app code in the skeleton).
5. **Failure notification:** plain `Mail::raw()` to a configured address — cannot assume atom's mail helper on humblebear's stack.

## Proposed shape (not yet approved — review/refine in brainstorming)

- `backup:run` command: dump DB → zip together with configured file paths → upload to configured disk → prune archives older than N days. (Skeleton generated `src/Commands/BackupCommand.php` with signature `backup:example` — replace it.)
- `config/backup.php`: disk, path prefix, retention days, files paths, notification email.
- Scheduling stays in each host app (`routes/console.php` / console kernel) — the package does not self-schedule.
- Pest tests via testbench; the SQLite path is fully testable without external binaries.

## Open points for next session

- **Laravel 12 support:** widen composer constraints to `illuminate/support ^12 || ^13`; dev harness is testbench 11 (Laravel 13) — decide how Laravel 12 gets verified (CI matrix with testbench 10?).
- Command name: `backup:run` vs a bare `backup` command.
- Archive naming convention (timestamped zip? per-app prefix?) and zip vs gzip for DB-only backups.
- Restore story: out of scope for v1, or a minimal `backup:restore`?
- Success notification (probably no — failure-only was the decided scope).
- Skeleton-project integration is a follow-up task in the skeleton-project repo, not here.
