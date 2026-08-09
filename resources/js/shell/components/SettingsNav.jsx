import React from 'react';
import { Link } from '@inertiajs/react';

// Die Unter-Navigation des Einstellungsbereichs. Sie steht zwischen der
// Seitenleiste und dem Inhalt und sagt zweierlei: dass man sich hier im
// Einstellungsbereich befindet, und woran sich alles einstellen lässt.
//
// Die Gruppen liefert der Server (App\Support\SettingsNav); die Leiste zeichnet
// nur, was sie bekommt — welche Seiten es gibt und ob gerade ein Projekt
// feststeht, entscheidet dort die Prüfung auf vorhandene Routen.
//
// Ohne Symbole, anders als die Hauptnavigation: sie klappt nicht ein, und die
// Einträge sind Wörter, keine Orte. Ein Symbol je Datenschutzregel-Liste wäre
// eine Erfindung, die man erst lernen müsste.

const itemBase =
    'block rounded-md px-3 py-1.5 text-sm transition duration-150 ease-in-out focus:outline-none';
const itemActive = 'bg-rose-50 font-semibold text-rose-700 dark:bg-rose-900/40 dark:text-rose-200';
const itemInactive =
    'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/60 dark:hover:text-gray-100';

export default function SettingsNav({ settings }) {
    // sticky + self-start: die Leiste bleibt beim Scrollen einer langen
    // Regelliste stehen — dieselbe Konvention wie bei der Hauptnavigation.
    return (
        <aside className="hidden w-56 shrink-0 lg:block">
            <nav
                aria-label={settings.title}
                className="sticky top-6 space-y-5 border-e border-gray-200 pe-4 dark:border-gray-700"
            >
                {settings.groups.map((group) => (
                    <SettingsGroup key={group.label} group={group} />
                ))}
            </nav>
        </aside>
    );
}

// Der Kontext unter der Überschrift nennt das Projekt, an dem die Einträge
// darunter hängen. Ohne ihn stünden dieselben Beschriftungen („Stammdaten",
// „Datenschutz") für zwei Ebenen da, und man sähe der Leiste nicht an, welche
// gerade gemeint ist.
function SettingsGroup({ group }) {
    return (
        <div>
            <div className="px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                {group.label}
            </div>

            {group.context && (
                <div className="truncate px-3 pb-1 text-xs text-gray-400 dark:text-gray-500">
                    {group.context}
                </div>
            )}

            <div className="space-y-0.5">
                {group.links.map((link) => (
                    <Link
                        key={link.href}
                        href={link.href}
                        aria-current={link.active ? 'page' : undefined}
                        className={`${itemBase} ${link.active ? itemActive : itemInactive}`}
                    >
                        {link.label}
                    </Link>
                ))}
            </div>
        </div>
    );
}

// Dieselben Gruppen für schmale Viewports, wo die Leiste oben über dem Inhalt
// steht statt daneben: eine Spalte aus 56 rem neben dem Inhalt ließe für beides
// zu wenig Platz.
export function SettingsNavCompact({ settings }) {
    return (
        <nav
            aria-label={settings.title}
            className="mb-6 space-y-4 rounded-lg border border-gray-200 bg-white p-4 lg:hidden dark:border-gray-700 dark:bg-gray-800"
        >
            {settings.groups.map((group) => (
                <SettingsGroup key={group.label} group={group} />
            ))}
        </nav>
    );
}
