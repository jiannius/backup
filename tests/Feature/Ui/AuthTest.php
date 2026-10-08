<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Http\Middleware\Authorize;
use Livewire\Livewire;

use function Livewire\trigger;

beforeEach(function () {
    $disk = Storage::fake('local');
    $disk->put('backups/laravel-2026-02-28-120000.zip', 'archive-bytes');

    // No temporary URLs: the controller streams the file, so an allowed request is a 200.
    $disk->buildTemporaryUrlsUsing(fn () => throw new RuntimeException('This driver does not support creating temporary URLs.'));

    // A login page for the "auth" middleware to redirect guests to.
    Route::get('/login', fn () => 'login')->name('login');
});

it('registers the ui routes when enabled', function () {
    expect(Route::has('backup.ui.index'))->toBeTrue();
    expect(Route::has('backup.ui.download'))->toBeTrue();
    expect(route('backup.ui.index', absolute: false))->toBe('/backups');
});

it('redirects a guest through the auth middleware', function () {
    backup()->auth(fn (): bool => true);

    $this->post('/backups/download/laravel-2026-02-28-120000.zip')->assertRedirect('/login');
});

it('allows the local environment through the default hook', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->detectEnvironment(fn () => 'local');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertOk();
});

it('returns 403 in production through the default hook', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs(new GenericUser(['id' => 1]))
        ->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertForbidden();
});

it('returns 403 on the download when a custom auth hook says no', function () {
    backup()->auth(fn (Request $request): bool => false);

    $this->actingAs(new GenericUser(['id' => 1]))
        ->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertForbidden();
});

it('gives the auth hook the request and lets an allowed user through', function () {
    backup()->auth(fn (Request $request): bool => $request->user()?->getAuthIdentifier() === 42);

    $this->actingAs(new GenericUser(['id' => 1]))
        ->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertForbidden();

    $this->actingAs(new GenericUser(['id' => 42]))
        ->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertOk();
});

it('keeps the Authorize middleware even when the middleware config is replaced', function () {
    config()->set('backup.ui.middleware', ['web']);

    // Routes are registered at boot, so reload the package routes with the new config.
    app('router')->setRoutes(new RouteCollection);
    require __DIR__.'/../../../routes/web.php';
    app('router')->getRoutes()->refreshNameLookups();

    $middleware = Route::getRoutes()->getByName('backup.ui.download')->gatherMiddleware();

    expect($middleware)->toContain(Authorize::class);
});

it('returns 404 on the page and the download when the ui is disabled at runtime', function () {
    backup()->auth(fn (): bool => true);
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->actingAs(new GenericUser(['id' => 1]));

    $this->get('/backups')->assertOk();

    // Routes stay registered for the process, but the middleware must refuse them.
    config()->set('backup.ui.enabled', false);

    $this->get('/backups')->assertNotFound();
    $this->post('/backups/download/laravel-2026-02-28-120000.zip')->assertNotFound();
});

it('falls back to /backups when the ui path is empty, never serving the page at the root', function () {
    config()->set('backup.ui.path', '');

    app('router')->setRoutes(new RouteCollection);
    require __DIR__.'/../../../routes/web.php';
    app('router')->getRoutes()->refreshNameLookups();

    expect(route('backup.ui.index', absolute: false))->toBe('/backups');
    expect(route('backup.ui.download', 'laravel-2026-02-28-120000.zip', absolute: false))->toBe('/backups/download/laravel-2026-02-28-120000.zip');
});

it('gates Livewire update requests, not just the page route', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->actingAs(new GenericUser(['id' => 1]));

    $allowed = true;
    backup()->auth(function () use (&$allowed): bool {
        return $allowed;
    });

    $html = $this->get('/backups')->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

    // A plain re-render touches no component code that checks access itself,
    // so only the persistent middleware can refuse it.
    $payload = ['components' => [[
        'snapshot' => html_entity_decode($matches[1]),
        'updates' => (object) [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]];

    $update = function () use ($payload) {
        // Livewire flushes its per-request state when a real request ends; requests in one test share the app.
        trigger('flush-state');

        return $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true']);
    };

    $update()->assertOk();

    // Access revoked after the page loaded: the update request itself is refused.
    $allowed = false;
    $update()->assertForbidden();

    // And so is it once the UI is switched off.
    $allowed = true;
    config()->set('backup.ui.enabled', false);
    $update()->assertNotFound();
});

it('gates Livewire update requests that target only the archive table', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->actingAs(new GenericUser(['id' => 1]));

    $allowed = true;
    backup()->auth(function () use (&$allowed): bool {
        return $allowed;
    });

    $html = $this->get('/backups')->assertOk()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded))
        ->first(fn (string $json): bool => json_decode($json, true)['memo']['name'] === 'backup-archives');

    expect($snapshot)->not->toBeNull();

    // Only the child component is sent, so the parent's own checks never run.
    $payload = ['components' => [[
        'snapshot' => $snapshot,
        'updates' => (object) [],
        'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
    ]]];

    $update = function () use ($payload) {
        trigger('flush-state');

        return $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true']);
    };

    $update()->assertOk();

    $allowed = false;
    $update()->assertForbidden();
});

it('registers the Authorize middleware as Livewire persistent middleware', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(Authorize::class);
});
