<?php

use Jiannius\Backup\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Whether the sqlite3 CLI binary is available for real dump tests.
 */
function sqlite3Available(): bool
{
    return trim((string) shell_exec('command -v sqlite3')) !== '';
}
