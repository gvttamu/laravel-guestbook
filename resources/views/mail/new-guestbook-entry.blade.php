<x-mail::message>
# New guestbook entry

**{{ $entry->name }}** signed the guestbook on {{ $entry->created_at->toDayDateTimeString() }} UTC:

<x-mail::panel>
{{ $entry->message }}
</x-mail::panel>

<x-mail::button :url="route('guestbook.index')">
View the guestbook
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
