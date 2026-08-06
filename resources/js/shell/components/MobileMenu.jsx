import React from 'react';
import { Link } from '@inertiajs/react';
import ThemeToggle from './ThemeToggle.jsx';

// Aufklappbares Menü für schmale Viewports (< sm): Primärlinks, dann ein Block
// mit Name/E-Mail, Theme-Umschalter und den Menü-Einträgen inkl. Logout.
const linkBase =
    'block w-full border-l-4 py-2 pe-4 ps-3 text-start text-base font-medium transition duration-150 ease-in-out focus:outline-none';
const linkActive =
    'border-rose-400 bg-rose-50 text-rose-700 focus:border-rose-700 focus:bg-rose-100 focus:text-rose-800 dark:border-rose-500 dark:bg-rose-900/40 dark:text-rose-300';
const linkInactive =
    'border-transparent text-gray-600 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-800 focus:border-gray-300 focus:bg-gray-50 focus:text-gray-800 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200';

function ResponsiveLink({ href, active, children }) {
    return (
        <Link href={href} className={`${linkBase} ${active ? linkActive : linkInactive}`}>
            {children}
        </Link>
    );
}

export default function MobileMenu({ shell, open }) {
    // Primärlinks und Menü-Einträge stehen hier untereinander — Einträge, die
    // schon oben als Primärlink erscheinen, werden nicht doppelt gezeigt.
    const linkHrefs = shell.links.map((link) => link.href);
    const menuItems = shell.menu.filter((item) => !linkHrefs.includes(item.href));

    return (
        <div className={`${open ? 'block' : 'hidden'} sm:hidden`}>
            <div className="space-y-1 pb-3 pt-2">
                {shell.links.map((link) => (
                    <ResponsiveLink key={link.href} href={link.href} active={link.active}>
                        {link.label}
                    </ResponsiveLink>
                ))}
            </div>

            <div className="border-t border-gray-200 pb-1 pt-4 dark:border-gray-700">
                <div className="flex items-center justify-between px-4">
                    <div>
                        <div className="text-base font-medium text-gray-800 dark:text-gray-200">
                            {shell.user?.name ?? shell.labels.guest}
                        </div>
                        {shell.user && (
                            <div className="text-sm font-medium text-gray-500 dark:text-gray-400">
                                {shell.user.email}
                            </div>
                        )}
                    </div>
                    <ThemeToggle labels={shell.labels.theme} />
                </div>

                <div className="mt-3 space-y-1">
                    {menuItems.map((item) => (
                        <ResponsiveLink key={item.href} href={item.href}>
                            {item.label}
                        </ResponsiveLink>
                    ))}

                    {shell.user && shell.logoutHref && (
                        <form method="POST" action={shell.logoutHref}>
                            <input type="hidden" name="_token" value={shell.csrf} />
                            <button type="submit" className={`${linkBase} ${linkInactive}`}>
                                {shell.labels.signOut}
                            </button>
                        </form>
                    )}

                    {!shell.user && shell.loginHref && (
                        <ResponsiveLink href={shell.loginHref}>{shell.labels.signIn}</ResponsiveLink>
                    )}
                </div>
            </div>
        </div>
    );
}
