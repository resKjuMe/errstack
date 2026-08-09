import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import useFilter from '../../filters/useFilter.js';
import { filterQuery } from '../../filters/useGlobalFilter.js';
import { formatDateTime, formatNumber, useTranslations } from '../../i18n.js';
import Flamegraph from './Flamegraph.jsx';
import FunctionList from './FunctionList.jsx';
import { duration, percent } from './format.jsx';

// Die Profil-Übersicht: was im Zeitraum aufgenommen wurde — und, sobald eine
// Transaktion gewählt ist, alle ihre Profile übereinandergelegt.
//
// Die Reihenfolge auf der Seite ist die Reihenfolge der Fragen: erst „gibt es
// überhaupt etwas", dann „was tut dieser Endpunkt üblicherweise", dann „was hat
// sich seit der letzten Version verändert". Ohne gewählte Transaktion bleibt es
// bei der ersten — eine Zusammenfassung über verschiedene Transaktionen hinweg
// wäre der Durchschnitt aus Anmeldeseite und nächtlichem Import.
export default function ProfilingIndex({
    profiles,
    listLimit,
    transactionName,
    release,
    compare,
    releases,
    aggregate,
    comparison,
}) {
    const { shell } = usePage().props;
    const filter = useFilter();
    const { t, formats } = useTranslations();

    // Der ganze Zustand steht in der Adresszeile: ein Neuladen behält ihn, und
    // ein geteilter Link zeigt beim Empfänger denselben Vergleich.
    const visit = (patch) => {
        const query = filterQuery(filter.value);
        const next = { transaction: transactionName, release, compare, ...patch };

        for (const [key, value] of Object.entries(next)) {
            if (value) {
                query.set(key, value);
            }
        }

        router.get(
            `${window.location.pathname}?${query.toString()}`,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    };

    return (
        <>
            <PageHead
                title={t('profiling.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('profiling.help.purpose')}</li>
                        <li>{t('profiling.help.gap')}</li>
                        <li>{t('profiling.help.aggregate')}</li>
                        <li>{t('profiling.help.self_total')}</li>
                        <li>{t('profiling.help.sampling')}</li>
                    </ul>
                }
            />

            {aggregate && (
                <Card
                    className="mb-6"
                    title={t('profiling.aggregate.heading', { transaction: transactionName })}
                    description={t('profiling.aggregate.hint', { limit: aggregate.profileLimit })}
                >
                    <div className="mb-4 flex flex-wrap items-end gap-3">
                        <ReleaseSelect
                            id="profiling_release"
                            label={t('profiling.aggregate.release')}
                            empty={t('profiling.aggregate.all_releases')}
                            value={release ?? ''}
                            options={releases}
                            formats={formats}
                            onChange={(value) => visit({ release: value || null })}
                        />
                        <ReleaseSelect
                            id="profiling_compare"
                            label={t('profiling.aggregate.compare')}
                            empty={t('profiling.aggregate.no_compare')}
                            value={compare ?? ''}
                            options={releases}
                            formats={formats}
                            onChange={(value) => visit({ compare: value || null })}
                        />
                        <Link
                            href={window.location.pathname}
                            className="mb-2 text-sm text-rose-600 hover:underline dark:text-rose-400"
                        >
                            {t('profiling.aggregate.clear')}
                        </Link>
                    </div>

                    {aggregate.flamegraph.samples === 0 ? (
                        <p className="py-8 text-center text-sm text-gray-600 dark:text-gray-300">
                            {t('profiling.list.empty_transaction')}
                        </p>
                    ) : (
                        <>
                            <p className="mb-3 text-sm text-gray-600 dark:text-gray-300">
                                {t('profiling.aggregate.profiles', {
                                    count: formatNumber(profiles.length, formats),
                                    samples: formatNumber(aggregate.flamegraph.samples, formats),
                                })}
                            </p>

                            <Flamegraph
                                roots={aggregate.flamegraph.roots}
                                frames={aggregate.frames}
                                totalUs={aggregate.flamegraph.totalUs}
                                dropped={aggregate.flamegraph.droppedNodes}
                                pruned={aggregate.flamegraph.prunedNodes}
                            />

                            <h3 className="mt-6 mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {t('profiling.functions.heading')}
                            </h3>

                            <FunctionList
                                functions={aggregate.flamegraph.functions}
                                totalUs={aggregate.flamegraph.totalUs}
                                limit={aggregate.flamegraph.functions.length}
                                functionCount={aggregate.flamegraph.functionCount}
                            />
                        </>
                    )}
                </Card>
            )}

            {comparison && (
                <Card
                    className="mb-6"
                    title={t('profiling.comparison.heading', {
                        baseline: compare,
                        candidate: release ?? t('profiling.aggregate.all_releases'),
                    })}
                    description={t('profiling.comparison.hint')}
                >
                    <Comparison
                        rows={comparison}
                        baseline={compare}
                        candidate={release ?? t('profiling.aggregate.all_releases')}
                        t={t}
                        formats={formats}
                    />
                </Card>
            )}

            <Card title={t('profiling.list.heading')}>
                {profiles.length === 0 ? (
                    <div className="py-8 text-center">
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            {t(
                                transactionName
                                    ? 'profiling.list.empty_transaction'
                                    : 'profiling.list.empty'
                            )}
                        </p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('profiling.list.empty_hint')}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    <tr>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-start font-medium"
                                        >
                                            {t('profiling.list.columns.transaction')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-start font-medium"
                                        >
                                            {t('profiling.list.columns.started')}
                                        </th>
                                        <th scope="col" className="px-3 py-2 text-end font-medium">
                                            {t('profiling.list.columns.duration')}
                                        </th>
                                        <th scope="col" className="px-3 py-2 text-end font-medium">
                                            {t('profiling.list.columns.samples')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-3 py-2 text-start font-medium xl:table-cell"
                                        >
                                            {t('profiling.list.columns.environment')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-3 py-2 text-start font-medium xl:table-cell"
                                        >
                                            {t('profiling.list.columns.release')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                    {profiles.map((profile) => (
                                        <tr key={profile.id}>
                                            <td className="px-3 py-2">
                                                <Link
                                                    href={profile.href}
                                                    className="font-mono break-all text-rose-600 hover:underline dark:text-rose-400"
                                                    title={t('profiling.list.open')}
                                                >
                                                    {profile.transactionName}
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        visit({
                                                            transaction: profile.transactionName,
                                                            release: null,
                                                            compare: null,
                                                        })
                                                    }
                                                    className="ms-2 text-xs text-gray-500 hover:underline dark:text-gray-400"
                                                >
                                                    {t('profiling.list.aggregate')}
                                                </button>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {formatDateTime(profile.startedAt, formats)}
                                            </td>
                                            <td className="px-3 py-2 text-end tabular-nums">
                                                {duration(profile.durationUs, t, formats)}
                                            </td>
                                            <td className="px-3 py-2 text-end tabular-nums">
                                                {formatNumber(profile.sampleCount, formats)}
                                            </td>
                                            <td className="hidden px-3 py-2 xl:table-cell">
                                                {profile.environment}
                                            </td>
                                            <td className="hidden px-3 py-2 font-mono text-xs xl:table-cell">
                                                {profile.release ?? ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {profiles.length >= listLimit && (
                            <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                {t('profiling.list.limit', {
                                    limit: formatNumber(listLimit, formats),
                                })}
                            </p>
                        )}
                    </>
                )}
            </Card>
        </>
    );
}

function ReleaseSelect({ id, label, empty, value, options, formats, onChange }) {
    return (
        <div>
            <label
                htmlFor={id}
                className="block text-sm font-medium text-gray-700 dark:text-gray-300"
            >
                {label}
            </label>
            <select
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
            >
                <option value="">{empty}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {`${option.value} (${formatNumber(option.count, formats)})`}
                    </option>
                ))}
            </select>
        </div>
    );
}

// Der Vergleich zweier Versionen. Rot heißt „hat mehr Anteil an der Rechenzeit
// als vorher" — nicht „ist langsamer geworden": ob der Aufruf insgesamt länger
// dauert, steht in der Performance-Übersicht und nicht hier.
function Comparison({ rows, baseline, candidate, t, formats }) {
    if (rows.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-gray-600 dark:text-gray-300">
                {t('profiling.comparison.empty')}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" className="px-3 py-2 text-start font-medium">
                            {t('profiling.comparison.columns.function')}
                        </th>
                        <th scope="col" className="px-3 py-2 text-end font-medium">
                            {t('profiling.comparison.columns.baseline', { release: baseline })}
                        </th>
                        <th scope="col" className="px-3 py-2 text-end font-medium">
                            {t('profiling.comparison.columns.candidate', { release: candidate })}
                        </th>
                        <th scope="col" className="px-3 py-2 text-end font-medium">
                            {t('profiling.comparison.columns.delta')}
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {rows.map((row, position) => (
                        <tr key={`${row.module ?? ''}|${row.function}|${position}`}>
                            <td className="px-3 py-2">
                                <span className="font-mono break-all text-gray-900 dark:text-gray-100">
                                    {row.module ? `${row.module}::${row.function}` : row.function}
                                </span>
                                {row.file && (
                                    <div className="text-xs break-all text-gray-500 dark:text-gray-400">
                                        {row.file}
                                    </div>
                                )}
                            </td>
                            <td className="px-3 py-2 text-end tabular-nums">
                                {percent(row.baselineShare, formats)}
                                <div className="text-xs text-gray-500 dark:text-gray-400">
                                    {duration(row.baselineUs, t, formats)}
                                </div>
                            </td>
                            <td className="px-3 py-2 text-end tabular-nums">
                                {percent(row.candidateShare, formats)}
                                <div className="text-xs text-gray-500 dark:text-gray-400">
                                    {duration(row.candidateUs, t, formats)}
                                </div>
                            </td>
                            <td
                                className={`px-3 py-2 text-end tabular-nums ${
                                    row.deltaShare > 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : row.deltaShare < 0
                                          ? 'text-emerald-600 dark:text-emerald-400'
                                          : ''
                                }`}
                            >
                                {percent(row.deltaShare, formats, { signDisplay: 'exceptZero' })}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
