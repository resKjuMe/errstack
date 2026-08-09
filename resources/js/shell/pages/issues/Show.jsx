import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import { SecondaryButton } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';
import Activity from './detail/Activity.jsx';
import Attachments from './detail/Attachments.jsx';
import Breadcrumbs from './detail/Breadcrumbs.jsx';
import EventNav from './detail/EventNav.jsx';
import RawData from './detail/RawData.jsx';
import StackTrace from './detail/StackTrace.jsx';
import SuspectCommits from './detail/SuspectCommits.jsx';
import { hasContent, KeyValues, Section } from './detail/Sections.jsx';
import IssueActions from './IssueActions.jsx';

// Die Detailseite eines Fehlers.
//
// Sie beantwortet die Frage, die die Liste offen lässt: warum. Ganz oben steht,
// was für den Fehler insgesamt gilt — Häufigkeit, Betroffene, erstes und letztes
// Auftreten —, darunter **eine** Meldung mit allem, was zu ihr gehört. Die
// Trennung ist wichtig genug, um sie sichtbar zu machen: ein Stacktrace gilt für
// eine Meldung, eine Häufigkeit für den Fehler, und wer beides in eine Zeile
// legt, verwechselt sie später.
//
// Die Reihenfolge der Abschnitte folgt dem, wonach jemand sucht: erst der
// Stacktrace, dann die letzten Schritte, dann der Kontext. Abschnitte ohne
// Inhalt erscheinen nicht.
export default function Show({
    issue,
    event,
    navigation,
    rawHref,
    suspects,
    attachments,
    activity,
    comments,
    actions,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={issue.title}
                appName={shell.appName}
                help={t('issues.detail.help')}
                meta={navigation && <EventNav navigation={navigation} t={t} />}
            />

            {issue.mergedInto && <MergedIntoNotice head={issue.mergedInto} t={t} />}

            <IssueHeader issue={issue} actions={actions} t={t} />

            {issue.merged.length > 0 && <MergedSources sources={issue.merged} t={t} />}

            {event === null ? (
                <div className="rounded-lg bg-white p-6 text-sm shadow dark:bg-gray-800">
                    <p className="text-gray-700 dark:text-gray-300">
                        {t('issues.detail.no_event')}
                    </p>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('issues.detail.no_event_hint')}
                    </p>
                </div>
            ) : (
                <div className="space-y-4">
                    {event.notes && <Notes notes={event.notes} t={t} />}

                    <Section
                        title={t('issues.detail.exception.title')}
                        when={event.exceptions.length > 0}
                    >
                        <StackTrace
                            exceptions={event.exceptions}
                            symbolication={event.symbolication}
                            t={t}
                        />
                    </Section>

                    {/* Direkt hinter dem Stacktrace: „wo ist es passiert" und
                        „welche Änderung war das" sind dieselbe Frage in zwei
                        Schritten. Ohne verbundenes Repository ist die Liste
                        leer, und der Abschnitt fehlt dann ganz — ein leerer
                        Kasten mit Erklärung wäre auf jeder Seite zu sehen, auf
                        der nie jemand Commits übergeben wird. */}
                    <Section
                        title={t('issues.suspects.title')}
                        description={t('issues.suspects.description')}
                        when={suspects.length > 0}
                    >
                        <SuspectCommits suspects={suspects} t={t} />
                    </Section>

                    <Section
                        title={t('issues.detail.message.title')}
                        when={event.exceptions.length === 0 && event.message !== null}
                    >
                        <Message message={event.message ?? {}} />
                    </Section>

                    <Section
                        title={t('issues.detail.breadcrumbs.title')}
                        description={t('issues.detail.breadcrumbs.description')}
                        when={event.breadcrumbs.length > 0}
                    >
                        <Breadcrumbs breadcrumbs={event.breadcrumbs} t={t} />
                    </Section>

                    {/* Die Anhänge stehen hinter den letzten Schritten und vor
                        der Anfrage (M5): ein Screenshot zeigt, was der Mensch vor
                        dem Absturz gesehen hat — das gehört zur Erzählung des
                        Fehlers und nicht zu den technischen Feldern darunter.
                        Ohne Anhänge fehlt der Bereich ganz; ein leerer Kasten
                        wäre auf fast jeder Fehlerseite zu sehen, weil die
                        meisten SDKs von sich aus keine Dateien mitschicken. */}
                    <Section
                        title={t('issues.attachments.title')}
                        description={t('issues.attachments.description', {
                            days: attachments?.retentionDays ?? 0,
                        })}
                        when={(attachments?.items.length ?? 0) > 0}
                    >
                        <Attachments attachments={attachments} t={t} />
                    </Section>

                    <Section
                        title={t('issues.detail.sections.request')}
                        when={hasContent(event.request)}
                    >
                        <KeyValues values={event.request ?? {}} />
                    </Section>

                    <Section title={t('issues.detail.sections.user')} when={hasContent(event.user)}>
                        <KeyValues values={event.user ?? {}} />
                    </Section>

                    <Section
                        title={t('issues.detail.sections.contexts')}
                        when={event.contexts.length > 0}
                    >
                        <div className="space-y-4">
                            {event.contexts.map((context) => (
                                <div key={context.key}>
                                    <h3 className="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {context.key}
                                        {context.type !== context.key && (
                                            <span className="ms-2 text-xs font-normal text-gray-500 dark:text-gray-400">
                                                {context.type}
                                            </span>
                                        )}
                                    </h3>
                                    <KeyValues values={context.values} />
                                </div>
                            ))}
                        </div>
                    </Section>

                    <Section title={t('issues.detail.sections.tags')} when={event.tags.length > 0}>
                        <KeyValues values={event.tags} />
                    </Section>

                    <Section
                        title={t('issues.detail.sections.extra')}
                        when={hasContent(event.extra)}
                    >
                        <KeyValues values={event.extra ?? {}} />
                    </Section>

                    <EventMeta event={event} t={t} />

                    <Section
                        title={t('issues.detail.sections.modules')}
                        when={event.modules.length > 0}
                    >
                        <KeyValues values={event.modules} />
                    </Section>

                    <Section
                        title={t('issues.detail.raw.title')}
                        description={t('issues.detail.raw.description')}
                        when={rawHref !== null}
                    >
                        <RawData href={rawHref} t={t} />
                    </Section>
                </div>
            )}

            {/* Der Verlauf steht ganz unten: er beantwortet keine Frage, die man
                vor einem offenen Stacktrace hat — aber die wichtigste, wenn ein
                Fehler wieder auftaucht, den jemand für erledigt hielt. Seit S10
                stehen die Kommentare darin, und damit ist er auch die Absprache
                zu diesem Fehler. */}
            <div className="mt-4">
                <Section title={t('issues.activity.title')}>
                    <Activity activity={activity} comments={comments} t={t} />
                </Section>
            </div>
        </>
    );
}

// Der Text einer Meldung ohne Ausnahme.
//
// Der fertige Satz steht oben und groß, die Vorlage samt eingesetzten Werten
// darunter: gelesen wird der Satz, gebraucht wird die Vorlage nur, wenn jemand
// wissen will, woher er kommt.
function Message({ message }) {
    const { formatted, ...rest } = message;

    return (
        <>
            {formatted && (
                <p className="text-sm break-words whitespace-pre-wrap text-gray-900 dark:text-gray-100">
                    {formatted}
                </p>
            )}

            <div className={formatted ? 'mt-3' : ''}>
                <KeyValues values={rest} />
            </div>
        </>
    );
}

// Der Kopf: der Fehler als Ganzes.
function IssueHeader({ issue, actions, t }) {
    return (
        <div className="mb-4 rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <div className="flex flex-wrap items-center gap-2">
                <LevelBadge level={issue.level} label={issue.levelLabel} />
                {issue.type && (
                    <span className="font-mono text-sm text-gray-700 dark:text-gray-300">
                        {issue.type}
                    </span>
                )}
                {issue.project && (
                    <Link
                        href={issue.project.href}
                        className="text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        {issue.project.name}
                    </Link>
                )}
            </div>

            {issue.culprit && (
                <p className="mt-1 font-mono text-sm break-all text-gray-500 dark:text-gray-400">
                    {issue.culprit}
                </p>
            )}

            <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <Figure label={t('issues.detail.header.times_seen')} value={issue.timesSeenLabel} />
                <Figure label={t('issues.detail.header.users_seen')} value={issue.usersSeenLabel} />
                <Figure label={t('issues.detail.header.first_seen')} value={issue.firstSeenLabel} />
                <Figure label={t('issues.detail.header.last_seen')} value={issue.lastSeenLabel} />
                <Figure label={t('issues.detail.header.status')} value={issue.statusLabel} />
                {/* Die Wichtigkeit — mit dem Hinweis, wer sie gesetzt hat
                    (S11). „Hoch" von der Ableitung und „hoch, weil ich das
                    sage" sind zwei verschiedene Aussagen, und nur die zweite
                    bleibt beim nächsten Durchlauf stehen. */}
                <Figure
                    label={t('issues.detail.header.priority')}
                    value={issue.priorityLabel}
                    hint={t(
                        issue.priorityLocked
                            ? 'issues.priority.hint_locked'
                            : 'issues.priority.hint'
                    )}
                />
            </dl>

            {/* Wer zuständig ist (S7) — und ob der Fehler noch zur Prüfung
                liegt. Eine eigene Zeile und keine siebte Kennzahl: „Anna Beck,
                zugewiesen am 10.03. von Jonas" ist ein Satz und keine Zahl, und
                zwischen sechs Zählern stünde er falsch. */}
            <AssignmentNote issue={issue} t={t} />

            {/* Der Zustand allein sagt „erledigt". Erst die Bedingung sagt, ob
                das heißt „behoben", „behoben in 1.4.2" oder „behoben, sobald
                ausgeliefert wird" — und daran hängt, ob der Eintrag morgen
                wieder auftaucht. */}
            <StateNote issue={issue} t={t} />

            <div className="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                <IssueActions
                    actions={actions}
                    target={{ issues: [issue.id] }}
                    status={issue.status}
                    state={{
                        bookmarked: issue.bookmarked,
                        subscribed: issue.subscribed,
                        assignee: issue.assignee,
                        priority: issue.priority,
                        priorityLocked: issue.priorityLocked,
                    }}
                    t={t}
                />
            </div>
        </div>
    );
}

// Die Zuständigkeit (S7): wer sich kümmert — oder dass es noch niemand tut.
//
// Beides steht hier und nicht nur eines: „zur Prüfung" ist keine Zuständigkeit,
// sondern deren Abwesenheit mit einem Datum daran. Wer den Fehler aufschlägt,
// soll sehen, ob er ihn liegen lassen darf.
function AssignmentNote({ issue, t }) {
    if (issue.assignee) {
        return (
            <p className="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                {issue.assignee.by
                    ? t('issues.assignment.state_by', {
                          name: issue.assignee.label,
                          at: issue.assignee.atLabel,
                          actor: issue.assignee.by,
                      })
                    : t('issues.assignment.state', {
                          name: issue.assignee.label,
                          at: issue.assignee.atLabel,
                      })}
            </p>
        );
    }

    if (issue.forReview) {
        return (
            <p className="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {t('issues.assignment.for_review_state')}
            </p>
        );
    }

    return null;
}

// Woran der Zustand hängt — nur dort, wo es etwas zu sagen gibt.
//
// Der Fortschritt einer Bedingung („41 von 100") steht ausdrücklich mit dabei:
// eine Bedingung, deren Stand man nicht sieht, ist von „dauerhaft" nicht zu
// unterscheiden, und dann fragt sich jeder, warum der Fehler nicht wiederkommt.
function StateNote({ issue, t }) {
    if (issue.ignore) {
        const progress = issue.ignore.progress;

        return (
            <p className="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                {progress
                    ? t('issues.actions.ignored_state', {
                          condition: issue.ignore.condition,
                          done: progress.done,
                          total: progress.total,
                      })
                    : issue.ignore.condition}
            </p>
        );
    }

    // „Im nächsten Release" wird zuerst geprüft, obwohl auch dort eine Version
    // steht: sie ist dort der Bezugspunkt und nicht das Ziel — behoben ist der
    // Fehler in dem, was nach ihr kommt.
    if (issue.resolution?.nextRelease) {
        return (
            <p className="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                {issue.resolution.release
                    ? t('issues.actions.resolved_next_after', {
                          release: issue.resolution.release,
                      })
                    : t('issues.actions.resolved_next')}
            </p>
        );
    }

    if (issue.resolution?.release) {
        return (
            <p className="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                {t('issues.actions.resolved_in', { release: issue.resolution.release })}
            </p>
        );
    }

    // Zurückgekommen (S8). Der Hinweis steht am Ende, weil er den **offenen**
    // Eintrag betrifft: die Fälle darüber schließen sich mit ihm aus, und wo
    // beides zuträfe, ist die laufende Bedingung die dringendere Auskunft.
    if (issue.regression) {
        return (
            <p className="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                {issue.regression.release
                    ? t('issues.regression.state_in', {
                          release: issue.regression.release,
                          at: issue.regression.atLabel,
                      })
                    : t('issues.regression.state', { at: issue.regression.atLabel })}
            </p>
        );
    }

    return null;
}

// Woraus dieser Eintrag besteht: die von Hand beigetretenen Untergruppen.
//
// Der Abschnitt steht unmittelbar unter dem Kopf, weil er dessen Zahlen erklärt:
// wer eine Häufigkeit sieht, die aus zwei Fingerabdrücken stammt, soll das nicht
// erst suchen müssen. Jede Untergruppe steht mit ihren **eigenen** Zahlen da —
// daran erkennt man, ob das Zusammenführen richtig war.
function MergedSources({ sources, t }) {
    return (
        <div className="mb-4 rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <h2 className="text-sm font-medium text-gray-900 dark:text-gray-100">
                {t('issues.merge.sources.title', { count: sources.length })}
            </h2>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('issues.merge.sources.description')}
            </p>

            <ul className="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
                {sources.map((source) => (
                    <li
                        key={source.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 py-3"
                    >
                        <div className="min-w-0 flex-1">
                            <Link
                                href={source.href}
                                className="text-sm font-medium text-gray-900 underline hover:text-gray-700 dark:text-gray-100 dark:hover:text-gray-300"
                            >
                                {source.title}
                            </Link>

                            {source.fingerprints.length > 0 && (
                                <p className="mt-0.5 font-mono text-xs break-all text-gray-500 dark:text-gray-400">
                                    {source.fingerprints.join(', ')}
                                </p>
                            )}
                        </div>

                        <span className="text-xs text-gray-500 dark:text-gray-400">
                            {t('issues.merge.sources.figures', {
                                count: source.timesSeenLabel,
                                first: source.firstSeenLabel,
                                last: source.lastSeenLabel,
                            })}
                        </span>

                        <SecondaryButton
                            type="button"
                            title={t('issues.merge.split.hint')}
                            onClick={() =>
                                router.delete(source.unmergeHref, { preserveScroll: true })
                            }
                        >
                            {t('issues.merge.split.action')}
                        </SecondaryButton>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// Dieser Eintrag ist selbst eine Untergruppe.
//
// Er bleibt aufrufbar — Lesezeichen und alte Links zeigen weiter hierher —, aber
// seine Zahlen stehen still: gezählt wird ab dem Zusammenführen am Kopf. Ohne
// diesen Hinweis sähe das aus wie ein Fehler, der aufgehört hat.
function MergedIntoNotice({ head, t }) {
    return (
        <div className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            {t('issues.merge.merged_into')}{' '}
            <Link href={head.href} className="font-medium underline">
                {head.title}
            </Link>
        </div>
    );
}

function Figure({ label, value, hint = null }) {
    return (
        <div>
            <dt className="text-xs text-gray-500 dark:text-gray-400">{label}</dt>
            <dd
                // Der Hinweis hängt am Wert und nicht daneben: die Kopfzeile ist
                // eine Reihe kurzer Zahlen, und ein zweiter Satz Text in jeder
                // Zelle würde sie zerreißen.
                title={hint ?? undefined}
                className="mt-0.5 text-sm font-semibold text-gray-900 dark:text-gray-100"
            >
                {value}
            </dd>
        </div>
    );
}

// Die Angaben zur einzelnen Meldung — bewusst weiter unten: sie sagen, welche
// Meldung man ansieht, und nicht, was passiert ist.
function EventMeta({ event, t }) {
    const values = {
        [t('issues.detail.meta.event_id')]: event.eventId,
        [t('issues.detail.meta.occurred_at')]: event.occurredAtLabel,
        [t('issues.detail.meta.received_at')]: event.receivedAtLabel,
        [t('issues.detail.meta.level')]: event.levelLabel,
        [t('issues.detail.meta.platform')]: event.platform,
        [t('issues.detail.meta.environment')]: event.environment,
        [t('issues.detail.meta.release')]: event.release,
        [t('issues.detail.meta.dist')]: event.dist,
        [t('issues.detail.meta.server_name')]: event.serverName,
        [t('issues.detail.meta.transaction')]: event.transaction,
        [t('issues.detail.meta.logger')]: event.logger,
        [t('issues.detail.meta.sdk')]: event.sdk,
    };

    const present = Object.fromEntries(
        Object.entries(values).filter(([, value]) => value !== null && value !== undefined)
    );

    return (
        <Section title={t('issues.detail.meta.title')}>
            <KeyValues values={present} />
        </Section>
    );
}

// Was unterwegs weggefallen ist. Ein gekürzter Stacktrace sieht aus wie ein
// kurzer — ohne diesen Hinweis sucht man an der falschen Stelle.
function Notes({ notes, t }) {
    const lines = [
        notes.truncated?.length
            ? t('issues.detail.notes.truncated', { paths: notes.truncated.join(', ') })
            : null,
        notes.invalid?.length
            ? t('issues.detail.notes.invalid', { paths: notes.invalid.join(', ') })
            : null,
    ].filter(Boolean);

    return (
        <div className="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <p className="font-medium">{t('issues.detail.notes.title')}</p>
            {lines.map((line, index) => (
                <p key={index} className="mt-0.5 text-xs break-all">
                    {line}
                </p>
            ))}
        </div>
    );
}

// Derselbe Schweregrad wie in der Liste — dieselben Farben, damit die Marke
// beim Wechsel von der Liste in die Tiefe dieselbe bleibt.
function LevelBadge({ level, label }) {
    const tone =
        {
            fatal: 'bg-rose-600 text-white',
            error: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
            warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
            debug: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        }[level] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

    return (
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold ${tone}`}>
            {label}
        </span>
    );
}
