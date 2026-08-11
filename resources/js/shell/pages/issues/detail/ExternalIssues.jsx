import React, { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { DangerButton, InputError, PrimaryButton, TextInput } from '../../../components/Form.jsx';

// Die Tickets beim Anbieter, die zu diesem Fehler gehören (X1, X4).
//
// **Ein Formular für zwei Vorgänge.** Bleibt das Nummernfeld leer, entsteht ein
// neues Ticket; steht eine Nummer darin, wird das vorhandene verknüpft. Zwei
// getrennte Formulare wären die ordentlichere Aufteilung und die schlechtere
// Oberfläche: es ist derselbe Satz — „dieser Fehler gehört zu jenem Ticket" —,
// und wer ihn sagen will, weiß meistens erst beim Tippen, ob es das Ticket
// schon gibt.
//
// **Und ein Formular für alle Anbieter** (X4). Die Anbieter-Auswahl erscheint
// erst, wenn es mehr als einen gibt — bei einem einzigen wäre sie eine Auswahl
// ohne Wahl. Woher die Ziele kommen, unterscheidet sich: GitHub bringt seine
// Repositories mit (sie liegen als Zeilen hier), bei Jira und Linear ist es ein
// Aufruf über das Netz und wird auf Anforderung geholt — die Fehlerseite soll
// sich nicht deshalb verzögern, weil rechts ein Formular steht.
//
// Verknüpfte Tickets bleiben stehen, auch wenn die Anbindung gelöst wurde: der
// Verweis trägt Adresse und Nummer bei sich. Dann fehlt nur das Formular
// darunter — es gäbe nichts, wohin es senden könnte.
export default function ExternalIssues({ links, t }) {
    return (
        <div className="space-y-4">
            {links.links.length > 0 && (
                <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                    {links.links.map((link) => (
                        <LinkRow key={link.id} link={link} canLink={links.canLink} t={t} />
                    ))}
                </ul>
            )}

            {links.canLink && (
                <LinkForm providers={links.providers} storeHref={links.storeHref} t={t} />
            )}
        </div>
    );
}

function LinkRow({ link, canLink, t }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <li className="flex items-start justify-between gap-4 py-3 first:pt-0 last:pb-0">
            <div className="min-w-0">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <a
                        href={link.url}
                        target="_blank"
                        rel="noreferrer"
                        className="shrink-0 font-mono text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                    >
                        {link.reference}
                    </a>

                    {/* Der Anbieter steht dabei, sobald mehrere möglich sind:
                        `OPS-42` allein sagt nicht, ob es ein Jira-Vorgang oder
                        eine Linear-Aufgabe ist — und beide Schreibweisen sind
                        dieselbe. */}
                    <span className="shrink-0 text-xs text-gray-400 dark:text-gray-500">
                        {link.providerLabel}
                    </span>

                    <StateBadge state={link.state} label={link.stateLabel} />
                </div>

                <p className="mt-1 truncate text-sm text-gray-900 dark:text-gray-100">
                    {link.title || '—'}
                </p>
            </div>

            {canLink && (
                <DangerButton
                    type="button"
                    disabled={processing}
                    onClick={() => destroy(link.deleteHref, { preserveScroll: true })}
                >
                    {t('integrations.issue.actions.unlink')}
                </DangerButton>
            )}
        </li>
    );
}

// Offen und geschlossen unterscheiden sich in der Farbe und nicht nur im Wort:
// die Zeile wird überflogen, und „ist das erledigt?" soll ohne Lesen zu
// beantworten sein.
function StateBadge({ state, label }) {
    const className =
        state === 'closed'
            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

    return <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs ${className}`}>{label}</span>;
}

function LinkForm({ providers, storeHref, t }) {
    const [index, setIndex] = useState(0);
    const provider = providers[index] ?? null;

    const { data, setData, post, processing, errors, reset } = useForm({
        provider: provider?.value ?? '',
        repository: provider?.defaultTarget ?? provider?.targets[0] ?? '',
        number: '',
    });

    // Beim Wechsel des Anbieters wird das Ziel mitgewechselt: ein Projekt des
    // einen ist beim anderen keines, und ein stehengebliebenes `acme/webshop`
    // in einem Jira-Feld ist ein Fehlschlag mit einer Meldung, die niemand
    // erwartet.
    useEffect(() => {
        if (provider === null) {
            return;
        }

        setData((current) => ({
            ...current,
            provider: provider.value,
            repository: provider.defaultTarget ?? provider.targets[0] ?? '',
        }));
    }, [index]);

    if (provider === null) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('integrations.issue.no_repositories')}
            </p>
        );
    }

    const submit = (e) => {
        e.preventDefault();
        post(storeHref, { preserveScroll: true, onSuccess: () => reset('number') });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
            {providers.length > 1 && (
                <div className="w-32">
                    <select
                        value={String(index)}
                        onChange={(e) => setIndex(Number(e.target.value))}
                        aria-label={t('integrations.issue.provider')}
                        className="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        {providers.map((option, i) => (
                            <option key={option.value} value={String(i)}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            <div className="min-w-48">
                <TargetField
                    provider={provider}
                    value={data.repository}
                    onChange={(value) => setData('repository', value)}
                    t={t}
                />
                <InputError message={errors.repository} className="mt-2" />
            </div>

            <div className="w-32">
                <TextInput
                    name="number"
                    value={data.number}
                    placeholder={t('integrations.issue.fields.number_placeholder')}
                    onChange={(e) => setData('number', e.target.value)}
                />
                <InputError message={errors.number} className="mt-2" />
            </div>

            <PrimaryButton type="submit" disabled={processing || data.repository === ''}>
                {data.number
                    ? t('integrations.issue.actions.link')
                    : t('integrations.issue.actions.create')}
            </PrimaryButton>

            <p className="w-full text-xs text-gray-500 dark:text-gray-400">
                {t('integrations.issue.hint')}
            </p>
        </form>
    );
}

// Das Ziel: eine Auswahl, wo die Liste hier liegt (GitHub), sonst ein Textfeld,
// dem auf Anforderung eine Auswahl zur Seite tritt.
//
// Das Textfeld bleibt auch nach dem Laden beschreibbar — bis die Liste da ist.
// Wer den Projektschlüssel kennt, soll ihn tippen können, ohne auf einen Aufruf
// bei Jira zu warten.
function TargetField({ provider, value, onChange, t }) {
    const [targets, setTargets] = useState(provider.targetsHref === null ? provider.targets : null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState(null);

    // Beim Wechsel des Anbieters wird die geholte Liste verworfen: sie gehört
    // zum vorherigen.
    useEffect(() => {
        setTargets(provider.targetsHref === null ? provider.targets : null);
        setFailure(null);
    }, [provider.value]);

    const load = async () => {
        setLoading(true);
        setFailure(null);

        try {
            const response = await fetch(provider.targetsHref, {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();

            setTargets((body.targets ?? []).map((target) => target.key));
            setFailure(body.error ?? null);
        } catch (e) {
            setFailure(t('integrations.ticket.targets.load_failed'));
        } finally {
            setLoading(false);
        }
    };

    if (targets === null) {
        return (
            <>
                <div className="flex gap-2">
                    <TextInput
                        name="repository"
                        value={value}
                        placeholder={t('integrations.ticket.fields.target')}
                        onChange={(e) => onChange(e.target.value)}
                    />
                    <button
                        type="button"
                        disabled={loading}
                        onClick={load}
                        className="shrink-0 rounded-md border border-gray-300 px-2 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                    >
                        {loading
                            ? t('integrations.ticket.targets.loading')
                            : t('integrations.ticket.targets.load')}
                    </button>
                </div>
                {failure && (
                    <p className="mt-1 text-xs text-red-600 dark:text-red-400">{failure}</p>
                )}
            </>
        );
    }

    return (
        <>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
            >
                <option value="">{t('integrations.ticket.targets.choose')}</option>
                {targets.map((target) => (
                    <option key={target} value={target}>
                        {target}
                    </option>
                ))}
            </select>
            {failure && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{failure}</p>}
        </>
    );
}
