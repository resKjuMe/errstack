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

// Die Leiste der gespeicherten Auswertungen über der freien Auswertung.
//
// **Eine gespeicherte Auswertung ist hier ein Link und kein Knopf.** Sie besteht
// aus einer Frage und einem Ausschnitt, und beides steht in der Adresszeile —
// die Adresse baut der Server (App\Support\Discover\SavedQueryData::href). Damit
// ist jede weitergebbar, der Verlauf zurück funktioniert, und die Oberfläche muss
// nicht wissen, wie ein gespeicherter Zeitraum in die Filterleiste kommt.
//
// **Gespeichert wird, was gerade eingestellt ist.** Das Formular schickt deshalb
// keine eigenen Felder für die Abfrage mit, sondern den Zustand, den die Seite
// ohnehin führt: `query` (die sieben Angaben der Abfrage-Leiste) und `filter`
// (Zeitraum, Umgebung, Projekt). Ein zweiter Satz Eingabefelder daneben wäre eine
// zweite Stelle, an der eine Auswertung entsteht — und die beiden könnten sich
// widersprechen.
export default function SavedQueries({ data, query, filter, t }) {
    // Welches Feld gerade offen ist: `save` für die neue Auswertung, sonst die
    // Kennung der Auswertung, die verwaltet wird. Immer nur eines — zwei offene
    // Formulare übereinander wären zwei Namen im Blickfeld und einer davon der
    // falsche.
    const [open, setOpen] = useState(null);

    const managed = data.items.find((item) => item.id === open) ?? null;

    return (
        <div className="mb-4 border-b border-gray-100 pb-4 dark:border-gray-700">
            <div className="flex flex-wrap items-center gap-2">
                <span className="me-1 text-xs font-medium uppercase text-gray-500 dark:text-gray-400">
                    {t('discover.saved.title')}
                </span>

                {data.items.map((item) => (
                    <Chip
                        key={item.id}
                        item={item}
                        open={open === item.id}
                        onManage={() => setOpen(open === item.id ? null : item.id)}
                        t={t}
                    />
                ))}

                {data.items.length === 0 && (
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        {t('discover.saved.empty')}
                    </span>
                )}

                <SecondaryButton
                    type="button"
                    className="ms-auto"
                    disabled={data.remaining === 0}
                    onClick={() => setOpen(open === 'save' ? null : 'save')}
                >
                    {t('discover.saved.save')}
                </SecondaryButton>
            </div>

            {open === 'save' && (
                <QueryForm
                    key="save"
                    values={{ name: '', description: '', shared: false }}
                    hint={t('discover.saved.save_hint')}
                    onCancel={() => setOpen(null)}
                    onSubmit={(values) =>
                        router.post(
                            data.storeHref,
                            { ...values, ...query, ...filter },
                            { preserveScroll: true, onSuccess: () => setOpen(null) }
                        )
                    }
                    t={t}
                />
            )}

            {managed && (
                <>
                    {managed.own && (
                        <QueryForm
                            // Der Schlüssel hängt an der Auswertung: ohne ihn
                            // behielte das Formular beim Wechsel auf eine andere
                            // die Eingaben der vorigen.
                            key={managed.id}
                            values={{
                                name: managed.name,
                                description: managed.description,
                                shared: managed.shared,
                            }}
                            // Beim Ändern wird die Frage aus der Seite
                            // übernommen — dieselbe Regel wie beim Speichern.
                            // Wer nur umbenennen will, öffnet die Auswertung
                            // vorher; dann steht in der Seite, was in ihr steht.
                            hint={t('discover.saved.save_hint')}
                            onCancel={() => setOpen(null)}
                            onSubmit={(values) =>
                                router.patch(
                                    managed.updateHref,
                                    { ...values, ...query, ...filter },
                                    { preserveScroll: true, onSuccess: () => setOpen(null) }
                                )
                            }
                            onDelete={() => {
                                if (window.confirm(t('discover.saved.confirm_delete'))) {
                                    router.delete(managed.destroyHref, {
                                        preserveScroll: true,
                                        onSuccess: () => setOpen(null),
                                    });
                                }
                            }}
                            t={t}
                        />
                    )}

                    <WidgetForm
                        key={`widget-${managed.id}`}
                        item={managed}
                        dashboards={data.dashboards}
                        widgetTypes={data.widgetTypes}
                        t={t}
                    />
                </>
            )}
        </div>
    );
}

// Eine gespeicherte Auswertung in der Leiste.
//
// Der Link öffnet sie, der Knopf daneben verwaltet sie. Sie stehen deshalb
// **neben** dem Link und nicht darin: ein Knopf in einem Link ist im Browser ein
// Klick, der zwei Dinge tun könnte.
function Chip({ item, open, onManage, t }) {
    // Der Titel sagt, was die Auswertung ist, wem sie gehört und dass sie ihren
    // Zeitraum mitbringt. Drei Sätze im Mauszeiger sind mehr als eine Zeile in
    // der Leiste — aber es ist genau das, was man vor dem Klick wissen will.
    const title = [
        item.description || null,
        item.own ? null : t('discover.saved.shared_by', { name: item.ownerName ?? '' }),
        t('discover.saved.restores_period'),
    ]
        .filter(Boolean)
        .join(' — ');

    return (
        <span className="inline-flex items-center gap-1">
            <Link
                href={item.href}
                title={title}
                className={
                    'inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm transition ' +
                    (open
                        ? 'bg-indigo-600 text-white'
                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600')
                }
            >
                {item.name}

                {/* Wem eine fremde Auswertung gehört, steht nicht im Text: sie
                    soll so heißen, wie ihr Ersteller sie genannt hat. Für die
                    Vorlesesoftware steht es trotzdem da. */}
                <span className="sr-only">{title}</span>
            </Link>

            <button
                type="button"
                onClick={onManage}
                className="text-xs text-gray-400 underline hover:text-gray-600 dark:hover:text-gray-200"
            >
                {t('discover.saved.manage')}
            </button>
        </span>
    );
}

// Das Formular für eine neue und für eine bestehende Auswertung — dasselbe.
//
// Zwei Formulare wären zweimal dieselben drei Felder, und das zweite bekäme die
// nächste Änderung nicht mit. Der einzige Unterschied ist der Löschknopf: den
// gibt es nur, wo es etwas zu löschen gibt.
function QueryForm({ values, onSubmit, onCancel, onDelete = null, hint = null, t }) {
    const { errors } = usePage().props;

    const [name, setName] = useState(values.name);
    const [description, setDescription] = useState(values.description);
    const [shared, setShared] = useState(values.shared);

    return (
        <form
            className="mt-3 flex flex-wrap items-end gap-3 rounded-md bg-gray-50 p-3 dark:bg-gray-900/50"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ name, description, shared });
            }}
        >
            <div>
                <InputLabel htmlFor="saved_query_name" value={t('discover.saved.name')} />
                <TextInput
                    id="saved_query_name"
                    className="mt-1 w-56"
                    value={name}
                    maxLength={80}
                    placeholder={t('discover.saved.name_placeholder')}
                    onChange={(e) => setName(e.target.value)}
                />
                <InputError message={errors?.name} className="mt-1" />
            </div>

            <div className="min-w-64 flex-1">
                <InputLabel
                    htmlFor="saved_query_description"
                    value={t('discover.saved.description')}
                />
                <TextInput
                    id="saved_query_description"
                    className="mt-1 w-full"
                    value={description}
                    maxLength={500}
                    placeholder={t('discover.saved.description_placeholder')}
                    onChange={(e) => setDescription(e.target.value)}
                />
                <InputError message={errors?.description} className="mt-1" />
            </div>

            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <Checkbox checked={shared} onChange={(e) => setShared(e.target.checked)} />
                {t('discover.saved.shared')}
            </label>

            <PrimaryButton type="submit">{t('discover.saved.submit')}</PrimaryButton>

            <SecondaryButton type="button" onClick={onCancel}>
                {t('discover.saved.cancel')}
            </SecondaryButton>

            {onDelete && (
                <DangerButton type="button" onClick={onDelete}>
                    {t('discover.saved.delete')}
                </DangerButton>
            )}

            <p className="w-full text-xs text-gray-500 dark:text-gray-400">
                {t('discover.saved.shared_hint')}
                {hint ? ` ${hint}` : ''}
            </p>
        </form>
    );
}

// „Als Kachel übernehmen" — und, für fremde Auswertungen, der Weg zu einer
// eigenen Kopie.
//
// Der Knopf steht auch an fremden Auswertungen: übernehmen und duplizieren darf,
// wer sie sehen darf. Die Auswahl zeigt nur **eigene** Dashboards — eine Kachel
// anzulegen ist eine Änderung am Dashboard, und die darf nur dessen Ersteller.
function WidgetForm({ item, dashboards, widgetTypes, t }) {
    const { errors } = usePage().props;

    const [dashboard, setDashboard] = useState(dashboards[0]?.id ?? '');
    const [type, setType] = useState('table');

    return (
        <div className="mt-3 flex flex-wrap items-end gap-3 rounded-md bg-gray-50 p-3 dark:bg-gray-900/50">
            {dashboards.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('discover.saved.widget.none')}
                </p>
            ) : (
                <form
                    className="flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(
                            item.widgetHref,
                            { dashboard, type, title: item.name },
                            { preserveScroll: true }
                        );
                    }}
                >
                    <div>
                        <InputLabel
                            htmlFor="saved_query_dashboard"
                            value={t('discover.saved.widget.dashboard')}
                        />
                        <SelectInput
                            id="saved_query_dashboard"
                            className="mt-1"
                            value={dashboard}
                            options={dashboards.map((entry) => ({
                                value: entry.id,
                                label: entry.name,
                            }))}
                            onChange={(e) => setDashboard(e.target.value)}
                        />
                        <InputError message={errors?.dashboard} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="saved_query_widget_type"
                            value={t('discover.saved.widget.type')}
                        />
                        <SelectInput
                            id="saved_query_widget_type"
                            className="mt-1"
                            value={type}
                            options={widgetTypes.map((entry) => ({
                                value: entry.value,
                                label: entry.label,
                            }))}
                            onChange={(e) => setType(e.target.value)}
                        />
                    </div>

                    <PrimaryButton type="submit">{t('discover.saved.widget.submit')}</PrimaryButton>
                </form>
            )}

            <SecondaryButton
                type="button"
                onClick={() => router.post(item.duplicateHref, {}, { preserveScroll: true })}
            >
                {t('discover.saved.duplicate')}
            </SecondaryButton>

            <p className="w-full text-xs text-gray-500 dark:text-gray-400">
                {t('discover.saved.widget.hint')}
            </p>
        </div>
    );
}
