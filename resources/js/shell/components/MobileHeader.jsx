import React from 'react';
import { Link } from '@inertiajs/react';
import { LogoIcon, HamburgerIcon } from '../icons.jsx';

// Kopfzeile für schmale Viewports (< sm). Die Seitenleiste ist dort ausgeblendet,
// also braucht es einen Weg zur Navigation: Logo links, Schaltfläche rechts, das
// aufgeklappte Menü darunter (MobileMenu).
//
// Ab sm verschwindet diese Zeile vollständig — dort steht die Navigation links
// in der Leiste und eine Kopfzeile wäre nur noch ein zweites Logo.
export default function MobileHeader({ shell, open, onToggle }) {
    return (
        <div className="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 sm:hidden dark:border-gray-700 dark:bg-gray-800">
            <Link href={shell.logoHref}>
                <LogoIcon appName={shell.appName} />
            </Link>

            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                aria-label={shell.labels.menu}
                className="-me-2 inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-500 dark:hover:bg-gray-700 dark:hover:text-gray-300 dark:focus:bg-gray-700 dark:focus:text-gray-300"
            >
                <HamburgerIcon open={open} className="h-6 w-6" />
            </button>
        </div>
    );
}
