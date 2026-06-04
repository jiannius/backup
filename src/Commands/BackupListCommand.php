<?php

namespace Jiannius\Backup\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Number;

class BackupListCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'backup:list {--url : Include a temporary download URL column}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List the backup archives on the backup disk.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $backups = backup()->list();

        if ($backups->isEmpty()) {
            $this->info('No backups found.');

            return self::SUCCESS;
        }

        $withUrl = (bool) $this->option('url');
        $headers = $withUrl ? ['Date', 'Filename', 'Size', 'URL'] : ['Date', 'Filename', 'Size'];

        $rows = $backups->map(function (array $backup) use ($withUrl): array {
            $row = [
                $backup['date']->format('Y-m-d H:i:s'),
                $backup['filename'],
                Number::fileSize($backup['size']),
            ];

            if ($withUrl) {
                $row[] = $backup['url'] ?? '—';
            }

            return $row;
        })->all();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
