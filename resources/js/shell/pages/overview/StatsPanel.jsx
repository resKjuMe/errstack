import React from 'react';
import { Link } from '@inertiajs/react';
import { formatValue } from '../discover/format.jsx';
import { formatNumber } from '../../i18n.js';

// Ein paar Kennzahlen nebeneinander — und jede ist ein Link.
//
// **Der Weg gehört zur Zahl.** „412" ohne Ziel zwingt jeden dazu, die Auswahl
// in der Detailansicht von Hand nachzubauen; dabei entsteht regelmäßig ein
// anderer Ausschnitt als der, über den er gerade gestaunt hat. Die Adresse
// kommt vom Server und trägt den Filter schon bei sich.
//
// **Ein Anteil steht nur da, wo es eine Grenze gibt.** Ohne sie ist der
// Verbrauch eine Zahl und kein Anteil von etwas — dieselbe Regel wie auf der
// Kontingent-Seite.
export default function StatsPanel({ stats, t, formats }) {
    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {stats.map((stat) => (
                <Stat key={stat.key} stat={stat} t={t} formats={formats} />
            ))}
        </div>
    );
}

function Stat({ stat, t, formats }) {
    const value = formatValue(stat.value, stat, t, formats) ?? '—';

    const body = (
        <>
            <p className="truncate text-xs text-gray-500 dark:text-gray-400" title={stat.label}>
                {stat.label}
            </p>
            <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {value}
                {stat.limit != null && (
                    <span className="ms-1 text-xs font-normal text-gray-500 dark:text-gray-400">
                        {t('overview.organization.quota.of', {
                            limit: formatNumber(stat.limit, formats),
                        })}
                    </span>
                )}
            </p>

            {stat.percent != null && (
                <div
                    className="mt-2 h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700"
                    role="presentation"
                >
                    <div
                        className={`h-1.5 rounded-full ${stat.percent >= 100 ? 'bg-rose-600' : 'bg-rose-400'}`}
                        style={{ width: `${Math.min(stat.percent, 100)}%` }}
                    />
                </div>
            )}
        </>
    );

    if (!stat.href) {
        return <div className="min-w-0">{body}</div>;
    }

    return (
        <Link
            href={stat.href}
            className="-m-1 min-w-0 rounded-md p-1 hover:bg-gray-50 dark:hover:bg-gray-700/50"
        >
            {body}
        </Link>
    );
}
