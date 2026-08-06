import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { LogoIcon, HamburgerIcon } from '../icons.jsx';
import ThemeToggle from './ThemeToggle.jsx';
import UserMenu from './UserMenu.jsx';
import MobileMenu from './MobileMenu.jsx';

const navLinkBase =
    'inline-flex items-center border-b-2 px-1 pt-1 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none';
const navLinkActive =
    'border-rose-400 text-gray-900 focus:border-rose-700 dark:border-rose-500 dark:text-gray-100';
const navLinkInactive =
    'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 focus:border-gray-300 focus:text-gray-700 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:text-gray-200';

export default function Nav({ shell }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <nav className="border-b border-gray-100 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div className="flex h-16 justify-between">
                    <div className="flex">
                        {/* Logo */}
                        <div className="flex shrink-0 items-center">
                            <Link href={shell.logoHref}>
                                <LogoIcon appName={shell.appName} />
                            </Link>
                        </div>

                        {/* Primärlinks */}
                        <div className="hidden sm:-my-px sm:ms-10 sm:flex sm:space-x-8">
                            {shell.links.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    aria-current={link.active ? 'page' : undefined}
                                    className={`${navLinkBase} ${link.active ? navLinkActive : navLinkInactive}`}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                    </div>

                    {/* Rechte Seite (Desktop) */}
                    <div className="hidden sm:ms-6 sm:flex sm:items-center">
                        <ThemeToggle labels={shell.labels.theme} className="me-1" />
                        <UserMenu shell={shell} />
                    </div>

                    {/* Hamburger (Mobil) */}
                    <div className="-me-2 flex items-center sm:hidden">
                        <button
                            type="button"
                            onClick={() => setMobileOpen((v) => !v)}
                            aria-expanded={mobileOpen}
                            aria-label={shell.labels.menu}
                            className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-500 dark:hover:bg-gray-700 dark:hover:text-gray-300 dark:focus:bg-gray-700 dark:focus:text-gray-300"
                        >
                            <HamburgerIcon open={mobileOpen} className="h-6 w-6" />
                        </button>
                    </div>
                </div>
            </div>

            <MobileMenu shell={shell} open={mobileOpen} />
        </nav>
    );
}
