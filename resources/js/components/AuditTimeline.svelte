<script>
    import { customerFieldValueLabel, customerFieldLabels as fieldLabels } from '../customerFields.js';

    // A customer's audit entries, newest first: what happened, who did it (or which automatic
    // check), from where, and each field's old and new value.
    let { entries } = $props();

    const eventStyles = {
        created: 'bg-green-50 text-green-700 ring-green-200',
        updated: 'bg-blue-50 text-blue-700 ring-blue-200',
        deleted: 'bg-red-50 text-red-700 ring-red-200',
        verified: 'bg-green-50 text-green-700 ring-green-200',
        flagged: 'bg-amber-50 text-amber-700 ring-amber-200',
        approved: 'bg-green-50 text-green-700 ring-green-200',
        rejected: 'bg-red-50 text-red-700 ring-red-200',
        scored: 'bg-violet-50 text-violet-700 ring-violet-200',
        assigned: 'bg-blue-50 text-blue-700 ring-blue-200',
        loan_approved: 'bg-green-50 text-green-700 ring-green-200',
        loan_rejected: 'bg-red-50 text-red-700 ring-red-200',
        loan_referred: 'bg-violet-50 text-violet-700 ring-violet-200',
        loan_reconsidered: 'bg-amber-50 text-amber-700 ring-amber-200',
    };

    // Checks that ran without a person show who did them, instead of "an unknown user".
    const automaticActors = {
        scored: 'automatic financial scoring',
        loan_approved: 'the automatic decision engine',
        loan_rejected: 'the automatic decision engine',
        loan_referred: 'the automatic decision engine',
        flagged: 'automatic verification',
        verified: 'automatic verification',
        approved: 'automatic verification',
        rejected: 'automatic verification',
    };

    // Server-rendered timestamps are shown in UTC, then switch to the visitor's locale and time zone once mounted.
    let mounted = $state(false);

    $effect(() => {
        mounted = true;
    });

    function formatTimestamp(timestamp) {
        const date = new Date(timestamp);

        return mounted ? date.toLocaleString() : date.toLocaleString('en-US', { timeZone: 'UTC', timeZoneName: 'short' });
    }

    function changes(entry) {
        const fields = [...new Set([...Object.keys(entry.old_values ?? {}), ...Object.keys(entry.new_values ?? {})])];

        return fields.map((field) => ({
            field,
            label: fieldLabels[field] ?? field.charAt(0).toUpperCase() + field.slice(1).replaceAll('_', ' '),
            hasOld: entry.old_values != null && field in entry.old_values,
            old: customerFieldValueLabel(field, entry.old_values?.[field]),
            new: customerFieldValueLabel(field, entry.new_values?.[field]),
        }));
    }

    function display(value) {
        if (Array.isArray(value)) {
            return value.length ? value.join('; ') : '—';
        }

        return value === null || value === undefined || value === '' ? '—' : value;
    }
</script>

<ol class="space-y-4">
    {#each entries as entry (entry.id)}
        <li class="border-l-2 border-slate-200 pl-4">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {eventStyles[entry.event] ?? 'bg-slate-50 text-slate-700 ring-slate-200'}">
                    {entry.event}
                </span>
                <time datetime={entry.created_at} class="text-slate-500">{formatTimestamp(entry.created_at)}</time>
            </div>
            <p class="mt-1 text-xs text-slate-500">
                By {entry.user?.name ?? automaticActors[entry.event] ?? 'an unknown user'}{entry.ip_address ? ` from ${entry.ip_address}` : ''}
            </p>

            <dl class="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
                {#each changes(entry) as change (change.field)}
                    <dt class="text-slate-500">{change.label}</dt>
                    <dd class="break-words">
                        {#if change.hasOld}
                            <del class="text-slate-400">{display(change.old)}</del> →
                        {/if}
                        {display(change.new)}
                    </dd>
                {/each}
            </dl>
        </li>
    {:else}
        <li class="text-sm text-slate-500">No audit entries for this customer.</li>
    {/each}
</ol>
