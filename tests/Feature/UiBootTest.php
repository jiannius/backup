<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Livewire;

it('boots livewire and renders atom markup in the testbench app', function () {
    Livewire::component('boot-fixture', new class extends Component
    {
        /**
         * Render an Atom component.
         */
        public function render(): string
        {
            return '<div><atom:button>Boot</atom:button></div>';
        }
    });

    Livewire::test('boot-fixture')->assertSee('Boot')->assertDontSee('<atom:', false);
});

it('compiles atom tags through the blade precompiler', function () {
    expect(Blade::render('<atom:button>Hello</atom:button>'))->toContain('Hello')->not->toContain('<atom:');
});

it('provides the fixture layout for full-page components', function () {
    expect(view()->exists('backup-test::layout'))->toBeTrue();
    expect(config('livewire.component_layout'))->toBe('backup-test::layout');
});
