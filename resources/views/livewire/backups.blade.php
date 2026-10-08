<div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
    <div>
        <atom:heading size="xl" level="1">Backups</atom:heading>
        <atom:subheading>Back up the database and download an archive.</atom:subheading>
    </div>

    <div
        @if ($this->isRunning) wire:poll.3s="pollStatus" @endif
        class="flex flex-col gap-4"
        data-backup-status
    >
        @php($status = $this->status)

        @if ($this->isRunning)
            <atom:callout variant="info" icon="loading">
                {{ ($status['status'] ?? null) === 'running' ? 'A database backup is running.' : 'A database backup is queued and will start shortly.' }}
            </atom:callout>
        @elseif (($status['status'] ?? null) === 'completed')
            <atom:callout variant="success">
                Backup completed: {{ $status['filename'] }}
            </atom:callout>
        @elseif (($status['status'] ?? null) === 'failed')
            <atom:callout variant="danger" heading="The last backup failed">
                {{ $status['error'] }}
            </atom:callout>
        @endif

        @error('run')
            <atom:callout variant="warning">{{ $message }}</atom:callout>
        @enderror

        <div>
            <atom:button
                type="button"
                variant="primary"
                icon="database"
                wire:click="run"
                wire:loading.attr="disabled"
                wire:target="run"
                :disabled="$this->isRunning"
            >Run database backup</atom:button>
        </div>
    </div>

    {{-- A child component, so the status poll above never re-renders (or re-lists) the archives. --}}
    <livewire:backup-archives />
</div>
