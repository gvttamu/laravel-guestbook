<x-mail::message>
# Weekly guestbook summary

Your guestbook received **{{ trans_choice('{0} no entries|{1} 1 entry|[2,*] :count entries', $entries->count()) }}** between {{ $weekStart->toFormattedDateString() }} and {{ $weekEnd->toFormattedDateString() }} (UTC).

@if ($entries->isNotEmpty())
The full list is in the attached PDF.
@endif

<x-mail::button :url="route('guestbook.index')">
View the guestbook
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
