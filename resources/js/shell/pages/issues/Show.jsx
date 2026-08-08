import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import { useT } from '../../i18n.js';
import Activity from './detail/Activity.jsx';
import Breadcrumbs from './detail/Breadcrumbs.jsx';
import EventNav from './detail/EventNav.jsx';
import RawData from './detail/RawData.jsx';
import StackTrace from './detail/StackTrace.jsx';
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
export default function Show({ issue, event, navigation, rawHref, activity, comments, actions }) {
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

            <IssueHeader issue={issue} actions={actions} t={t} />

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
                        <StackTrace exceptions={event.exceptions} t={t} />
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
                <Figure label={t('issues.detail.header.priority')} value={issue.priorityLabel} />
            </dl>

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
                    state={{ bookmarked: issue.bookmarked, subscribed: issue.subscribed }}
                    t={t}
                />
            </div>
        </div>
    );
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

    if (issue.resolution?.release) {
        return (
            <p className="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                {t('issues.actions.resolved_in', { release: issue.resolution.release })}
            </p>
        );
    }

    if (issue.resolution?.nextRelease) {
        return (
            <p className="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                {t('issues.actions.resolved_next')}
            </p>
        );
    }

    return null;
}

function Figure({ label, value }) {
    return (
        <div>
            <dt className="text-xs text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="mt-0.5 text-sm font-semibold text-gray-900 dark:text-gray-100">
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
