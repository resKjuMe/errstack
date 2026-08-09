import React, { useState } from 'react';
import { router } from '@inertiajs/react';

// Die Dateien zu einer Meldung: Screenshot, Logdatei, Speicherabbild.
//
// **Ein Bild wird gezeigt, alles andere angekündigt.** Ein Screenshot ist der
// Grund, warum es Anhänge gibt — wer einen Absturz untersucht, will das Bild
// sehen und nicht erst herunterladen. Eine Logdatei dagegen wird auf Klick
// angerissen: sie ist oft mehrere Megabyte groß, und in eine Fehlerseite gehört
// davon der Anfang, nicht alles.
//
// **Was keine Vorschau hat, bekommt keine.** Ob eine Datei im Browser angesehen
// werden darf, entscheidet der Server beim Ablegen und nicht diese Anzeige: ohne
// `previewHref` gibt es hier keinen Weg, sie doch einzubetten. Das ist Absicht —
// ein Anhang kommt aus einer überwachten Anwendung, und ein als Bild getarntes
// HTML-Dokument würde sonst im Browser eines Teammitglieds ausgeführt.
export default function Attachments({ attachments, t }) {
    return (
        <ul className="divide-y divide-gray-200 dark:divide-gray-700">
            {attachments.items.map((attachment) => (
                <Attachment
                    key={attachment.id}
                    attachment={attachment}
                    canDelete={attachments.canDelete}
                    t={t}
                />
            ))}
        </ul>
    );
}

function Attachment({ attachment, canDelete, t }) {
    return (
        <li className="py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <a
                    href={attachment.downloadHref}
                    className="min-w-0 flex-1 truncate text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                >
                    {attachment.name}
                </a>

                <span className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                    {attachment.kindLabel}
                </span>

                <span className="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                    {attachment.sizeLabel}
                </span>
            </div>

            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('issues.attachments.received', { time: attachment.receivedAtLabel })}
                {attachment.contentType && <span className="ms-2">{attachment.contentType}</span>}
                {/* Die Frist steht an jeder Datei und nicht nur oben am Bereich:
                    „warum ist der Screenshot von letzter Woche weg" ist die Frage,
                    die hier beantwortet werden muss, und zwar bevor sie
                    aufkommt. */}
                <span className="ms-2">
                    {t('issues.attachments.expires', { time: attachment.expiresAtLabel })}
                </span>
            </p>

            <Preview attachment={attachment} t={t} />

            {canDelete && attachment.deleteHref && (
                <button
                    type="button"
                    onClick={() => {
                        if (!window.confirm(t('issues.attachments.delete_confirm'))) {
                            return;
                        }

                        router.delete(attachment.deleteHref, { preserveScroll: true });
                    }}
                    className="mt-2 text-xs text-red-600 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                >
                    {t('issues.attachments.delete')}
                </button>
            )}
        </li>
    );
}

// Die Vorschau — je Art eine andere, und für Dateien ohne Vorschau keine.
function Preview({ attachment, t }) {
    if (attachment.previewHref === null) {
        return null;
    }

    return attachment.kind === 'image' ? (
        <ImagePreview attachment={attachment} t={t} />
    ) : (
        <TextPreview attachment={attachment} t={t} />
    );
}

// Ein Bild steht sofort da, in der Höhe begrenzt und verlinkt auf die
// Originalgröße: ein Screenshot einer 4K-Anzeige würde die Seite sonst allein
// füllen, und genau diesen Screenshot will man dann doch ganz sehen.
function ImagePreview({ attachment, t }) {
    return (
        <a
            href={attachment.previewHref}
            target="_blank"
            rel="noopener noreferrer"
            className="mt-2 block"
        >
            <img
                src={attachment.previewHref}
                alt={t('issues.attachments.image_alt', { name: attachment.name })}
                loading="lazy"
                className="max-h-64 max-w-full rounded border border-gray-200 dark:border-gray-700"
            />
        </a>
    );
}

// Text wird erst auf Klick geholt — dieselbe Überlegung wie bei der Rohansicht
// ({@see RawData.jsx}): eine Seite mit fünf Logdateien würde sonst fünf Dateien
// mitliefern, die meist niemand aufschlägt.
function TextPreview({ attachment, t }) {
    const [state, setState] = useState({ status: 'idle', text: null });

    const toggle = async () => {
        if (state.status === 'ready') {
            setState({ status: 'idle', text: null });

            return;
        }

        setState({ status: 'loading', text: null });

        try {
            const response = await fetch(attachment.previewHref, {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setState({ status: 'ready', text: await response.text() });
        } catch {
            setState({ status: 'failed', text: null });
        }
    };

    return (
        <div className="mt-2">
            <button
                type="button"
                onClick={toggle}
                aria-expanded={state.status === 'ready'}
                className="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                {state.status === 'ready'
                    ? t('issues.attachments.preview_hide')
                    : t('issues.attachments.preview_show')}
            </button>

            {state.status === 'loading' && (
                <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    {t('issues.attachments.preview_loading')}
                </p>
            )}

            {state.status === 'failed' && (
                <p className="mt-2 text-sm text-rose-600 dark:text-rose-400">
                    {t('issues.attachments.preview_failed')}
                </p>
            )}

            {state.status === 'ready' && (
                <>
                    <pre className="mt-2 max-h-64 overflow-auto rounded bg-gray-50 p-3 text-xs whitespace-pre-wrap text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                        {state.text}
                    </pre>

                    {/* Ein abgeschnittener Anriss, der aussieht wie eine ganze
                        Datei, schickt den Suchenden an die falsche Stelle —
                        dieselbe Überlegung wie bei den Kürzungsvermerken einer
                        Meldung. */}
                    {attachment.previewTruncated && (
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('issues.attachments.preview_truncated')}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
