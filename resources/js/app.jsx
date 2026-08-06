import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import AppShell from './shell/AppShell.jsx';
import Dashboard from './shell/pages/Dashboard.jsx';
import Components from './shell/pages/Components.jsx';

// Seiten-Registry: Inertia löst den vom Server gelieferten Seitennamen hier
// auf. Jede Seite wird in die AppShell gehängt (gemeinsames Layout), sofern sie
// nicht selbst ein Layout mitbringt.
const pages = {
    Dashboard,
    Components,
};

createInertiaApp({
    resolve: (name) => {
        const page = pages[name];

        if (!page) {
            throw new Error(`Unbekannte Inertia-Seite: ${name}`);
        }

        page.layout = page.layout ?? ((content) => <AppShell>{content}</AppShell>);

        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#f43f5e' },
});
