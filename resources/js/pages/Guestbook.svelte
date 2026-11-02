<script>
    import { Form } from '@inertiajs/svelte';
    import FlashMessage from '../components/FlashMessage.svelte';

    let { entries } = $props();

    const inputClass = (error) =>
        `w-full rounded-lg border px-3 py-2 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none ${error ? 'border-red-400' : 'border-slate-300'}`;

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

<FlashMessage />

<main class="mx-auto max-w-xl px-4 py-12">
    <h1 class="text-3xl font-bold tracking-tight">Guestbook</h1>
    <p class="mt-1 text-slate-500">Leave a note. Served from Laravel.</p>

    <Form action="/" method="post" resetOnSuccess class="mt-8 space-y-3 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        {#snippet children({ errors, processing })}
            <div>
                <input
                    name="name"
                    placeholder="Your name"
                    aria-label="Your name"
                    aria-invalid={errors.name ? 'true' : undefined}
                    aria-describedby={errors.name ? 'name-error' : undefined}
                    class={inputClass(errors.name)}
                />
                {#if errors.name}
                    <p id="name-error" class="mt-1 text-sm text-red-600">{errors.name}</p>
                {/if}
            </div>
            <div>
                <textarea
                    name="message"
                    rows="3"
                    placeholder="Say something nice"
                    aria-label="Message"
                    aria-invalid={errors.message ? 'true' : undefined}
                    aria-describedby={errors.message ? 'message-error' : undefined}
                    class={inputClass(errors.message)}
                ></textarea>
                {#if errors.message}
                    <p id="message-error" class="mt-1 text-sm text-red-600">{errors.message}</p>
                {/if}
            </div>

            <button
                disabled={processing}
                class="rounded-lg bg-brand px-4 py-2 font-medium text-white transition hover:opacity-90 active:scale-[0.98] disabled:opacity-60"
            >
                Sign
            </button>
        {/snippet}
    </Form>

    <ul class="mt-8 space-y-3">
        {#each entries as entry (entry.id)}
            <li class="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                <p class="whitespace-pre-line">{entry.message}</p>
                <p class="mt-2 text-xs text-slate-500">
                    — {entry.name}, <time datetime={entry.created_at}>{formatTimestamp(entry.created_at)}</time>
                </p>
            </li>
        {:else}
            <li class="text-slate-500">No entries yet. Be the first!</li>
        {/each}
    </ul>
</main>
