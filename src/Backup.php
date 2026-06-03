<?php

namespace Jiannius\Backup;

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
}
