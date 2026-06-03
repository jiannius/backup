<?php

it('runs the example artisan command', function () {
    $this->artisan('backup:example')
        ->assertExitCode(0);
});
