<script>
    import { Link } from '@inertiajs/svelte';
    import ReviewDecisions from '../../components/ReviewDecisions.svelte';

    // A verification run that isn't waiting for review any more, reached from a link that
    // outlived its review, such as the email that asked for it; or one waiting for review
    // that's assigned to another officer (`assigned_to`).
    let { review } = $props();

    const name = $derived(`${review.customer.first_name} ${review.customer.last_name}`);
    const decided = $derived(review.reviews.length > 0);
</script>

<main class="mx-auto max-w-2xl px-4 py-12">
    <Link href="/compliance/reviews" class="text-sm text-slate-500 hover:text-slate-700">← Compliance reviews</Link>
    <h1 class="mt-2 text-3xl font-bold tracking-tight">Review of {name}</h1>

    <section class="mt-6 rounded-lg bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-200" role="status">
        <h2 class="font-semibold text-slate-900">
            {#if review.assigned_to}
                This review is assigned to {review.assigned_to}
            {:else if decided}
                This review has been completed
            {:else if review.status === 'superseded'}
                This review is no longer needed
            {:else}
                Nothing is waiting for review
            {/if}
        </h2>
        <p class="mt-1">
            {#if review.assigned_to}
                It’s theirs to approve or reject. The check is now: <span class="font-medium">{review.status_label}</span>.
            {:else if !decided && review.status === 'superseded'}
                {name}’s details changed before anyone reviewed the match, so a new check started with the new details.
            {:else}
                The check is now: <span class="font-medium">{review.status_label}</span>.
            {/if}
        </p>
        {#if decided && review.status === 'superseded'}
            <p class="mt-1">{name}’s details have changed since, so a new check started with the new details.</p>
        {/if}
    </section>

    {#if review.assigned_to && decided}
        <ReviewDecisions title="Earlier in this check" reviews={review.reviews} />
    {:else if decided}
        <ReviewDecisions title={review.reviews.length > 1 ? 'Decisions' : 'Decision'} reviews={review.reviews} />
    {/if}

    <p class="mt-6 text-sm">
        <Link href={`/workflows/customer-verification?run=${review.id}`} class="font-medium text-brand hover:underline">See every step of this check</Link>
    </p>
</main>
