## Backup

`jiannius/backup` is a Laravel package. Its service provider auto-registers and loads routes, migrations, views (`backup::*`), and anonymous Blade components (`<x-backup::*>`) into this app.

### Public API — the `backup()` helper

The package exposes a single entry-point resolvable via the `backup()` helper or `app('backup')`:

@verbatim
<code-snippet name="Using the backup singleton" lang="php">
backup()->version();              // package version
backup()->config('name');         // read config('backup.name')
</code-snippet>
@endverbatim

### Config

Publish and override the package config:

@verbatim
<code-snippet name="Publish config" lang="bash">
php artisan vendor:publish --tag=backup-config
</code-snippet>
@endverbatim

Values live under `config('backup.*')`.

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

### Components

Use `<x-backup::example title="..." />` for the package's anonymous Blade components. Check `vendor/jiannius/backup/components/` for the full set before writing custom markup.
