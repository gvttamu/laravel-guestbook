<script>
    // The compliance decisions recorded in a verification run, oldest first: each one's step,
    // the matches it decided on, who decided and when, and their notes.
    let { title, reviews } = $props();

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
        {#each reviews as review (review.reviewed_at)}
            <li class="text-sm">
                <p>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {review.decision === 'approved'
                            ? 'bg-green-50 text-green-700 ring-green-200'
                            : 'bg-red-50 text-red-700 ring-red-200'}"
                    >
                        {review.decision}
                    </span>
                    <span class="ml-1 font-medium">{review.step} match</span>
                    <span class="text-slate-500">
                        · {review.reviewed_by ?? 'an unknown reviewer'},
                        <time datetime={review.reviewed_at}>{formatTimestamp(review.reviewed_at)}</time>
                    </span>
                </p>
                {#if review.matches.length > 0}
                    <ul class="mt-1 list-disc pl-5 text-slate-600">
                        {#each review.matches as match (match)}
                            <li>{match}</li>
                        {/each}
                    </ul>
                {/if}
                {#if review.notes}
                    <p class="mt-1 border-l-2 border-slate-200 pl-3 text-slate-600">{review.notes}</p>
                {/if}
            </li>
        {/each}
    </ol>
</section>
