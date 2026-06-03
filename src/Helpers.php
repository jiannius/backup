<?php

use Jiannius\Backup\Backup;

if (! function_exists('backup')) {
    /**
     * Resolve the Backup singleton.
     */
    function backup(): Backup
    {
        return app('backup');
    }
}
