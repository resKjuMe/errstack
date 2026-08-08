import React from 'react';
import { usePage } from '@inertiajs/react';
import Nav from './components/Nav.jsx';
import Flash from './components/Flash.jsx';
import { ToastProvider } from './components/Toast.jsx';

// Persistentes React-Grundgerüst: Wrapper, Navigation, Flash-Meldungen und die
// Toast-Ausgabe. Bleibt über Inertia-Navigationen hinweg gemountet (die Navi
// wird nicht neu aufgebaut); der seitenspezifische Inhalt kommt als children.
// Die Shell-Nutzlast (Links, Menü, Labels) liefert Inertia als Shared-Prop.
export default function AppShell({ children }) {
    const { props, url } = usePage();
    const { shell, flash, errors } = props;

    return (
        <ToastProvider>
            <div className="min-h-screen bg-gray-100 dark:bg-gray-900">
                <Nav shell={shell} />

                {/* key={url} → bei jeder Inertia-Navigation neu gemountet, sodass die
                    Seiten-Einblendung (.es-page-enter) genau einmal läuft. Die Navi
                    liegt außerhalb und bleibt persistent. */}
                <div key={url} className="es-page-enter">
                    <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                        <Flash
                            status={flash?.status}
                            error={flash?.error}
                            errors={errors}
                            undo={flash?.undo}
                            undoHref={shell?.undoHref}
                        />
                        {children}
                    </main>
                </div>
            </div>
        </ToastProvider>
    );
}
