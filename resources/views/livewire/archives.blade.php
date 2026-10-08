<div>
    @if ($this->backups->isEmpty())
        {{-- atom:table's own empty state has fixed wording, so draw the empty card here. --}}
        <div class="rounded-lg border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-800">
            <atom:empty heading="No backups yet" subheading="Run a database backup and it will show up here." />
        </div>
    @else
        <atom:table>
            <x-slot:columns>
                <atom:table.column>Date</atom:table.column>
                <atom:table.column>File</atom:table.column>
                <atom:table.column align="right">Size</atom:table.column>
                <atom:table.column align="right"></atom:table.column>
            </x-slot:columns>

            <x-slot:rows>
                @foreach ($this->backups as $backup)
                    <atom:table.row wire:key="backup-{{ $backup['filename'] }}">
                        <atom:table.cell>{{ $backup['date']->format('Y-m-d H:i') }}</atom:table.cell>
                        <atom:table.cell>{{ $backup['filename'] }}</atom:table.cell>
                        <atom:table.cell align="right">{{ \Illuminate\Support\Number::fileSize($backup['size']) }}</atom:table.cell>
                        <atom:table.cell align="right">
                            <form method="POST" action="{{ route('backup.ui.download', $backup['filename']) }}">
                                @csrf
                                <atom:button type="submit" size="sm" variant="default" icon="download">Download</atom:button>
                            </form>
                        </atom:table.cell>
                    </atom:table.row>
                @endforeach
            </x-slot:rows>
        </atom:table>
    @endif
</div>
