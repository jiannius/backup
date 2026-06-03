<?php

use Illuminate\Support\Facades\Blade;

it('ships a renderable consumer-facing boost guideline', function () {
    $path = __DIR__.'/../../resources/boost/guidelines/core.blade.php';

    expect(file_exists($path))->toBeTrue();

    // Laravel Boost neutralizes code-example tokens (<?php, backticks, <x- tags)
    // before Blade-compiling a guideline file — see
    // Laravel\Boost\Concerns\RendersBladeGuidelines::renderContent(). Mirror that
    // here so this test renders the file the way Boost actually does, rather than
    // letting a naive render choke on the embedded PHP example or resolve the
    // <x-backup::example> tag as a live component.
    $prepared = str_replace(
        ['`', '<?php', '</x-', '<x-'],
        ['__BT__', '__PHP__', '__XC__', '__XO__'],
        (string) file_get_contents($path),
    );

    $rendered = Blade::render($prepared);

    expect($rendered)->toContain('Backup')->toContain('backup()');
});
