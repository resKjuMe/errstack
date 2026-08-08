import React from 'react';
import { Link } from '@inertiajs/react';
import Card from '../../components/Card.jsx';

// Die Merkmal-Verteilung, wie sie auf beiden Ebenen aussieht — am einzelnen
// Fehler und über die gewählten Projekte.
//
// Eine Komponente für beide, weil sie dieselbe Aussage machen: je Merkmal die
// Werte mit Anzahl und Anteil. Was sich unterscheidet, ist nur, worüber gezählt
// wurde — und das steht in der Nutzlast, nicht in der Darstellung.
//
// Alle Zahlen kommen fertig geschrieben vom Server (`countLabel`,
// `shareLabel`); der rohe Anteil daneben ist für die Balkenbreite da. Formatiert
// wird nicht zweimal: wie eine Zahl aussieht, hängt an der Sprache, und die
// kennt der Server.

// Die Übersicht: je Merkmal eine Karte mit den häufigsten Werten.
export function TagFacets({ facets, t }) {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            {facets.map((facet) => (
                <Card key={facet.key}>
                    <div className="flex items-baseline justify-between gap-3">
                        <h2 className="truncate text-base font-semibold text-gray-900 dark:text-gray-100">
                            {facet.label}
                        </h2>
                        <Link
                            href={facet.href}
                            className="shrink-0 text-sm font-medium text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                        >
                            {t('tags.list.all_values')}
                        </Link>
                    </div>

                    <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {t('tags.detail.values', { count: facet.valueCount })} ·{' '}
                        {t('tags.detail.total', { count: facet.totalLabel })}
                    </p>

                    <div className="mt-4">
                        <TagValues facet={facet} t={t} />
                    </div>
                </Card>
            ))}
        </div>
    );
}

// Die Werte eines Merkmals als Balken, häufigster zuerst.
//
// Der Balken trägt den Anteil und nicht die Anzahl: „412" sagt ohne Nenner
// nichts, „73 %" sagt es sofort. Die Anzahl steht daneben, weil 73 % von 11
// etwas anderes bedeuten als 73 % von 11.000.
export function TagValues({ facet, t }) {
    return (
        <ul className="space-y-2">
            {facet.values.map((value) => (
                <li key={value.value}>
                    <Link
                        href={value.href}
                        title={t('tags.list.filter')}
                        className="group block rounded px-1 py-0.5 hover:bg-gray-50 dark:hover:bg-gray-700/40"
                    >
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="truncate text-gray-900 group-hover:underline dark:text-gray-100">
                                {value.value}
                            </span>
                            <span className="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                                {value.shareLabel}
                                <span className="ms-2 text-xs">{value.countLabel}</span>
                            </span>
                        </div>
                        <Bar share={value.share} />
                    </Link>
                </li>
            ))}

            {facet.rest && (
                <li className="px-1 py-0.5">
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate text-gray-500 dark:text-gray-400">
                            {t('tags.list.rest')}
                        </span>
                        <span className="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                            {facet.rest.shareLabel}
                            <span className="ms-2 text-xs">{facet.rest.countLabel}</span>
                        </span>
                    </div>
                    <Bar share={facet.rest.share} muted />
                </li>
            )}
        </ul>
    );
}

// Der Balken selbst. `aria-hidden`, weil Anteil und Anzahl direkt darüber im
// Text stehen — vorgelesen wäre er eine zweite, wortlose Wiederholung.
function Bar({ share, muted = false }) {
    const width = Math.max(Math.min(share, 100), 0);

    return (
        <div
            aria-hidden="true"
            className="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"
        >
            <div
                className={`h-full rounded-full ${muted ? 'bg-gray-300 dark:bg-gray-600' : 'bg-indigo-500'}`}
                style={{ width: `${width}%` }}
            />
        </div>
    );
}

// Die Detailansicht eines Merkmals: alle Werte, nicht nur die häufigsten.
export function TagDetail({ detail, backHref, backLabel, capLimit, t }) {
    return (
        <Card>
            <div className="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                        {detail.label}
                    </h2>
                    <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {t('tags.detail.values', { count: detail.valueCount })} ·{' '}
                        {t('tags.detail.total', { count: detail.totalLabel })}
                    </p>
                </div>

                <Link
                    href={backHref}
                    className="text-sm font-medium text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                >
                    {backLabel}
                </Link>
            </div>

            {detail.capped && (
                <p className="mt-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {t('tags.detail.capped', { limit: capLimit })}
                </p>
            )}

            <div className="mt-4">
                <TagValues facet={detail} t={t} />
            </div>
        </Card>
    );
}

// „Noch nichts da" — und warum.
export function TagsEmpty({ t }) {
    return (
        <Card>
            <p className="text-sm text-gray-500 dark:text-gray-400">{t('tags.list.empty')}</p>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('tags.list.empty_hint')}
            </p>
        </Card>
    );
}
