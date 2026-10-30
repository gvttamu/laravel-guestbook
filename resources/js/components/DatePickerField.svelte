<script>
    import { untrack } from 'svelte';

    // A date typed into a text box or picked from a calendar. The calendar has month and year dropdowns, so distant years
    // (like a date of birth) are one choice away instead of many clicks or scrolls in the browser's own picker. The value is 'YYYY-MM-DD'.
    // `field.pastOnly` limits choices to dates before today.
    let { field, value = $bindable(), error } = $props();

    const pad = (number) => String(number).padStart(2, '0');
    const toIso = (year, month, day) => `${year}-${pad(month + 1)}-${pad(day)}`;

    const now = new Date();
    const today = toIso(now.getFullYear(), now.getMonth(), now.getDate());
    const oldestYear = 1900;

    const monthNames = Array.from({ length: 12 }, (_, index) =>
        new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2000, index, 1))),
    );
    const weekdays = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
    const years = Array.from({ length: now.getFullYear() - oldestYear + 1 }, (_, index) => now.getFullYear() - index);

    let open = $state(false);
    let viewYear = $state(now.getFullYear());
    let viewMonth = $state(now.getMonth());
    let root;
    let trigger;

    const selected = $derived(/^\d{4}-\d{2}-\d{2}$/.test(value ?? '') ? value : null);

    function formatDate(iso) {
        const [year, month, day] = iso.split('-').map(Number);

        return `${day} ${monthNames[month - 1]} ${year}`;
    }

    // Typed dates are day first: 10/12/1990, 10-12-1990, 10 Dec 1990 and 10 December 1990 all work, as does 1990-12-10.
    // Returns null for anything else, including dates that don't exist.
    function parse(input) {
        let match;
        let day;
        let month;
        let year;

        if ((match = input.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/))) {
            [year, month, day] = match.slice(1).map(Number);
        } else if ((match = input.match(/^(\d{1,2})[\s/.-]+(\d{1,2})[\s/.-]+(\d{4})$/))) {
            [day, month, year] = match.slice(1).map(Number);
        } else if ((match = input.match(/^(\d{1,2})\s+([a-z]{3,})\.?,?\s+(\d{4})$/i))) {
            day = Number(match[1]);
            month = monthNames.findIndex((name) => name.toLowerCase().startsWith(match[2].toLowerCase())) + 1;
            year = Number(match[3]);
        } else {
            return null;
        }

        const date = new Date(Date.UTC(year, month - 1, day));

        return month >= 1 && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? toIso(year, month - 1, day) : null;
    }

    const notADate = 'Enter a real date, like 10/12/1990 or 10 December 1990.';

    // What's in the text box. A draft can hold text that isn't a date yet; it's shown as typed, with the problem.
    // Only the starting value is read; after that the text box leads and updates the value.
    let text = $state(untrack(() => (selected ? formatDate(selected) : (value ?? ''))));
    let problem = $state(untrack(() => (value && !selected ? notADate : null)));

    function pastOnlyProblem(iso) {
        return field.pastOnly && iso >= today ? `${field.label} must be in the past.` : null;
    }

    // The form autosaves on bubbling input events, so announce the change the same way a native input would.
    function setValue(newValue) {
        if (newValue !== value) {
            value = newValue;
            root.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    // Typed text is only read once the person leaves the box (or presses Enter), so half-typed dates aren't flagged.
    // Text that isn't a date is still passed on, so the draft keeps it and the customer can't be saved until it's fixed.
    function commitText() {
        const typed = text.trim();
        const iso = typed === '' ? '' : parse(typed);

        if (iso === null) {
            problem = notADate;
            setValue(typed);

            return;
        }

        problem = iso === '' ? null : pastOnlyProblem(iso);
        text = iso === '' ? '' : formatDate(iso);
        setValue(iso);
    }

    function commitOnEnter(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            commitText();
        }
    }

    // Monday-first weeks, padded with blanks before the 1st.
    const days = $derived.by(() => {
        const leadingBlanks = (new Date(viewYear, viewMonth, 1).getDay() + 6) % 7;
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

        return [
            ...Array.from({ length: leadingBlanks }, () => null),
            ...Array.from({ length: daysInMonth }, (_, index) => {
                const iso = toIso(viewYear, viewMonth, index + 1);

                return { day: index + 1, iso, disabled: field.pastOnly && iso >= today };
            }),
        ];
    });

    const canGoBack = $derived(viewYear > oldestYear || viewMonth > 0);
    const canGoForward = $derived(viewYear < now.getFullYear() || viewMonth < 11);

    function show() {
        const [year, month] = (selected ?? today).split('-').map(Number);
        viewYear = year;
        viewMonth = month - 1;
        open = true;
    }

    function close({ refocus = false } = {}) {
        open = false;

        if (refocus) {
            trigger.focus();
        }
    }

    function closeOnEscape(event) {
        if (open && event.key === 'Escape') {
            event.preventDefault();
            close({ refocus: true });
        }
    }

    function shiftMonth(by) {
        const date = new Date(viewYear, viewMonth + by, 1);
        viewYear = date.getFullYear();
        viewMonth = date.getMonth();
    }

    function choose(iso) {
        text = formatDate(iso);
        problem = null;
        setValue(iso);
        close({ refocus: true });
    }

    function focusCalendar(node) {
        (node.querySelector('[aria-pressed="true"]') ?? node.querySelector('select'))?.focus();
    }

    $effect(() => {
        if (!open) {
            return;
        }

        const closeWhenOutside = (event) => {
            if (!root.contains(event.target)) {
                close();
            }
        };

        document.addEventListener('pointerdown', closeWhenOutside);

        return () => document.removeEventListener('pointerdown', closeWhenOutside);
    });

    // A problem spotted here is clearer than the server's message for the same text, so it wins.
    const message = $derived(problem ?? error);

    const navButtonClass = 'rounded-md p-1.5 text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent';
    const selectClass = 'rounded-md border border-slate-300 bg-white px-2 py-1 text-sm focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none';
</script>

<div bind:this={root} class="relative" onkeydown={closeOnEscape} role="presentation">
    <label for={field.name} class="block text-sm font-medium text-slate-700">
        {field.label}
        {#if !field.required}<span class="font-normal text-slate-400">(optional)</span>{/if}
    </label>

    <div class="relative mt-1">
        <input
            id={field.name}
            name={field.name}
            type="text"
            placeholder="For example, 10/12/1990"
            bind:value={text}
            onblur={commitText}
            onkeydown={commitOnEnter}
            aria-invalid={message ? 'true' : undefined}
            aria-describedby={message ? `${field.name}-error` : undefined}
            class="w-full rounded-lg border px-3 py-2 pr-11 focus:border-brand focus:ring-2 focus:ring-brand/30 focus:outline-none {message
                ? 'border-red-400'
                : 'border-slate-300'}"
        />
        <button
            bind:this={trigger}
            type="button"
            aria-label="Choose {field.label.toLowerCase()} from a calendar"
            aria-haspopup="dialog"
            aria-expanded={open}
            onclick={() => (open ? close() : show())}
            class="absolute inset-y-0 right-0 flex items-center rounded-r-lg px-3 text-slate-400 hover:text-slate-600 focus:text-brand focus:outline-none"
        >
            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>
    </div>

    {#if open}
        <div role="dialog" aria-label="Choose {field.label.toLowerCase()}" class="absolute z-10 mt-2 w-72 rounded-xl bg-white p-3 shadow-lg ring-1 ring-slate-200" use:focusCalendar>
            <div class="flex items-center gap-1">
                <button type="button" class={navButtonClass} aria-label="Previous month" disabled={!canGoBack} onclick={() => shiftMonth(-1)}>‹</button>
                <select aria-label="Month" bind:value={viewMonth} class="{selectClass} min-w-0 flex-1 pr-8">
                    {#each monthNames as name, index (name)}
                        <option value={index}>{name}</option>
                    {/each}
                </select>
                <select aria-label="Year" bind:value={viewYear} class="{selectClass} w-24 shrink-0 pr-8">
                    {#each years as year (year)}
                        <option value={year}>{year}</option>
                    {/each}
                </select>
                <button type="button" class={navButtonClass} aria-label="Next month" disabled={!canGoForward} onclick={() => shiftMonth(1)}>›</button>
            </div>

            <div class="mt-3 grid grid-cols-7 gap-1 text-center text-xs text-slate-500" aria-hidden="true">
                {#each weekdays as weekday (weekday)}
                    <span>{weekday}</span>
                {/each}
            </div>
            <div class="mt-1 grid grid-cols-7 gap-1 text-center text-sm">
                {#each days as cell, index (cell?.iso ?? `blank-${index}`)}
                    {#if cell}
                        <button
                            type="button"
                            aria-label="{cell.day} {monthNames[viewMonth]} {viewYear}"
                            aria-pressed={cell.iso === selected}
                            disabled={cell.disabled}
                            onclick={() => choose(cell.iso)}
                            class="rounded-md py-1.5 tabular-nums disabled:text-slate-300 disabled:hover:bg-transparent {cell.iso === selected
                                ? 'bg-brand font-semibold text-white'
                                : cell.iso === today
                                  ? 'font-semibold text-brand hover:bg-slate-100'
                                  : 'hover:bg-slate-100'}"
                        >
                            {cell.day}
                        </button>
                    {:else}
                        <span></span>
                    {/if}
                {/each}
            </div>
        </div>
    {/if}

    {#if message}
        <p id="{field.name}-error" class="mt-1 text-sm text-red-600">{message}</p>
    {/if}
</div>
