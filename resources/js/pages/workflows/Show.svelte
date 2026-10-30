<script>
    import { Link, page } from '@inertiajs/svelte';
    import { untrack } from 'svelte';

    // `diagram` is Mermaid flowchart source generated from the workflow's state machine; it numbers
    // places in the order of `places` (place0, place1, ...). `run.history` is each place the run has
    // been in, oldest first, starting where every run starts.
    let { title, description, diagram, places, run } = $props();

    const history = $derived(run?.history ?? []);

    // The step shown: the run's latest unless a replay or a click on the timeline picks another.
    let frame = $state(untrack(() => (run?.history.length ?? 1) - 1));
    let playing = $state(false);

    const STEP_MILLISECONDS = 1200;

    $effect(() => {
        if (!playing) {
            return;
        }

        const timer = setInterval(() => {
            if (frame >= history.length - 1) {
                playing = false;
            } else {
                frame++;
            }
        }, STEP_MILLISECONDS);

        return () => clearInterval(timer);
    });

    function replay() {
        frame = 0;
        playing = true;
    }

    function show(index) {
        playing = false;
        frame = Math.max(0, Math.min(history.length - 1, index));
    }

    // Places the run had already passed through are greyed; the place it's at in this step is highlighted.
    const source = $derived.by(() => {
        if (!run) {
            return diagram;
        }

        const current = history[frame].place;
        const visited = new Set(history.slice(0, frame).map((step) => step.place));
        visited.delete(current);

        return [
            diagram,
            ...[...visited].map((place) => `style place${places.indexOf(place)} fill:#f1f5f9,stroke:#94a3b8`),
            `style place${places.indexOf(current)} fill:#eef2ff,stroke:#4f46e5,stroke-width:3px`,
        ].join('\n');
    });

    let svg = $state(null);
    let failed = $state(false);
    let mermaidModule;
    let renders = 0;

    // Mermaid needs the browser, so the diagram is drawn once the page has mounted, and redrawn for each step.
    $effect(() => {
        const text = source;
        let cancelled = false;

        (mermaidModule ??= import('mermaid').then(({ default: mermaid }) => {
            mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: 'neutral', fontFamily: 'inherit' });

            return mermaid;
        }))
            .then((mermaid) => mermaid.render(`workflow-diagram-${++renders}`, text))
            .then((rendered) => {
                if (!cancelled) {
                    svg = rendered.svg;
                }
            })
            .catch(() => {
                if (!cancelled) {
                    failed = true;
                }
            });

        return () => (cancelled = true);
    });

    // Server-rendered timestamps are shown in UTC, then switch to the visitor's locale and time zone once mounted.
    let mounted = $state(false);

    $effect(() => {
        mounted = true;
    });

    function formatTimestamp(timestamp) {
        const date = new Date(timestamp);

        return mounted ? date.toLocaleString() : date.toLocaleString('en-US', { timeZone: 'UTC', timeZoneName: 'short' });
    }

    const buttonClass =
        'rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent';
</script>

<main class="mx-auto max-w-5xl px-4 py-12">
    {#if run && page.props.auth.user?.canCaptureCustomers}
        <Link href={`/customers?customer=${run.customer.id}`} class="text-sm text-slate-500 hover:text-slate-700">← {run.customer.first_name} {run.customer.last_name}</Link>
    {/if}

    <h1 class="mt-2 text-3xl font-bold tracking-tight">{title}</h1>
    <p class="mt-2 max-w-3xl text-slate-600">{description}</p>

    {#if run}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">
                Run #{run.id} for {run.customer.first_name} {run.customer.last_name}, step {frame + 1} of {history.length}:
                <span class="font-medium">{history[frame].label}</span>
            </p>
            <div class="flex gap-2">
                <button type="button" class={buttonClass} onclick={() => show(frame - 1)} disabled={frame === 0} aria-label="Previous step">‹</button>
                {#if playing}
                    <button type="button" class={buttonClass} onclick={() => (playing = false)}>Pause</button>
                {:else}
                    <button type="button" class={buttonClass} onclick={replay} disabled={history.length < 2}>Replay</button>
                {/if}
                <button type="button" class={buttonClass} onclick={() => show(frame + 1)} disabled={frame === history.length - 1} aria-label="Next step">›</button>
            </div>
        </div>
    {/if}

    <div class="mt-4 overflow-x-auto rounded-xl bg-white p-6 ring-1 ring-slate-200">
        {#if svg}
            <div class="flex justify-center">{@html svg}</div>
        {:else if failed}
            <p class="text-sm text-red-600">The diagram couldn’t be drawn.</p>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{source}</pre>
        {:else}
            <div class="h-40 animate-pulse rounded-lg bg-slate-100" aria-label="Drawing the diagram…"></div>
        {/if}
    </div>

    {#if run}
        <section class="mt-6 rounded-xl bg-white p-5 ring-1 ring-slate-200" aria-labelledby="steps-heading">
            <h2 id="steps-heading" class="font-semibold">Steps this run took</h2>
            <ol class="mt-3 space-y-1">
                {#each history as step, index (index)}
                    <li>
                        <button
                            type="button"
                            onclick={() => show(index)}
                            aria-current={index === frame ? 'step' : undefined}
                            class="flex w-full flex-wrap items-baseline gap-x-3 rounded-lg px-3 py-2 text-left text-sm {index === frame
                                ? 'bg-indigo-50 ring-1 ring-indigo-200'
                                : index < frame
                                  ? 'text-slate-500 hover:bg-slate-50'
                                  : 'hover:bg-slate-50'}"
                        >
                            <span class="font-medium">{index + 1}. {step.label}</span>
                            {#if step.transition}
                                <span class="text-slate-500">{step.transition}{step.by ? ` · ${step.by}` : ''}</span>
                            {:else}
                                <span class="text-slate-500">Run started</span>
                            {/if}
                            <time datetime={step.at} class="ml-auto text-slate-500">{formatTimestamp(step.at)}</time>
                            {#if step.reconstructed}
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600" title="Rebuilt from the audit log; the time is when the entry was made">
                                    from audit log
                                </span>
                            {/if}
                        </button>
                    </li>
                {/each}
            </ol>
            {#if history.at(-1).place !== run.status}
                <p class="mt-3 text-sm text-slate-500">
                    The run is now <span class="font-medium">{run.status}</span>, but the steps after “{history.at(-1).label}” weren’t recorded. It ran in the old
                    workflow engine, which logged less.
                </p>
            {/if}
        </section>
    {/if}

    <p class="mt-4 text-sm text-slate-500">
        Drawn from the state machine the app runs, so it always matches the code. Rounded boxes are steps; arrows are the transitions between them.
    </p>
</main>
