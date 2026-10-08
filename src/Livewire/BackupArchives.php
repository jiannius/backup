<?php

namespace Jiannius\Backup\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Jiannius\Atom\Traits\AtomComponent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class BackupArchives extends Component
{
    use AtomComponent;

    /**
     * Gate the table itself.
     */
    public function mount(): void
    {
        abort_unless(backup()->authorize(request()), 403);
    }

    /**
     * This app's archives, newest first. No temporary download URLs are built:
     * downloads go through the POST route, which hands out a fresh link on demand.
     *
     * @return Collection<int, array{filename: string, path: string, size: int, date: Carbon, url: ?string}>
     */
    #[Computed]
    public function backups(): Collection
    {
        return backup()->list(withUrls: false);
    }

    /**
     * Reload the table when a run ends (the page polls the status, not this table).
     */
    #[On('backup-run-finished')]
    public function reload(): void
    {
        unset($this->backups);
    }

    /**
     * Render the archive table.
     */
    public function render(): View
    {
        return view('backup::livewire.archives');
    }
}
