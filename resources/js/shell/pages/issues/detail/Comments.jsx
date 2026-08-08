import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { InputError, PrimaryButton, SecondaryButton } from '../../../components/Form.jsx';
import MentionInput from './MentionInput.jsx';

// Kommentare an einem Fehler: das Schreibfeld und die einzelne Wortmeldung in
// der Zeitleiste.
//
// Der Text kommt vom Server bereits zerlegt (`segments`): gewöhnlicher Text und
// Nennungen im Wechsel. Hier wird deshalb nicht ein zweites Mal nach `@Namen`
// gesucht — die Oberfläche käme sonst zu einem anderen Ergebnis als die Stelle,
// die tatsächlich benachrichtigt hat, und niemand könnte sagen, welches der
// beiden stimmt.

// Das Feld zum Schreiben eines neuen Kommentars.
export function CommentForm({ comments, t }) {
    const form = useForm({ body: '' });

    const submit = (event) => {
        event.preventDefault();

        form.post(comments.storeHref, {
            preserveScroll: true,
            // Der neue Kommentar steht in der Zeitleiste, nicht im Feld: nach
            // dem Absenden wird es geleert, damit die nächste Wortmeldung nicht
            // die vorige fortsetzt.
            onSuccess: () => form.reset('body'),
        });
    };

    return (
        <form onSubmit={submit} className="mb-4">
            <MentionInput
                id="issue_comment"
                value={form.data.body}
                onChange={(value) => form.setData('body', value)}
                suggestHref={comments.suggestHref}
                placeholder={t('issues.comments.placeholder')}
                maxLength={comments.limit}
                t={t}
            />

            <InputError message={form.errors.body} className="mt-1" />

            <div className="mt-2 flex flex-wrap items-center gap-3">
                <PrimaryButton disabled={form.processing || form.data.body.trim() === ''}>
                    {t('issues.comments.submit')}
                </PrimaryButton>

                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t('issues.comments.hint')}
                </p>
            </div>
        </form>
    );
}

// Eine Wortmeldung in der Zeitleiste — angezeigt oder, wer sie geschrieben hat,
// gerade im Bearbeiten.
export function Comment({ entry, comments, t }) {
    const [editing, setEditing] = useState(false);

    return (
        <li className="rounded-md bg-gray-50 p-3 dark:bg-gray-900/50">
            <div className="flex flex-wrap items-baseline gap-x-2">
                <span className="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {entry.actor ?? t('issues.activity.system')}
                </span>

                {entry.edited && (
                    <span
                        className="text-xs text-gray-500 dark:text-gray-400"
                        title={t('issues.comments.edited_at', { at: entry.editedAtLabel })}
                    >
                        {t('issues.comments.edited')}
                    </span>
                )}

                <time
                    dateTime={entry.at ?? undefined}
                    className="ms-auto text-xs text-gray-500 dark:text-gray-400"
                >
                    {entry.atLabel}
                </time>
            </div>

            {editing ? (
                <EditForm
                    entry={entry}
                    comments={comments}
                    onDone={() => setEditing(false)}
                    t={t}
                />
            ) : (
                <>
                    <p className="mt-1 text-sm break-words whitespace-pre-wrap text-gray-900 dark:text-gray-100">
                        {entry.segments.map((segment, at) =>
                            segment.type === 'mention' ? (
                                <span
                                    key={at}
                                    className="rounded bg-indigo-50 px-1 font-medium text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300"
                                >
                                    {segment.value}
                                </span>
                            ) : (
                                <React.Fragment key={at}>{segment.value}</React.Fragment>
                            )
                        )}
                    </p>

                    {(entry.canEdit || entry.canDelete) && (
                        <div className="mt-2 flex gap-3 text-xs">
                            {entry.canEdit && (
                                <button
                                    type="button"
                                    onClick={() => setEditing(true)}
                                    className="text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                                >
                                    {t('issues.comments.edit')}
                                </button>
                            )}

                            {entry.canDelete && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        // Ein Kommentar ist nicht
                                        // wiederherstellbar — anders als die
                                        // Zustandsaktionen, die alle einen
                                        // Rückweg haben. Also gefragt, bevor er
                                        // weg ist.
                                        if (!window.confirm(t('issues.comments.delete_confirm'))) {
                                            return;
                                        }

                                        router.delete(entry.destroyHref, {
                                            preserveScroll: true,
                                        });
                                    }}
                                    className="text-red-600 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                >
                                    {t('issues.comments.delete')}
                                </button>
                            )}
                        </div>
                    )}
                </>
            )}
        </li>
    );
}

// Das Bearbeiten einer eigenen Wortmeldung.
//
// Im Feld steht der geschriebene Text und nicht der dargestellte: wer eine
// Nennung ändern will, muss sehen, was er geschrieben hat.
function EditForm({ entry, comments, onDone, t }) {
    const form = useForm({ body: entry.body });

    const submit = (event) => {
        event.preventDefault();

        form.patch(entry.updateHref, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="mt-2">
            <MentionInput
                id={`issue_comment_${entry.id}`}
                value={form.data.body}
                onChange={(value) => form.setData('body', value)}
                suggestHref={comments.suggestHref}
                maxLength={comments.limit}
                autoFocus
                t={t}
            />

            <InputError message={form.errors.body} className="mt-1" />

            <div className="mt-2 flex gap-2">
                <PrimaryButton disabled={form.processing || form.data.body.trim() === ''}>
                    {t('issues.comments.save')}
                </PrimaryButton>

                <SecondaryButton type="button" onClick={onDone}>
                    {t('issues.comments.cancel')}
                </SecondaryButton>
            </div>
        </form>
    );
}
