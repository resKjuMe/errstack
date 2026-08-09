import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { formatDateTime, formatNumber, useTranslations } from '../../i18n.js';
import Player, { Position } from './Player.jsx';
import Timeline from './Timeline.jsx';
import { bytes, clock, shortUrl } from './format.js';

// Die Abspielseite: der Film, die Zeitleiste, die Sitzung.
//
// Die Seite steht sofort — Kopfdaten und Sprungmarken sind klein und kommen mit
// der Antwort. Die Bilddaten holt sie danach selbst nach: sie wiegen Megabyte,
// und eine Seite, die erst erscheint, wenn alles da ist, sieht aus, als hinge
// sie.
export default function ReplaysShow({ replay, errors, dataHref, listHref }) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    const playerRef = useRef(null);
    const [events, setEvents] = useState(null);
    const [timeline, setTimeline] = useState(null);
    const [failed, setFailed] = useState(false);
    const [currentMs, setCurrentMs] = useState(0);
    const [playing, setPlaying] = useState(false);
    const [speed, setSpeed] = useState(1);
    const [skipInactive, setSkipInactive] = useState(false);

    useEffect(() => {
        const controller = new AbortController();

        fetch(dataHref, { signal: controller.signal, headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json();
            })
            .then((data) => {
                setEvents(Array.isArray(data.events) ? data.events : []);
                setTimeline(data.timeline ?? null);
            })
            .catch((error) => {
                // Ein abgebrochener Abruf ist kein Fehlschlag: er passiert, wenn
                // jemand die Seite verlässt, bevor die Sitzung geladen ist.
                if (error.name !== 'AbortError') {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [dataHref]);

    const jump = useCallback((offsetMs) => {
        setCurrentMs(offsetMs);
        playerRef.current?.goto(offsetMs);
    }, []);

    const toggle = () => {
        if (playing) {
            playerRef.current?.pause();
        } else {
            playerRef.current?.play();
        }
    };

    const changeSpeed = (value) => {
        setSpeed(value);
        playerRef.current?.setSpeed(value);
    };

    const toggleSkip = () => {
        setSkipInactive((previous) => !previous);
        playerRef.current?.toggleSkipInactive();
    };

    return (
        <>
            <PageHead
                title={t('replays.detail_title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('replays.help.purpose')}</li>
                        <li>{t('replays.help.masking')}</li>
                        <li>{t('replays.help.retention')}</li>
                    </ul>
                }
                meta={
                    <Link
                        href={listHref}
                        className="text-sm text-rose-600 hover:underline dark:text-rose-400"
                    >
                        {t('replays.title')}
                    </Link>
                }
            />

            {!replay.masked && (
                <div className="mb-6 rounded border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
                    <p className="text-sm font-medium text-amber-800 dark:text-amber-200">
                        {t('replays.masking.unmasked')}
                    </p>
                    <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                        {t('replays.masking.unmasked_hint')}
                    </p>
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Card title={t('replays.player.heading')}>
                        {failed ? (
                            <div className="rounded border border-dashed border-gray-300 p-8 text-center dark:border-gray-600">
                                <p className="text-sm text-gray-700 dark:text-gray-200">
                                    {t('replays.player.failed')}
                                </p>
                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {t('replays.player.failed_hint')}
                                </p>
                            </div>
                        ) : events === null ? (
                            <div className="rounded border border-dashed border-gray-300 p-8 text-center dark:border-gray-600">
                                <p className="text-sm text-gray-700 dark:text-gray-200">
                                    {t('replays.player.loading')}
                                </p>
                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {t('replays.player.loading_hint')}
                                </p>
                            </div>
                        ) : events.length < 2 ? (
                            <p className="py-8 text-center text-sm text-gray-600 dark:text-gray-300">
                                {t('replays.player.empty')}
                            </p>
                        ) : (
                            <>
                                <Player
                                    ref={playerRef}
                                    events={events}
                                    onTime={setCurrentMs}
                                    onState={(state) => setPlaying(state === 'playing')}
                                />

                                <div className="mt-3 flex flex-wrap items-center gap-3">
                                    <button
                                        type="button"
                                        onClick={toggle}
                                        className="rounded bg-rose-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-rose-700"
                                    >
                                        {t(
                                            playing ? 'replays.player.pause' : 'replays.player.play'
                                        )}
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => jump(0)}
                                        className="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                    >
                                        {t('replays.player.restart')}
                                    </button>

                                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                        {t('replays.player.speed')}
                                        <select
                                            value={speed}
                                            onChange={(event) =>
                                                changeSpeed(Number(event.target.value))
                                            }
                                            className="rounded border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900"
                                        >
                                            {[0.5, 1, 2, 4, 8].map((value) => (
                                                <option key={value} value={value}>
                                                    {value}×
                                                </option>
                                            ))}
                                        </select>
                                    </label>

                                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                        <input
                                            type="checkbox"
                                            checked={skipInactive}
                                            onChange={toggleSkip}
                                            className="rounded border-gray-300 text-rose-600 focus:ring-rose-500 dark:border-gray-600 dark:bg-gray-900"
                                        />
                                        {t('replays.player.skip_inactive')}
                                    </label>

                                    <Position
                                        currentMs={currentMs}
                                        durationMs={replay.durationMs}
                                    />
                                </div>
                            </>
                        )}
                    </Card>

                    <Card className="mt-6" title={t('replays.timeline.heading')}>
                        <Timeline
                            errors={errors}
                            timeline={timeline}
                            durationMs={replay.durationMs}
                            currentMs={currentMs}
                            onJump={jump}
                        />
                    </Card>
                </div>

                <Card title={t('replays.meta.heading')}>
                    <dl className="space-y-3 text-sm">
                        <Fact label={t('replays.meta.user')}>
                            {replay.user ? (
                                <span className="font-mono break-all">{replay.user.label}</span>
                            ) : (
                                t('replays.list.anonymous')
                            )}
                        </Fact>
                        <Fact label={t('replays.meta.started')}>
                            {formatDateTime(replay.startedAt, formats)}
                        </Fact>
                        <Fact label={t('replays.meta.duration')}>{clock(replay.durationMs)}</Fact>
                        <Fact label={t('replays.meta.browser')}>
                            {replay.browser ?? t('replays.meta.unknown')}
                        </Fact>
                        <Fact label={t('replays.meta.os')}>
                            {replay.os ?? t('replays.meta.unknown')}
                        </Fact>
                        {replay.device && (
                            <Fact label={t('replays.meta.device')}>{replay.device}</Fact>
                        )}
                        <Fact label={t('replays.meta.environment')}>{replay.environment}</Fact>
                        {replay.release && (
                            <Fact label={t('replays.meta.release')}>{replay.release}</Fact>
                        )}
                        <Fact label={t('replays.meta.sdk')}>
                            {replay.sdk ?? t('replays.meta.unknown')}
                        </Fact>
                        <Fact label={t('replays.meta.segments')}>
                            {formatNumber(replay.segmentCount, formats)}
                        </Fact>
                        <Fact label={t('replays.meta.events')}>
                            {formatNumber(replay.eventCount, formats)}
                        </Fact>
                        <Fact label={t('replays.meta.size')}>
                            {bytes(replay.sizeBytes, formats)}
                        </Fact>
                        <Fact label={t('replays.masking.masked')}>
                            {replay.masked
                                ? t('replays.masking.masked_hint')
                                : t('replays.masking.unmasked_hint')}
                        </Fact>
                        <Fact label={t('replays.meta.replay_id')}>
                            <span className="font-mono text-xs break-all">{replay.replayId}</span>
                        </Fact>
                    </dl>

                    {replay.urls.length > 0 && (
                        <div className="mt-4">
                            <h3 className="text-xs uppercase text-gray-500 dark:text-gray-400">
                                {t('replays.meta.urls')}
                            </h3>
                            <ul className="mt-2 space-y-1">
                                {replay.urls.map((url) => (
                                    <li
                                        key={url}
                                        className="font-mono text-xs break-all text-gray-600 dark:text-gray-300"
                                        title={url}
                                    >
                                        {shortUrl(url)}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}

function Fact({ label, children }) {
    return (
        <div>
            <dt className="text-xs uppercase text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="mt-0.5 text-gray-900 dark:text-gray-100">{children}</dd>
        </div>
    );
}
