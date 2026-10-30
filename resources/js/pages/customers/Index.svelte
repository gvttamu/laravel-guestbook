<script>
    import { Link } from '@inertiajs/svelte';
    import { customerFieldValueLabel, customerFieldLabels as fieldLabels } from '../../customerFields.js';

    let { customers, hasDraft, customerIdsWithDrafts, auditHistory } = $props();

    const eventStyles = {
        created: 'bg-green-50 text-green-700 ring-green-200',
        updated: 'bg-blue-50 text-blue-700 ring-blue-200',
        deleted: 'bg-red-50 text-red-700 ring-red-200',
        verified: 'bg-green-50 text-green-700 ring-green-200',
        flagged: 'bg-amber-50 text-amber-700 ring-amber-200',
        approved: 'bg-green-50 text-green-700 ring-green-200',
        rejected: 'bg-red-50 text-red-700 ring-red-200',
        scored: 'bg-violet-50 text-violet-700 ring-violet-200',
    };

    const riskBandStyles = {
        low: 'bg-green-50 text-green-700 ring-green-200',
        medium: 'bg-amber-50 text-amber-700 ring-amber-200',
        high: 'bg-red-50 text-red-700 ring-red-200',
    };

    const workflowStyles = {
        pending: 'bg-slate-50 text-slate-700 ring-slate-200',
        running: 'bg-blue-50 text-blue-700 ring-blue-200',
        verified: 'bg-green-50 text-green-700 ring-green-200',
        flagged: 'bg-amber-50 text-amber-700 ring-amber-200',
        approved: 'bg-green-50 text-green-700 ring-green-200',
        rejected: 'bg-red-50 text-red-700 ring-red-200',
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

    // Opening and closing the panel only reloads the audit history, not the customer list.
    const panelLinkProps = { only: ['auditHistory'], preserveState: true, preserveScroll: true };
</script>

<main class="mx-auto max-w-7xl px-4 py-12">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-3xl font-bold tracking-tight">Customers</h1>
        <Link href="/customers/create" class="rounded-lg bg-brand px-4 py-2 font-medium text-white hover:opacity-90">
            {hasDraft ? 'Continue draft' : 'New customer'}
        </Link>
    </div>

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-slate-500">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Location</th>
                        <th class="px-4 py-3 font-medium">Workflow</th>
                        <th class="px-4 py-3 font-medium">Score</th>
                        <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    {#each customers as customer (customer.id)}
                        <tr class={auditHistory?.customer.id === customer.id ? 'bg-brand/5' : ''}>
                            <td class="px-4 py-3 font-medium">{customer.first_name} {customer.last_name}</td>
                            <td class="px-4 py-3">{customer.email}</td>
                            <td class="px-4 py-3">{customer.city}, {customer.country}</td>
                            <td class="px-4 py-3">
                                {#if customer.workflow}
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {workflowStyles[customer.workflow.status] ??
                                            'bg-slate-50 text-slate-700 ring-slate-200'}"
                                    >
                                        {customer.workflow.status}
                                    </span>
                                    {#if customer.workflow.status === 'flagged'}
                                        <span class="mt-1 block text-xs text-slate-500">at {customer.workflow.result?.failed_step} check · awaiting review</span>
                                    {:else if customer.workflow.status === 'running' && customer.workflow.result?.reviews?.length}
                                        {@const lastReview = customer.workflow.result.reviews.at(-1)}
                                        <span class="mt-1 block text-xs text-slate-500">{lastReview.step} match {lastReview.decision} · finishing check</span>
                                    {:else if customer.workflow.result?.failed_step}
                                        <span class="mt-1 block text-xs text-slate-500">at {customer.workflow.result.failed_step} check</span>
                                    {:else if customer.workflow.current_task}
                                        <span class="mt-1 block text-xs text-slate-500">{customer.workflow.current_task}</span>
                                    {/if}
                                {:else}
                                    <span class="text-slate-400">—</span>
                                {/if}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {#if customer.scoring?.status === 'scored'}
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {riskBandStyles[customer.scoring.result.risk_band]}"
                                        title={[`Financial score ${customer.scoring.result.score}, ${customer.scoring.result.risk_band} risk`, ...customer.scoring.result.reasons].join('\n')}
                                    >
                                        {customer.scoring.result.score} · {customer.scoring.result.risk_band}
                                    </span>
                                {:else if customer.scoring}
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {workflowStyles[customer.scoring.status] ?? workflowStyles.pending}">
                                        {customer.scoring.status}
                                    </span>
                                {:else}
                                    <span class="text-slate-400" title="No financials captured yet">—</span>
                                {/if}
                            </td>
                            <td class="space-x-3 px-4 py-3 text-right whitespace-nowrap">
                                <Link href={`/customers/${customer.id}/edit`} class="font-medium text-brand hover:underline">
                                    {customerIdsWithDrafts.includes(customer.id) ? 'Continue editing' : 'Edit'}
                                </Link>
                                <Link
                                    href={`/customers?customer=${customer.id}`}
                                    {...panelLinkProps}
                                    class="font-medium whitespace-nowrap text-brand hover:underline"
                                    aria-current={auditHistory?.customer.id === customer.id ? 'true' : undefined}
                                >
                                    History
                                </Link>
                            </td>
                        </tr>
                    {:else}
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-slate-500">No customers yet.</td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </div>

        <aside class="rounded-xl bg-white p-5 ring-1 ring-slate-200" aria-labelledby="audit-history-heading">
            {#if auditHistory}
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="audit-history-heading" class="font-semibold">Audit history</h2>
                        <p class="text-sm text-slate-500">{auditHistory.customer.first_name} {auditHistory.customer.last_name}</p>
                    </div>
                    <Link href="/customers" {...panelLinkProps} class="text-sm text-slate-500 hover:text-slate-700">Close</Link>
                </div>

                <ol class="mt-4 space-y-4">
                    {#each auditHistory.entries as entry (entry.id)}
                        <li class="border-l-2 border-slate-200 pl-4">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {eventStyles[entry.event] ?? 'bg-slate-50 text-slate-700 ring-slate-200'}">
                                    {entry.event}
                                </span>
                                <time datetime={entry.created_at} class="text-slate-500">{formatTimestamp(entry.created_at)}</time>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                By {entry.user?.name ?? 'an unknown user'}{entry.ip_address ? ` from ${entry.ip_address}` : ''}
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
            {:else}
                <h2 id="audit-history-heading" class="font-semibold">Audit history</h2>
                <p class="mt-1 text-sm text-slate-500">Choose <span class="font-medium">History</span> on a customer to see who changed what, and when.</p>
            {/if}
        </aside>
    </div>
</main>
