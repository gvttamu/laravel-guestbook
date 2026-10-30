<script>
    import { Link } from '@inertiajs/svelte';

    let { reviews, engineAvailable } = $props();

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
    <Link href="/inbox" class="text-sm text-slate-500 hover:text-slate-700">← Inbox</Link>
    <h1 class="mt-2 text-3xl font-bold tracking-tight">Compliance reviews</h1>
    <p class="mt-1 text-slate-500">Customers flagged by the verification workflow, oldest first.</p>

    {#if !engineAvailable}
        <div class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200" role="alert">
            The workflow engine isn’t reachable, so open reviews can’t be shown. Check that it’s running on port 8090.
        </div>
    {/if}

    <div class="mt-8 overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Customer</th>
                    <th class="px-4 py-3 font-medium">Flagged at</th>
                    <th class="px-4 py-3 font-medium">Match</th>
                    <th class="px-4 py-3 font-medium">Waiting since</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                {#each reviews as review (review.id)}
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{review.customer.first_name} {review.customer.last_name}</p>
                            <p class="text-slate-500">{review.customer.email}</p>
                        </td>
                        <td class="px-4 py-3">{review.failed_step} check</td>
                        <td class="px-4 py-3 text-red-700">
                            {review.matches[0] ?? '—'}
                            {#if review.matches.length > 1}<span class="text-slate-500">(+{review.matches.length - 1} more)</span>{/if}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-500">
                            <time datetime={review.created_at}>{formatTimestamp(review.created_at)}</time>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link href={`/compliance/reviews/${review.id}`} class="font-medium text-brand hover:underline">Review</Link>
                        </td>
                    </tr>
                {:else}
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-slate-500">
                            {engineAvailable ? 'No customers are waiting for review.' : 'Reviews will appear here once the engine is reachable.'}
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</main>
