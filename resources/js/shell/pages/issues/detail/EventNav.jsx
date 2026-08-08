import React from 'react';
import { Link } from '@inertiajs/react';

// Das Blättern zwischen den Meldungen eines Fehlers.
//
// Vier Wege statt zwei: „neuere" und „ältere" sind die Schritte, „neueste" und
// „älteste" die Sprünge. Beides wird gebraucht — man kommt mit der neuesten
// Meldung an und will oft zur ersten, weil dort steht, womit es anfing.
//
// Fehlt ein Weg, steht dort eine abgeschaltete Schaltfläche und kein Link ins
// Nichts: dass man am Rand ist, ist selbst eine Auskunft.
export default function EventNav({ navigation, t }) {
    return (
        <div className="flex flex-wrap items-center gap-1">
            <span className="me-1 text-xs text-gray-500 dark:text-gray-400">
                {t('issues.detail.nav.label')}
            </span>

            <NavLink href={navigation.oldest} label={`« ${t('issues.detail.nav.oldest')}`} />
            <NavLink href={navigation.older} label={`‹ ${t('issues.detail.nav.older')}`} />
            <NavLink href={navigation.newer} label={`${t('issues.detail.nav.newer')} ›`} />
            <NavLink href={navigation.newest} label={`${t('issues.detail.nav.newest')} »`} />
        </div>
    );
}

function NavLink({ href, label }) {
    const shape = 'rounded-md border px-2 py-1 text-xs font-medium';

    if (href === null) {
        return (
            <span
                aria-disabled="true"
                className={`${shape} border-gray-200 text-gray-300 dark:border-gray-700 dark:text-gray-600`}
            >
                {label}
            </span>
        );
    }

    return (
        <Link
            href={href}
            className={`${shape} border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700`}
        >
            {label}
        </Link>
    );
}
