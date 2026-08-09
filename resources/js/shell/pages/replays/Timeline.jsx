import React, { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { formatNumber, useTranslations } from '../../i18n.js';
import { bytes, clock, severityClass, shortUrl, statusClass } from './format.js';

// Die Spuren neben dem Film — und der Balken darüber.
//
// Der Balken ist die eigentliche Auskunft der Seite: er zeigt auf einen Blick,
// wo in einer Sitzung von acht Minuten etwas passiert ist. Ohne ihn müsste man
// den Film ansehen, um zu wissen, ob er sich anzusehen lohnt.
//
// Darunter die Spuren in Reitern statt untereinander. Der Grund ist die Menge:
// eine Sitzung bringt hunderte Netzwerkanfragen und ein paar Klicks mit, und
// untereinander verdeckt die eine Liste die andere vollständig.
export default function Timeline({ errors, timeline, durationMs, currentMs, onJump }) {
    const { t, formats } = useTranslations();
    const [track, setTrack] = useState(errors.length > 0 ? 'errors' : 'breadcrumbs');

    const tracks = useMemo(
        () => ({
            errors: errors.map((error) => ({
                offsetMs: error.offsetMs,
                key: `error-${error.eventId}`,
                level: error.level,
                primary: error.title,
                secondary: error.culprit,
                href: error.href,
            })),
            breadcrumbs: (timeline?.breadcrumbs ?? []).map((crumb, index) => ({
                offsetMs: crumb.offsetMs,
                key: `crumb-${index}`,
                level: crumb.level,
                primary: crumb.category ?? '—',
                secondary: crumb.message,
            })),
            console: (timeline?.console ?? []).map((line, index) => ({
                offsetMs: line.offsetMs,
                key: `console-${index}`,
                level: line.level,
                primary: line.message ?? '',
                secondary: (line.arguments ?? []).join(' '),
            })),
        }),
        [errors, timeline]
    );

    const network = timeline?.network ?? [];
    const truncated = timeline?.truncated ?? {};

    return (
        <div>
            <Ruler
                errors={errors}
                durationMs={durationMs}
                currentMs={currentMs}
                onJump={onJump}
                t={t}
            />

            <div className="mt-4 flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700">
                <Tab
                    active={track === 'errors'}
                    onClick={() => setTrack('errors')}
                    label={t('replays.tracks.errors')}
                    count={errors.length}
                    accent
                />
                <Tab
                    active={track === 'breadcrumbs'}
                    onClick={() => setTrack('breadcrumbs')}
                    label={t('replays.tracks.breadcrumbs')}
                    count={tracks.breadcrumbs.length}
                />
                <Tab
                    active={track === 'console'}
                    onClick={() => setTrack('console')}
                    label={t('replays.tracks.console')}
                    count={tracks.console.length}
                />
                <Tab
                    active={track === 'network'}
                    onClick={() => setTrack('network')}
                    label={t('replays.tracks.network')}
                    count={network.length}
                />
            </div>

            <div className="mt-3 max-h-96 overflow-y-auto">
                {track === 'network' ? (
                    <NetworkTrack rows={network} onJump={onJump} t={t} formats={formats} />
                ) : (
                    <EntryTrack rows={tracks[track]} onJump={onJump} t={t} />
                )}

                {truncated[track] > 0 && (
                    <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {t('replays.timeline.truncated', {
                            count: formatNumber(truncated[track], formats),
                        })}
                    </p>
                )}
            </div>
        </div>
    );
}

// Der Balken über den Spuren: die Sitzung als Strecke, die Fehler als Marken.
//
// Ein Klick darauf springt an die Stelle — auch zwischen den Marken. Das ist der
// Grund, warum es ein Balken ist und keine Reihe von Punkten: „ungefähr in der
// Mitte" ist eine Angabe, die jemand machen will.
function Ruler({ errors, durationMs, currentMs, onJump, t }) {
    const total = Math.max(1, durationMs);
    const percent = (ms) => `${Math.min(100, Math.max(0, (ms / total) * 100))}%`;

    return (
        <div>
            <div
                role="presentation"
                onClick={(event) => {
                    const rect = event.currentTarget.getBoundingClientRect();
                    const ratio = (event.clientX - rect.left) / Math.max(1, rect.width);

                    onJump(Math.round(ratio * total));
                }}
                className="relative h-8 cursor-pointer rounded bg-gray-100 dark:bg-gray-700"
            >
                <div
                    className="absolute inset-y-0 start-0 rounded-s bg-gray-300 dark:bg-gray-600"
                    style={{ width: percent(currentMs) }}
                />

                {errors.map((error) => (
                    <button
                        key={error.eventId}
                        type="button"
                        title={`${clock(error.offsetMs)} · ${error.title}`}
                        aria-label={t('replays.timeline.jump')}
                        onClick={(event) => {
                            event.stopPropagation();
                            onJump(error.offsetMs);
                        }}
                        className="absolute top-0 h-8 w-1 -translate-x-1/2 rounded bg-rose-500 hover:w-1.5 dark:bg-rose-400"
                        style={{ insetInlineStart: percent(error.offsetMs) }}
                    />
                ))}
            </div>

            <div className="mt-1 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>0:00</span>
                <span>{t('replays.timeline.hint')}</span>
                <span className="tabular-nums">{clock(durationMs)}</span>
            </div>
        </div>
    );
}

function Tab({ active, onClick, label, count, accent = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${
                active
                    ? 'border-rose-500 font-medium text-rose-600 dark:text-rose-400'
                    : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100'
            }`}
        >
            {label}
            <span
                className={`ms-2 rounded px-1.5 py-0.5 text-xs ${
                    accent && count > 0
                        ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'
                        : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
                }`}
            >
                {count}
            </span>
        </button>
    );
}

function EntryTrack({ rows, onJump, t }) {
    if (rows.length === 0) {
        return (
            <p className="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                {t('replays.tracks.empty')}
            </p>
        );
    }

    return (
        <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
            {rows.map((row) => (
                <li key={row.key} className="flex items-start gap-3 py-2">
                    <button
                        type="button"
                        onClick={() => onJump(row.offsetMs)}
                        title={t('replays.timeline.jump')}
                        className="tabular-nums text-xs text-rose-600 hover:underline dark:text-rose-400"
                    >
                        {clock(row.offsetMs)}
                    </button>

                    <div className="min-w-0 flex-1">
                        <p className={`break-words ${severityClass(row.level)}`}>
                            {row.href ? (
                                <Link href={row.href} className="hover:underline">
                                    {row.primary}
                                </Link>
                            ) : (
                                row.primary
                            )}
                        </p>
                        {row.secondary && (
                            <p className="mt-0.5 font-mono text-xs break-all text-gray-500 dark:text-gray-400">
                                {row.secondary}
                            </p>
                        )}
                    </div>
                </li>
            ))}
        </ul>
    );
}

function NetworkTrack({ rows, onJump, t, formats }) {
    if (rows.length === 0) {
        return (
            <p className="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                {t('replays.tracks.empty')}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th scope="col" className="px-2 py-1 text-start font-medium">
                            {t('replays.tracks.network_columns.time')}
                        </th>
                        <th scope="col" className="px-2 py-1 text-start font-medium">
                            {t('replays.tracks.network_columns.method')}
                        </th>
                        <th scope="col" className="px-2 py-1 text-start font-medium">
                            {t('replays.tracks.network_columns.description')}
                        </th>
                        <th scope="col" className="px-2 py-1 text-end font-medium">
                            {t('replays.tracks.network_columns.status')}
                        </th>
                        <th scope="col" className="px-2 py-1 text-end font-medium">
                            {t('replays.tracks.network_columns.size')}
                        </th>
                        <th scope="col" className="px-2 py-1 text-end font-medium">
                            {t('replays.tracks.network_columns.duration')}
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {rows.map((row, index) => (
                        <tr key={`${row.offsetMs}-${index}`}>
                            <td className="px-2 py-1">
                                <button
                                    type="button"
                                    onClick={() => onJump(row.offsetMs)}
                                    title={t('replays.timeline.jump')}
                                    className="tabular-nums text-xs text-rose-600 hover:underline dark:text-rose-400"
                                >
                                    {clock(row.offsetMs)}
                                </button>
                            </td>
                            <td className="px-2 py-1 text-xs uppercase">{row.method ?? '—'}</td>
                            <td className="px-2 py-1">
                                <span
                                    className="font-mono text-xs break-all"
                                    title={row.description ?? undefined}
                                >
                                    {shortUrl(row.description) ?? row.op ?? '—'}
                                </span>
                            </td>
                            <td
                                className={`px-2 py-1 text-end tabular-nums text-xs ${statusClass(row.status)}`}
                            >
                                {row.status ?? '—'}
                            </td>
                            <td className="px-2 py-1 text-end tabular-nums text-xs">
                                {row.size === null || row.size === undefined
                                    ? '—'
                                    : bytes(row.size, formats)}
                            </td>
                            <td className="px-2 py-1 text-end tabular-nums text-xs">
                                {row.durationMs === null || row.durationMs === undefined
                                    ? '—'
                                    : `${formatNumber(row.durationMs, formats)} ms`}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
