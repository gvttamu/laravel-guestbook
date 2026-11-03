<script>
    import { Link, useForm } from '@inertiajs/svelte';
    import CreditAssessment from '../../components/CreditAssessment.svelte';
    import CreditDecisions from '../../components/CreditDecisions.svelte';

    // A loan application that isn't waiting for this credit officer: assigned to another one
    // (`assigned_to`), decided, automatically or by an officer, not yet scored, or replaced by a
    // newer run. A decided one that's still the customer's latest can be reconsidered, which
    // reopens it for a decision.
    let { review } = $props();

    const name = $derived(`${review.customer.first_name} ${review.customer.last_name}`);

    const statusStyles = {
        approved: 'bg-green-50 text-green-800 ring-green-200',
        rejected: 'bg-red-50 text-red-800 ring-red-200',
    };

    const form = useForm({ notes: '' });

    function reconsider(event) {
        event.preventDefault();
        form.post(`/credit/reviews/${review.id}/reconsider`, { preserveScroll: true });
    }
</script>

<main class="mx-auto max-w-2xl px-4 py-12">
    <Link href="/credit/reviews" class="text-sm text-slate-500 hover:text-slate-700">← Credit reviews</Link>
    <h1 class="mt-2 text-3xl font-bold tracking-tight">Loan application: {name}</h1>
    <p class="mt-1 text-sm"><Link href={`/customers/${review.customer.id}/case`} class="font-medium text-brand hover:underline">View the full case</Link></p>

    <section class="mt-6 rounded-lg p-4 text-sm ring-1 {statusStyles[review.status] ?? 'bg-slate-50 text-slate-700 ring-slate-200'}" role="status">
        <h2 class="font-semibold">
            {#if review.above_tier}
                This is a {review.credit_risk_tier ?? 'unrated'}-risk application
            {:else if review.assigned_to}
                This application is assigned to {review.assigned_to}
            {:else}
                {review.status_label}
            {/if}
        </h2>
        {#if review.above_tier}
            <p class="mt-1">It’s above your credit risk tier, so it’s for a credit officer with a higher tier to decide.</p>
        {:else if review.assigned_to}
            <p class="mt-1">It’s theirs to approve or reject.</p>
        {:else if review.status === 'superseded'}
            <p class="mt-1">{name}’s financials or loan changed before it was decided, so a new application was scored.</p>
        {:else if review.status === 'pending' || review.status === 'scored'}
            <p class="mt-1">The application hasn’t been decided yet.</p>
        {/if}
    </section>

    <CreditAssessment {review} />

    {#if review.decisions.length > 0}
        <CreditDecisions title="Credit officers’ decisions" decisions={review.decisions} />
    {/if}

    {#if review.can_reconsider}
        <form class="mt-6 space-y-3 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200" onsubmit={reconsider}>
            <div>
                <label for="notes" class="block font-semibold">Reconsider this decision</label>
                <p class="text-sm text-slate-500">This reopens the application for a credit officer to approve or reject.</p>
                <textarea
                    id="notes"
                    rows="3"
                    bind:value={form.notes}
                    placeholder="Why it should be reconsidered"
                    class="mt-2 w-full rounded-lg border px-3 py-2 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none {form.errors.notes
                        ? 'border-red-400'
                        : 'border-slate-300'}"
                ></textarea>
                {#if form.errors.notes}
                    <p class="mt-1 text-sm text-red-600">{form.errors.notes}</p>
                {/if}
            </div>
            <div class="flex justify-end">
                <button
                    type="submit"
                    disabled={form.processing}
                    class="rounded-lg px-4 py-2 font-medium ring-1 ring-slate-300 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {form.processing ? 'Reopening…' : 'Reconsider'}
                </button>
            </div>
        </form>
    {/if}

    <p class="mt-6 text-sm">
        <Link href={`/workflows/financial-scoring?run=${review.id}`} class="font-medium text-brand hover:underline">See every step of this application</Link>
    </p>
</main>
