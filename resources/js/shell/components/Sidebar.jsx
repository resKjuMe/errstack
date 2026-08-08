import React from 'react';
import { Link } from '@inertiajs/react';
import { LogoIcon, MenuIcon, SidebarToggleIcon } from '../icons.jsx';
import ThemeToggle from './ThemeToggle.jsx';
import UserMenu from './UserMenu.jsx';

// Primärnavigation als feste Leiste am linken Rand (ab sm). Die Einträge kommen
// nach Themen gruppiert vom Server (App\Support\ShellData::nav); die Leiste
// zeichnet nur, was sie bekommt — welche Seiten es gibt, entscheidet dort die
// Prüfung auf vorhandene Routen.
//
// Eingeklappt bleibt jeder Eintrag als Symbol bedienbar; die Beschriftung wandert
// in den Tooltip. Deshalb hat jeder Eintrag ein Icon: ohne wäre die schmale
// Leiste eine Spalte aus leeren Kästchen.
//
// Für schmale Viewports zeichnet MobileMenu dieselben Gruppen — die Leiste ist
// dort ausgeblendet.

const itemBase =
    'flex items-center gap-3 rounded-md px-2 py-2 text-sm font-medium transition duration-150 ease-in-out focus:outline-none';
const itemActive = 'bg-rose-50 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200';
const itemInactive =
    'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700/60 dark:hover:text-gray-100';

function NavItem({ link, collapsed }) {
    return (
        <Link
            href={link.href}
            aria-current={link.active ? 'page' : undefined}
            title={collapsed ? link.label : undefined}
            className={`${itemBase} ${link.active ? itemActive : itemInactive} ${collapsed ? 'justify-center' : ''}`}
        >
            <MenuIcon name={link.icon} className="h-5 w-5 shrink-0" />
            {!collapsed && <span className="truncate">{link.label}</span>}
        </Link>
    );
}

export default function Sidebar({ shell, collapsed, onToggle }) {
    const toggleLabel = collapsed ? shell.labels.sidebar.expand : shell.labels.sidebar.collapse;

    // sticky + h-screen + self-start: die Leiste bleibt beim Scrollen einer
    // langen Liste stehen. Ohne self-start dehnt der Flex-Container sie auf die
    // volle Seitenhöhe, und sticky hätte keinen Spielraum.
    return (
        <aside
            className={`hidden shrink-0 border-e border-gray-200 bg-white sm:sticky sm:top-0 sm:flex sm:h-screen sm:flex-col sm:self-start dark:border-gray-700 dark:bg-gray-800 ${collapsed ? 'w-16' : 'w-60'}`}
        >
            {/* Kopf: Logo. Der Umschalter für die Organisation kommt mit U2 an
                dieselbe Stelle. */}
            <div
                className={`flex h-16 shrink-0 items-center border-b border-gray-200 px-3 dark:border-gray-700 ${collapsed ? 'justify-center' : ''}`}
            >
                <Link href={shell.logoHref} className="flex items-center">
                    <LogoIcon appName={collapsed ? '' : shell.appName} />
                </Link>
            </div>

            {/* Die Navigation selbst scrollt, wenn sie länger wird als das
                Fenster — Kopf und Fuß bleiben stehen. */}
            <nav className="flex-1 space-y-4 overflow-y-auto px-2 py-4">
                {shell.nav.map((group, index) => (
                    <div key={group.label ?? `group-${index}`}>
                        {group.label && !collapsed && (
                            <div className="px-2 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {group.label}
                            </div>
                        )}

                        {/* Eingeklappt ersetzt eine Trennlinie die Überschrift:
                            die Gruppierung bleibt sichtbar, ohne Text. */}
                        {group.label && collapsed && (
                            <div className="mx-2 mb-2 border-t border-gray-200 dark:border-gray-700" />
                        )}

                        <div className="space-y-0.5">
                            {group.links.map((link) => (
                                <NavItem key={link.href} link={link} collapsed={collapsed} />
                            ))}
                        </div>
                    </div>
                ))}
            </nav>

            {/* Fuß: Design, Nutzer-Menü und der Umschalter für die Leiste. Die
                Einstellungen ziehen mit U2 hier ein. */}
            <div
                className={`flex shrink-0 items-center gap-1 border-t border-gray-200 px-2 py-2 dark:border-gray-700 ${collapsed ? 'flex-col' : ''}`}
            >
                <div className={collapsed ? '' : 'min-w-0 flex-1'}>
                    <UserMenu shell={shell} compact={collapsed} />
                </div>

                <ThemeToggle labels={shell.labels.theme} />

                <button
                    type="button"
                    onClick={onToggle}
                    title={toggleLabel}
                    aria-label={toggleLabel}
                    aria-expanded={!collapsed}
                    className="inline-flex items-center justify-center rounded-md p-2 text-gray-500 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-700 focus:outline-none dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                >
                    <SidebarToggleIcon collapsed={collapsed} className="h-5 w-5" />
                </button>
            </div>
        </aside>
    );
}
