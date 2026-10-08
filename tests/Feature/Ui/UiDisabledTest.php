<?php

namespace Jiannius\Backup\Tests\Feature\Ui;

use Illuminate\Support\Facades\Route;
use Jiannius\Backup\Tests\UiDisabledTestCase;

class UiDisabledTest extends UiDisabledTestCase
{
    /**
     * A fresh install (ui.enabled = false) exposes no UI routes at all.
     */
    public function test_no_ui_routes_are_registered_when_disabled(): void
    {
        $this->assertFalse(config('backup.ui.enabled'));
        $this->assertFalse(Route::has('backup.ui.index'));
        $this->assertFalse(Route::has('backup.ui.download'));

        $this->get('/backups')->assertNotFound();
    }
}
