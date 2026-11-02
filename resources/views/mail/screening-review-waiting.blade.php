<x-mail::message>
# A screening review is waiting

The sanctions screening for **{{ $customerName }}** found a possible match at the **{{ $step }}** check. It needs a compliance officer to approve or reject it before the check can finish.

<x-mail::button :url="$reviewUrl">
Review the match
</x-mail::button>

You're receiving this because you're a compliance officer. The match details are only shown in the app.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
