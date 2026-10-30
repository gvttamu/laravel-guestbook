<script>
    import { router, useForm, useHttp } from '@inertiajs/svelte';
    import CustomerField from '../../components/CustomerField.svelte';
    import { customerSections as steps } from '../../customerFields.js';

    // `customer` is the customer as currently saved when the draft edits one, or null for a new customer.
    let { draft, customer, step } = $props();

    const allFields = steps.flatMap((s) => s.fields.map((field) => ({ ...field, step: s })));
    const currentStep = $derived(steps.find((s) => s.key === step));
    const stepIndex = $derived(steps.indexOf(currentStep));

    // Holds the field values; also used to submit, so submission errors land in form.errors.
    const form = useForm(Object.fromEntries(allFields.map((field) => [field.name, draft.payload[field.name] ?? ''])));

    // Autosave sends the whole draft plus the version it was based on (optimistic locking).
    let version = draft.version;
    const autosave = useHttp({}).transform(() => ({ ...form.data(), version, current_step: step }));

    /** @type {'saved' | 'pending' | 'saving' | 'invalid' | 'error' | 'conflict'} */
    let status = $state('saved');
    let lastSavedAt = $state(null);
    let discarding = false;

    let timer;
    let edits = 0;
    let inFlight = null;
    let saveAgain = false;

    function scheduleSave() {
        if (status === 'conflict' || discarding) {
            return;
        }

        edits++;
        status = 'pending';
        clearTimeout(timer);
        timer = setTimeout(save, 800);
    }

    // Only one save is in flight at a time, so responses can never arrive out of order.
    // Changes made while a save is running are sent by a follow-up save.
    function save() {
        clearTimeout(timer);

        if (status === 'conflict' || discarding) {
            return Promise.resolve(false);
        }

        if (inFlight) {
            saveAgain = true;

            return inFlight;
        }

        inFlight = saveUntilCurrent().finally(() => (inFlight = null));

        return inFlight;
    }

    async function saveUntilCurrent() {
        do {
            saveAgain = false;
            const editsSent = edits;
            status = 'saving';

            try {
                const response = await autosave.patch(`/customer-drafts/${draft.id}`);

                if (!response) {
                    status = 'invalid';

                    return false;
                }

                version = response.version;
                lastSavedAt = new Date();
                status = edits === editsSent ? 'saved' : 'pending';
            } catch (error) {
                if (error.response?.status === 409) {
                    status = 'conflict';

                    return false;
                }

                status = 'error';
                timer = setTimeout(save, 5000);

                return false;
            }
        } while (saveAgain);

        return true;
    }

    async function flush() {
        if (status === 'saved' && !inFlight) {
            return true;
        }

        return (await save()) && status === 'saved';
    }

    async function visit(url) {
        if (await flush()) {
            router.visit(url);
        }
    }

    const sameValue = (a, b) => String(a ?? '') === String(b ?? '');

    const isComplete = $derived(allFields.every((field) => !field.required || String(form[field.name] ?? '').trim() !== ''));

    // Compared with the details when editing began, so a resumed draft still counts its earlier changes.
    const hasChanges = $derived(customer === null || allFields.some((field) => !sameValue(form[field.name], draft.original_payload[field.name])));

    // Someone else has saved the customer since this draft started, so it can't be saved over their changes.
    const isOutOfDate = $derived(customer !== null && customer.version !== draft.customer_version);
    const changedByOthers = $derived(isOutOfDate ? allFields.filter((field) => !sameValue(customer[field.name], draft.original_payload[field.name])) : []);

    async function submit() {
        if (!(await flush())) {
            return;
        }

        form.transform(() => ({})).post(`/customer-drafts/${draft.id}/submit`, { preserveState: true });
    }

    function discard({ reopen = false } = {}) {
        if (!reopen && !confirm(customer ? 'Discard your changes to this customer?' : 'Discard this new customer?')) {
            return;
        }

        discarding = true;
        clearTimeout(timer);
        router.delete(`/customer-drafts/${draft.id}`, { data: { reopen } });
    }

    const errorFor = (name) => form.errors[name] ?? autosave.errors[name];

    const otherStepErrors = $derived(
        allFields.filter((field) => field.step.key !== step && form.errors[field.name]).map((field) => ({ ...field, message: form.errors[field.name] })),
    );

    const statusText = $derived(
        {
            saved: lastSavedAt ? `Draft saved at ${lastSavedAt.toLocaleTimeString()}` : 'Draft saved',
            pending: 'Unsaved changes',
            saving: 'Saving draft…',
            invalid: 'Not saved: fix the highlighted fields',
            error: 'Couldn’t save. Retrying…',
            conflict: 'This draft was changed in another tab or window.',
        }[status],
    );

    $effect(() => {
        const warnAboutUnsavedChanges = (event) => {
            if (status !== 'saved' && !discarding) {
                event.preventDefault();
            }
        };

        window.addEventListener('beforeunload', warnAboutUnsavedChanges);

        return () => {
            window.removeEventListener('beforeunload', warnAboutUnsavedChanges);
            clearTimeout(timer);
        };
    });
</script>

<main class="mx-auto max-w-xl px-4 py-12">
    <button type="button" class="text-sm text-slate-500 hover:text-slate-700" onclick={() => visit(customer ? `/customers?customer=${customer.id}` : '/customers')}>
        ← Customers
    </button>

    <h1 class="mt-2 text-3xl font-bold tracking-tight">
        {customer ? `Edit ${draft.original_payload.first_name} ${draft.original_payload.last_name}` : 'New customer'}
    </h1>

    {#if customer}
        <p class="mt-1 text-sm text-slate-500">Changes are kept as a draft until you save them.</p>
    {/if}

    <ol class="mt-6 flex gap-2 text-sm">
        {#each steps as s, index (s.key)}
            <li class="flex-1">
                <button
                    type="button"
                    onclick={() => visit(`/customer-drafts/${draft.id}/${s.key}`)}
                    aria-current={s.key === step ? 'step' : undefined}
                    class="w-full border-t-4 pt-2 text-left {s.key === step ? 'border-brand font-medium text-slate-900' : 'border-slate-200 text-slate-500 hover:text-slate-700'}"
                >
                    {index + 1}. {s.title}
                    {#if allFields.some((field) => field.step.key === s.key && form.errors[field.name])}
                        <span class="ml-1 inline-block size-2 rounded-full bg-red-500" title="This step has errors"></span>
                        <span class="sr-only">(has errors)</span>
                    {/if}
                </button>
            </li>
        {/each}
    </ol>

    {#if status === 'conflict'}
        <div class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200">
            This draft was changed in another tab or window, so your latest changes here weren’t saved.
            <button type="button" class="font-medium underline" onclick={() => window.location.reload()}>Reload the latest version</button>
        </div>
    {/if}

    {#if isOutOfDate}
        <div class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200" role="alert">
            <p>Someone else saved changes to this customer after you started editing, so this draft can’t be saved.</p>
            {#if changedByOthers.length}
                <p class="mt-1">They changed: {changedByOthers.map((field) => field.label).join(', ')}.</p>
            {/if}
            <p class="mt-2">
                <button type="button" class="font-medium underline" onclick={() => discard({ reopen: true })}>Start again from the latest details</button>
                and make your changes again. This draft will be discarded.
            </p>
        </div>
    {/if}

    {#if otherStepErrors.length}
        <div class="mt-6 rounded-lg bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200">
            <p class="font-medium">Please fix these before {customer ? 'saving' : 'submitting'}:</p>
            <ul class="mt-1 list-disc pl-5">
                {#each otherStepErrors as field (field.name)}
                    <li>
                        <button type="button" class="underline" onclick={() => visit(`/customer-drafts/${draft.id}/${field.step.key}`)}>
                            {field.step.title}
                        </button>: {field.message}
                    </li>
                {/each}
            </ul>
        </div>
    {/if}

    <form
        class="mt-6 space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
        oninput={scheduleSave}
        onsubmit={(event) => {
            event.preventDefault();
            submit();
        }}
    >
        {#if currentStep.description}
            <p class="text-sm text-slate-500">{currentStep.description}</p>
        {/if}

        {#each currentStep.fields as field (field.name)}
            <CustomerField {field} bind:value={form[field.name]} error={errorFor(field.name)} />
        {/each}

        <div class="flex items-center justify-between gap-4 border-t border-slate-100 pt-4">
            <div class="text-sm">
                <p class={status === 'error' || status === 'invalid' || status === 'conflict' ? 'text-red-600' : 'text-slate-500'} aria-live="polite">
                    {statusText}
                </p>
                <button type="button" class="font-medium text-slate-500 hover:text-red-600" onclick={() => discard()}>
                    {customer ? 'Discard changes' : 'Discard draft'}
                </button>
            </div>

            <div class="flex gap-2">
                {#if stepIndex > 0}
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50"
                        onclick={() => visit(`/customer-drafts/${draft.id}/${steps[stepIndex - 1].key}`)}
                    >
                        Back
                    </button>
                {/if}

                {#if stepIndex < steps.length - 1}
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50"
                        onclick={() => visit(`/customer-drafts/${draft.id}/${steps[stepIndex + 1].key}`)}
                    >
                        Next
                    </button>
                {/if}

                <button
                    type="submit"
                    disabled={!isComplete || !hasChanges || isOutOfDate || form.processing || status === 'conflict'}
                    title={!isComplete ? `Fill in all required fields on every step to ${customer ? 'save' : 'submit'}` : !hasChanges ? 'No changes to save' : undefined}
                    class="rounded-lg bg-brand px-4 py-2 font-medium text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {#if customer}
                        {form.processing ? 'Saving…' : 'Save changes'}
                    {:else}
                        {form.processing ? 'Submitting…' : 'Submit'}
                    {/if}
                </button>
            </div>
        </div>
    </form>
</main>
