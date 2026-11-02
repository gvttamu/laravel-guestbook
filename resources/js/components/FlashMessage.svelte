<script>
    import { page, router } from '@inertiajs/svelte';

    // Shows a general error, one that isn't about a particular field: flashed by the server as
    // `error`, or a request that couldn't reach the server at all. Field errors are shown on
    // their fields instead.
    let networkFailed = $state(false);
    let dismissed = $state(null);

    // Each response brings a new flash, so dismissing one doesn't hide the same message flashed again later.
    const message = $derived(
        networkFailed ? 'Couldn’t reach the server. Check your connection and try again.' : page.flash !== dismissed ? page.flash?.error : null,
    );

    $effect(() => {
        const stopListeningForNetworkErrors = router.on('networkError', () => {
            networkFailed = true;
        });
        const stopListeningForVisits = router.on('start', () => {
            networkFailed = false;
        });

        return () => {
            stopListeningForNetworkErrors();
            stopListeningForVisits();
        };
    });

    function dismiss() {
        networkFailed = false;
        dismissed = page.flash;
    }
</script>

{#if message}
    <div class="fixed inset-x-4 top-4 z-50 mx-auto flex max-w-md items-start gap-3 rounded-lg bg-red-50 p-4 text-sm text-red-800 shadow-lg ring-1 ring-red-200" role="alert">
        <p class="flex-1">{message}</p>
        <button type="button" class="text-red-700 hover:text-red-900" onclick={dismiss} aria-label="Dismiss">✕</button>
    </div>
{/if}
