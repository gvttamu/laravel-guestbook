<script>
    import { Link } from '@inertiajs/svelte';
    import AuditTimeline from '../../components/AuditTimeline.svelte';

    let { customers, hasDraft, customerIdsWithDrafts, auditHistory } = $props();

    // The loan decision each scoring status records, shown under the score.
    const loanDecisions = {
        credit_review: 'referred to credit',
        approved: 'loan approved',
        rejected: 'loan rejected',
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

    // Verification's state machine has a place per review step; the list shows them as one badge, with the step underneath.
    const verificationLabels = {
        contact_review: { badge: 'flagged', awaitingReview: 'contact' },
        address_review: { badge: 'flagged', awaitingReview: 'address' },
        address_check: { badge: 'running' },
    };

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
                                {#if customer.verification}
                                    {@const verification = verificationLabels[customer.verification.status] ?? { badge: customer.verification.status }}
                                    <!-- The badge opens the verification workflow's diagram, showing where this run is. -->
                                    <Link
                                        href={`/workflows/customer-verification?run=${customer.verification.id}`}
                                        class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 hover:underline {workflowStyles[verification.badge] ??
                                            'bg-slate-50 text-slate-700 ring-slate-200'}"
                                    >
                                        {verification.badge}
                                    </Link>
                                    {#if verification.awaitingReview}
                                        <span class="mt-1 block text-xs text-slate-500">at {verification.awaitingReview} check · awaiting review</span>
                                    {:else if customer.verification.status === 'address_check'}
                                        {@const lastReview = customer.verification.result.reviews.at(-1)}
                                        <span class="mt-1 block text-xs text-slate-500">{lastReview.step} match {lastReview.decision} · finishing check</span>
                                    {:else if customer.verification.result?.failed_step}
                                        <span class="mt-1 block text-xs text-slate-500">at {customer.verification.result.failed_step} check</span>
                                    {/if}
                                {:else}
                                    <span class="text-slate-400">—</span>
                                {/if}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <!-- The badge opens the scoring workflow's diagram, showing where this run is. -->
                                {#if customer.scoring?.result?.risk_band}
                                    <Link
                                        href={`/workflows/financial-scoring?run=${customer.scoring.id}`}
                                        class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 hover:underline {riskBandStyles[customer.scoring.result.risk_band]}"
                                        title={[`Financial score ${customer.scoring.result.score}, ${customer.scoring.result.risk_band} risk`, ...customer.scoring.result.reasons].join('\n')}
                                    >
                                        {customer.scoring.result.score} · {customer.scoring.result.risk_band}
                                    </Link>
                                    {#if loanDecisions[customer.scoring.status]}
                                        <span class="mt-1 block text-xs text-slate-500">{loanDecisions[customer.scoring.status]}</span>
                                    {/if}
                                {:else if customer.scoring}
                                    <Link
                                        href={`/workflows/financial-scoring?run=${customer.scoring.id}`}
                                        class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 hover:underline {workflowStyles[customer.scoring.status] ?? workflowStyles.pending}"
                                    >
                                        {customer.scoring.status}
                                    </Link>
                                {:else}
                                    <span class="text-slate-400" title="No financials captured yet">—</span>
                                {/if}
                            </td>
                            <td class="space-x-3 px-4 py-3 text-right whitespace-nowrap">
                                <Link href={`/customers/${customer.id}/case`} class="font-medium text-brand hover:underline">Case</Link>
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

                <div class="mt-4">
                    <AuditTimeline entries={auditHistory.entries} />
                </div>
            {:else}
                <h2 id="audit-history-heading" class="font-semibold">Audit history</h2>
                <p class="mt-1 text-sm text-slate-500">Choose <span class="font-medium">History</span> on a customer to see who changed what, and when.</p>
            {/if}
        </aside>
    </div>
</main>
