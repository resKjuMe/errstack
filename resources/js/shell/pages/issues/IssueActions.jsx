import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import {
    DangerButton,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';
import AssigneePicker from './AssigneePicker.jsx';

// Die Aktionsleiste eines Fehlers — dieselbe in der Liste und auf der
// Detailseite.
//
// Sie kennt keinen Unterschied zwischen „dieser eine" und „alle 12.480": beides
// ist eine Angabe darüber, **was** gemeint ist (`target`), und die geht
// unverändert an den Server. Zwei Fassungen — eine für die Zeile, eine für die
// Auswahl — wären zweimal dieselbe Bestätigungslogik, und die zweite bekäme die
// nächste Aktion nicht mit.
//
// **Die Filterfelder fahren mit.** Meint die Aktion „alle", muss der Server
// dieselbe Menge bilden können, die die Liste zeigt — also mit demselben
// Zeitraum, denselben Projekten, derselben Suche. Sie stehen vollständig in der
// Adresszeile und werden von dort übernommen; sie im Formular nachzubauen wäre
// eine zweite Wahrheit über die aktuelle Ansicht.
export default function IssueActions({ actions, target, status, state = {}, compact = false, t }) {
    // Welches Untermenü offen ist: `resolve`, `ignore`, `assign` oder nichts.
    // Die drei sind die einzigen Aktionen mit einer Rückfrage — alle übrigen
    // sind ein Klick.
    const [open, setOpen] = useState(null);
    const [busy, setBusy] = useState(false);

    const submit = (payload, confirmText = null) => {
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }

        setBusy(true);
        setOpen(null);

        router.post(
            actions.store,
            { ...filterFields(), ...target, ...payload },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            }
        );
    };

    const resolved = status === 'resolved';
    const ignored = status === 'ignored';

    return (
        <div className="flex flex-wrap items-center gap-2">
            {resolved || ignored ? (
                <SecondaryButton
                    type="button"
                    disabled={busy}
                    onClick={() => submit({ action: 'unresolve' })}
                >
                    {t('issues.actions.unresolve')}
                </SecondaryButton>
            ) : (
                <PrimaryButton type="button" disabled={busy} onClick={() => setOpen('resolve')}>
                    {t('issues.actions.resolve')}
                </PrimaryButton>
            )}

            {!ignored && (
                <SecondaryButton type="button" disabled={busy} onClick={() => setOpen('ignore')}>
                    {t('issues.actions.ignore')}
                </SecondaryButton>
            )}

            {/* Die Zuständigkeit (S7). Die Schaltfläche trägt den jetzigen
                Zuständigen als Beschriftung — „Zuweisen" allein ließe offen, ob
                schon jemand zuständig ist, und genau das ist die Frage, wegen
                der man hinschaut. In der Sammelaktion gibt es keinen jetzigen
                Zuständigen: die Auswahl kann fünfzig verschiedene haben. */}
            <SecondaryButton type="button" disabled={busy} onClick={() => setOpen('assign')}>
                {state.assignee
                    ? t('issues.assignment.assigned_to', { name: state.assignee.label })
                    : t('issues.assignment.action')}
            </SecondaryButton>

            <SecondaryButton
                type="button"
                disabled={busy}
                onClick={() => submit({ action: state.bookmarked ? 'unbookmark' : 'bookmark' })}
            >
                {t(state.bookmarked ? 'issues.actions.unbookmark' : 'issues.actions.bookmark')}
            </SecondaryButton>

            <SecondaryButton
                type="button"
                disabled={busy}
                onClick={() => submit({ action: state.subscribed ? 'unsubscribe' : 'subscribe' })}
            >
                {t(state.subscribed ? 'issues.actions.unsubscribe' : 'issues.actions.subscribe')}
            </SecondaryButton>

            {actions.canDelete && (
                <>
                    <DangerButton
                        type="button"
                        disabled={busy}
                        onClick={() =>
                            submit({ action: 'delete' }, t('issues.actions.confirm.delete'))
                        }
                    >
                        {t('issues.actions.delete')}
                    </DangerButton>

                    {/* „Löschen und verwerfen" steht getrennt daneben und nicht
                        als Häkchen im selben Dialog: es ist die einzige Aktion,
                        die auch **künftige** Meldungen betrifft, und ein
                        angehaktes Kästchen wäre dafür zu leise. */}
                    <DangerButton
                        type="button"
                        disabled={busy}
                        onClick={() =>
                            submit({ action: 'discard' }, t('issues.actions.confirm.discard'))
                        }
                    >
                        {t('issues.actions.discard')}
                    </DangerButton>
                </>
            )}

            {open === 'resolve' && (
                <ModePanel
                    modes={actions.resolveModes}
                    onCancel={() => setOpen(null)}
                    onApply={(mode) => submit({ action: 'resolve', mode })}
                    t={t}
                />
            )}

            {open === 'ignore' && (
                <IgnorePanel
                    actions={actions}
                    onCancel={() => setOpen(null)}
                    onApply={(payload) => submit({ action: 'ignore', ...payload })}
                    t={t}
                />
            )}

            {open === 'assign' && (
                <AssigneePicker
                    suggestHref={actions.assignSuggestHref}
                    current={state.assignee?.term ?? null}
                    onCancel={() => setOpen(null)}
                    // `null` heißt „niemand" — der Server liest daraus dieselbe
                    // Aktion mit leerem Zuständigen und hebt die Zuständigkeit
                    // auf. Eine zweite Aktion dafür wäre ein zweiter Name für
                    // denselben Vorgang.
                    onApply={(assignee) => submit({ action: 'assign', assignee })}
                    t={t}
                />
            )}

            {compact || <span className="sr-only">{t('issues.actions.title')}</span>}
        </div>
    );
}

// Die Rückfrage beim Erledigen: sofort, in dieser Version, mit der nächsten
// Auslieferung.
function ModePanel({ modes, onApply, onCancel, t }) {
    const [mode, setMode] = useState(modes[0]?.value ?? 'now');

    return (
        <Panel onCancel={onCancel} onApply={() => onApply(mode)} t={t}>
            <SelectInput
                value={mode}
                options={modes}
                onChange={(e) => setMode(e.target.value)}
                aria-label={t('issues.actions.resolve')}
            />
        </Panel>
    );
}

// Die Rückfrage beim Stummschalten. Die Schwelle und das Zeitfenster erscheinen
// nur zu den Bedingungen, die sie kennen — ein Feld, das für die gewählte Art
// keine Bedeutung hat, ist eine Einladung, es trotzdem auszufüllen.
function IgnorePanel({ actions, onApply, onCancel, t }) {
    const [mode, setMode] = useState('forever');
    const [count, setCount] = useState('100');
    const [window_, setWindow] = useState('');

    const needsCount = mode === 'until_count' || mode === 'until_users';
    const allowsWindow = mode === 'until_count';

    return (
        <Panel
            onCancel={onCancel}
            onApply={() =>
                onApply({
                    mode,
                    count: needsCount ? Number(count) : null,
                    window: allowsWindow && window_ !== '' ? Number(window_) : null,
                })
            }
            t={t}
        >
            <SelectInput
                value={mode}
                options={actions.ignoreModes}
                onChange={(e) => setMode(e.target.value)}
                aria-label={t('issues.actions.ignore')}
            />

            {needsCount && (
                <TextInput
                    type="number"
                    min="1"
                    value={count}
                    onChange={(e) => setCount(e.target.value)}
                    aria-label={t('issues.actions.threshold')}
                    className="w-28"
                />
            )}

            {allowsWindow && (
                <SelectInput
                    value={window_}
                    options={actions.windows}
                    placeholder={t('issues.actions.window.none')}
                    onChange={(e) => setWindow(e.target.value)}
                    aria-label={t('issues.actions.window.label')}
                />
            )}
        </Panel>
    );
}

function Panel({ children, onApply, onCancel, t }) {
    return (
        <div className="flex w-full flex-wrap items-center gap-2 rounded-md bg-gray-50 p-3 dark:bg-gray-900/50">
            {children}

            <PrimaryButton type="button" onClick={onApply}>
                {t('issues.actions.apply')}
            </PrimaryButton>

            <SecondaryButton type="button" onClick={onCancel}>
                {t('issues.actions.cancel')}
            </SecondaryButton>
        </div>
    );
}

// Die Felder der Adresszeile, wie sie der Server für „alle" braucht.
//
// Übernommen statt nachgebaut: die Liste legt ihren ganzen Zustand dort ab, und
// was sie zeigt, hängt an genau diesen Werten. `page` bleibt draußen — eine
// Sammelaktion meint nicht die Seite, sondern die Auswahl.
function filterFields() {
    const query = new URLSearchParams(globalThis.location?.search ?? '');

    query.delete('page');

    const fields = {};

    for (const [key, value] of query.entries()) {
        // Die Projektauswahl ist ein Feld mit mehreren Werten
        // (`projects[]=a&projects[]=b`); alles andere ist einwertig.
        if (key === 'projects[]' || key === 'projects') {
            fields.projects = [...(fields.projects ?? []), value];
        } else {
            fields[key] = value;
        }
    }

    return fields;
}
