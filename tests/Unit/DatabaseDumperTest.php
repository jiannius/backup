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

it('throws when a credentialed connection has no username', function () {
    config()->set('database.connections.source', [
        'driver' => 'mysql',
        'host' => 'db.example.com',
        'database' => 'app',
    ]);

    expect(fn () => (new DatabaseDumper)->dumper('source'))
        ->toThrow(RuntimeException::class, 'requires a username for backup');
});
