import React, { useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ChevronDownIcon, MenuIcon } from '../icons.jsx';
import useDismiss from './useDismiss.js';

// Umschalter für die Organisation am Kopf der Seitenleiste: welche gerade gilt,
// und mit einem Klick eine andere. Vorher lag der Wechsel allein auf der Seite
// „Organisationen" — von einer Fehlerliste aus musste man dafür wegnavigieren.
//
// Das Menü listet die **übrigen** Organisationen: die aktive steht schon in der
// Schaltfläche darüber. Wer zu genau einer gehört, findet darin folglich nur den
// Anlege-Eintrag — das ist kein leeres Menü, sondern das erwartete Bild.
//
// Gewechselt wird per POST auf `organizations.switch` (dieselbe Route wie auf der
// Organisationsseite); die Wahl steckt danach am Konto und überlebt Seitenwechsel
// und Neuladen.

// Das Kürzel als Plakette — in der eingeklappten Leiste ist es alles, was von
// der Organisation bleibt.
function Initials({ children }) {
    return (
        <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-rose-100 text-xs font-semibold uppercase text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
            {children}
        </span>
    );
}

// Die aktive Organisation, wie sie in der Schaltfläche steht. Als eigene
// Funktion, weil derselbe Block auch ohne Menü gezeichnet wird.
function Current({ current, name, collapsed, withChevron }) {
    return (
        <>
            {/* Ohne Organisation — und bei einem Namen, aus dem sich kein Kürzel
                bilden lässt — bleibt der Gedankenstrich, damit die Plakette nicht
                als leerer Kasten dasteht. */}
            <Initials>{current?.initials || '—'}</Initials>

            {!collapsed && (
                <>
                    {/* min-w-0 am Textblock, nicht am truncate-Element selbst:
                        ohne das wächst der Flex-Kasten mit dem Namen und die
                        Kürzung greift nie. */}
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {name}
                        </span>
                        {current && (
                            <span className="block truncate text-xs text-gray-500 dark:text-gray-400">
                                {current.slug}
                            </span>
                        )}
                    </span>

                    {withChevron && (
                        <ChevronDownIcon className="h-4 w-4 shrink-0 fill-current text-gray-400 dark:text-gray-500" />
                    )}
                </>
            )}
        </>
    );
}

const buttonBase =
    'flex w-full items-center gap-2 rounded-md px-2 py-2 text-start transition duration-150 ease-in-out focus:outline-none';

export default function OrganizationSwitcher({ org, labels, collapsed = false }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useDismiss(open, ref, setOpen);

    const current = org.current;
    const name = current?.name ?? labels.none;

    // Nichts zu wechseln und nichts anzulegen: dann steht die Organisation
    // einfach da. Eine Schaltfläche, die ein leeres Menü aufklappt, wäre eine
    // Zusage, die niemand einlöst.
    const hasMenu = org.options.length > 0 || Boolean(org.createHref);

    const switchTo = (href) => {
        setOpen(false);
        router.post(href, {}, { preserveScroll: true });
    };

    if (!hasMenu) {
        return (
            <div
                className={`${buttonBase} ${collapsed ? 'justify-center' : ''}`}
                title={collapsed ? name : undefined}
            >
                <Current current={current} name={name} collapsed={collapsed} />
            </div>
        );
    }

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                // Zweck und Zustand: eingeklappt bliebe sonst nur das Kürzel zu hören.
                aria-label={`${labels.switch}: ${name}`}
                title={collapsed ? name : undefined}
                className={`${buttonBase} hover:bg-gray-100 dark:hover:bg-gray-700/60 ${collapsed ? 'justify-center' : ''}`}
            >
                <Current current={current} name={name} collapsed={collapsed} withChevron />
            </button>

            {open && (
                <div className="absolute start-0 top-full z-50 mt-1 w-60 rounded-md shadow-lg">
                    <div className="rounded-md bg-white py-1 ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        {org.options.map((option) => (
                            <button
                                key={option.slug}
                                type="button"
                                onClick={() => switchTo(option.switchHref)}
                                className="flex w-full items-center gap-2 px-3 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                            >
                                <Initials>{option.initials}</Initials>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate">{option.name}</span>
                                    <span className="block truncate text-xs text-gray-500 dark:text-gray-400">
                                        {option.slug}
                                    </span>
                                </span>
                            </button>
                        ))}

                        {org.createHref && (
                            <>
                                {org.options.length > 0 && (
                                    <div className="my-1 border-t border-gray-200 dark:border-gray-700" />
                                )}

                                <Link
                                    href={org.createHref}
                                    onClick={() => setOpen(false)}
                                    className="flex w-full items-center gap-2 px-3 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                                >
                                    <MenuIcon
                                        name="plus"
                                        className="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                                    />
                                    {labels.create}
                                </Link>
                            </>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
