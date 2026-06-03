<?php

namespace Jiannius\Backup\Commands;

use Illuminate\Console\Command;

class BackupCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'backup:example {--force : Run without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'An example package command — replace with your own.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Backup command ran. Edit '.static::class.' to implement it.');

        return self::SUCCESS;
    }
}
