import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import FilterBar from '../../components/FilterBar.jsx';
import Pagination from '../../components/Pagination.jsx';
import { Checkbox, InputLabel, SecondaryButton, SelectInput } from '../../components/Form.jsx';
import { TableSkeleton } from '../../components/Skeleton.jsx';
import { useT } from '../../i18n.js';
import IssueActions from './IssueActions.jsx';
import IssueRow from './IssueRow.jsx';
import SavedSearches from './SavedSearches.jsx';
import SearchInput from './SearchInput.jsx';
import useIssueSelection from './useIssueSelection.js';
import useLiveIssues from './useLiveIssues.js';

// Die Fehlerliste: alle Fehler der gewählten Projekte mit Häufigkeit,
// Betroffenen, letztem Auftreten und Verlauf.
//
// Der ganze Zustand — Filter, Sortierung, Zustandsauswahl, Seite — steht in der
// Adresszeile und wird serverseitig aufgelöst. Dieselbe Entscheidung wie bei der
// Filterleiste und aus denselben Gründen: ein Neuladen behält die Ansicht, der
// Verlauf vor und zurück funktioniert, und ein Link auf „die häufigsten offenen
// Fehler der letzten 24 Stunden" ist ein Link.
export default function Index({
    filter,
    issues,
    list,
    sortOptions,
    statusOptions,
    series,
    live: liveConfig,
    environmentIgnored,
    totalLabel,
    searchError = null,
    unavailableTerms = [],
    tagsHref,
    mergeHref,
    suggestHref,
    actions,
    savedSearches,
}) {
    const { shell } = usePage().props;
    const t = useT();

    const ids = issues.data.map((issue) => issue.id);
    const selection = useIssueSelection(ids);
    const loading = useListLoading();

    // Von selbst nachladen nur dort, wo ein neuer Fehler auch hingehört: oben
    // auf der ersten Seite der Voreinstellung. Und nicht, solange etwas
    // ausgewählt ist — eine Auswahl, unter der die Zeilen wechseln, ist eine
    // Falle.
    const live = useLiveIssues(liveConfig, {
        auto: issues.current_page === 1 && list.sort === 'last_seen',
        paused: selection.selected.size > 0 || selection.allMatching,
    });

    // Sortierung und Zustand sind Felder dieser Seite, nicht der Leiste; die
    // übrigen Parameter der Adresszeile bleiben deshalb stehen. Eine neue
    // Sortierung beginnt auf Seite 1 — „Seite 7" einer anderen Reihenfolge ist
    // eine andere Seite 7.
    const go = (patch) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) => query.set(key, value));
        query.delete('page');

        router.get(`${window.location.pathname}?${query.toString()}`, {}, { preserveState: true });
    };

    // Das Suchfeld ist ein eigener Zustand, weil es beim Tippen noch nicht
    // suchen soll: eine Abfrage je Tastenanschlag wäre bei einer Liste dieser
    // Größe eine Last ohne Nutzen. Abgeschickt wird mit der Eingabetaste.
    const [q, setQ] = useState(list.q ?? '');

    // Kommt eine neue Seite vom Server — Verlauf zurück, gespeicherter Link —,
    // folgt das Feld ihr. Ohne das stünde im Feld die alte Eingabe und in der
    // Liste die neue Auswahl.
    useEffect(() => setQ(list.q ?? ''), [list.q]);

    const showProject = filter.value.projects.length !== 1;
    const trendLabel = t('issues.trend.label', { period: series.periodLabel });

    return (
        <>
            <PageHead title={t('issues.title')} appName={shell.appName} help={t('issues.help')} />

            <FilterBar filter={filter} />

            {environmentIgnored && (
                <p className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {t('issues.environment_ignored')}
                </p>
            )}

            <Card className="mb-4">
                {/* Die Ansichten stehen **über** dem Suchfeld und nicht daneben:
                    sie sind der Einstieg, das Feld die Verfeinerung. Wer eine
                    Ansicht anklickt, findet ihren Ausdruck danach im Feld
                    wieder und arbeitet dort weiter. */}
                <SavedSearches
                    data={savedSearches}
                    current={list}
                    sortOptions={sortOptions}
                    t={t}
                />

                <div className="flex flex-wrap items-end gap-4">
                    <SearchInput
                        value={q}
                        onChange={setQ}
                        onSubmit={() => go({ q })}
                        suggestHref={suggestHref}
                        t={t}
                    />

                    <div>
                        <InputLabel htmlFor="issue_sort" value={t('issues.filter.sort')} />
                        <SelectInput
                            id="issue_sort"
                            className="mt-1"
                            value={list.sort}
                            options={sortOptions}
                            onChange={(e) => go({ sort: e.target.value })}
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="issue_status" value={t('issues.filter.status')} />
                        <SelectInput
                            id="issue_status"
                            className="mt-1"
                            value={list.status}
                            options={statusOptions}
                            onChange={(e) => go({ status: e.target.value })}
                        />
                    </div>

                    <div className="ms-auto flex items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <Link
                            href={tagsHref}
                            className="underline hover:text-gray-700 dark:hover:text-gray-200"
                        >
                            {t('tags.link.overview')}
                        </Link>
                        <span>{t('issues.list.count', { count: totalLabel })}</span>
                    </div>
                </div>
            </Card>

            {/* Eine Eingabe, die nicht aufging, leert die Liste nicht — sie
                sagt, woran es liegt, und zeigt die ungefilterte Liste. Die
                Stelle steht dabei, weil „ungültiger Ausdruck" bei drei Klammern
                keine Auskunft ist. */}
            {searchError && (
                <p
                    role="alert"
                    className="mb-4 rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
                >
                    {t('issues.filter.search_error', { message: searchError.message })}
                    {searchError.excerpt && (
                        <>
                            {' '}
                            <span className="font-mono">
                                {t('issues.filter.search_error_at', {
                                    excerpt: searchError.excerpt,
                                })}
                            </span>
                        </>
                    )}
                </p>
            )}

            {unavailableTerms.length > 0 && (
                <p className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {t('issues.filter.search_unavailable', {
                        terms: unavailableTerms.join(', '),
                    })}
                </p>
            )}

            {live.pending > 0 && (
                <button
                    type="button"
                    onClick={live.show}
                    className="mb-4 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                >
                    {live.pending === 1
                        ? t('issues.live.new_one')
                        : t('issues.live.new_many', { count: live.pending })}{' '}
                    — {t('issues.live.show')}
                </button>
            )}

            <div aria-busy={loading}>
                {loading ? (
                    <TableSkeleton rows={8} cols={5} />
                ) : (
                    <div className="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                        {issues.data.length === 0 ? (
                            <div className="p-6 text-sm text-gray-500 dark:text-gray-400">
                                <p>{t('issues.list.empty')}</p>
                                <p className="mt-1 text-xs">{t('issues.list.empty_hint')}</p>
                            </div>
                        ) : (
                            <>
                                <ListHeader
                                    selection={selection}
                                    total={issues.total}
                                    totalLabel={totalLabel}
                                    pageSize={ids.length}
                                    mergeHref={mergeHref}
                                    actions={actions}
                                    t={t}
                                />

                                <ul className="divide-y divide-gray-100 dark:divide-gray-700">
                                    {issues.data.map((issue) => (
                                        <IssueRow
                                            key={issue.id}
                                            issue={issue}
                                            selected={selection.isSelected(issue.id)}
                                            onToggle={selection.toggle}
                                            showProject={showProject}
                                            trendLabel={trendLabel}
                                            t={t}
                                        />
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>
                )}
            </div>

            <Pagination links={issues.links} />
        </>
    );
}

// Die Kopfzeile der Liste — zugleich die Auswahl.
//
// Zwei Rollen in einer Zeile, weil sie einander ausschließen: solange nichts
// ausgewählt ist, sagen die Spaltennamen, was in den Spalten steht; sobald etwas
// ausgewählt ist, ist die einzig interessante Auskunft, was ausgewählt ist. Zwei
// Streifen übereinander wären derselbe Inhalt mit doppelter Höhe.
//
// „Alle auf dieser Seite" und „alle 12.480" sind bewusst zwei Schritte: die
// zweite Aussage soll man ausdrücklich treffen und nicht durch einen Klick auf
// dieselbe Stelle.
function ListHeader({ selection, total, totalLabel, pageSize, mergeHref, actions, t }) {
    const some = selection.allMatching || selection.selected.size > 0;

    // Zusammenführen braucht die Kennungen, und „alle Treffer" hat keine: die
    // Aussage ist serverseitig ein `where` und könnte Zehntausende meinen. Eine
    // Aktion, die aus zehntausend Fehlern einen macht, soll man nicht mit zwei
    // Klicks auslösen können — also nur die ausdrücklich angehakten Zeilen.
    const mergeable = !selection.allMatching && selection.selected.size > 1;

    return (
        <div className="flex items-center gap-4 border-b border-gray-100 px-4 py-2 text-xs font-medium text-gray-500 dark:border-gray-700 dark:text-gray-400">
            <Checkbox
                checked={selection.allOnPage}
                onChange={selection.togglePage}
                aria-label={t('issues.selection.page')}
            />

            {some ? (
                <div className="flex flex-1 flex-wrap items-center gap-3 text-sm">
                    <span className="text-gray-700 dark:text-gray-300">
                        {selection.allMatching
                            ? t('issues.selection.all_selected', { count: totalLabel })
                            : t('issues.selection.selected', { count: selection.selected.size })}
                    </span>

                    {selection.allOnPage && !selection.allMatching && total > pageSize && (
                        <button
                            type="button"
                            onClick={selection.selectAllMatching}
                            className="font-medium text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                        >
                            {t('issues.selection.select_all', { count: totalLabel })}
                        </button>
                    )}

                    <SecondaryButton type="button" onClick={selection.clear}>
                        {t('issues.selection.clear')}
                    </SecondaryButton>

                    {mergeable && (
                        <SecondaryButton
                            type="button"
                            title={t('issues.merge.hint')}
                            onClick={() =>
                                router.post(
                                    mergeHref,
                                    { issues: [...selection.selected] },
                                    { preserveScroll: true }
                                )
                            }
                        >
                            {t('issues.merge.action', { count: selection.selected.size })}
                        </SecondaryButton>
                    )}

                    {/* Die Sammelaktionen. Sie stehen in derselben Zeile wie die
                        Auswahl und nicht in einem eigenen Streifen: was
                        ausgewählt ist und was damit geschehen soll, gehört
                        zusammen — und ein zweiter Streifen wäre derselbe Inhalt
                        mit doppelter Höhe.

                        `allMatching` schickt statt der Kennungen einen
                        Schalter: „alle 12.480" ist serverseitig ein `where` und
                        wäre als Liste von Zahlen weder übertragbar noch
                        prüfbar. */}
                    <IssueActions
                        actions={actions}
                        target={
                            selection.allMatching
                                ? { all: true }
                                : { issues: [...selection.selected] }
                        }
                        compact
                        t={t}
                    />
                </div>
            ) : (
                <>
                    <span className="min-w-0 flex-1 uppercase">{t('issues.columns.issue')}</span>
                    <span className="hidden w-32 uppercase lg:block">
                        {t('issues.columns.assignee')}
                    </span>
                    <span className="hidden w-28 uppercase sm:block">
                        {t('issues.columns.trend')}
                    </span>
                    <span className="w-16 text-right uppercase">{t('issues.columns.events')}</span>
                    <span className="w-16 text-right uppercase">{t('issues.columns.users')}</span>
                    <span className="hidden w-44 text-right uppercase md:block">
                        {t('issues.columns.last_seen')}
                    </span>
                </>
            )}
        </div>
    );
}

// Läuft gerade ein Aufruf, der diese Liste austauscht?
//
// Inertia lässt die alte Seite stehen, bis die neue da ist — ohne einen
// Platzhalter sieht ein Wechsel der Sortierung deshalb sekundenlang so aus, als
// wäre der Klick ins Leere gegangen.
//
// **Teilaufrufe zählen nicht.** Das Live-Nachladen holt nur `issues` nach; die
// Liste steht dabei richtig da, und ein Platzhalter wäre ein Flackern ohne
// Anlass.
function useListLoading() {
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const listeners = [
            router.on('start', (event) => {
                if ((event.detail.visit.only ?? []).length === 0) {
                    setLoading(true);
                }
            }),
            router.on('finish', () => setLoading(false)),
        ];

        return () => listeners.forEach((remove) => remove());
    }, []);

    return loading;
}
