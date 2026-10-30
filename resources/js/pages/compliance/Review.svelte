<script>
    import { Link, useForm } from '@inertiajs/svelte';
    import { customerSections } from '../../customerFields.js';

    let { review } = $props();

    const form = useForm({ decision: '', notes: '' });

    // A sanctions review doesn't need the customer's finances or loan application, so the server doesn't send them.
    const fields = customerSections.filter((section) => !['financials', 'loan'].includes(section.key)).flatMap((section) => section.fields);

    // Server-rendered timestamps are shown in UTC, then switch to the visitor's locale and time zone once mounted.
    let mounted = $state(false);

    $effect(() => {
        mounted = true;
    });

    function formatTimestamp(timestamp) {
        const date = new Date(timestamp);

        return mounted ? date.toLocaleString() : date.toLocaleString('en-US', { timeZone: 'UTC', timeZoneName: 'short' });
    }

    function submit(event) {
        event.preventDefault();
        form.post(`/compliance/reviews/${review.id}`, { preserveScroll: true });
    }
</script>

<main class="mx-auto max-w-2xl px-4 py-12">
    <Link href="/compliance/reviews" class="text-sm text-slate-500 hover:text-slate-700">← Compliance reviews</Link>
    <h1 class="mt-2 text-3xl font-bold tracking-tight">Review {review.customer.first_name} {review.customer.last_name}</h1>

    <section class="mt-6 rounded-lg bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-200">
        <h2 class="font-semibold">Flagged at the {review.failed_step} check</h2>
        <ul class="mt-1 list-disc pl-5">
            {#each review.matches as match (match)}
                <li>{match}</li>
            {/each}
        </ul>
    </section>

    {#if review.earlier_reviews.length > 0}
        <section class="mt-6 rounded-xl bg-white p-5 ring-1 ring-slate-200">
            <h2 class="font-semibold">Earlier in this check</h2>
            <ol class="mt-3 space-y-4">
                {#each review.earlier_reviews as earlier (earlier.reviewed_at)}
                    <li class="text-sm">
                        <p>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {earlier.decision === 'approved'
                                    ? 'bg-green-50 text-green-700 ring-green-200'
                                    : 'bg-red-50 text-red-700 ring-red-200'}"
                            >
                                {earlier.decision}
                            </span>
                            <span class="ml-1 font-medium">{earlier.step} match</span>
                            <span class="text-slate-500">
                                · {earlier.reviewed_by ?? 'an unknown reviewer'},
                                <time datetime={earlier.reviewed_at}>{formatTimestamp(earlier.reviewed_at)}</time>
                            </span>
                        </p>
                        {#if earlier.matches.length > 0}
                            <ul class="mt-1 list-disc pl-5 text-slate-600">
                                {#each earlier.matches as match (match)}
                                    <li>{match}</li>
                                {/each}
                            </ul>
                        {/if}
                        {#if earlier.notes}
                            <p class="mt-1 border-l-2 border-slate-200 pl-3 text-slate-600">{earlier.notes}</p>
                        {/if}
                    </li>
                {/each}
            </ol>
        </section>
    {/if}

    <section class="mt-6 rounded-xl bg-white p-5 ring-1 ring-slate-200">
        <h2 class="font-semibold">Customer details</h2>
        <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
            {#each fields as field (field.name)}
                <dt class="text-slate-500">{field.label}</dt>
                <dd class="break-words">{review.customer[field.name] || '—'}</dd>
            {/each}
        </dl>
    </section>

    <form class="mt-6 space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" onsubmit={submit}>
        <fieldset>
            <legend class="font-semibold">Decision</legend>
            <div class="mt-2 flex gap-3">
                <label class="flex flex-1 cursor-pointer items-start gap-2 rounded-lg p-3 ring-1 ring-slate-300 has-checked:bg-green-50 has-checked:ring-green-400">
                    <input type="radio" name="decision" value="approved" bind:group={form.decision} class="mt-1" />
                    <span>
                        <span class="block font-medium">Approve</span>
                        <span class="block text-sm text-slate-500">The match is a false positive.</span>
                    </span>
                </label>
                <label class="flex flex-1 cursor-pointer items-start gap-2 rounded-lg p-3 ring-1 ring-slate-300 has-checked:bg-red-50 has-checked:ring-red-400">
                    <input type="radio" name="decision" value="rejected" bind:group={form.decision} class="mt-1" />
                    <span>
                        <span class="block font-medium">Reject</span>
                        <span class="block text-sm text-slate-500">The customer is on the sanctions list.</span>
                    </span>
                </label>
            </div>
            {#if form.errors.decision}
                <p class="mt-1 text-sm text-red-600">{form.errors.decision}</p>
            {/if}
        </fieldset>

        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700">
                Notes
                {#if form.decision !== 'rejected'}<span class="font-normal text-slate-400">(optional)</span>{/if}
            </label>
            <textarea
                id="notes"
                rows="3"
                bind:value={form.notes}
                class="mt-1 w-full rounded-lg border px-3 py-2 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none {form.errors.notes
                    ? 'border-red-400'
                    : 'border-slate-300'}"
            ></textarea>
            {#if form.errors.notes}
                <p class="mt-1 text-sm text-red-600">{form.errors.notes}</p>
            {/if}
        </div>

        <div class="flex justify-end border-t border-slate-100 pt-4">
            <button
                type="submit"
                disabled={form.processing}
                class="rounded-lg bg-brand px-4 py-2 font-medium text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
            >
                {form.processing ? 'Saving…' : 'Record decision'}
            </button>
        </div>
    </form>
</main>
