<script>
    import DatePickerField from './DatePickerField.svelte';

    // One field of the customer form, described by an entry in customerFields.js.
    let { field, value = $bindable(), error } = $props();

    const inputClass = $derived(
        `mt-1 w-full rounded-lg border px-3 py-2 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none ${error ? 'border-red-400' : 'border-slate-300'}`,
    );
</script>

{#if field.type === 'date'}
    <DatePickerField {field} bind:value {error} />
{:else}
    <div>
        <label for={field.name} class="block text-sm font-medium text-slate-700">
            {field.label}
            {#if !field.required}<span class="font-normal text-slate-400">(optional)</span>{/if}
        </label>

        {#if field.type === 'select'}
            <select id={field.name} name={field.name} bind:value aria-invalid={error ? 'true' : undefined} class="{inputClass} bg-white">
                <option value="" disabled>Choose…</option>
                {#each field.options as option (option.value)}
                    <option value={option.value}>{option.label}</option>
                {/each}
            </select>
        {:else if field.type === 'textarea'}
            <textarea id={field.name} name={field.name} rows="3" bind:value aria-invalid={error ? 'true' : undefined} class={inputClass}></textarea>
        {:else}
            <input
                id={field.name}
                name={field.name}
                type={field.type ?? 'text'}
                autocomplete={field.autocomplete}
                {...field.attributes}
                bind:value
                aria-invalid={error ? 'true' : undefined}
                class={inputClass}
            />
        {/if}

        {#if error}
            <p class="mt-1 text-sm text-red-600">{error}</p>
        {:else if field.hint}
            <p class="mt-1 text-sm text-slate-500">{field.hint}</p>
        {/if}
    </div>
{/if}
