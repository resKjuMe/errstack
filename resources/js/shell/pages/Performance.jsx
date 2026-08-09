import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import Card from '../components/Card.jsx';
import { InputLabel, PrimaryButton, SecondaryButton, TextInput } from '../components/Form.jsx';
import useFilter from '../filters/useFilter.js';
import { filterQuery } from '../filters/useGlobalFilter.js';
import { formatNumber, useTranslations } from '../i18n.js';
import { duration, Missing, percent } from './performance/format.jsx';

// Die Performance-Übersicht: welche Seiten und Endpunkte im gewählten Zeitraum
// langsam oder fehlerhaft waren.
//
// Ihr Zustand steht vollständig in der Adresszeile — Filter, Suche, Sortierung
// und Seite. Das ist keine Wiederholung des Filter-Musters aus F7, sondern
// dieselbe Mechanik: `filterQuery` baut den Filterteil, diese Seite hängt ihre
// drei eigenen Parameter an. Damit übersteht die ganze Ansicht das Neuladen und
// lässt sich als Link teilen — „schau dir mal an, was hier oben steht" ist die
// häufigste Verwendung dieser Seite.
export default function Performance({
    rows,
    columns,
    sort,
    direction,
    q,
    pagination,
    truncated,
    groupLimit,
    trendsHref,
}) {
    const { shell } = usePage().props;
    const filter = useFilter();
    const { t, formats } = useTranslations();

    // Die Sucheingabe ist der einzige Zustand, der **nicht** sofort in die
    // Adresszeile wandert: ein Aufruf je Tastendruck wäre eine Abfrage je
    // Tastendruck. Abgeschickt wird mit Eingabetaste oder Schaltfläche.
    const [search, setSearch] = useState(q);

    useEffect(() => {
        setSearch(q);
    }, [q]);

    const visit = (patch) => {
        const query = filterQuery(filter.value);
        const next = { q, sort, direction, page: pagination.page, ...patch };

        if (next.q) {
            query.set('q', next.q);
        }

        query.set('sort', next.sort);
        query.set('direction', next.direction);

        // Die erste Seite ist die Voreinstellung und gehört nicht in den Link.
        if (next.page > 1) {
            query.set('page', String(next.page));
        }

        router.get(
            `${window.location.pathname}?${query.toString()}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    // Ein Klick auf die aktive Spalte dreht die Richtung um; eine andere Spalte
    // beginnt mit der Richtung, die dort etwas zeigt — bei Zahlen das Größte
    // zuerst, bei Namen das Alphabet. Jede Änderung führt zurück auf Seite 1,
    // weil „Seite 3 der alten Sortierung" nichts bedeutet.
    const toggleSort = (column) => {
        const active = column.key === sort;

        visit({
            sort: column.key,
            direction: active
                ? direction === 'asc'
                    ? 'desc'
                    : 'asc'
                : column.numeric
                  ? 'desc'
                  : 'asc',
            page: 1,
        });
    };

    const submitSearch = (e) => {
        e.preventDefault();
        visit({ q: search.trim(), page: 1 });
    };

    return (
        <>
            <PageHead
                title={t('performance.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('performance.help.purpose')}</li>
                        <li>{t('performance.help.percentiles')}</li>
                        <li>{t('performance.help.sampling')}</li>
                        <li>{t('performance.help.trend')}</li>
                        <li>{t('performance.help.search')}</li>
                    </ul>
                }
            />

            {/* Der Pfeil in der Tabelle sagt „langsamer als im Vorzeitraum",
                aber nicht, wann es passiert ist. Dafür gibt es die Trend-Liste
                (PF7), und der Weg dorthin gehört an die Stelle, an der die
                Frage entsteht. */}
            <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">
                <Link
                    href={trendsHref}
                    className="underline hover:text-gray-700 dark:hover:text-gray-200"
                >
                    {t('performance_trends.link')}
                </Link>
            </p>

            <Card>
                <form onSubmit={submitSearch} className="mb-4 flex flex-wrap items-end gap-2">
                    <div className="min-w-64 flex-1">
                        <InputLabel
                            htmlFor="performance_search"
                            value={t('performance.search.label')}
                        />
                        <TextInput
                            id="performance_search"
                            type="search"
                            value={search}
                            placeholder={t('performance.search.placeholder')}
                            className="mt-1 font-mono"
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>

                    <PrimaryButton type="submit">{t('performance.search.submit')}</PrimaryButton>

                    {q !== '' && (
                        <SecondaryButton type="button" onClick={() => visit({ q: '', page: 1 })}>
                            {t('performance.search.clear')}
                        </SecondaryButton>
                    )}
                </form>

                {truncated && (
                    <p className="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                        {t('performance.truncated', { limit: groupLimit })}
                    </p>
                )}

                {rows.length === 0 ? (
                    <Empty searching={q !== ''} />
                ) : (
                    <>
                        {/* Die Tabelle ist breiter als ein Telefon. Sie rollt in
                            ihrem eigenen Rahmen, damit nicht die ganze Seite
                            seitlich wandert. */}
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-200 dark:border-gray-700">
                                        {columns.map((column) => (
                                            <SortableHeader
                                                key={column.key}
                                                column={column}
                                                active={column.key === sort}
                                                direction={direction}
                                                onSort={() => toggleSort(column)}
                                            />
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-700/60">
                                    {rows.map((row) => (
                                        <tr
                                            // Das Nullbyte als Trennzeichen wie serverseitig
                                            // (TransactionOverview::key) — als Escape
                                            // geschrieben, damit die Datei Text bleibt: ein
                                            // echtes Nullbyte macht sie für git zur
                                            // Binärdatei, und jede zweite Änderung daran ist
                                            // dann ein Konflikt von Hand.
                                            key={`${row.name}\u0000${row.op}`}
                                            className="hover:bg-gray-50 dark:hover:bg-gray-700/40"
                                        >
                                            {columns.map((column) => (
                                                <Cell
                                                    key={column.key}
                                                    column={column}
                                                    row={row}
                                                    t={t}
                                                    formats={formats}
                                                    filter={filter}
                                                />
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Pagination
                            pagination={pagination}
                            onPage={(page) => visit({ page })}
                            t={t}
                            formats={formats}
                        />
                    </>
                )}
            </Card>
        </>
    );
}

// Zwei Leerzustände und nicht einer: „hier ist nichts angekommen" und „deine
// Suche trifft nichts" verlangen verschiedene nächste Schritte.
function Empty({ searching }) {
    const { t } = useTranslations();

    return (
        <div className="py-8 text-center">
            <p className="text-sm text-gray-600 dark:text-gray-300">
                {t(searching ? 'performance.empty.no_results' : 'performance.empty.no_data')}
            </p>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t(
                    searching
                        ? 'performance.empty.no_results_hint'
                        : 'performance.empty.no_data_hint'
                )}
            </p>
        </div>
    );
}

function SortableHeader({ column, active, direction, onSort }) {
    const { t } = useTranslations();

    // `aria-sort` gehört an die Zelle und nicht an die Schaltfläche darin —
    // Vorleseprogramme lesen es an der Spalte.
    const ariaSort = !active ? 'none' : direction === 'asc' ? 'ascending' : 'descending';

    // Angekündigt wird, was ein Klick **täte**, nicht was gerade gilt.
    const nextDirection = active
        ? direction === 'asc'
            ? 'descending'
            : 'ascending'
        : column.numeric
          ? 'descending'
          : 'ascending';

    return (
        <th
            scope="col"
            aria-sort={ariaSort}
            className={`px-3 py-2 font-medium ${column.numeric ? 'text-end' : 'text-start'} ${
                column.secondary ? 'hidden xl:table-cell' : ''
            }`}
        >
            <button
                type="button"
                onClick={onSort}
                title={t(`performance.sort.${nextDirection}`, { column: column.label })}
                className={`inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-gray-100 ${
                    active ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'
                }`}
            >
                {column.label}
                <span aria-hidden="true" className={active ? '' : 'opacity-0'}>
                    {direction === 'asc' ? '↑' : '↓'}
                </span>
            </button>
        </th>
    );
}

function Cell({ column, row, t, formats, filter }) {
    return (
        <td
            className={`px-3 py-2 ${column.numeric ? 'text-end tabular-nums' : 'text-start'} ${
                column.secondary ? 'hidden xl:table-cell' : ''
            }`}
        >
            {renderCell(column.key, row, t, formats, filter)}
        </td>
    );
}

function renderCell(key, row, t, formats, filter) {
    switch (key) {
        case 'name':
            return <TransactionName row={row} t={t} filter={filter} />;
        case 'throughput':
            return <Throughput row={row} t={t} formats={formats} />;
        case 'p50':
            return duration(row.p50Us, t, formats);
        case 'p75':
            return duration(row.p75Us, t, formats);
        case 'p95':
            return duration(row.p95Us, t, formats);
        case 'p99':
            return duration(row.p99Us, t, formats);
        case 'avg':
            return duration(row.avgUs, t, formats);
        case 'failureRate':
            return <FailureRate row={row} formats={formats} />;
        case 'users':
            return formatNumber(row.users, formats);
        case 'userMisery':
            return <UserMisery row={row} t={t} formats={formats} />;
        case 'count':
            return formatNumber(row.count, formats);
        case 'trend':
            return <Trend row={row} t={t} formats={formats} />;
        default:
            // Eine Spalte, die der Server schickt und die hier fehlt, bleibt
            // leer statt die Seite abstürzen zu lassen — sie fällt im Kopf der
            // Tabelle trotzdem auf.
            return null;
    }
}

// Der Durchsatz ist die hochgerechnete Zahl der Aufrufe je Minute. Unterhalb
// von 0,1 wird er nicht auf „0,0" gerundet, sondern als „kleiner als" gezeigt:
// eine Seite, die dreimal am Tag aufgerufen wird, hat Verkehr — nur wenig, und
// eine Null wäre schlicht falsch.
function Throughput({ row, t, formats }) {
    const value =
        row.throughput > 0 && row.throughput < 0.1
            ? `< ${formatNumber(0.1, formats, { minimumFractionDigits: 1 })}`
            : formatNumber(row.throughput, formats, {
                  minimumFractionDigits: 1,
                  maximumFractionDigits: 1,
              });

    return (
        <span
            title={t('performance.row.measurements', {
                measured: formatNumber(row.count, formats),
                extrapolated: formatNumber(row.extrapolatedCount, formats, {
                    maximumFractionDigits: 0,
                }),
            })}
        >
            {value}
            <span className="ms-1 text-xs text-gray-500 dark:text-gray-400">
                {t('performance.units.per_minute')}
            </span>
        </span>
    );
}

// Der Name führt in die Detailanalyse (PF3) — mit derselben Projektauswahl und
// demselben Zeitraum. Genau das ist die Bewegung, für die diese Seite da ist:
// „wohin schauen" beantwortet die Liste, „warum" die Seite dahinter.
function TransactionName({ row, t, filter }) {
    return (
        <div className="max-w-96">
            <Link
                href={transactionHref(row, filter)}
                className="font-mono break-all text-gray-900 underline decoration-gray-300 underline-offset-2 hover:decoration-gray-500 dark:text-gray-100 dark:decoration-gray-600 dark:hover:decoration-gray-400"
            >
                {row.name}
            </Link>
            <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                {row.op === '' ? t('performance.row.no_op') : row.op}
            </span>
        </div>
    );
}

// Name und Operation stehen als Parameter in der Adresse und nicht im Pfad: ein
// Transaktionsname ist meist selbst ein Pfad.
function transactionHref(row, filter) {
    const query = filterQuery(filter.value);

    query.set('name', row.name);

    if (row.op !== '') {
        query.set('op', row.op);
    }

    return `/leistung/transaktion?${query.toString()}`;
}

// Ab einem Prozent rot: darunter ist eine Fehlerrate der Normalbetrieb einer
// Anwendung mit Bots und abgebrochenen Verbindungen, darüber ist sie eine
// Nachricht.
function FailureRate({ row, formats }) {
    if (row.failureRate === null) {
        return <Missing />;
    }

    return (
        <span className={row.failureRate >= 0.01 ? 'text-rose-600 dark:text-rose-400' : ''}>
            {percent(row.failureRate, formats)}
        </span>
    );
}

function UserMisery({ row, t, formats }) {
    if (row.userMisery === null) {
        return <Missing />;
    }

    return (
        <span
            title={t('performance.row.users', {
                miserable: formatNumber(row.miserableUsers, formats),
                users: formatNumber(row.users, formats),
            })}
            className={row.userMisery >= 0.05 ? 'text-rose-600 dark:text-rose-400' : ''}
        >
            {percent(row.userMisery, formats)}
        </span>
    );
}

const TREND_STYLES = {
    worse: { glyph: '▲', className: 'text-rose-600 dark:text-rose-400' },
    better: { glyph: '▼', className: 'text-emerald-600 dark:text-emerald-400' },
    flat: { glyph: '→', className: 'text-gray-400 dark:text-gray-500' },
};

// „Neu" und „zu wenig Daten" bekommen bewusst keinen Pfeil: ein Pfeil behauptet
// eine Richtung, und die gibt es in beiden Fällen nicht.
function Trend({ row, t, formats }) {
    const style = TREND_STYLES[row.trend];
    const change =
        row.changeRatio === null
            ? null
            : formatNumber(row.changeRatio * 100, formats, {
                  maximumFractionDigits: 0,
                  signDisplay: 'exceptZero',
              }) + ' %';

    const label =
        change === null
            ? row.trendLabel
            : t('performance.row.trend_change', { label: row.trendLabel, change });

    if (!style) {
        return (
            <span className="text-xs text-gray-400 dark:text-gray-500" title={label}>
                {row.trend === 'new' ? row.trendLabel : '—'}
            </span>
        );
    }

    return (
        <span className={`inline-flex items-center gap-1 ${style.className}`} title={label}>
            <span aria-hidden="true">{style.glyph}</span>
            <span className="sr-only">{label}</span>
            <span aria-hidden="true">{change}</span>
        </span>
    );
}

function Pagination({ pagination, onPage, t, formats }) {
    if (pagination.lastPage <= 1) {
        return null;
    }

    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('performance.pagination.summary', {
                    page: formatNumber(pagination.page, formats),
                    pages: formatNumber(pagination.lastPage, formats),
                    total: formatNumber(pagination.total, formats),
                })}
            </p>

            <div className="flex gap-2">
                <SecondaryButton
                    type="button"
                    disabled={pagination.page <= 1}
                    onClick={() => onPage(pagination.page - 1)}
                >
                    {t('performance.pagination.previous')}
                </SecondaryButton>
                <SecondaryButton
                    type="button"
                    disabled={pagination.page >= pagination.lastPage}
                    onClick={() => onPage(pagination.page + 1)}
                >
                    {t('performance.pagination.next')}
                </SecondaryButton>
            </div>
        </div>
    );
}
