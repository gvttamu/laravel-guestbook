<script>
    import { Link, page } from '@inertiajs/svelte';

    let { reviews, decided } = $props();

    const outcomeStyles = {
        approved: 'bg-green-50 text-green-700 ring-green-200',
        rejected: 'bg-red-50 text-red-700 ring-red-200',
    };

    // Officers see applications in their own credit risk tier and every less risky one.
    const tier = $derived(page.props.auth.user?.creditRiskTier);

    const tierStyles = {
        low: 'bg-green-50 text-green-700 ring-green-200',
        medium: 'bg-amber-50 text-amber-700 ring-amber-200',
        high: 'bg-orange-50 text-orange-700 ring-orange-200',
        highest: 'bg-red-50 text-red-700 ring-red-200',
    };

    const formatMoney = (amount) => (amount === null ? '—' : Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

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
    <h1 class="mt-2 text-3xl font-bold tracking-tight">Credit reviews</h1>
    <p class="mt-1 text-slate-500">
        Loan applications{tier ? ` up to ${tier} credit risk` : ''} that the decision engine referred, or an officer reconsidered, riskiest
        first, then oldest first.
    </p>

    <div class="mt-8 overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Credit risk</th>
                    <th class="px-4 py-3 font-medium">Customer</th>
                    <th class="px-4 py-3 font-medium">Loan</th>
                    <th class="px-4 py-3 font-medium">Affordability</th>
                    <th class="px-4 py-3 font-medium">Credit score</th>
                    <th class="px-4 py-3 font-medium">Waiting since</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                {#each reviews as review (review.id)}
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {tierStyles[review.credit_risk_tier] ?? 'bg-slate-50 text-slate-700 ring-slate-200'}">
                                {review.credit_risk_tier ?? 'unrated'}
                            </span>
                            <span class="block pt-1 text-slate-500">{review.credit_risk_points ?? '—'} points</span>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{review.customer.first_name} {review.customer.last_name}</p>
                            <p class="text-slate-500">{review.customer.email}</p>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap tabular-nums">
                            {formatMoney(review.loan_amount)}
                            <span class="block text-slate-500">over {review.loan_term_months ?? '—'} months</span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{review.score ?? '—'} · {review.risk_band ?? '—'} risk</td>
                        <td class="px-4 py-3 whitespace-nowrap">{review.credit_score ?? 'No credit file'}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-slate-500">
                            <time datetime={review.referred_at}>{formatTimestamp(review.referred_at)}</time>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link href={`/credit/reviews/${review.id}`} class="font-medium text-brand hover:underline">Review</Link>
                        </td>
                    </tr>
                {:else}
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-slate-500">No loan applications are waiting for review.</td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>

    <h2 class="mt-12 text-xl font-semibold tracking-tight">Decided applications</h2>
    <p class="mt-1 text-slate-500">The most recently decided, automatically or by an officer. Open one to reconsider it.</p>

    <div class="mt-4 overflow-x-auto rounded-xl bg-white ring-1 ring-slate-200">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-slate-500">
                <tr>
                    <th class="px-4 py-3 font-medium">Outcome</th>
                    <th class="px-4 py-3 font-medium">Customer</th>
                    <th class="px-4 py-3 font-medium">Loan</th>
                    <th class="px-4 py-3 font-medium">Credit risk</th>
                    <th class="px-4 py-3 font-medium">Decided</th>
                    <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                {#each decided as application (application.id)}
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {outcomeStyles[application.status]}">{application.status}</span>
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{application.customer.first_name} {application.customer.last_name}</p>
                            <p class="text-slate-500">{application.customer.email}</p>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap tabular-nums">{formatMoney(application.loan_amount)}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{application.credit_risk_tier ?? 'unrated'}</td>
                        <td class="px-4 py-3 text-slate-500">
                            <time datetime={application.decided_at} class="whitespace-nowrap">{formatTimestamp(application.decided_at)}</time>
                            <span class="block">by {application.decided_by ?? 'the decision engine'}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Link href={`/credit/reviews/${application.id}`} class="font-medium text-brand hover:underline">View</Link>
                        </td>
                    </tr>
                {:else}
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-slate-500">No loan applications have been decided yet.</td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</main>
