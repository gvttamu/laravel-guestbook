import { createInertiaApp } from '@inertiajs/svelte';
import AppLayout from './layouts/AppLayout.svelte';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.svelte', { eager: true });

        return pages[`./pages/${name}.svelte`];
    },
    // Signed-in pages share the navigation bar; the guestbook and sign-in page don't.
    layout: (name) => (name === 'Inbox' || name.startsWith('customers/') || name.startsWith('compliance/') || name.startsWith('workflows/') ? AppLayout : undefined),
});
