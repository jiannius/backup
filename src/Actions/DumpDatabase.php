<?php

namespace Jiannius\Backup\Actions;

use RuntimeException;
use Spatie\DbDumper\Databases\MariaDb;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\PostgreSql;
use Spatie\DbDumper\Databases\Sqlite;
use Spatie\DbDumper\DbDumper;

class DumpDatabase
{
    /**
     * Dump the given Laravel connection to a file.
     */
    public function handle(?string $connection, string $path): void
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

        if (is_null($config)) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
        }

        if (in_array($config['driver'], ['mysql', 'mariadb', 'pgsql']) && empty($config['username'])) {
            throw new RuntimeException("Database connection [{$name}] requires a username for backup.");
        }

        $dumper = match ($config['driver']) {
            'sqlite' => Sqlite::create()
                ->setDbName($config['database']),
            'mysql' => MySql::create()
                ->setHost($config['host'] ?? '127.0.0.1')
                ->setPort((int) ($config['port'] ?? 3306))
                ->setDbName($config['database'])
                ->setUserName($config['username'])
                ->setPassword($config['password'] ?? ''),
            'mariadb' => MariaDb::create()
                ->setHost($config['host'] ?? '127.0.0.1')
                ->setPort((int) ($config['port'] ?? 3306))
                ->setDbName($config['database'])
                ->setUserName($config['username'])
                ->setPassword($config['password'] ?? ''),
            'pgsql' => PostgreSql::create()
                ->setHost($config['host'] ?? '127.0.0.1')
                ->setPort((int) ($config['port'] ?? 5432))
                ->setDbName($config['database'])
                ->setUserName($config['username'])
                ->setPassword($config['password'] ?? ''),
            default => throw new RuntimeException("Unsupported database driver [{$config['driver']}] for backup."),
        };

        if ($binaryPath = config('backup.database.binary_path')) {
            $dumper->setDumpBinaryPath($binaryPath);
        }

        return $dumper;
    }
}
