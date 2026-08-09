import React from 'react';
import { Link } from '@inertiajs/react';
import OrganizationSwitcher from './OrganizationSwitcher.jsx';
import ThemeToggle from './ThemeToggle.jsx';
import { MenuIcon } from '../icons.jsx';

// Aufklappbares Menü für schmale Viewports (< sm): oben die Organisation,
// darunter dieselben Navigationsgruppen wie die Seitenleiste, dann ein Block mit
// Name/E-Mail, Theme-Umschalter und den Menü-Einträgen inkl. Logout.
//
// Der Umschalter für die Organisation steht auch hier: die Seitenleiste ist auf
// schmalen Viewports ausgeblendet, und ohne ihn wäre der Wechsel dort nur über
// die Seite „Organisationen" zu erreichen — genau der Umweg, den U2 abschafft.
const linkBase =
    'block w-full border-l-4 py-2 pe-4 ps-3 text-start text-base font-medium transition duration-150 ease-in-out focus:outline-none';
const linkActive =
    'border-rose-400 bg-rose-50 text-rose-700 focus:border-rose-700 focus:bg-rose-100 focus:text-rose-800 dark:border-rose-500 dark:bg-rose-900/40 dark:text-rose-300';
const linkInactive =
    'border-transparent text-gray-600 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-800 focus:border-gray-300 focus:bg-gray-50 focus:text-gray-800 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200';

function ResponsiveLink({ href, active, icon, children }) {
    return (
        <Link href={href} className={`${linkBase} ${active ? linkActive : linkInactive}`}>
            <span className="flex items-center gap-3">
                <MenuIcon name={icon} className="h-5 w-5 shrink-0" />
                {children}
            </span>
        </Link>
    );
}

export default function MobileMenu({ shell, open }) {
    // Navigationsgruppen, Anker und Menü-Einträge stehen hier untereinander —
    // Einträge, die schon oben in der Navigation oder als Anker erscheinen,
    // werden nicht doppelt gezeigt.
    const navHrefs = shell.nav.flatMap((group) => group.links.map((link) => link.href));
    const shownHrefs = [...navHrefs, ...shell.footer.map((link) => link.href)];
    const menuItems = shell.menu.filter((item) => !shownHrefs.includes(item.href));

    return (
        <div
            className={`${open ? 'block' : 'hidden'} border-b border-gray-200 bg-white sm:hidden dark:border-gray-700 dark:bg-gray-800`}
        >
            {shell.org && (
                <div className="border-b border-gray-200 px-2 py-2 dark:border-gray-700">
                    <OrganizationSwitcher org={shell.org} labels={shell.labels.org} />
                </div>
            )}

            <div className="space-y-4 pb-3 pt-2">
                {shell.nav.map((group, index) => (
                    <div key={group.label ?? `group-${index}`}>
                        {group.label && (
                            <div className="px-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                {group.label}
                            </div>
                        )}

                        <div className="space-y-1">
                            {group.links.map((link) => (
                                <ResponsiveLink
                                    key={link.href}
                                    href={link.href}
                                    active={link.active}
                                    icon={link.icon}
                                >
                                    {link.label}
                                </ResponsiveLink>
                            ))}
                        </div>
                    </div>
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
                    {shell.footer.map((link) => (
                        <ResponsiveLink key={link.href} href={link.href} icon={link.icon}>
                            {link.label}
                        </ResponsiveLink>
                    ))}

                    {menuItems.map((item) => (
                        <ResponsiveLink key={item.href} href={item.href} icon={item.icon}>
                            {item.label}
                        </ResponsiveLink>
                    ))}

                    {shell.user && shell.logoutHref && (
                        <form method="POST" action={shell.logoutHref}>
                            <input type="hidden" name="_token" value={shell.csrf} />
                            <button type="submit" className={`${linkBase} ${linkInactive}`}>
                                <span className="flex items-center gap-3">
                                    <MenuIcon name="logout" className="h-5 w-5 shrink-0" />
                                    {shell.labels.signOut}
                                </span>
                            </button>
                        </form>
                    )}

                    {!shell.user && shell.loginHref && (
                        <ResponsiveLink href={shell.loginHref} icon="login">
                            {shell.labels.signIn}
                        </ResponsiveLink>
                    )}
                </div>
            </div>
        </div>
    );
}
