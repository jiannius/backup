<x-mail::message>
# Backup failed

The scheduled backup for **{{ config('app.name') }}** failed with the following error:

<x-mail::panel>
{{ $error }}
</x-mail::panel>

Please check the application logs for details.
</x-mail::message>
