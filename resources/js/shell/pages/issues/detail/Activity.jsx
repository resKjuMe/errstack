import React from 'react';
import Pagination from '../../../components/Pagination.jsx';
import { Comment, CommentForm } from './Comments.jsx';

// Die Zeitleiste eines Fehlers: wer wann was getan — und wer was dazu gesagt
// hat.
//
// Beides in **einer** Liste, obwohl es aus zwei Tabellen kommt. Die Frage, die
// hier beantwortet wird, ist eine einzige: was ist mit diesem Fehler passiert?
// Ob die Antwort „Anna hat erledigt" oder „Anna hat geschrieben: das lag am
// Zeitlimit" lautet, ist für den Lesenden derselbe Faden; zwei Listen
// nebeneinander müsste er im Kopf zusammenführen, und zwar nach Uhrzeit.
//
// Der Satz eines Vermerks kommt fertig vom Server — „stummgeschaltet, bis 100
// weitere Ereignisse in 60 Minuten" ist ein Satz mit Beugung und Zahlen, und den
// aus Bausteinen im Browser zusammenzusetzen hieße, zwei Sprachen dort
// nachzubauen. Ohne Namen steht „automatisch": der einzige Vermerk ohne
// handelndes Konto ist der Ablauf einer Stummschaltung, und der fällt bei der
// Aufnahme an, wo niemand daneben steht.
//
// Das Schreibfeld steht **oben**, weil die Leiste mit dem Neuesten anfängt: wer
// etwas beitragen will, soll nicht erst an zwanzig Einträgen vorbei.
export default function Activity({ activity, comments, t }) {
    const entries = activity?.data ?? [];

    return (
        <>
            {comments?.canWrite && <CommentForm comments={comments} t={t} />}

            {entries.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('issues.activity.empty')}
                </p>
            ) : (
                <ol className="space-y-2">
                    {entries.map((entry) =>
                        entry.kind === 'comment' ? (
                            <Comment key={entry.key} entry={entry} comments={comments} t={t} />
                        ) : (
                            <Entry key={entry.key} entry={entry} t={t} />
                        )
                    )}
                </ol>
            )}

            <Pagination links={activity?.links ?? []} />
        </>
    );
}

// Ein Vermerk: eine Zeile, weil er eine Zeile ist.
function Entry({ entry, t }) {
    return (
        <li className="flex flex-wrap items-baseline gap-x-2 px-3 text-sm">
            <span className="text-gray-900 dark:text-gray-100">{entry.text}</span>
            <span className="text-xs text-gray-500 dark:text-gray-400">
                {t('issues.activity.by', {
                    actor: entry.actor ?? t('issues.activity.system'),
                })}
            </span>
            <time
                dateTime={entry.at ?? undefined}
                className="ms-auto text-xs text-gray-500 dark:text-gray-400"
            >
                {entry.atLabel}
            </time>
        </li>
    );
}
