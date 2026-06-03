{{-- Anonymous Blade component. Use as <x-backup::example title="..." />. --}}
@props(['title' => 'Backup'])

<div {{ $attributes->merge(['class' => 'backup-example']) }}>
    <h2>{{ $title }}</h2>
    {{ $slot }}
</div>
