<script>
    import { Link } from '@inertiajs/svelte';
    import AuditTimeline from '../../components/AuditTimeline.svelte';
    import { customerFieldValueLabel, customerSections } from '../../customerFields.js';

    // A customer's case: their details, every workflow run and the steps it took, and the audit trail.
    // Compliance officers get it without the finances, loan and scores (`showsFinances` is false).
    let { customer, showsFinances, runs, timeline, editUrl, hasDraft } = $props();

    const name = $derived(`${customer.first_name} ${customer.last_name}`);
    const sections = $derived(showsFinances ? customerSections : customerSections.filter((section) => !['financials', 'loan'].includes(section.key)));

    // The latest run of each workflow is the customer's current status in it.
    const current = $derived({
        verification: runs.find((run) => run.workflow === 'customer-verification'),
        scoring: runs.find((run) => run.workflow === 'financial-scoring'),
    });

    const statusStyles = {
        verified: 'bg-green-50 text-green-700 ring-green-200',
        approved: 'bg-green-50 text-green-700 ring-green-200',
        rejected: 'bg-red-50 text-red-700 ring-red-200',
        contact_review: 'bg-amber-50 text-amber-700 ring-amber-200',
        address_review: 'bg-amber-50 text-amber-700 ring-amber-200',
        credit_review: 'bg-violet-50 text-violet-700 ring-violet-200',
        superseded: 'bg-slate-50 text-slate-500 ring-slate-200',
    };
    const statusStyle = (status) => statusStyles[status] ?? 'bg-blue-50 text-blue-700 ring-blue-200';

    // Server-rendered timestamps are shown in UTC, then switch to the visitor's locale and time zone once mounted.
    let mounted = $state(false);

    $effect(() => {
        mounted = true;
    });

    function formatTimestamp(timestamp) {
        const date = new Date(timestamp);

        return mounted ? date.toLocaleString() : date.toLocaleString('en-US', { timeZone: 'UTC', timeZoneName: 'short' });
    }
</script>

<main class="mx-auto max-w-7xl px-4 py-12">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-sm text-slate-500">Case #{customer.id}</p>
            <h1 class="text-3xl font-bold tracking-tight">{name}</h1>
            <p class="mt-1 text-slate-500">{customer.email} · {customer.phone}</p>
            <p class="mt-1 text-sm text-slate-500">Opened {formatTimestamp(customer.created_at)} · last changed {formatTimestamp(customer.updated_at)}</p>
        </div>
        {#if editUrl}
            <Link href={editUrl} class="rounded-lg bg-brand px-4 py-2 font-medium text-white hover:opacity-90">
                {hasDraft ? 'Continue editing' : 'Edit customer'}
            </Link>
        {/if}
    </div>

    <dl class="mt-6 flex flex-wrap gap-3 text-sm">
        {#each [['Verification', current.verification], ...(showsFinances ? [['Loan application', current.scoring]] : [])] as [label, run] (label)}
            <div class="rounded-lg bg-white px-4 py-3 ring-1 ring-slate-200">
                <dt class="text-slate-500">{label}</dt>
                <dd class="mt-1">
                    {#if run}
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {statusStyle(run.status)}">{run.status_label}</span>
                    {:else}
                        <span class="text-slate-400">Not started</span>
                    {/if}
                </dd>
            </div>
        {/each}
    </dl>

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="space-y-6">
            <section class="rounded-xl bg-white p-5 ring-1 ring-slate-200">
                <h2 class="font-semibold">Details</h2>
                <div class="mt-3 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                    {#each sections as section (section.key)}
                        <div>
                            <h3 class="text-sm font-medium text-slate-700">{section.title}</h3>
                            <dl class="mt-1 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
                                {#each section.fields as field (field.name)}
                                    <dt class="text-slate-500">{field.label}</dt>
                                    <dd class="break-words">{customerFieldValueLabel(field.name, customer[field.name]) ?? '—'}</dd>
                                {/each}
                            </dl>
                        </div>
                    {/each}
                </div>
                {#if !showsFinances}
                    <p class="mt-4 text-sm text-slate-500">The customer’s finances, loan and scores aren’t shown to compliance officers.</p>
                {/if}
            </section>

            <section>
                <h2 class="font-semibold">Workflow runs</h2>
                <p class="text-sm text-slate-500">Newest first. A new run starts whenever the details it checks change.</p>

                <div class="mt-3 space-y-4">
                    {#each runs as run (run.id)}
                        <article class="rounded-xl bg-white p-5 ring-1 ring-slate-200">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="font-medium">{run.title} <span class="font-normal text-slate-500">· run #{run.id}</span></h3>
                                    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {statusStyle(run.status)}">{run.status_label}</span>
                                        {#if run.assigned_to}<span class="text-slate-500">assigned to {run.assigned_to}</span>{/if}
                                    </p>
                                </div>
                                <div class="space-x-3 text-sm whitespace-nowrap">
                                    {#if run.review_url}
                                        <Link href={run.review_url} class="font-medium text-brand hover:underline">Open review</Link>
                                    {/if}
                                    <Link href={`/workflows/${run.workflow}?run=${run.id}`} class="font-medium text-brand hover:underline">Diagram</Link>
                                </div>
                            </div>

                            {#if run.details.matches?.length}
                                <ul class="mt-3 list-disc pl-5 text-sm text-red-700">
                                    {#each run.details.matches as match (match)}
                                        <li>{match}</li>
                                    {/each}
                                </ul>
                            {/if}
                            {#if run.details.score !== undefined}
                                <p class="mt-3 text-sm text-slate-600">
                                    Affordability {run.details.score} ({run.details.risk_band} risk) · credit score {run.details.credit_score ?? 'none on file'}
                                    {#if run.details.credit_risk_tier}· {run.details.credit_risk_tier} credit risk tier{/if}
                                </p>
                            {/if}

                            <ol class="mt-3 space-y-1 border-l-2 border-slate-100 pl-4 text-sm">
                                {#each run.history as step, index (index)}
                                    <li>
                                        <span class="font-medium">{step.label}</span>
                                        <span class="text-slate-500">
                                            {#if step.transition}· {step.transition}{/if}
                                            · {step.by ?? (step.transition ? 'automatic' : 'started')},
                                            <time datetime={step.at}>{formatTimestamp(step.at)}</time>
                                            {#if step.reconstructed}(reconstructed){/if}
                                        </span>
                                    </li>
                                {/each}
                            </ol>
                        </article>
                    {:else}
                        <p class="rounded-xl bg-white p-5 text-sm text-slate-500 ring-1 ring-slate-200">No workflows have run for this customer.</p>
                    {/each}
                </div>
            </section>
        </div>

        <aside class="rounded-xl bg-white p-5 ring-1 ring-slate-200" aria-labelledby="timeline-heading">
            <h2 id="timeline-heading" class="font-semibold">Timeline</h2>
            <p class="text-sm text-slate-500">Who did what, newest first.</p>
            <div class="mt-4">
                <AuditTimeline entries={timeline} />
            </div>
        </aside>
    </div>
</main>
