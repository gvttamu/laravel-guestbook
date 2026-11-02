<x-mail::message>
@if ($assignerName)
# A screening review has been assigned to you
@else
# A screening review is waiting
@endif

The sanctions screening for **{{ $customerName }}** found a possible match at the **{{ $step }}** check. It needs a compliance officer to approve or reject it before the check can finish.

<x-mail::button :url="$reviewUrl">
Review the match
</x-mail::button>

@if ($assignerName)
You're receiving this because {{ $assignerName }} assigned this review to you. The match details are only shown in the app.
@else
You're receiving this because you're a compliance officer. The match details are only shown in the app.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
