import React from 'react';
import { useForm } from '@inertiajs/react';
import { DangerButton, InputError, PrimaryButton, TextInput } from '../../../components/Form.jsx';

// Die Tickets beim Anbieter, die zu diesem Fehler gehören (X1).
//
// **Ein Formular für zwei Vorgänge.** Bleibt das Nummernfeld leer, entsteht ein
// neues Ticket; steht eine Nummer darin, wird das vorhandene verknüpft. Zwei
// getrennte Formulare wären die ordentlichere Aufteilung und die schlechtere
// Oberfläche: es ist derselbe Satz — „dieser Fehler gehört zu jenem Ticket" —,
// und wer ihn sagen will, weiß meistens erst beim Tippen, ob es das Ticket
// schon gibt.
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

            {links.canLink && <LinkForm links={links} t={t} />}
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

function LinkForm({ links, t }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        repository: links.repositories[0] ?? '',
        number: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(links.storeHref, { preserveScroll: true, onSuccess: () => reset('number') });
    };

    if (links.repositories.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('integrations.issue.no_repositories')}
            </p>
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
            <div className="min-w-48">
                <select
                    value={data.repository}
                    onChange={(e) => setData('repository', e.target.value)}
                    className="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                >
                    {links.repositories.map((name) => (
                        <option key={name} value={name}>
                            {name}
                        </option>
                    ))}
                </select>
                <InputError message={errors.repository} className="mt-2" />
            </div>

            <div className="w-32">
                <TextInput
                    name="number"
                    value={data.number}
                    inputMode="numeric"
                    placeholder={t('integrations.issue.fields.number_placeholder')}
                    onChange={(e) => setData('number', e.target.value)}
                />
                <InputError message={errors.number} className="mt-2" />
            </div>

            <PrimaryButton type="submit" disabled={processing}>
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
