import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import useFilter from '../../filters/useFilter.js';
import { filterQuery } from '../../filters/useGlobalFilter.js';
import { formatDateTime, formatNumber, useTranslations } from '../../i18n.js';
import { clock, shortUrl } from './format.js';

// Die Übersicht der aufgezeichneten Sitzungen.
//
// Sie ist bewusst schmal gehalten: der übliche Weg zu einer Aufzeichnung führt
// von einem Fehler und nicht über diese Liste. Wer hier landet, hat keinen
// konkreten Anlass — und sucht dann fast immer die Sitzung mit dem roten Punkt.
// Deshalb der eine Schalter oben und kein Filterformular.
export default function ReplaysIndex({ replays, total, listLimit, onlyWithErrors }) {
    const { shell } = usePage().props;
    const filter = useFilter();
    const { t, formats } = useTranslations();

    // Der ganze Zustand steht in der Adresszeile: ein Neuladen behält ihn, ein
    // geteilter Link zeigt beim Empfänger dieselbe Auswahl.
    const toggleErrors = (value) => {
        const query = filterQuery(filter.value);

        if (value) {
            query.set('errors', '1');
        }

        router.get(
            `${window.location.pathname}?${query.toString()}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    return (
        <>
            <PageHead
                title={t('replays.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('replays.help.purpose')}</li>
                        <li>{t('replays.help.entry')}</li>
                        <li>{t('replays.help.masking')}</li>
                        <li>{t('replays.help.retention')}</li>
                        <li>{t('replays.help.sampling')}</li>
                    </ul>
                }
            />

            <Card title={t('replays.list.heading')}>
                <div className="mb-4 flex flex-wrap items-center gap-4">
                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input
                            type="checkbox"
                            checked={onlyWithErrors}
                            onChange={(event) => toggleErrors(event.target.checked)}
                            className="rounded border-gray-300 text-rose-600 focus:ring-rose-500 dark:border-gray-600 dark:bg-gray-900"
                        />
                        {t('replays.list.only_errors')}
                    </label>

                    {total > 0 && (
                        <span className="text-xs text-gray-500 dark:text-gray-400">
                            {total > listLimit
                                ? t('replays.list.limit', {
                                      limit: formatNumber(listLimit, formats),
                                      total: formatNumber(total, formats),
                                  })
                                : t('replays.list.total', {
                                      count: formatNumber(total, formats),
                                  })}
                        </span>
                    )}
                </div>

                {replays.length === 0 ? (
                    <div className="py-8 text-center">
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            {t(onlyWithErrors ? 'replays.list.empty_errors' : 'replays.list.empty')}
                        </p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('replays.list.empty_hint')}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <tr>
                                    <th scope="col" className="px-3 py-2 text-start font-medium">
                                        {t('replays.list.columns.started')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-start font-medium">
                                        {t('replays.list.columns.user')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-start font-medium">
                                        {t('replays.list.columns.url')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-end font-medium">
                                        {t('replays.list.columns.duration')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-end font-medium">
                                        {t('replays.list.columns.errors')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-start font-medium">
                                        {t('replays.list.columns.browser')}
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-start font-medium">
                                        {t('replays.list.columns.environment')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                {replays.map((replay) => (
                                    <tr
                                        key={replay.id}
                                        className="hover:bg-gray-50 dark:hover:bg-gray-700/40"
                                    >
                                        <td className="px-3 py-2">
                                            <Link
                                                href={replay.href}
                                                className="font-medium text-rose-600 hover:underline dark:text-rose-400"
                                                title={t('replays.list.open')}
                                            >
                                                {formatDateTime(replay.startedAt, formats)}
                                            </Link>
                                            {replay.ongoing && (
                                                <span className="ms-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                    {t('replays.list.ongoing')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {replay.user ? (
                                                <span className="font-mono text-xs break-all">
                                                    {replay.user.label}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-gray-500 dark:text-gray-400">
                                                    {t('replays.list.anonymous')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span
                                                className="font-mono text-xs break-all"
                                                title={replay.url ?? undefined}
                                            >
                                                {shortUrl(replay.url) ?? '—'}
                                            </span>
                                            {replay.urlCount > 1 && (
                                                <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                                                    {t('replays.list.more_urls', {
                                                        count: formatNumber(
                                                            replay.urlCount - 1,
                                                            formats
                                                        ),
                                                    })}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-end tabular-nums">
                                            {clock(replay.durationMs)}
                                        </td>
                                        <td className="px-3 py-2 text-end tabular-nums">
                                            {replay.errorCount > 0 ? (
                                                <span className="font-medium text-rose-600 dark:text-rose-400">
                                                    {formatNumber(replay.errorCount, formats)}
                                                </span>
                                            ) : (
                                                <span className="text-gray-400 dark:text-gray-500">
                                                    0
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">
                                            {replay.browser ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">
                                            {replay.environment}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </>
    );
}
