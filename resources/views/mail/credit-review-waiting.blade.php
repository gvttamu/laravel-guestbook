<x-mail::message>
# A loan application is waiting for review

@if ($wasReconsidered)
A credit officer has reopened **{{ $customerName }}**'s loan application to be decided again.
@else
The decision engine couldn't approve or reject **{{ $customerName }}**'s loan application automatically, so it needs a credit officer to decide it.
@endif

It's in the **{{ $tier }}** credit risk tier.

<x-mail::button :url="$reviewUrl">
Review the application
</x-mail::button>

You're receiving this because you're a credit officer for {{ $tier }}-risk applications. The first officer to open it takes it on. The customer's finances and scores are only shown in the app.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
