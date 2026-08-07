import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { LogoIcon } from './icons.jsx';
import { useT } from './i18n.js';

// Rahmen der Gast-Seiten (Anmelden, Registrieren, Passwort zurücksetzen): Logo
// über einer zentrierten Karte, ohne Navigation und ohne Nutzer-Menü — es gibt ja
// noch keinen Nutzer. Aufbau wie das Gast-Layout in Planstack. Dunkelmodus kommt
// wie überall über die .dark-Klasse am <html>, die das Skript in
// partials/theme-init noch vor dem Rendern setzt.
//
// Der Titel kommt als Schlüssel, nicht als fertiger Text: die Seiten setzen ihr
// Layout außerhalb der Komponente (`Login.layout = …`), wo kein Hook und damit
// keine Übersetzung zur Verfügung steht. Er landet nur im Browser-Tab; in der
// Karte steht die Überschrift des jeweiligen Formulars nicht noch einmal.
export default function GuestShell({ titleKey, children }) {
    const { shell } = usePage().props;
    const t = useT();
    const appName = shell?.appName ?? 'Errstack';

    return (
        <div className="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0 dark:bg-gray-900">
            <Head title={`${t(titleKey)} · ${appName}`} />

            <div>
                <Link href="/">
                    <LogoIcon
                        appName={appName}
                        className="gap-3"
                        markClassName="h-16 w-16 p-3"
                        nameClassName="text-3xl"
                    />
                </Link>
            </div>

            <div className="es-page-enter mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg dark:bg-gray-800">
                {children}
            </div>
        </div>
    );
}
