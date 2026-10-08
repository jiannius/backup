<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Jiannius\Backup\Actions\BackupRunStatus;
use Jiannius\Backup\Actions\DumpDatabase;
use Jiannius\Backup\Actions\ListBackups;
use Jiannius\Backup\Jobs\RunBackup;
use Jiannius\Backup\Livewire\BackupArchives;
use Jiannius\Backup\Livewire\Backups;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 1)->setTime(12, 0));

    $this->disk = Storage::fake('local');
    $this->disk->put('backups/laravel-2026-02-27-120000.zip', 'older');
    touch($this->disk->path('backups/laravel-2026-02-27-120000.zip'), now()->subDays(2)->getTimestamp());
    $this->disk->put('backups/laravel-2026-02-28-120000.zip', 'newer-archive');
    touch($this->disk->path('backups/laravel-2026-02-28-120000.zip'), now()->subDay()->getTimestamp());

    // A signed URL builder so the "no temporary URL on the page" test has something to look for.
    $this->disk->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiration): string => 'https://files.example.com/'.$path.'?signature=secret'
    );

    Route::get('/login', fn () => 'login')->name('login');
    backup()->auth(fn (): bool => true);
    $this->actingAs(new GenericUser(['id' => 5]));
});

it('serves the page at the configured path on the host layout', function () {
    $this->get('/backups')
        ->assertOk()
        ->assertSee('Backup test layout')
        ->assertSee('Run database backup')
        ->assertDontSee('<atom:', false);
});

it('lists archives newest first with date, filename and size', function () {
    Livewire::test(Backups::class)
        ->assertSeeInOrder(['laravel-2026-02-28-120000.zip', 'laravel-2026-02-27-120000.zip'])
        ->assertSee('2026-02-28 12:00')
        ->assertSee('13 B');
});

it('never renders a temporary url on the page or in the component state', function () {
    $this->get('/backups')
        ->assertOk()
        ->assertDontSee('files.example.com', false)
        ->assertDontSee('signature=secret', false)
        ->assertSee('/backups/download/laravel-2026-02-28-120000.zip', false);

    $component = Livewire::test(Backups::class);

    expect(json_encode($component->snapshot ?? []))->not->toContain('files.example.com');
    expect($component->html())->not->toContain('files.example.com');
});

it('renders a csrf-protected post form per archive', function () {
    $html = Livewire::test(Backups::class)->html();

    expect($html)->toContain('method="POST"')->toContain('name="_token"');
});

it('queues a run, records queued and calls the hooks at request time', function () {
    Queue::fake();

    $options = [];
    backup()->beforeRunning(function (array $given) use (&$options) {
        $options[] = [$given, auth()->id()];
    });

    Livewire::test(Backups::class)->call('run');

    Queue::assertPushed(RunBackup::class, 1);
    expect(app(BackupRunStatus::class)->get()['status'])->toBe('queued');
    expect(app(BackupRunStatus::class)->get()['user'])->toBe('5');
    expect($options)->toBe([[['database' => true, 'files' => false], 5]]);
});

it('does not queue a second run while one is active', function () {
    Queue::fake();

    $options = 0;
    backup()->beforeRunning(function () use (&$options) {
        $options++;
    });

    Livewire::test(Backups::class)->call('run')->call('run');

    Queue::assertPushed(RunBackup::class, 1);
    expect($options)->toBe(1);
});

it('polls only while a run is active', function () {
    Queue::fake();

    $component = Livewire::test(Backups::class);
    expect($component->html())->not->toContain('wire:poll');

    $component->call('run');
    expect($component->html())->toContain('wire:poll')->toContain('queued');
});

it('shows a short error for a failed run, without the raw process output', function () {
    app(BackupRunStatus::class)->failed(queuedRun(), "Disk is on fire\n/usr/bin/mysqldump --host=db.example.com --password=hunter2");

    Livewire::test(Backups::class)
        ->assertSee('The last backup failed')
        ->assertSee('Disk is on fire')
        ->assertDontSee('mysqldump')
        ->assertDontSee('hunter2')
        ->assertDontSee('wire:poll', false);
});

it('tells the user when a run is already active', function () {
    Queue::fake();

    app(BackupRunStatus::class)->acquire();

    Livewire::test(Backups::class)
        ->call('run')
        ->assertHasErrors('run')
        ->assertSee('A backup is already running.');

    Queue::assertNothingPushed();
});

it('does not record a failure twice when the sync queue already did', function () {
    config()->set('queue.default', 'sync');
    config()->set('backup.database.connection', 'nope');
    app()->forgetInstance(DumpDatabase::class);

    $reported = 0;
    $this->app->make(ExceptionHandler::class)->reportable(function () use (&$reported) {
        $reported++;

        return false;
    });

    Livewire::test(Backups::class)->call('run')->assertOk();

    $status = app(BackupRunStatus::class)->get();

    expect($status['status'])->toBe('failed');
    expect($status['error'])->toContain('nope');
    expect($reported)->toBe(0);
    expect(app(BackupRunStatus::class)->isActive())->toBeFalse();
});

it('shows a backup-specific empty state before the first backup', function () {
    $this->disk->deleteDirectory('backups');

    Livewire::test(Backups::class)
        ->assertSee('No backups yet')
        ->assertDontSee('No Results');
});

it('builds no temporary urls to render the page', function () {
    $calls = 0;
    $this->disk->buildTemporaryUrlsUsing(function () use (&$calls): string {
        $calls++;

        return 'https://files.example.com/never';
    });

    $this->get('/backups')->assertOk()->assertSee('laravel-2026-02-28-120000.zip');
    Livewire::test(Backups::class)->assertSee('laravel-2026-02-28-120000.zip');

    expect($calls)->toBe(0);
});

it('polls only the status, never re-listing the archives, until the run ends', function () {
    Queue::fake();

    $listed = 0;
    app()->instance(ListBackups::class, new class($listed) extends ListBackups
    {
        public function __construct(public int &$listed) {}

        /**
         * Count the listings.
         */
        public function handle(string $disk, string $path, int $expiryMinutes, bool $withUrls = true): Collection
        {
            $this->listed++;

            return parent::handle($disk, $path, $expiryMinutes, $withUrls);
        }
    });

    $component = Livewire::test(Backups::class);
    expect($listed)->toBe(1);

    $component->call('run');
    $component->call('pollStatus')->call('pollStatus');

    expect($component->html())->toContain('wire:poll.3s="pollStatus"');
    expect($listed)->toBe(1);
    $component->assertNotDispatched('backup-run-finished');

    // The run ends: the poll tells the table to reload, once.
    $runId = app(BackupRunStatus::class)->get()['run_id'];
    app(BackupRunStatus::class)->completed($runId, 'laravel-2026-03-01-120000.zip');

    $component->call('pollStatus')->assertDispatched('backup-run-finished');
});

it('reloads the archive table when a run ends', function () {
    $table = Livewire::test(BackupArchives::class)->assertDontSee('laravel-2026-03-01-120000.zip');

    $this->disk->put('backups/laravel-2026-03-01-120000.zip', 'fresh');

    $table->dispatch('backup-run-finished')->assertSee('laravel-2026-03-01-120000.zip');
});

it('refuses the archive table when access is revoked', function () {
    backup()->auth(fn (Request $request): bool => false);

    Livewire::test(BackupArchives::class)->assertForbidden();
});

it('shows a completed run with its filename', function () {
    app(BackupRunStatus::class)->completed(queuedRun(), 'laravel-2026-03-01-120000.zip');

    Livewire::test(Backups::class)->assertSee('Backup completed: laravel-2026-03-01-120000.zip');
});

it('blocks the run and releases the lock when a beforeRunning hook aborts', function () {
    Queue::fake();

    backup()->beforeRunning(fn () => abort(403, 'Nope.'));

    Livewire::test(Backups::class)->call('run')->assertForbidden();

    Queue::assertNothingPushed();
    expect(app(BackupRunStatus::class)->isActive())->toBeFalse();
});

it('marks the run failed and reports it when the dispatch itself fails', function () {
    config()->set('queue.connections.broken', ['driver' => 'no-such-driver']);
    config()->set('backup.ui.queue.connection', 'broken');
    $this->app->make(ExceptionHandler::class)->reportable(fn () => false);

    Livewire::test(Backups::class)->call('run')->assertOk();

    $status = app(BackupRunStatus::class);

    expect($status->get()['status'])->toBe('failed');
    expect($status->get()['error'])->toContain('no-such-driver');
    expect($status->isActive())->toBeFalse();
});

it('returns 403 on the page and the run action when a custom auth hook says no', function () {
    Queue::fake();
    backup()->auth(fn (Request $request): bool => false);

    $this->get('/backups')->assertForbidden();

    Livewire::test(Backups::class)->assertForbidden();
});

it('refuses the run action when access is revoked after the page loaded', function () {
    Queue::fake();
    $allowed = true;
    backup()->auth(function () use (&$allowed): bool {
        return $allowed;
    });

    $component = Livewire::test(Backups::class);
    $allowed = false;

    $component->call('run')->assertForbidden();

    Queue::assertNothingPushed();
});

it('redirects a guest from the page', function () {
    $this->app['auth']->forgetGuards();

    $this->get('/backups')->assertRedirect('/login');
});

it('uses the configured layout view', function () {
    config()->set('backup.ui.layout', 'backup-test::alt-layout');

    $this->get('/backups')->assertSee('Alternative test layout')->assertDontSee('Backup test layout');
});

it('never drops the poll without announcing the end when the run finishes mid-request', function () {
    $component = Livewire::test(Backups::class);

    // The run reports active on the first check and finished on any later one.
    $status = new class extends BackupRunStatus
    {
        public int $checks = 0;

        public function isActive(): bool
        {
            return ++$this->checks === 1;
        }
    };
    app()->instance(BackupRunStatus::class, $status);

    $component->call('pollStatus');

    $pollRendered = str_contains($component->html(), 'wire:poll.3s="pollStatus"');
    $announced = collect(data_get($component->effects, 'dispatches', []))->contains(fn (array $event): bool => $event['name'] === 'backup-run-finished');

    expect($pollRendered || $announced)->toBeTrue();
    expect($status->checks)->toBe(1);
});
