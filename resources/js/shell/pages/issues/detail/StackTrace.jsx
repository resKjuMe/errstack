import React, { useState } from 'react';
import { Json, KeyValues } from './Sections.jsx';

// Der Stacktrace — und mit ihm die Ursachenkette.
//
// **Was eingeklappt ist, ist die eigentliche Entscheidung dieser Ansicht.** Ein
// Stacktrace aus einem Rahmenwerk hat vierzig Rahmen, von denen drei aus dem
// eigenen Code stammen; wer alle zeigt, zeigt nichts. Fremde Rahmen liegen
// deshalb zusammengefasst unter einer Zeile, die sagt, wie viele es sind, und
// die sich aufklappen lässt — sie sind nicht weg, sie sind nur nicht im Weg.
//
// Die Reihenfolge kommt fertig vom Server: zuletzt geworfene Ausnahme zuerst,
// innerster Rahmen zuerst.
//
// **Zwei Fassungen desselben Stacktraces** (R5): die gemeldete und, wenn
// Quellkarten hochgeladen sind, die zurückübersetzte. Gezeigt wird die
// zurückübersetzte, sobald es sie gibt — sie ist der Grund, aus dem jemand die
// Karten hochgeladen hat. Umschalten bleibt trotzdem möglich: eine Karte aus
// einem anderen Bauvorgang liefert plausible falsche Zeilen, und der einzige Weg,
// das zu merken, ist der Vergleich mit dem, was gemeldet wurde.
export default function StackTrace({ exceptions, symbolication, t }) {
    const translated = symbolication?.exceptions ?? [];
    const hasTranslation = translated.length > 0;

    const [original, setOriginal] = useState(false);
    const shown = hasTranslation && !original ? translated : exceptions;

    return (
        <div className="space-y-4">
            {symbolication && (
                <SymbolicationBar
                    symbolication={symbolication}
                    original={original}
                    onToggle={hasTranslation ? () => setOriginal((value) => !value) : null}
                    t={t}
                />
            )}

            <div className="space-y-6">
                {shown.map((exception, index) => (
                    <Exception key={index} exception={exception} t={t} />
                ))}
            </div>
        </div>
    );
}

// Der Kopf über dem Stacktrace: welche Fassung zu sehen ist, wie weit die
// Übersetzung kam — und warum sie nicht weiter kam.
//
// **Die Diagnose ist hier der wichtigere Teil.** „Nichts übersetzt" ohne
// Begründung sieht genauso aus wie „niemand hat Quellkarten hochgeladen", und die
// Ursache liegt fast immer in der Bauumgebung: eine Karte, die nicht ausgeliefert
// wurde, ein Pfad, der nicht zur geladenen Adresse passt.
function SymbolicationBar({ symbolication, original, onToggle, t }) {
    const { status, mappedFrames, totalFrames, diagnostics } = symbolication;

    const tone =
        status === 'mapped'
            ? 'text-emerald-700 dark:text-emerald-300'
            : status === 'failed'
              ? 'text-rose-700 dark:text-rose-300'
              : 'text-gray-600 dark:text-gray-400';

    return (
        <div className="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-900/40">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className={`font-medium ${tone}`}>
                    {status === 'pending'
                        ? t('issues.detail.symbolication.pending')
                        : t('issues.detail.symbolication.counted', {
                              mapped: mappedFrames,
                              total: totalFrames,
                          })}
                </span>

                {onToggle !== null && (
                    <button
                        type="button"
                        onClick={onToggle}
                        aria-pressed={original}
                        className="rounded border border-gray-300 px-1.5 py-0.5 font-medium text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        {original
                            ? t('issues.detail.symbolication.show_source')
                            : t('issues.detail.symbolication.show_minified')}
                    </button>
                )}
            </div>

            {diagnostics.length > 0 && (
                <ul className="mt-1.5 space-y-0.5 text-gray-500 dark:text-gray-400">
                    {diagnostics.map((diagnosis, index) => (
                        <li key={index}>
                            {diagnosis.reasonLabel}
                            {diagnosis.detail && (
                                <span className="ms-1 font-mono break-all">{diagnosis.detail}</span>
                            )}
                            <span className="ms-1">
                                {t('issues.detail.symbolication.frame_count', {
                                    count: diagnosis.count,
                                })}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Exception({ exception, t }) {
    const mechanism = exception.mechanism ?? {};
    const handled = mechanism.handled;

    return (
        <div>
            {exception.isCause && (
                <p className="mb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                    {t('issues.detail.exception.caused_by')}
                </p>
            )}

            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <h3 className="font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {exception.type ?? t('issues.detail.frames.unknown_file')}
                </h3>

                {handled === true && (
                    <Badge tone="gray">{t('issues.detail.exception.handled')}</Badge>
                )}
                {handled === false && (
                    <Badge tone="rose">{t('issues.detail.exception.unhandled')}</Badge>
                )}
                {mechanism.type && (
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        {t('issues.detail.exception.mechanism', { type: mechanism.type })}
                    </span>
                )}
            </div>

            {exception.value && (
                <p className="mt-1 text-sm break-words text-gray-700 dark:text-gray-300">
                    {exception.value}
                </p>
            )}

            <Frames frames={exception.frames} t={t} />
        </div>
    );
}

// Die Rahmen einer Ausnahme, fremde zu Blöcken zusammengefasst.
function Frames({ frames, t }) {
    if (frames.length === 0) {
        return (
            <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {t('issues.detail.frames.empty')}
            </p>
        );
    }

    return (
        <ul className="mt-3 divide-y divide-gray-100 overflow-hidden rounded-md border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
            {groupFrames(frames).map((group, index) =>
                group.inApp ? (
                    group.frames.map((frame, frameIndex) => (
                        <Frame key={`${index}-${frameIndex}`} frame={frame} open t={t} />
                    ))
                ) : (
                    <ForeignFrames key={index} frames={group.frames} t={t} />
                )
            )}
        </ul>
    );
}

// Aufeinanderfolgende Rahmen derselben Herkunft gehören zusammen: drei fremde
// Rahmen zwischen zwei eigenen sind ein Block, nicht drei einzelne Zeilen.
function groupFrames(frames) {
    const groups = [];

    frames.forEach((frame) => {
        const last = groups[groups.length - 1];

        if (last && last.inApp === frame.inApp) {
            last.frames.push(frame);

            return;
        }

        groups.push({ inApp: frame.inApp, frames: [frame] });
    });

    return groups;
}

// Ein Block fremder Rahmen: standardmäßig eine Zeile, aufgeklappt alle.
function ForeignFrames({ frames, t }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <li>
                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    aria-expanded={open}
                    className="w-full bg-gray-50 px-4 py-2 text-left text-xs text-gray-500 hover:bg-gray-100 dark:bg-gray-900/40 dark:text-gray-400 dark:hover:bg-gray-900/70"
                >
                    {frames.length === 1
                        ? t('issues.detail.frames.hidden_one')
                        : t('issues.detail.frames.hidden_many', { count: frames.length })}{' '}
                    — {open ? t('issues.detail.frames.hide') : t('issues.detail.frames.show')}
                </button>
            </li>

            {open &&
                frames.map((frame, index) => (
                    <Frame key={index} frame={frame} open={false} t={t} />
                ))}
        </>
    );
}

// Ein einzelner Rahmen: Stelle, Funktion — und aufgeklappt die Code-Umgebung.
//
// Eigene Rahmen stehen offen da, fremde zu, sobald man sie eingeblendet hat: wer
// einen fremden Rahmen aufklappt, will die Liste sehen, nicht vierzig
// Quelltextausschnitte.
function Frame({ frame, open: initiallyOpen, t }) {
    const minified = frame.minified ?? null;
    const hasDetails = frame.context.length > 0 || frame.vars !== null || minified !== null;
    const [open, setOpen] = useState(initiallyOpen && hasDetails);

    const location = [
        frame.lineno === null ? null : t('issues.detail.frames.line', { line: frame.lineno }),
        frame.colno === null ? null : t('issues.detail.frames.column', { column: frame.colno }),
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <li className="bg-white dark:bg-gray-800">
            <div
                className={`flex flex-wrap items-baseline gap-x-2 px-4 py-2 ${
                    frame.inApp ? '' : 'text-gray-500 dark:text-gray-400'
                }`}
            >
                {hasDetails ? (
                    <button
                        type="button"
                        onClick={() => setOpen((value) => !value)}
                        aria-expanded={open}
                        aria-label={t('issues.detail.frames.toggle')}
                        className="min-w-0 flex-1 text-left"
                    >
                        <FrameTitle frame={frame} location={location} t={t} />
                    </button>
                ) : (
                    <div className="min-w-0 flex-1">
                        <FrameTitle frame={frame} location={location} t={t} />
                    </div>
                )}

                {frame.inApp && <Badge tone="indigo">{t('issues.detail.frames.in_app')}</Badge>}
            </div>

            {open && (
                <div className="border-t border-gray-100 px-4 py-3 dark:border-gray-700">
                    {minified !== null && <MinifiedOrigin minified={minified} t={t} />}

                    {frame.context.length > 0 && <CodeContext lines={frame.context} />}

                    {frame.vars !== null && (
                        <div className="mt-3">
                            <p className="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                                {t('issues.detail.frames.vars')}
                            </p>
                            {Array.isArray(frame.vars) ? (
                                <Json value={frame.vars} />
                            ) : (
                                <KeyValues values={frame.vars} />
                            )}
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

function FrameTitle({ frame, location, t }) {
    return (
        <>
            <span className="font-mono text-sm break-all">
                {frame.filename ?? t('issues.detail.frames.unknown_file')}
            </span>
            {frame.function && (
                <span className="ms-2 font-mono text-sm text-gray-500 dark:text-gray-400">
                    {frame.function}
                </span>
            )}
            {location && <span className="ms-2 text-xs whitespace-nowrap">{location}</span>}
        </>
    );
}

// Woher ein zurückübersetzter Rahmen kam: die minimierte Stelle.
//
// Sie steht klein und einzeilig da und ist trotzdem nicht Beiwerk: stimmt die
// Übersetzung nicht, ist diese Zeile die einzige Stelle, an der es auffällt.
function MinifiedOrigin({ minified, t }) {
    const position = [minified.lineno, minified.colno].filter((value) => value !== null).join(':');

    return (
        <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
            {t('issues.detail.symbolication.from')}{' '}
            <span className="font-mono break-all">
                {minified.filename ?? t('issues.detail.frames.unknown_file')}
                {position && `:${position}`}
                {minified.function && ` — ${minified.function}`}
            </span>
        </p>
    );
}

// Der Quelltext um die Fehlerstelle. Die betroffene Zeile ist hervorgehoben —
// ohne sie wäre der Ausschnitt nur ein Ausschnitt.
function CodeContext({ lines }) {
    return (
        <pre className="overflow-x-auto rounded-md bg-gray-50 py-2 font-mono text-xs whitespace-pre text-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
            {lines.map((line, index) => (
                <div
                    key={index}
                    className={line.current ? 'bg-rose-100 px-3 dark:bg-rose-900/40' : 'px-3'}
                >
                    <span className="me-4 inline-block w-10 text-right text-gray-400 select-none dark:text-gray-500">
                        {line.number ?? ''}
                    </span>
                    {line.text}
                </div>
            ))}
        </pre>
    );
}

function Badge({ tone, children }) {
    const tones = {
        gray: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        rose: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
    };

    return (
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold ${tones[tone]}`}>
            {children}
        </span>
    );
}
