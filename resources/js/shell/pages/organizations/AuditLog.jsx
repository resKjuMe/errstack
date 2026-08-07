import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { InputLabel, PrimaryButton, SecondaryButton, TextInput } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Änderungsprotokoll einer Organisation: wer wann welche Verwaltungsaktion
// ausgeführt hat, samt Vorher/Nachher-Werten. Nur lesend — Einträge lassen sich
// weder ändern noch löschen, deshalb gibt es hier keine Schaltfläche dazu.
export default function AuditLog({
    organization,
    entries,
    filters,
    actionOptions,
    actorOptions,
    exportHref,
}) {
    const { shell } = usePage().props;
    const t = useT();
    const [form, setForm] = useState(filters);

    const set = (field, value) => setForm((previous) => ({ ...previous, [field]: value }));

    const submit = (e) => {
        e.preventDefault();
        // `only` bewusst nicht gesetzt: die Auswahllisten hängen am Bestand des
        // Protokolls und dürfen sich mit der Filterung ändern.
        router.get(window.location.pathname, cleaned(form), { preserveState: true });
    };

    const reset = () => {
        const empty = { actor: '', action: '', from: '', to: '' };
        setForm(empty);
        router.get(window.location.pathname, {}, { preserveState: true });
    };

    const query = new URLSearchParams(cleaned(form)).toString();

    return (
        <>
            <PageHead
                title={t('audit.title')}
                appName={shell.appName}
                help={t('audit.help')}
                meta={
                    <Link
                        href={organization.href}
                        className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                    >
                        {organization.name}
                    </Link>
                }
            />

            <div className="space-y-4">
                <Card title={t('audit.filter.title')} description={t('audit.filter.description')}>
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <div>
                            <InputLabel htmlFor="filter_actor" value={t('audit.filter.actor')} />
                            <Select
                                id="filter_actor"
                                value={form.actor}
                                onChange={(value) => set('actor', value)}
                                options={actorOptions}
                                placeholder={t('audit.filter.all')}
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="filter_action" value={t('audit.filter.action')} />
                            <Select
                                id="filter_action"
                                value={form.action}
                                onChange={(value) => set('action', value)}
                                options={actionOptions}
                                placeholder={t('audit.filter.all')}
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="filter_from" value={t('audit.filter.from')} />
                            <TextInput
                                id="filter_from"
                                type="date"
                                value={form.from}
                                className="mt-1"
                                onChange={(e) => set('from', e.target.value)}
                            />
                        </div>

                        <div>
                            <InputLabel htmlFor="filter_to" value={t('audit.filter.to')} />
                            <TextInput
                                id="filter_to"
                                type="date"
                                value={form.to}
                                className="mt-1"
                                onChange={(e) => set('to', e.target.value)}
                            />
                        </div>

                        <PrimaryButton type="submit">{t('audit.filter.submit')}</PrimaryButton>
                        <SecondaryButton type="button" onClick={reset}>
                            {t('audit.filter.reset')}
                        </SecondaryButton>

                        {/* Kein Inertia-Link: der Export ist ein Download, kein
                            Seitenwechsel. */}
                        <a
                            href={query ? `${exportHref}?${query}` : exportHref}
                            className="ms-auto inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                        >
                            {t('audit.filter.export')}
                        </a>
                    </form>
                </Card>

                <Card>
                    {entries.data.length === 0 ? (
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {t('audit.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                            {entries.data.map((entry) => (
                                <Entry key={entry.id} entry={entry} />
                            ))}
                        </ul>
                    )}

                    <Pagination links={entries.links} />
                </Card>
            </div>
        </>
    );
}

function Entry({ entry }) {
    return (
        <li className="py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {entry.actionLabel}
                    {entry.subject && (
                        <span className="text-gray-500 dark:text-gray-400"> · {entry.subject}</span>
                    )}
                </p>
                <p className="text-sm text-gray-500 dark:text-gray-400">{entry.occurredAt}</p>
            </div>

            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {entry.actorName}
                {entry.actorEmail && ` (${entry.actorEmail})`}
                {entry.ip && ` · ${entry.ip}`}
            </p>

            {entry.changes.length > 0 && (
                <ul className="mt-2 space-y-1">
                    {entry.changes.map((change) => (
                        <li key={change.field} className="text-sm text-gray-600 dark:text-gray-400">
                            <span className="font-medium">{change.field}:</span>{' '}
                            <span className="line-through">{change.before ?? '—'}</span>
                            {' → '}
                            <span>{change.after ?? '—'}</span>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

function Select({ id, value, onChange, options, placeholder }) {
    return (
        <select
            id={id}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
        >
            <option value="">{placeholder}</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

// Die Seitenzahlen kommen fertig vom Paginator; Einträge ohne Ziel sind die
// Auslassungspunkte und die Pfeile am jeweiligen Rand.
function Pagination({ links }) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <div className="mt-4 flex flex-wrap gap-1">
            {links.map((link, index) =>
                link.url === null ? (
                    <span
                        key={index}
                        className="px-3 py-1 text-sm text-gray-400 dark:text-gray-600"
                    >
                        {label(link.label)}
                    </span>
                ) : (
                    <Link
                        key={index}
                        href={link.url}
                        preserveState
                        className={`rounded-md px-3 py-1 text-sm ${
                            link.active
                                ? 'bg-indigo-600 text-white'
                                : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700'
                        }`}
                    >
                        {label(link.label)}
                    </Link>
                )
            )}
        </div>
    );
}

// Die Beschriftungen des Paginators enthalten die Pfeile als HTML-Entität. Statt
// sie als HTML einzusetzen, werden die beiden bekannten Zeichen ersetzt — die
// Beschriftung ist Text und soll Text bleiben.
function label(value) {
    return value.replaceAll('&laquo;', '«').replaceAll('&raquo;', '»');
}

// Leere Felder gehören nicht in die Adresszeile — sonst steht dort
// `?actor=&action=` und der Export bekäme leere Werte zu prüfen.
function cleaned(form) {
    return Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));
}
