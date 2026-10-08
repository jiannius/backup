<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-01 12:00:00'));

    $this->actingAs(new GenericUser(['id' => 1]));
    backup()->auth(fn (): bool => true);

    $this->disk = Storage::fake('local');
    $this->disk->put('backups/laravel-2026-02-28-120000.zip', 'archive-bytes');
    withoutTemporaryUrls();
    $this->disk->put('backups/other-app-2026-02-28-120000.zip', 'foreign');
    $this->disk->put('backups/laravel-staging-2026-02-28-120000.zip', 'sibling app');
});

/**
 * Make the fake disk unable to produce temporary URLs (like a plain local disk).
 */
function withoutTemporaryUrls(): void
{
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn () => throw new RuntimeException('This driver does not support creating temporary URLs.')
    );
}

/**
 * Make the fake disk produce temporary URLs.
 */
function withTemporaryUrls(): void
{
    Storage::disk('local')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiration): string => 'https://files.example.com/'.$path.'?expires='.$expiration->getTimestamp()
    );
}

it('redirects to a temporary url that expires after the ui download expiry', function () {
    withTemporaryUrls();
    config()->set('backup.ui.download_expiry', 3);

    $expires = now()->addMinutes(3)->getTimestamp();

    $this->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertRedirect("https://files.example.com/backups/laravel-2026-02-28-120000.zip?expires={$expires}");
});

it('defaults the ui download link to five minutes, independent of download.expiry', function () {
    withTemporaryUrls();
    config()->set('backup.download.expiry', 1440);

    expect(config('backup.ui.download_expiry'))->toBe(5);

    $expires = now()->addMinutes(5)->getTimestamp();

    $this->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertRedirect("https://files.example.com/backups/laravel-2026-02-28-120000.zip?expires={$expires}");
});

it('streams the file when the disk cannot create temporary urls', function () {
    $this->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertDownload('laravel-2026-02-28-120000.zip');
});

it('returns 404 for an unknown archive without calling the download hooks', function (string $filename) {
    $calls = 0;
    backup()->beforeDownloading(function () use (&$calls) {
        $calls++;
    });

    $this->post("/backups/download/{$filename}")->assertNotFound();

    expect($calls)->toBe(0);
})->with([
    'unknown file' => 'laravel-2000-01-01-000000.zip',
    'another app archive' => 'other-app-2026-02-28-120000.zip',
    'archive of an app whose slug extends this one' => 'laravel-staging-2026-02-28-120000.zip',
    'path traversal' => '..%2F..%2Fetc%2Fpasswd.zip',
    'dotted traversal' => '..zip',
]);

it('rejects filenames that are not zip archives at the route level', function () {
    $this->post('/backups/download/laravel-2026-02-28-120000.txt')->assertNotFound();
});

it('does not allow GET on the download route', function () {
    $this->get('/backups/download/laravel-2026-02-28-120000.zip')->assertStatus(405);
});

it('calls the beforeDownloading hooks with the filename', function () {
    $seen = [];
    backup()->beforeDownloading(function (string $filename) use (&$seen) {
        $seen[] = $filename;
    });

    $this->post('/backups/download/laravel-2026-02-28-120000.zip')->assertOk();

    expect($seen)->toBe(['laravel-2026-02-28-120000.zip']);
});

it('serves nothing when a beforeDownloading hook aborts', function () {
    withTemporaryUrls();

    backup()->beforeDownloading(fn () => abort(403, 'No download for you.'));

    $this->post('/backups/download/laravel-2026-02-28-120000.zip')
        ->assertForbidden()
        ->assertHeaderMissing('Location');
});

it('runs several beforeDownloading hooks in order', function () {
    $order = [];

    backup()
        ->beforeDownloading(function () use (&$order) {
            $order[] = 'first';
        })
        ->beforeDownloading(function () use (&$order) {
            $order[] = 'second';
        });

    $this->post('/backups/download/laravel-2026-02-28-120000.zip')->assertOk();

    expect($order)->toBe(['first', 'second']);
});

it('signs only the requested archive, however many archives exist', function () {
    $this->disk->put('backups/laravel-2026-02-27-120000.zip', 'older');
    $this->disk->put('backups/laravel-2026-02-26-120000.zip', 'oldest');

    $signed = [];
    Storage::disk('local')->buildTemporaryUrlsUsing(function (string $path) use (&$signed): string {
        $signed[] = $path;

        return 'https://files.example.com/'.$path;
    });

    $this->post('/backups/download/laravel-2026-02-27-120000.zip')
        ->assertRedirect('https://files.example.com/backups/laravel-2026-02-27-120000.zip');

    expect($signed)->toBe(['backups/laravel-2026-02-27-120000.zip']);
});
