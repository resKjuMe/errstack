import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import Sidebar from './components/Sidebar.jsx';
import MobileHeader from './components/MobileHeader.jsx';
import MobileMenu from './components/MobileMenu.jsx';
import Flash from './components/Flash.jsx';
import FilterBar from './components/FilterBar.jsx';
import { ToastProvider } from './components/Toast.jsx';
import useFilter from './filters/useFilter.js';

// Persistentes React-Grundgerüst: Wrapper, Navigation, Flash-Meldungen und die
// Toast-Ausgabe. Bleibt über Inertia-Navigationen hinweg gemountet (die Leiste
// wird nicht neu aufgebaut); der seitenspezifische Inhalt kommt als children.
// Die Shell-Nutzlast (Gruppen, Menü, Labels) liefert Inertia als Shared-Prop.
//
// Die Navigation steht links in einer festen Leiste; auf schmalen Viewports ist
// sie ausgeblendet und über die Kopfzeile erreichbar.

// Ein-/ausgeklappt liegt in localStorage, gelesen beim ersten Rendern —
// dieselbe Konvention wie beim Design (ThemeToggle). Ein Anti-Flash-Script wie
// dort braucht es nicht: die Leiste zeichnet ohnehin erst React.
const COLLAPSE_KEY = 'sidebar';

function initialCollapsed() {
    try {
        return localStorage.getItem(COLLAPSE_KEY) === 'collapsed';
    } catch (e) {
        return false;
    }
}

export default function AppShell({ children }) {
    const { props, url } = usePage();
    const { shell, flash, errors } = props;
    const filter = useFilter();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(initialCollapsed);

    const toggleCollapsed = () => {
        setCollapsed((v) => {
            const next = !v;

            try {
                localStorage.setItem(COLLAPSE_KEY, next ? 'collapsed' : 'expanded');
            } catch (e) {
                /* localStorage evtl. nicht verfügbar */
            }

            return next;
        });
    };

    return (
        <ToastProvider>
            <div className="flex min-h-screen bg-gray-100 dark:bg-gray-900">
                <Sidebar shell={shell} collapsed={collapsed} onToggle={toggleCollapsed} />

                {/* min-w-0: ohne das dehnt eine breite Tabelle die Flex-Spalte
                    auf, statt in sich zu scrollen — und die Seite bekäme einen
                    waagerechten Rollbalken. */}
                <div className="flex min-w-0 flex-1 flex-col">
                    <MobileHeader
                        shell={shell}
                        open={mobileOpen}
                        onToggle={() => setMobileOpen((v) => !v)}
                    />
                    <MobileMenu shell={shell} open={mobileOpen} />

                    {/* key={url} → bei jeder Inertia-Navigation neu gemountet, sodass die
                        Seiten-Einblendung (.es-page-enter) genau einmal läuft. Die Navi
                        liegt außerhalb und bleibt persistent. */}
                    <div key={url} className="es-page-enter">
                        <main className="px-4 py-8 sm:px-6 lg:px-8">
                            <Flash
                                status={flash?.status}
                                error={flash?.error}
                                errors={errors}
                                undo={flash?.undo}
                                undoHref={shell?.undoHref}
                            />
                            {/* Die globale Filterleiste steht hier und nicht in
                                der Seite: dadurch sitzt sie auf jeder
                                Auswertungsseite an derselben Stelle, und eine
                                neue bekommt sie, ohne sie einzubinden. `null`
                                heißt „hier gibt es nichts zu filtern" — dann
                                fehlt sie ganz. */}
                            {filter && <FilterBar filter={filter} />}
                            {children}
                        </main>
                    </div>
                </div>
            </div>
        </ToastProvider>
    );
}
