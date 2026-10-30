<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <title>Guestbook summary for the week of {{ $weekStart->toFormattedDateString() }}</title>
        <style>
            @page { margin: 36px 40px; }
            body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #0f172a; }
            h1 { font-size: 20px; margin: 0; }
            .period { margin: 4px 0 20px; color: #64748b; }
            .stat { display: inline-block; padding: 8px 14px; margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 6px; }
            .stat strong { font-size: 18px; color: #4f63d2; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; font-size: 10px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 6px 8px; }
            td { vertical-align: top; border-bottom: 1px solid #e2e8f0; padding: 8px; }
            tr { page-break-inside: avoid; }
            .when { width: 22%; color: #475569; white-space: nowrap; }
            .name { width: 20%; font-weight: bold; }
            .empty { color: #64748b; padding: 16px 0; }
        </style>
    </head>
    <body>
        <h1>{{ config('app.name') }} weekly summary</h1>
        <p class="period">{{ $weekStart->toFormattedDateString() }} – {{ $weekEnd->toFormattedDateString() }} (UTC)</p>

        <div class="stat"><strong>{{ $entries->count() }}</strong> {{ Str::plural('entry', $entries->count()) }}</div>

        @if ($entries->isEmpty())
            <p class="empty">No one signed the guestbook this week.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Signed</th>
                        <th>Name</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="when">{{ $entry->created_at->format('D, M j, g:i A') }}</td>
                            <td class="name">{{ $entry->name }}</td>
                            <td>{!! nl2br(e($entry->message)) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </body>
</html>
