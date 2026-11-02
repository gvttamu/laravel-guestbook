<script>
    import { Form } from '@inertiajs/svelte';
    import FlashMessage from '../../components/FlashMessage.svelte';

    const inputClass = (error) =>
        `mt-1 w-full rounded-lg border px-3 py-2 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none ${error ? 'border-red-400' : 'border-slate-300'}`;
</script>

<FlashMessage />

<main class="mx-auto max-w-sm px-4 py-16">
    <h1 class="text-3xl font-bold tracking-tight">Sign in</h1>
    <p class="mt-1 text-slate-500">Sign in to manage customers.</p>

    <!-- Wrong credentials come back as a flashed general error rather than a field error, so the password is cleared on that too. -->
    <Form
        action="/login"
        method="post"
        resetOnError={['password']}
        resetOnSuccess={['password']}
        class="mt-8 space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
    >
        {#snippet children({ errors, processing })}
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    aria-invalid={errors.email ? 'true' : undefined}
                    aria-describedby={errors.email ? 'email-error' : undefined}
                    class={inputClass(errors.email)}
                />
                {#if errors.email}
                    <p id="email-error" class="mt-1 text-sm text-red-600">{errors.email}</p>
                {/if}
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    aria-invalid={errors.password ? 'true' : undefined}
                    aria-describedby={errors.password ? 'password-error' : undefined}
                    class={inputClass(errors.password)}
                />
                {#if errors.password}
                    <p id="password-error" class="mt-1 text-sm text-red-600">{errors.password}</p>
                {/if}
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300" />
                Keep me signed in
            </label>

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
