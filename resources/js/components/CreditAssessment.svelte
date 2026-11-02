<script>
    // How a loan application was scored and what the decision engine made of it: the
    // affordability score and its reasons, the credit bureau's score, the credit risk tier whose
    // officers decide it, and the engine's decision.
    let { review } = $props();

    const riskBandStyles = {
        low: 'bg-green-50 text-green-700 ring-green-200',
        medium: 'bg-amber-50 text-amber-700 ring-amber-200',
        high: 'bg-red-50 text-red-700 ring-red-200',
    };

    const tierStyles = {
        low: 'bg-green-50 text-green-700 ring-green-200',
        medium: 'bg-amber-50 text-amber-700 ring-amber-200',
        high: 'bg-orange-50 text-orange-700 ring-orange-200',
        highest: 'bg-red-50 text-red-700 ring-red-200',
    };

    const engineDecisions = {
        approved: 'Approved automatically',
        rejected: 'Rejected automatically',
        referred: 'Referred to a credit officer',
    };
</script>

<section class="mt-6 grid gap-4 sm:grid-cols-2">
    <div class="rounded-xl bg-white p-5 ring-1 ring-slate-200">
        <h2 class="font-semibold">Affordability</h2>
        <p class="mt-2 text-2xl font-bold tabular-nums">
            {review.score ?? '—'}<span class="text-sm font-normal text-slate-500"> / 100</span>
            {#if review.risk_band}
                <span class="ml-1 rounded-full px-2 py-0.5 align-middle text-xs font-medium ring-1 {riskBandStyles[review.risk_band]}">{review.risk_band} risk</span>
            {/if}
        </p>
        <ul class="mt-2 list-disc pl-5 text-sm text-slate-600">
            {#each review.affordability_reasons as reason (reason)}
                <li>{reason}</li>
            {/each}
        </ul>
    </div>
    <div class="rounded-xl bg-white p-5 ring-1 ring-slate-200">
        <h2 class="font-semibold">Credit score</h2>
        <p class="mt-2 text-2xl font-bold tabular-nums">
            {#if review.credit_score === null}
                <span class="text-base font-medium text-slate-500">No credit file</span>
            {:else}
                {review.credit_score}<span class="text-sm font-normal text-slate-500"> / 850</span>
            {/if}
        </p>
        <p class="mt-2 text-sm text-slate-500">From the {review.credit_bureau ?? 'credit bureau'}</p>
    </div>
</section>

{#if review.credit_risk_tier}
    <section class="mt-6 rounded-xl bg-white p-5 ring-1 ring-slate-200">
        <h2 class="font-semibold">
            Credit risk tier:
            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 {tierStyles[review.credit_risk_tier]}">{review.credit_risk_tier}</span>
            {#if review.credit_risk_points !== null}<span class="text-sm font-normal text-slate-500">· {review.credit_risk_points} points</span>{/if}
        </h2>
        <p class="mt-1 text-sm text-slate-500">Decided by credit officers of this tier or a higher one.</p>
        {#if review.credit_risk_reasons.length > 0}
            <ul class="mt-2 list-disc pl-5 text-sm text-slate-600">
                {#each review.credit_risk_reasons as reason (reason)}
                    <li>{reason}</li>
                {/each}
            </ul>
        {/if}
    </section>
{/if}

{#if review.engine_decision}
    <section class="mt-6 rounded-lg bg-violet-50 p-4 text-sm text-violet-800 ring-1 ring-violet-200">
        <h2 class="font-semibold">Decision engine: {engineDecisions[review.engine_decision]}</h2>
        <ul class="mt-1 list-disc pl-5">
            {#each review.engine_reasons as reason (reason)}
                <li>{reason}</li>
            {/each}
        </ul>
    </section>
{/if}
