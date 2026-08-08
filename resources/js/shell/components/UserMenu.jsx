import React, { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDownIcon, MenuIcon } from '../icons.jsx';

// Nutzer-Dropdown (Name + Menü). Klick außerhalb und Escape schließen; ein Klick
// auf einen Eintrag schließt ebenfalls. Der Logout ist ein echtes POST-Formular
// (CSRF).
//
// Es steht im Fuß der Seitenleiste. Weil dort unten kein Platz nach unten ist,
// klappt es nach oben auf; `compact` zeigt in der eingeklappten Leiste nur das
// Zeichen statt des Namens.
//
// Ohne Anmeldung (bis Task F3) steht dort „Gast"; statt des Logouts erscheint
// ein Anmelde-Link, sobald es die Route gibt.
export default function UserMenu({ shell, compact = false }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onDocClick = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };

        document.addEventListener('click', onDocClick);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('click', onDocClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const name = shell.user?.name ?? shell.labels.guest;

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                aria-haspopup="menu"
                title={compact ? name : undefined}
                className={`inline-flex w-full items-center rounded-md border border-transparent px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none dark:text-gray-400 dark:hover:text-gray-200 ${compact ? 'justify-center px-2' : ''}`}
            >
                {compact ? (
                    <MenuIcon name="profile" className="h-5 w-5 shrink-0" />
                ) : (
                    <>
                        <div className="truncate">{name}</div>
                        <div className="ms-1">
                            <ChevronDownIcon className="h-4 w-4 shrink-0 fill-current" />
                        </div>
                    </>
                )}
            </button>

            {open && (
                <div
                    className="absolute bottom-full start-0 z-50 mb-2 w-56 rounded-md shadow-lg"
                    onClick={() => setOpen(false)}
                >
                    <div className="rounded-md bg-white py-1 ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10">
                        {shell.menu.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className="flex w-full items-center gap-2 whitespace-nowrap px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                            >
                                <MenuIcon
                                    name={item.icon}
                                    className="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                                />
                                {item.label}
                            </Link>
                        ))}

                        {shell.user && shell.logoutHref && (
                            /* stopPropagation: der Klick darf NICHT bis zum onClick des
                               Dropdown-Wrappers (setOpen(false)) durchblubbern — sonst
                               unmountet React das Formular noch VOR dem nativen Submit
                               und der Logout-POST wird verworfen. */
                            <form
                                method="POST"
                                action={shell.logoutHref}
                                onClick={(e) => e.stopPropagation()}
                            >
                                <input type="hidden" name="_token" value={shell.csrf} />
                                <button
                                    type="submit"
                                    className="flex w-full items-center gap-2 px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                                >
                                    <MenuIcon
                                        name="logout"
                                        className="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                                    />
                                    {shell.labels.signOut}
                                </button>
                            </form>
                        )}

                        {!shell.user && shell.loginHref && (
                            <Link
                                href={shell.loginHref}
                                className="flex w-full items-center gap-2 px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                            >
                                <MenuIcon
                                    name="login"
                                    className="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                                />
                                {shell.labels.signIn}
                            </Link>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
