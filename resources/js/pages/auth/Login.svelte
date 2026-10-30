<script>
    import { Form } from '@inertiajs/svelte';
</script>

<main class="mx-auto max-w-sm px-4 py-16">
    <h1 class="text-3xl font-bold tracking-tight">Sign in</h1>
    <p class="mt-1 text-slate-500">Sign in to manage customers.</p>

    <Form action="/login" method="post" resetOnError={['password']} class="mt-8 space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        {#snippet children({ errors, processing })}
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none"
                />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none"
                />
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300" />
                Keep me signed in
            </label>

            {#if errors.email || errors.password}
                <p class="text-sm text-red-600" role="alert">{errors.email ?? errors.password}</p>
            {/if}

            <button
                type="submit"
                disabled={processing}
                class="w-full rounded-lg bg-brand px-4 py-2 font-medium text-white transition hover:opacity-90 disabled:opacity-60"
            >
                {processing ? 'Signing in…' : 'Sign in'}
            </button>
        {/snippet}
    </Form>
</main>
