<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Mail\BackupFailed;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('local');

    $this->temp = sys_get_temp_dir().'/backup-hooks-test-'.uniqid();
    File::makeDirectory($this->temp.'/uploads', 0755, true);
    File::put($this->temp.'/uploads/photo.txt', 'photo');

    // Files-only path: no dump binary needed.
    config()->set('backup.files.include', [$this->temp.'/uploads']);
});

afterEach(function () {
    File::deleteDirectory($this->temp);
});

it('passes the options and the signed-in user to beforeRunning hooks', function () {
    $audit = [];

    backup()->beforeRunning(function (array $options) use (&$audit) {
        $audit[] = ['options' => $options, 'user' => auth()->user()?->getAuthIdentifier()];
    });

    $this->actingAs(new GenericUser(['id' => 7]));

    backup()->run(database: false);

    expect($audit)->toBe([['options' => ['database' => false, 'files' => true], 'user' => 7]]);
});

it('fires beforeRunning from the backup:run command, where runningInConsole is true', function () {
    $console = null;

    backup()->beforeRunning(function () use (&$console) {
        $console = app()->runningInConsole();
    });

    $this->artisan('backup:run', ['--only-files' => true])->assertSuccessful();

    expect($console)->toBeTrue();
});

it('skips the beforeRunning hooks when runHooks is false', function () {
    $calls = 0;

    backup()->beforeRunning(function () use (&$calls) {
        $calls++;
    });

    backup()->run(database: false, runHooks: false);

    expect($calls)->toBe(0);
});

it('runs several hooks in registration order', function () {
    $order = [];

    backup()
        ->beforeRunning(function () use (&$order) {
            $order[] = 'first';
        })
        ->beforeRunning(function () use (&$order) {
            $order[] = 'second';
        });

    backup()->run(database: false);

    expect($order)->toBe(['first', 'second']);
});

it('blocks the backup, logs it and emails the failure when a beforeRunning hook aborts', function () {
    Mail::fake();
    Log::spy();
    config()->set('backup.notifications.email', 'ops@example.com');

    backup()->beforeRunning(fn () => abort(403, 'Not today.'));

    expect(fn () => backup()->run(database: false))->toThrow(HttpException::class);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Not today.'))->once();
    Mail::assertSent(BackupFailed::class, fn (BackupFailed $mail): bool => $mail->hasTo('ops@example.com') && $mail->exception->getMessage() === 'Not today.');
});

it('logs and emails an unattended run that a throwing beforeRunning hook blocks', function () {
    Mail::fake();
    Log::spy();
    config()->set('backup.notifications.email', 'ops@example.com');

    backup()->beforeRunning(fn () => throw new RuntimeException('Maintenance window.'));

    $this->artisan('backup:run', ['--only-files' => true])->assertFailed();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Maintenance window.'))->once();
    Mail::assertSent(BackupFailed::class);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('does not notify when the hooks are skipped and the run succeeds', function () {
    Mail::fake();
    config()->set('backup.notifications.email', 'ops@example.com');

    backup()->beforeRunning(fn () => throw new RuntimeException('Never reached.'));

    expect(backup()->run(database: false, runHooks: false))->toEndWith('.zip');

    Mail::assertNothingSent();
});

it('does nothing when no hooks are registered', function () {
    expect(backup()->run(database: false))->toEndWith('.zip');
});

it('passes the filename to beforeDownloading hooks when an archive is downloaded', function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->actingAs(new GenericUser(['id' => 1]));
    backup()->auth(fn (): bool => true);

    $disk = Storage::disk('local');
    $disk->put('backups/laravel-2026-01-01-000000.zip', 'archive-bytes');
    $disk->buildTemporaryUrlsUsing(fn () => throw new RuntimeException('This driver does not support creating temporary URLs.'));

    $seen = [];
    backup()->beforeDownloading(function (string $filename) use (&$seen) {
        $seen[] = $filename;
    });

    $this->post('/backups/download/laravel-2026-01-01-000000.zip')->assertOk();

    expect($seen)->toBe(['laravel-2026-01-01-000000.zip']);
});

it('only allows the local environment by default', function () {
    $request = Request::create('/backups');

    app()->detectEnvironment(fn () => 'local');
    expect(backup()->authorize($request))->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    expect(backup()->authorize($request))->toBeFalse();
});

it('uses the registered auth callbacks instead of the default', function () {
    app()->detectEnvironment(fn () => 'production');

    backup()->auth(fn (Request $request): bool => $request->query('key') === 'open');

    expect(backup()->authorize(Request::create('/backups?key=open')))->toBeTrue();
    expect(backup()->authorize(Request::create('/backups?key=shut')))->toBeFalse();
});

it('requires every registered auth callback to allow the request', function () {
    backup()->auth(fn (): bool => true)->auth(fn (): bool => false);

    expect(backup()->authorize(Request::create('/backups')))->toBeFalse();
});
