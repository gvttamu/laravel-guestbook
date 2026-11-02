<script>
    // The credit officers' decisions and reconsiderations of a loan application, oldest first:
    // what each did, who and when, and their notes.
    let { title, decisions } = $props();

    const actionStyles = {
        approved: 'bg-green-50 text-green-700 ring-green-200',
        rejected: 'bg-red-50 text-red-700 ring-red-200',
        reconsidered: 'bg-amber-50 text-amber-700 ring-amber-200',
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
</script>

<section class="mt-6 rounded-xl bg-white p-5 ring-1 ring-slate-200">
    <h2 class="font-semibold">{title}</h2>
    <ol class="mt-3 space-y-4">
        {#each decisions as decision (decision.reviewed_at + decision.action)}
            <li class="text-sm">
                <p>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {actionStyles[decision.action]}">{decision.action}</span>
                    <span class="text-slate-500">
                        · {decision.reviewed_by ?? 'an unknown officer'},
                        <time datetime={decision.reviewed_at}>{formatTimestamp(decision.reviewed_at)}</time>
                    </span>
                </p>
                {#if decision.notes}
                    <p class="mt-1 border-l-2 border-slate-200 pl-3 text-slate-600">{decision.notes}</p>
                {/if}
            </li>
        {/each}
    </ol>
</section>
