# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [0.3.0] - 2026-10-08

### Added

- Optional built-in UI (jiannius/atom + Livewire): start a queued database backup, see the archives and their status, download an archive. Off by default (`BACKUP_UI_ENABLED`); configured by the new `ui` block in `config/backup.php`.
- Consumer hooks on the `backup()` singleton: `auth()` (who can reach the UI; default allows the `local` environment only), `beforeRunning()` and `beforeDownloading()` (for audit logging or extra checks; throw or abort to block).
- `backup()->run()` takes an optional trailing `bool $runHooks = true`.
- `RunBackup` queued job and a cache-backed run status (no migrations). Each run has an id; only the run that owns the lock can release it or write its status.
- `ui.download_expiry` / `BACKUP_UI_DOWNLOAD_EXPIRY` (default 5 minutes): lifetime of the link a UI download redirects to, separate from `download.expiry`.
- `backup()->list()` and `ListBackups` take an optional `$withUrls` argument (default `true`; `false` skips building the temporary URLs).
- README: "Built-in UI" and "Restoring a copy locally" sections.

### Changed

- `jiannius/atom` ^3 (which brings Livewire 4) is now a required dependency, even if you only use the CLI.
- `beforeRunning` hooks also fire for `backup:run`, the scheduler and programmatic runs. Nothing happens until a hook is registered. A hook that throws or aborts blocks the run and is logged and emailed like any other failure.
- Archives are now matched exactly as `{app-slug}-YYYY-MM-DD-HHMMSS.zip`. Before, `{app-slug}-*.zip` also matched another app's archives (`acme` vs `acme-staging`), which `backup:list`, UI downloads and pruning could then touch.

### Fixed

- The UI is disabled for real: with `ui.enabled` off the routes (and Livewire update requests) answer 404. An empty `ui.path` falls back to `backups` instead of serving the page at `/`.
- The status of a finished run fades (completed after 1 hour, failed after 24 hours) and a failed run shows only a short, single-line error on the page.

### Upgrade notes

- `jiannius/atom` is now required even if you only use the CLI, and its service provider boots in every app that installs this package. Atom brings an app-wide immutable `now()` (through its Carbon setup), `Builder`/`Request`/`Str`/`Arr` macros, a global `POST /atom/action/{name}` route and a Blade precompiler. Check these against your app before upgrading.
- Without the UI there is nothing to configure. To use it, see "Built-in UI" in the README (shared cache, a real queue worker, Tailwind scanning, a named `login` route).

### Upgrading

Require `^0.3`. No action is needed unless you want the UI.

## [0.2.0]

- Backup listing + temporary download URLs (`backup:list`, `backup()->list()`).

## [0.1.0]

- Initial package backup: `backup:run` dumps the database (SQLite, MySQL, MariaDB, PostgreSQL), zips it with the configured folders, uploads the archive to a filesystem disk, prunes old archives and emails on failure.
