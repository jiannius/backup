<?php

namespace Jiannius\Backup\Tests;

abstract class UiDisabledTestCase extends TestCase
{
    /**
     * The built-in UI stays off, as it does for a fresh install.
     */
    protected bool $uiEnabled = false;
}
