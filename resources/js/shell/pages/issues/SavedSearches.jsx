import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Checkbox,
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';

// Die Ansichtsleiste über der Fehlerliste: die Standard-Ansichten, die
// gespeicherten Suchen und der Weg, die gerade eingestellte Suche zu behalten.
//
// **Eine Ansicht ist hier ein Link und kein Knopf.** Sie besteht aus einem
// Suchausdruck und einer Sortierung, und beides steht in der Adresszeile — die
// Adresse baut der Server (App\Support\Issues\IssueViews::href). Damit ist jede
// Ansicht weitergebbar, der Verlauf zurück funktioniert, und die Oberfläche muss
// nicht wissen, was eine Suche mit dem Zeitraum macht: nämlich nichts.
//
// **Standard-Ansichten und gespeicherte Suchen stehen nebeneinander**, weil sie
// dasselbe sind. Der einzige Unterschied ist, dass die einen sich verwalten
// lassen und die anderen es nicht nötig haben.
export default function SavedSearches({ data, current, sortOptions, t }) {
    // Welches Feld gerade offen ist: `save` für die neue Suche, sonst die
    // Kennung der Suche, die verwaltet wird. Immer nur eines — zwei offene
    // Formulare übereinander wären zwei Namen im Blickfeld und einer davon der
    // falsche.
    const [open, setOpen] = useState(null);

    const managed = data.items.find((item) => item.id === open) ?? null;

    return (
        <div className="mb-4 border-b border-gray-100 pb-4 dark:border-gray-700">
            <div className="flex flex-wrap items-center gap-2">
                <span className="me-1 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                    {t('issues.saved.title')}
                </span>

                {data.views.map((view) => (
                    <Chip
                        key={view.key}
                        href={view.href}
                        label={view.name}
                        active={isActive(view, current)}
                        // Was die Suchsprache kennt und die Daten noch nicht,
                        // steht trotzdem in der Leiste — aber es sagt hier, dass
                        // es noch nicht vollständig antwortet. Die Liste
                        // wiederholt es darunter, sobald man draufklickt; erst
                        // dort zu erfahren, dass „Zur Prüfung" gerade alles
                        // zeigt, wäre ein Klick zu spät.
                        warning={view.available ? null : t('issues.saved.unavailable')}
                    />
                ))}

                {data.items.map((item) => (
                    <Chip
                        key={item.id}
                        href={item.href}
                        label={item.name}
                        active={isActive(item, current)}
                        badge={item.isDefault ? t('issues.saved.default_badge') : null}
                        hint={
                            item.own
                                ? null
                                : t('issues.saved.shared_by', { name: item.ownerName ?? '' })
                        }
                        onManage={
                            item.own ? () => setOpen(open === item.id ? null : item.id) : null
                        }
                        manageLabel={t('issues.saved.manage')}
                        // Ein Standard lässt sich nur setzen, wenn klar ist,
                        // wofür: genau ein Projekt in der Auswahl. Sonst fehlt
                        // der Knopf, statt eine Frage zu stellen, die keine
                        // Antwort hat.
                        onDefault={
                            data.project === null
                                ? null
                                : () => toggleDefault(item, data.project.slug)
                        }
                        defaultLabel={t(
                            item.isDefault
                                ? 'issues.saved.clear_default'
                                : 'issues.saved.set_default',
                            { project: data.project?.name ?? '' }
                        )}
                    />
                ))}

                {data.items.length === 0 && (
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        {t('issues.saved.empty')}
                    </span>
                )}

                <SecondaryButton
                    type="button"
                    className="ms-auto"
                    disabled={data.remaining === 0}
                    onClick={() => setOpen(open === 'save' ? null : 'save')}
                >
                    {t('issues.saved.save')}
                </SecondaryButton>
            </div>

            {open === 'save' && (
                <SearchForm
                    key="save"
                    hint={t('issues.saved.save_hint')}
                    values={{ name: '', q: current.q, sort: current.sort, shared: false }}
                    sortOptions={sortOptions}
                    onCancel={() => setOpen(null)}
                    onSubmit={(values) =>
                        router.post(data.storeHref, values, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(null),
                        })
                    }
                    t={t}
                />
            )}

            {managed && (
                <SearchForm
                    // Der Schlüssel hängt an der Suche: ohne ihn behielte das
                    // Formular beim Wechsel auf eine andere Suche die Eingaben
                    // der vorigen.
                    key={managed.id}
                    hint={
                        data.project === null
                            ? null
                            : t('issues.saved.default_hint', {
                                  project: data.project.name,
                              })
                    }
                    values={{
                        name: managed.name,
                        q: managed.query,
                        sort: managed.sort,
                        shared: managed.shared,
                    }}
                    sortOptions={sortOptions}
                    onCancel={() => setOpen(null)}
                    onSubmit={(values) =>
                        router.patch(managed.updateHref, values, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(null),
                        })
                    }
                    onDelete={() => {
                        if (window.confirm(t('issues.saved.confirm_delete'))) {
                            router.delete(managed.destroyHref, {
                                preserveScroll: true,
                                onSuccess: () => setOpen(null),
                            });
                        }
                    }}
                    t={t}
                />
            )}
        </div>
    );
}

// Eine Ansicht in der Leiste.
//
// Der Link trägt die Ansicht, die Knöpfe daneben verwalten sie. Sie stehen
// deshalb **neben** dem Link und nicht darin: ein Knopf in einem Link ist im
// Browser ein Klick, der zwei Dinge tun könnte.
function Chip({
    href,
    label,
    active,
    badge = null,
    hint = null,
    warning = null,
    onManage = null,
    manageLabel = null,
    onDefault = null,
    defaultLabel = null,
}) {
    const base =
        'inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm transition ' +
        (active
            ? 'bg-indigo-600 text-white'
            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600');

    return (
        <span className="inline-flex items-center gap-1">
            <Link href={href} className={base} title={warning ?? hint ?? undefined}>
                {label}

                {badge && <span className="rounded-full bg-white/20 px-1.5 text-xs">{badge}</span>}

                {/* Der Stern steht für „noch nicht vollständig auswertbar". Er
                    ersetzt den Hinweis nicht, er kündigt ihn an — der ganze Satz
                    steht im Titel und, sobald die Ansicht offen ist, über der
                    Liste. */}
                {warning && <span aria-hidden="true">*</span>}

                {/* Wem eine fremde Suche gehört, steht nicht im Text der
                    Ansicht: sie soll so heißen, wie ihr Ersteller sie genannt
                    hat. Für die Vorlesesoftware und den Mauszeiger steht es
                    trotzdem da. */}
                {(warning || hint) && <span className="sr-only">{warning ?? hint}</span>}
            </Link>

            {onDefault && (
                <button
                    type="button"
                    onClick={onDefault}
                    title={defaultLabel}
                    className="text-xs text-gray-400 underline hover:text-gray-600 dark:hover:text-gray-200"
                >
                    {defaultLabel}
                </button>
            )}

            {onManage && (
                <button
                    type="button"
                    onClick={onManage}
                    className="text-xs text-gray-400 underline hover:text-gray-600 dark:hover:text-gray-200"
                >
                    {manageLabel}
                </button>
            )}
        </span>
    );
}

// Das Formular für eine neue und für eine bestehende Suche — dasselbe.
//
// Zwei Formulare wären zweimal dieselben vier Felder, und das zweite bekäme die
// nächste Änderung nicht mit. Der einzige Unterschied ist der Löschknopf: den
// gibt es nur, wo es etwas zu löschen gibt.
function SearchForm({ values, sortOptions, onSubmit, onCancel, onDelete = null, hint = null, t }) {
    const { errors } = usePage().props;

    const [name, setName] = useState(values.name);
    const [q, setQ] = useState(values.q);
    const [sort, setSort] = useState(values.sort);
    const [shared, setShared] = useState(values.shared);

    return (
        <form
            className="mt-3 flex flex-wrap items-end gap-3 rounded-md bg-gray-50 p-3 dark:bg-gray-900/50"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ name, q, sort, shared });
            }}
        >
            <div>
                <InputLabel htmlFor="saved_name" value={t('issues.saved.name')} />
                <TextInput
                    id="saved_name"
                    className="mt-1 w-56"
                    value={name}
                    maxLength={80}
                    placeholder={t('issues.saved.name_placeholder')}
                    onChange={(e) => setName(e.target.value)}
                />
                <InputError message={errors?.name} className="mt-1" />
            </div>

            <div className="min-w-64 flex-1">
                <InputLabel htmlFor="saved_query" value={t('issues.saved.query')} />
                <TextInput
                    id="saved_query"
                    className="mt-1 w-full font-mono"
                    value={q}
                    maxLength={500}
                    onChange={(e) => setQ(e.target.value)}
                />
                <InputError message={errors?.q} className="mt-1" />
            </div>

            <div>
                <InputLabel htmlFor="saved_sort" value={t('issues.saved.sort')} />
                <SelectInput
                    id="saved_sort"
                    className="mt-1"
                    value={sort}
                    options={sortOptions}
                    onChange={(e) => setSort(e.target.value)}
                />
            </div>

            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <Checkbox checked={shared} onChange={(e) => setShared(e.target.checked)} />
                {t('issues.saved.shared')}
            </label>

            <PrimaryButton type="submit">{t('issues.saved.submit')}</PrimaryButton>

            <SecondaryButton type="button" onClick={onCancel}>
                {t('issues.saved.cancel')}
            </SecondaryButton>

            {onDelete && (
                <DangerButton type="button" onClick={onDelete}>
                    {t('issues.saved.delete')}
                </DangerButton>
            )}

            <p className="w-full text-xs text-gray-500 dark:text-gray-400">
                {t('issues.saved.shared_hint')}
                {hint ? ` ${hint}` : ''}
            </p>
        </form>
    );
}

// Zeigt die Liste gerade genau diese Ansicht?
//
// Verglichen werden Suchtext und Sortierung — also das, woraus eine Ansicht
// besteht. Der Zeitraum bleibt außen vor, weil er nicht dazugehört: „Offen" ist
// dieselbe Ansicht, ob man die letzten 24 Stunden oder die letzten 30 Tage
// betrachtet.
function isActive(view, current) {
    return view.query === (current.q ?? '') && view.sort === current.sort;
}

// „Standard für dieses Projekt" an- und abschalten.
//
// Zwei Adressen, ein Knopf: welche der beiden gemeint ist, sagt der aktuelle
// Zustand. Das Projekt fährt im Rumpf mit — die Fehlerliste liegt nicht unter
// einem Projekt, und der Server soll nicht raten müssen, welches gemeint ist.
function toggleDefault(item, projectSlug) {
    if (item.isDefault) {
        router.delete(item.defaultHref, {
            data: { project: projectSlug },
            preserveScroll: true,
        });

        return;
    }

    router.put(item.defaultHref, { project: projectSlug }, { preserveScroll: true });
}
