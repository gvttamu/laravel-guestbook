<script>
    import { Link, page } from '@inertiajs/svelte';
    import { customerSections } from '../customerFields.js';

    let { items } = $props();

    // Flashed once by a save; it isn't kept in history, so going back doesn't show it again.
    const saved = $derived(page.flash?.customerSaved);
    let dismissedSave = $state(null);

    const savedText = {
        created: (name) => `${name} was added. Verification and financial scoring have started.`,
        updated: (name) => `Your changes to ${name} were saved.`,
        unchanged: (name) => `There were no changes to save for ${name}.`,
    };

    const types = {
        sanctions_review: { label: 'Sanctions review', style: 'bg-red-50 text-red-700 ring-red-200', action: 'Review' },
        new_customer: { label: 'New customer draft', style: 'bg-slate-50 text-slate-700 ring-slate-200', action: 'Continue' },
        customer_changes: { label: 'Unsaved changes', style: 'bg-blue-50 text-blue-700 ring-blue-200', action: 'Continue' },
    };

    function stepName(key) {
        const index = customerSections.findIndex((section) => section.key === key);

        return index === -1 ? key : `step ${index + 1}, ${customerSections[index].title}`;
    }

    const customerName = (customer) => [customer.first_name, customer.last_name].filter(Boolean).join(' ') || 'Unnamed customer';

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

<main class="mx-auto max-w-5xl px-4 py-12">
    <h1 class="text-3xl font-bold tracking-tight">Inbox</h1>
    <p class="mt-1 text-slate-500">Work waiting on you, oldest first.</p>

    {#if saved && dismissedSave !== saved}
        <div class="mt-6 flex items-start justify-between gap-4 rounded-lg bg-green-50 p-4 text-sm text-green-800 ring-1 ring-green-200" role="status">
            <div>
                <p class="font-medium">Customer saved</p>
                <p class="mt-1">
                    {savedText[saved.outcome](saved.name)}
                    <Link href={`/customers?customer=${saved.id}`} class="font-medium underline">View their history</Link>
                </p>
            </div>
            <button type="button" class="text-green-700 hover:text-green-900" onclick={() => (dismissedSave = saved)} aria-label="Dismiss">✕</button>
        </div>
    {/if}

    <ul class="mt-8 divide-y divide-slate-100 rounded-xl bg-white ring-1 ring-slate-200">
        {#each items as item (item.key)}
            {@const type = types[item.type]}
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-sm sm:flex-nowrap">
                <span class="w-36 shrink-0">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 {type.style}">{type.label}</span>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-medium">{customerName(item.customer)}</p>
                    {#if item.type === 'sanctions_review'}
                        <p class="text-slate-500">
                            Possible match at the {item.failed_step} check:
                            <span class="text-red-700">{item.matches[0] ?? '—'}</span>
                            {#if item.matches.length > 1}(+{item.matches.length - 1} more){/if}
                        </p>
                    {:else}
                        <p class="text-slate-500">
                            {item.type === 'new_customer' ? 'Not submitted yet' : 'Not saved yet'}, last on {stepName(item.step)}
                        </p>
                        {#if item.is_out_of_date}
                            <p class="text-amber-700">Someone else has saved this customer since you started, so these changes can’t be saved.</p>
                        {/if}
                    {/if}
                </div>

                <time datetime={item.since} class="shrink-0 whitespace-nowrap text-slate-500" title={item.type === 'sanctions_review' ? 'Waiting since' : 'Last saved'}>
                    {formatTimestamp(item.since)}
                </time>

                <Link href={item.url} class="w-16 shrink-0 text-right font-medium text-brand hover:underline">{type.action}</Link>
            </li>
        {:else}
            <li class="px-4 py-6 text-center text-sm text-slate-500">Nothing needs your attention.</li>
        {/each}
    </ul>
</main>
