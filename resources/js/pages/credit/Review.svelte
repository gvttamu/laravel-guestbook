<script>
    import { Link, useForm } from '@inertiajs/svelte';
    import CreditAssessment from '../../components/CreditAssessment.svelte';
    import CreditDecisions from '../../components/CreditDecisions.svelte';
    import { customerFieldValueLabel, customerSections } from '../../customerFields.js';

    let { review } = $props();

    const form = useForm({ decision: '', notes: '' });

    // The officer decides on the customer's finances and loan, so those are shown in full.
    const sections = customerSections.filter((section) => ['financials', 'loan'].includes(section.key));

    function submit(event) {
        event.preventDefault();
        form.post(`/credit/reviews/${review.id}`, { preserveScroll: true });
    }
</script>

<main class="mx-auto max-w-2xl px-4 py-12">
    <Link href="/credit/reviews" class="text-sm text-slate-500 hover:text-slate-700">← Credit reviews</Link>
    <h1 class="mt-2 text-3xl font-bold tracking-tight">Loan application: {review.customer.first_name} {review.customer.last_name}</h1>
    <p class="mt-1 text-slate-500">{review.customer.email} · {review.customer.phone}</p>

    <CreditAssessment {review} />

    {#if review.decisions.length > 0}
        <CreditDecisions title="Earlier decisions" decisions={review.decisions} />
    {/if}

    {#each sections as section (section.key)}
        <section class="mt-6 rounded-xl bg-white p-5 ring-1 ring-slate-200">
            <h2 class="font-semibold">{section.title}</h2>
            <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-sm">
                {#each section.fields as field (field.name)}
                    <dt class="text-slate-500">{field.label}</dt>
                    <dd class="break-words">{customerFieldValueLabel(field.name, review.customer[field.name]) ?? '—'}</dd>
                {/each}
                {#if section.key === 'loan' && review.loan_repayment !== null}
                    <dt class="text-slate-500">Estimated repayment</dt>
                    <dd>{review.loan_repayment.toFixed(2)} a month</dd>
                {/if}
            </dl>
        </section>
    {/each}

    <form class="mt-6 space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" onsubmit={submit}>
        <fieldset>
            <legend class="font-semibold">Decision</legend>
            <div class="mt-2 flex gap-3">
                <label class="flex flex-1 cursor-pointer items-start gap-2 rounded-lg p-3 ring-1 ring-slate-300 has-checked:bg-green-50 has-checked:ring-green-400">
                    <input type="radio" name="decision" value="approved" bind:group={form.decision} class="mt-1" />
                    <span>
                        <span class="block font-medium">Approve</span>
                        <span class="block text-sm text-slate-500">Lend to this customer.</span>
                    </span>
                </label>
                <label class="flex flex-1 cursor-pointer items-start gap-2 rounded-lg p-3 ring-1 ring-slate-300 has-checked:bg-red-50 has-checked:ring-red-400">
                    <input type="radio" name="decision" value="rejected" bind:group={form.decision} class="mt-1" />
                    <span>
                        <span class="block font-medium">Reject</span>
                        <span class="block text-sm text-slate-500">Decline the application.</span>
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
