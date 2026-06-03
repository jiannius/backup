<?php

namespace Jiannius\Backup\Commands;

use Illuminate\Console\Command;
use Throwable;

class BackupCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'backup:run
        {--only-db : Back up the database only}
        {--only-files : Back up the configured folders only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Back up the database and configured folders to the backup disk.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('only-db') && $this->option('only-files')) {
            $this->error('The --only-db and --only-files options are mutually exclusive.');

            return self::INVALID;
        }

        try {
            $filename = backup()->run(
                database: ! $this->option('only-files'),
                files: ! $this->option('only-db'),
            );
        } catch (Throwable $e) {
            $this->error("Backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Backup uploaded: {$filename}");

        return self::SUCCESS;
    }
}
