import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import Pagination from '../../components/Pagination.jsx';
import { InputLabel, SecondaryButton, SelectInput } from '../../components/Form.jsx';
import useFilter from '../../filters/useFilter.js';
import { useTranslations } from '../../i18n.js';
import { duration } from './format.jsx';

// Die Trend-Liste: welche Transaktionen umgeschlagen sind und wann.
//
// **Was diese Liste gegenüber der Übersicht hinzufügt, ist der Zeitpunkt.** Der
// Pfeil in der Übersicht sagt „langsamer als im Vorzeitraum" — hier steht,
// **wann** es passiert ist, und daneben, was zu diesem Zeitpunkt ausgeliefert
// wurde. Das ist der ganze Zweck: eine Verschlechterung ohne Zeitpunkt ist eine
// Suche, eine mit Zeitpunkt ist ein Verdacht.
//
// Der Zustand steht wie überall vollständig in der Adresszeile.
export default function Trends({
    trends,
    list,
    sortOptions,
    directionOptions,
    seenOptions,
    thresholds,
    totalLabel,
    overviewHref,
}) {
    const { shell } = usePage().props;
    const filter = useFilter();
    const { t, formats } = useTranslations();

    // Sortierung, Richtung und Stand sind Felder dieser Seite; die übrigen
    // Parameter der Adresszeile bleiben stehen. Eine neue Auswahl beginnt auf
    // Seite 1 — „Seite 7" einer anderen Reihenfolge ist eine andere Seite 7.
    const go = (patch) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) => query.set(key, value));
        query.delete('page');

        router.get(`${window.location.pathname}?${query.toString()}`, {}, { preserveState: true });
    };

    const showProject = filter.value.projects.length !== 1;

    return (
        <>
            <PageHead
                title={t('performance_trends.title')}
                appName={shell.appName}
                help={t('performance_trends.help')}
            />

            <Card className="mb-4">
                <div className="flex flex-wrap items-end gap-4">
                    <div>
                        <InputLabel
                            htmlFor="trend_sort"
                            value={t('performance_trends.filter.sort')}
                        />
                        <SelectInput
                            id="trend_sort"
                            className="mt-1"
                            value={list.sort}
                            options={sortOptions}
                            onChange={(e) => go({ sort: e.target.value })}
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="trend_direction"
                            value={t('performance_trends.filter.direction')}
                        />
                        <SelectInput
                            id="trend_direction"
                            className="mt-1"
                            value={list.direction}
                            options={directionOptions}
                            onChange={(e) => go({ direction: e.target.value })}
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="trend_seen"
                            value={t('performance_trends.filter.seen')}
                        />
                        <SelectInput
                            id="trend_seen"
                            className="mt-1"
                            value={list.seen}
                            options={seenOptions}
                            onChange={(e) => go({ seen: e.target.value })}
                        />
                    </div>

                    <div className="ms-auto flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                        <Link
                            href={overviewHref}
                            className="underline hover:text-gray-700 dark:hover:text-gray-200"
                        >
                            {t('performance_trends.overview')}
                        </Link>
                        <span>{t('performance_trends.list.count', { count: totalLabel })}</span>
                    </div>
                </div>
            </Card>

            <div className="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                {trends.data.length === 0 ? (
                    <div className="p-6 text-sm text-gray-500 dark:text-gray-400">
                        <p>{t('performance_trends.list.empty')}</p>
                        <p className="mt-1 text-xs">{t('performance_trends.list.empty_hint')}</p>
                    </div>
                ) : (
                    <>
                        <div className="flex items-center gap-4 border-b border-gray-100 bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                            <div className="min-w-0 flex-1">
                                {t('performance_trends.columns.transaction')}
                            </div>
                            <div className="w-36 text-right">
                                {t('performance_trends.columns.change')}
                            </div>
                            <div className="hidden w-44 text-right md:block">
                                {t('performance_trends.columns.breakpoint')}
                            </div>
                            <div className="hidden w-48 text-right lg:block">
                                {t('performance_trends.columns.cause')}
                            </div>
                            <div className="w-40 text-right">
                                {t('performance_trends.columns.state')}
                            </div>
                        </div>

                        <ul className="divide-y divide-gray-100 dark:divide-gray-700">
                            {trends.data.map((trend) => (
                                <Row
                                    key={trend.id}
                                    trend={trend}
                                    showProject={showProject}
                                    t={t}
                                    formats={formats}
                                />
                            ))}
                        </ul>
                    </>
                )}
            </div>

            {/* Der Maßstab unter der Liste und nicht in der Hilfe: „warum steht
                das hier nicht drin" ist die zweite Frage nach dem Aufschlagen,
                und sie wird an der Liste gestellt, nicht am Fragezeichen. */}
            <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {t('performance_trends.thresholds', thresholds)}
            </p>

            <Pagination links={trends.links} />
        </>
    );
}

// Eine Zeile. Die Spaltenbreiten stimmen mit der Kopfzeile überein — sie stehen
// an beiden Stellen, weil dies eine Liste aus Flex-Zeilen ist und keine Tabelle:
// eine echte Tabelle bekäme die Zeile auf schmalen Geräten nicht umgebrochen.
function Row({ trend, showProject, t, formats }) {
    const worse = trend.direction === 'worse';

    const toggleSeen = () => {
        const options = { preserveScroll: true, preserveState: false };

        trend.isSeen
            ? router.delete(trend.seenUrl, options)
            : router.post(trend.seenUrl, {}, options);
    };

    return (
        <li className="flex items-center gap-4 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <DirectionBadge worse={worse} label={trend.directionLabel} />
                    <Link
                        href={trend.href}
                        className="truncate font-medium text-gray-900 hover:underline dark:text-gray-100"
                    >
                        {trend.name}
                    </Link>
                </div>

                <p className="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">
                    {trend.op}
                    {trend.op && <span className="mx-2">·</span>}
                    {trend.environment}
                    {showProject && trend.project && (
                        <>
                            <span className="mx-2">·</span>
                            <Link
                                href={trend.project.href}
                                className="underline hover:text-gray-700 dark:hover:text-gray-200"
                            >
                                {trend.project.name}
                            </Link>
                        </>
                    )}
                </p>
            </div>

            {/* Die Änderung groß, die beiden Höhen klein darunter: der Prozentwert
                ordnet ein, die Millisekunden sagen, ob es weh tut. Eine
                Verdopplung von 3 ms auf 6 ms und eine von 300 ms auf 600 ms sind
                dieselbe Zahl und nicht dieselbe Nachricht. */}
            <div className="w-36 text-right">
                <p
                    className={
                        worse
                            ? 'font-medium text-rose-600 dark:text-rose-400'
                            : 'font-medium text-emerald-600 dark:text-emerald-400'
                    }
                >
                    {worse ? '+' : '−'}
                    {trend.changeLabel}
                </p>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t('performance_trends.list.from_to', {
                        before: durationText(trend.beforeP95Us, t, formats),
                        after: durationText(trend.afterP95Us, t, formats),
                    })}
                </p>
            </div>

            <div className="hidden w-44 text-right text-sm md:block">
                <p className="text-gray-700 dark:text-gray-300">{trend.breakpointAtLabel}</p>
                {/* Die Aussagekraft steht in der Zeile, weil sie der Grund ist,
                    warum man der Zeile glauben darf. */}
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t('performance_trends.list.confidence', { value: trend.confidenceLabel })}
                </p>
            </div>

            <div className="hidden w-48 text-right text-sm lg:block">
                {trend.deploy ? (
                    <>
                        <p className="truncate font-mono text-xs text-gray-700 dark:text-gray-300">
                            {trend.deploy.version}
                        </p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            {trend.deploy.atLabel}
                        </p>
                    </>
                ) : (
                    <p className="text-xs text-gray-400 dark:text-gray-500">
                        {t('performance_trends.list.no_deploy')}
                    </p>
                )}
            </div>

            <div className="w-40 text-right">
                <SecondaryButton type="button" onClick={toggleSeen}>
                    {trend.isSeen
                        ? t('performance_trends.actions.mark_unseen')
                        : t('performance_trends.actions.mark_seen')}
                </SecondaryButton>

                {trend.isSeen && (
                    <p className="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                        {trend.seenBy
                            ? t('performance_trends.list.seen_by', {
                                  name: trend.seenBy,
                                  at: trend.seenAtLabel,
                              })
                            : t('performance_trends.list.seen_at', { at: trend.seenAtLabel })}
                    </p>
                )}
            </div>
        </li>
    );
}

// Die Richtung als Marke: sie unterscheidet auf einen Blick die schlechte
// Nachricht von der guten, ohne dass man das Vorzeichen lesen muss.
function DirectionBadge({ worse, label }) {
    const className = worse
        ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200'
        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';

    return (
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold ${className}`}>
            {label}
        </span>
    );
}

// Dieselbe Dauer als reiner Text: sie steckt hier in einem übersetzten Satz
// („von 200 ms auf 900 ms") und kann deshalb kein Element sein.
function durationText(microseconds, t, formats) {
    const value = duration(microseconds, t, formats);

    return typeof value === 'string' ? value : '';
}
