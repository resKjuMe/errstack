import React, { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { SecondaryButton } from '../../components/Form.jsx';
import { formatDuration } from '../../duration.js';
import { formatDateTime, formatNumber, useTranslations } from '../../i18n.js';
import Waterfall from './Waterfall.jsx';
import SpanDetails, { ErrorLine } from './SpanDetails.jsx';
import { ancestorsOf, collapsibleKeys } from './rows.js';

// Die Trace-Ansicht: der gesamte Ablauf eines Aufrufs über alle Dienste hinweg.
//
// Der geöffnete Schritt steht in der Adresszeile (`?schritt=`) und nicht nur im
// Zustand dieser Seite. Dieselbe Entscheidung wie bei den übrigen
// Auswertungsseiten und aus demselben Grund: „schau dir diese Abfrage an" ist
// die häufigste Verwendung, und das ist nur dann ein Link, wenn die Auswahl in
// der Adresse steht.
//
// Nachgeladen wird dabei ausschließlich der Schritt (`only: ['span',
// 'selected']`). Die Spur selbst bleibt stehen — sie ein zweites Mal zu lesen
// und zusammenzusetzen wäre bei zehntausend Schritten die teuerste Antwort auf
// einen Klick.
export default function Show({ trace, waterfall, selected, span }) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    const [collapsed, setCollapsed] = useState(() => new Set());
    const [loading, setLoading] = useState(false);

    const rows = waterfall.rows;

    // Ein Verweis von außen zeigt auf einen Schritt, der unter etwas
    // Zugeklapptem liegen kann. Dann wird aufgeklappt, was ihn verdeckt — sonst
    // landete man auf einer Spur, in der die gesuchte Zeile nicht dasteht.
    useEffect(() => {
        if (selected === null) {
            return;
        }

        const ancestors = ancestorsOf(rows, selected);

        if (ancestors.length === 0) {
            return;
        }

        setCollapsed((current) => {
            if (!ancestors.some((key) => current.has(key))) {
                return current;
            }

            const next = new Set(current);
            ancestors.forEach((key) => next.delete(key));

            return next;
        });
    }, [selected, rows]);

    const toggle = (key) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (!next.delete(key)) {
                next.add(key);
            }

            return next;
        });

    const select = (row) => visit(row.spanId === selected ? null : row.spanId);

    const visit = (spanId) => {
        const query = new URLSearchParams();

        if (spanId) {
            query.set('schritt', spanId);
        }

        const search = query.toString();

        router.get(
            `${window.location.pathname}${search === '' ? '' : `?${search}`}`,
            {},
            {
                only: ['span', 'selected'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            }
        );
    };

    const collapsible = useMemo(() => collapsibleKeys(rows), [rows]);
    const empty = rows.length === 0;

    return (
        <>
            <PageHead
                title={t('traces.title')}
                appName={shell.appName}
                meta={
                    <span className="font-mono text-xs break-all text-gray-500 dark:text-gray-400">
                        {trace}
                    </span>
                }
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('traces.help.purpose')}</li>
                        <li>{t('traces.help.waterfall')}</li>
                        <li>{t('traces.help.gaps')}</li>
                        <li>{t('traces.help.errors')}</li>
                        <li>{t('traces.help.select')}</li>
                    </ul>
                }
            />

            {empty ? (
                <Card>
                    <div className="py-8 text-center">
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            {t('traces.empty.title')}
                        </p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('traces.empty.hint')}
                        </p>
                    </div>
                </Card>
            ) : (
                <>
                    <Summary waterfall={waterfall} t={t} formats={formats} />

                    {waterfall.truncated && (
                        <p className="mb-6 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                            {t('traces.truncated', {
                                transactions: formatNumber(waterfall.limits.transactions, formats),
                                spans: formatNumber(waterfall.limits.spans, formats),
                                errors: formatNumber(waterfall.limits.errors, formats),
                            })}
                        </p>
                    )}

                    {waterfall.looseErrors.length > 0 && (
                        <Card
                            title={t('traces.errors.loose')}
                            description={t('traces.errors.loose_hint')}
                            className="mb-6"
                        >
                            <ul className="space-y-2">
                                {waterfall.looseErrors.map((error) => (
                                    <li key={error.id}>
                                        <ErrorLine error={error} t={t} formats={formats} />
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}

                    <Card title={t('traces.waterfall.heading')}>
                        <div className="mb-3 flex flex-wrap gap-2">
                            <SecondaryButton
                                type="button"
                                onClick={() => setCollapsed(new Set(collapsible))}
                            >
                                {t('traces.waterfall.collapse_all')}
                            </SecondaryButton>
                            <SecondaryButton type="button" onClick={() => setCollapsed(new Set())}>
                                {t('traces.waterfall.expand_all')}
                            </SecondaryButton>
                        </div>

                        <Waterfall
                            rows={rows}
                            totalUs={waterfall.durationUs}
                            collapsed={collapsed}
                            onToggle={toggle}
                            selected={selected}
                            onSelect={select}
                        />
                    </Card>

                    {selected !== null && (
                        <SpanDetails span={span} loading={loading} onClose={() => visit(null)} />
                    )}
                </>
            )}
        </>
    );
}

// Was die Spur in einem Satz ist: wie lange, wie viele Dienste, wie viele
// Fehler. Steht über dem Wasserfall, weil die Frage „lohnt das Hinsehen"
// beantwortet sein soll, bevor jemand zehntausend Zeilen liest.
function Summary({ waterfall, t, formats }) {
    return (
        <Card className="mb-6">
            <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3 lg:grid-cols-5">
                <Metric
                    label={t('traces.summary.duration')}
                    value={formatDuration(waterfall.durationUs, t, formats)}
                />
                <Metric
                    label={t('traces.summary.started')}
                    value={formatDateTime(waterfall.startedAt, formats, {
                        dateStyle: 'medium',
                        timeStyle: 'medium',
                    })}
                />
                <Metric
                    label={t('traces.summary.transactions')}
                    value={formatNumber(waterfall.transactions, formats)}
                />
                <Metric
                    label={t('traces.summary.spans')}
                    value={formatNumber(waterfall.spans, formats)}
                />
                <Metric
                    label={t('traces.summary.errors')}
                    value={formatNumber(waterfall.errors, formats)}
                    alarming={waterfall.errors > 0}
                />
            </dl>

            {waterfall.services.length > 0 && (
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                        {t('traces.summary.services')}
                    </span>
                    {waterfall.services.map((service) => (
                        <span
                            key={service.slug}
                            className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200"
                        >
                            {service.name}
                            <span className="ms-1 text-gray-500 dark:text-gray-400">
                                {formatNumber(service.transactions, formats)}
                            </span>
                        </span>
                    ))}
                </div>
            )}
        </Card>
    );
}

function Metric({ label, value, alarming = false }) {
    return (
        <div>
            <dt className="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                {label}
            </dt>
            <dd
                className={`mt-1 text-lg font-semibold tabular-nums ${
                    alarming
                        ? 'text-rose-600 dark:text-rose-400'
                        : 'text-gray-900 dark:text-gray-100'
                }`}
            >
                {value}
            </dd>
        </div>
    );
}
