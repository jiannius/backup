<?php

it('boots the testbench application in the testing environment', function () {
    expect(app())->not->toBeNull();
    expect(app()->environment())->toBe('testing');
});
