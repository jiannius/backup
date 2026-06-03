<?php

use Illuminate\Support\Facades\Blade;

it('renders the anonymous blade component under the backup namespace', function () {
    $html = Blade::render('<x-backup::example title="Hello" >body</x-backup::example>');

    expect($html)->toContain('Hello')->toContain('body');
});
