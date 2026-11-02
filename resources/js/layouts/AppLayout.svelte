<script>
    import { Link, page } from '@inertiajs/svelte';
    import FlashMessage from '../components/FlashMessage.svelte';

    let { children } = $props();

    const user = $derived(page.props.auth.user);
    const currentUrl = $derived(page.url);

    const navLinkClass = (prefix) =>
        `rounded-md px-3 py-1.5 font-medium ${currentUrl.startsWith(prefix) ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:text-slate-900'}`;
</script>

<header class="border-b border-slate-200 bg-white">
    <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
        <div class="flex items-center gap-1">
            <Link href="/inbox" class={navLinkClass('/inbox')}>
                Inbox
                {#if page.props.inboxCount}
                    <span class="ml-1 rounded-full bg-brand px-1.5 py-0.5 text-xs font-semibold text-white tabular-nums">{page.props.inboxCount}</span>
                    <span class="sr-only">items</span>
                {/if}
            </Link>
            {#if user?.canCaptureCustomers}
                <Link href="/customers" class={navLinkClass('/customers')}>Customers</Link>
            {/if}
            {#if user?.isComplianceOfficer}
                <Link href="/compliance/reviews" class={navLinkClass('/compliance')}>Compliance reviews</Link>
            {/if}
            {#if user?.isCreditOfficer}
                <Link href="/credit/reviews" class={navLinkClass('/credit')}>Credit reviews</Link>
            {/if}
        </div>

        {#if user}
            <div class="flex items-center gap-3">
                <span class="text-slate-600">
                    {user.name}
                    {#if user.isComplianceOfficer}
                        <span class="ml-1 rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-blue-200">Compliance</span>
                    {/if}
                    {#if user.isCreditOfficer}
                        <span class="ml-1 rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-violet-200">Credit{user.creditRiskTier ? ` · ${user.creditRiskTier} risk` : ''}</span>
                    {/if}
                </span>
                <Link href="/logout" method="post" as="button" class="font-medium text-slate-600 hover:text-slate-900">Sign out</Link>
            </div>
        {/if}
    </nav>
</header>

<FlashMessage />

{@render children()}
