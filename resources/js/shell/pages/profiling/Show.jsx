import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { formatDateTime, formatNumber, useTranslations } from '../../i18n.js';
import Flamegraph from './Flamegraph.jsx';
import FunctionList from './FunctionList.jsx';
import { duration } from './format.jsx';

// Ein einzelnes Profil: was **dieser** Aufruf getan hat.
//
// Der Kopf stellt zwei Zahlen nebeneinander, und das ist die wichtigste Auskunft
// der Seite: die Antwortzeit der Transaktion und die Rechenzeit des Profils.
// Klaffen sie auseinander, hat die Anwendung gewartet und nicht gerechnet — dann
// ist der Flamegraph darunter die falsche Spur, und die Einzelschritte der
// Transaktion sind die richtige.
export default function ProfilingShow({ profile, frames, flamegraph, transaction, aggregateHref }) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    return (
        <>
            <PageHead
                title={t('profiling.detail_title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('profiling.help.purpose')}</li>
                        <li>{t('profiling.help.gap')}</li>
                        <li>{t('profiling.help.self_total')}</li>
                        <li>{t('profiling.help.sampling')}</li>
                    </ul>
                }
                meta={
                    <Link
                        href={aggregateHref}
                        className="text-sm text-rose-600 hover:underline dark:text-rose-400"
                    >
                        {t('profiling.profile.aggregate_link')}
                    </Link>
                }
            />

            <Card className="mb-6">
                <dl className="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                    <Fact label={t('profiling.profile.transaction')}>
                        <span className="font-mono break-all">{profile.transactionName}</span>
                        {transaction?.op && (
                            <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                                {transaction.op}
                            </span>
                        )}
                    </Fact>
                    <Fact label={t('profiling.profile.started')}>
                        {formatDateTime(profile.startedAt, formats)}
                    </Fact>
                    <Fact label={t('profiling.profile.cpu')}>
                        {duration(profile.durationUs, t, formats)}
                    </Fact>
                    <Fact label={t('profiling.profile.wall')}>
                        {transaction ? duration(transaction.durationUs, t, formats) : '—'}
                    </Fact>
                    <Fact label={t('profiling.profile.samples')}>
                        {formatNumber(profile.sampleCount, formats)}
                    </Fact>
                    <Fact label={t('profiling.profile.thread')}>{profile.threadId ?? '—'}</Fact>
                    <Fact label={t('profiling.profile.environment')}>{profile.environment}</Fact>
                    <Fact label={t('profiling.profile.release')}>
                        <span className="font-mono text-xs break-all">
                            {profile.release ?? '—'}
                        </span>
                    </Fact>
                </dl>
            </Card>

            <Card className="mb-6" title={t('profiling.flamegraph.heading')}>
                <Flamegraph
                    roots={flamegraph.roots}
                    frames={frames}
                    totalUs={flamegraph.totalUs}
                    dropped={flamegraph.droppedNodes}
                    pruned={flamegraph.prunedNodes}
                />
            </Card>

            <Card title={t('profiling.functions.heading')}>
                <FunctionList
                    functions={flamegraph.functions}
                    totalUs={flamegraph.totalUs}
                    limit={flamegraph.functions.length}
                    functionCount={flamegraph.functionCount}
                />
            </Card>
        </>
    );
}

function Fact({ label, children }) {
    return (
        <div>
            <dt className="text-xs uppercase text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="mt-1 text-gray-900 dark:text-gray-100">{children}</dd>
        </div>
    );
}
