import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    TextInput,
} from '../../components/Form.jsx';

// Ein Ticket-System: verbinden, einstellen, Rückadresse (X4).
//
// **Verbunden wird mit einem Formular und nicht über eine Weiterleitung.** Ein
// API-Token setzt keine registrierte App voraus, und bis es die gibt, ist das
// der Weg, der sofort funktioniert. Der Preis sind drei Felder statt eines
// Knopfs — welche es sind, sagt der Server (`section.fields`), damit die Liste
// nicht doppelt geführt wird.
//
// Alles, was nach dem Verbinden folgt, steht **untereinander in einer Karte**
// und nicht in drei: es ist eine Frage in drei Teilen — was passiert
// automatisch, womit fängt ein neues Ticket an, wohin schickt der Anbieter seine
// Meldungen.
export default function TicketConnection({ section, canManage, t }) {
    const { provider, integration } = section;

    if (integration === null) {
        return <Connect section={section} canManage={canManage} t={t} />;
    }

    const lost = integration.status === 'disconnected';

    return (
        <Card title={provider.label}>
            {/* Der verlorene Zugang steht ganz oben: er ist der einzige Zustand,
                in dem jemand handeln muss, und alles darunter ist dann ohnehin
                ohne Wirkung. */}
            {lost && (
                <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-100">
                    <p className="font-medium">{t('integrations.lost.title')}</p>
                    {integration.lastError && (
                        <p className="mt-1 font-mono text-xs">{integration.lastError}</p>
                    )}
                </div>
            )}

            <dl className="space-y-2 text-sm">
                <Row label={t('integrations.fields.account')} value={integration.account} />
                <Row label={t('integrations.fields.status')} value={integration.statusLabel} />
                <Row
                    label={t('integrations.fields.connected_at')}
                    value={
                        integration.connectedBy
                            ? t('integrations.fields.connected_by', {
                                  at: integration.connectedAtLabel ?? '—',
                                  name: integration.connectedBy,
                              })
                            : integration.connectedAtLabel
                    }
                />
                <Row
                    label={t('integrations.fields.last_synced_at')}
                    value={integration.lastSyncedAtLabel ?? t('integrations.fields.never')}
                />
            </dl>

            {canManage && (
                <>
                    <Settings section={section} t={t} />
                    <Webhook integration={integration} t={t} />

                    <div className="mt-6 flex flex-wrap gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <ReplaceToken section={section} t={t} />
                        <Disconnect integration={integration} t={t} />
                    </div>
                </>
            )}
        </Card>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="text-right text-gray-900 dark:text-gray-100">{value ?? '—'}</dd>
        </div>
    );
}

// Das Formular zum Verbinden. Auch der Weg zum Ersetzen eines Tokens — deshalb
// nimmt es die Beschriftung von außen.
function Connect({ section, canManage, t, compact = false }) {
    const { provider, fields, connectHref, docsUrl } = section;
    const { data, setData, post, processing, errors, reset } = useForm({
        base_url: '',
        email: '',
        token: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(connectHref, { preserveScroll: true, onSuccess: () => reset() });
    };

    const form = (
        <form onSubmit={submit} className="space-y-3">
            {fields.includes('base_url') && (
                <Field
                    name="base_url"
                    value={data.base_url}
                    onChange={(v) => setData('base_url', v)}
                    error={errors.base_url}
                    placeholder={t('integrations.ticket.fields.base_url_placeholder')}
                    t={t}
                />
            )}

            {fields.includes('email') && (
                <Field
                    name="email"
                    type="email"
                    value={data.email}
                    onChange={(v) => setData('email', v)}
                    error={errors.email}
                    t={t}
                />
            )}

            <Field
                name="token"
                // `password` und nicht `text`: das Token steht sonst im Klartext
                // auf dem Bildschirm von jemandem, der es gerade aus einer
                // anderen Anwendung kopiert hat — und wird beim Vorführen
                // mitgefilmt.
                type="password"
                value={data.token}
                onChange={(v) => setData('token', v)}
                error={errors.token}
                autoComplete="off"
                t={t}
            />

            <div className="flex flex-wrap items-center gap-3">
                <PrimaryButton type="submit" disabled={processing || data.token === ''}>
                    {compact
                        ? t('integrations.ticket.actions.reconnect')
                        : t('integrations.ticket.actions.connect')}
                </PrimaryButton>

                {docsUrl !== '' && (
                    <a
                        href={docsUrl}
                        target="_blank"
                        rel="noreferrer"
                        className="text-sm text-indigo-600 underline dark:text-indigo-400"
                    >
                        {t('integrations.ticket.docs')}
                    </a>
                )}
            </div>
        </form>
    );

    if (compact) {
        return form;
    }

    return (
        <Card title={provider.label} description={t('integrations.ticket.empty')}>
            {canManage ? form : null}
        </Card>
    );
}

function Field({ name, value, onChange, error, type = 'text', t, ...props }) {
    return (
        <div>
            <InputLabel htmlFor={name} value={t(`integrations.ticket.fields.${name}`)} />
            <TextInput
                id={name}
                name={name}
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="mt-1"
                {...props}
            />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t(`integrations.ticket.hints.${name}`)}
            </p>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

// Der Abgleich und die Vorbelegung — ein Formular, weil es dieselbe Frage aus
// zwei Richtungen ist: was passiert automatisch, und womit fängt ein neues
// Ticket an.
function Settings({ section, t }) {
    const { integration, provider } = section;
    const { data, setData, patch, processing, errors } = useForm({
        sync_inbound: integration.syncInbound,
        sync_outbound: integration.syncOutbound,
        default_project: integration.defaultProject ?? '',
        default_type: integration.defaultType ?? '',
        default_priority: integration.defaultPriority ?? '',
        default_assignee: integration.defaultAssignee ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(integration.settingsHref, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="mt-6 space-y-4 border-t border-gray-200 pt-4 dark:border-gray-700"
        >
            <div>
                <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {t('integrations.ticket.sync.title')}
                </h4>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('integrations.ticket.sync.hint')}
                </p>

                <Switch
                    name="sync_inbound"
                    checked={data.sync_inbound}
                    onChange={(v) => setData('sync_inbound', v)}
                    label={t('integrations.ticket.sync.inbound')}
                    hint={t('integrations.ticket.sync.inbound_hint')}
                />
                <Switch
                    name="sync_outbound"
                    checked={data.sync_outbound}
                    onChange={(v) => setData('sync_outbound', v)}
                    label={t('integrations.ticket.sync.outbound')}
                    hint={t('integrations.ticket.sync.outbound_hint')}
                />
            </div>

            <div>
                <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {t('integrations.ticket.defaults.title')}
                </h4>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('integrations.ticket.defaults.hint')}
                </p>

                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <Target
                        section={section}
                        value={data.default_project}
                        onChange={(v) => setData('default_project', v)}
                        error={errors.default_project}
                        t={t}
                    />

                    {/* Den Vorgangstyp gibt es nur bei Jira. Ein leeres Feld
                        daneben, das nichts tut, wäre eine Einladung, es
                        auszufüllen. */}
                    {provider.value === 'jira' && (
                        <Small
                            name="default_type"
                            value={data.default_type}
                            onChange={(v) => setData('default_type', v)}
                            error={errors.default_type}
                            t={t}
                        />
                    )}

                    <Small
                        name="default_priority"
                        value={data.default_priority}
                        onChange={(v) => setData('default_priority', v)}
                        error={errors.default_priority}
                        t={t}
                    />
                    <Small
                        name="default_assignee"
                        value={data.default_assignee}
                        onChange={(v) => setData('default_assignee', v)}
                        error={errors.default_assignee}
                        t={t}
                    />
                </div>
            </div>

            <PrimaryButton type="submit" disabled={processing}>
                {t('integrations.ticket.actions.save')}
            </PrimaryButton>
        </form>
    );
}

function Switch({ name, checked, onChange, label, hint }) {
    return (
        <label className="mt-3 flex items-start gap-3">
            <Checkbox
                name={name}
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="mt-0.5"
            />
            <span>
                <span className="block text-sm text-gray-900 dark:text-gray-100">{label}</span>
                <span className="block text-xs text-gray-500 dark:text-gray-400">{hint}</span>
            </span>
        </label>
    );
}

function Small({ name, value, onChange, error, t }) {
    return (
        <div>
            <InputLabel htmlFor={name} value={t(`integrations.ticket.fields.${name}`)} />
            <TextInput
                id={name}
                name={name}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="mt-1"
            />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t(`integrations.ticket.hints.${name}`)}
            </p>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

// Das vorbelegte Projekt: ein Textfeld, dem auf Anforderung eine Auswahl zur
// Seite tritt. Die Liste kommt über das Netz, und dieses Formular soll auch dann
// benutzbar sein, wenn der Anbieter gerade nicht antwortet — deshalb bleibt das
// Feld immer beschreibbar.
function Target({ section, value, onChange, error, t }) {
    const [targets, setTargets] = useState(null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState(null);

    const load = async () => {
        setLoading(true);
        setFailure(null);

        try {
            const response = await fetch(section.integration.targetsHref, {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();

            setTargets(body.targets ?? []);
            setFailure(body.error ?? null);
        } catch (e) {
            setFailure(t('integrations.ticket.targets.load_failed'));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div>
            <InputLabel
                htmlFor="default_project"
                value={t('integrations.ticket.fields.default_project')}
            />

            {targets === null ? (
                <div className="mt-1 flex gap-2">
                    <TextInput
                        id="default_project"
                        name="default_project"
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                    />
                    <SecondaryButton type="button" disabled={loading} onClick={load}>
                        {loading
                            ? t('integrations.ticket.targets.loading')
                            : t('integrations.ticket.targets.load')}
                    </SecondaryButton>
                </div>
            ) : (
                <select
                    id="default_project"
                    name="default_project"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                >
                    <option value="">{t('integrations.ticket.targets.choose')}</option>
                    {targets.map((target) => (
                        <option key={target.key} value={target.key}>
                            {target.key} — {target.name}
                        </option>
                    ))}
                </select>
            )}

            {failure && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{failure}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

// Die Rückadresse. Sie enthält ein Geheimnis und steht deshalb nur hier, hinter
// der Berechtigung, Anbindungen zu verwalten.
function Webhook({ integration, t }) {
    const { post, processing } = useForm({});

    if (integration.webhookUrl === null) {
        return null;
    }

    return (
        <div className="mt-6 border-t border-gray-200 pt-4 dark:border-gray-700">
            <h4 className="text-sm font-medium text-gray-900 dark:text-gray-100">
                {t('integrations.ticket.webhook.title')}
            </h4>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('integrations.ticket.webhook.hint')}
            </p>

            <code className="mt-2 block overflow-x-auto rounded bg-gray-100 p-2 font-mono text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                {integration.webhookUrl}
            </code>

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {t('integrations.ticket.webhook.why')}
            </p>

            <SecondaryButton
                type="button"
                className="mt-3"
                disabled={processing}
                onClick={() => {
                    if (window.confirm(t('integrations.ticket.webhook.rotate_confirm'))) {
                        post(integration.rotateHref, { preserveScroll: true });
                    }
                }}
            >
                {t('integrations.ticket.webhook.rotate')}
            </SecondaryButton>
        </div>
    );
}

// Ein Token ersetzen: dasselbe Formular wie beim Verbinden, hinter einem
// Aufklapper. Es steht nicht offen da, weil der Regelfall „ist verbunden" ist —
// und ein sichtbares Token-Feld auf einer funktionierenden Anbindung wirkt wie
// eine Aufforderung.
function ReplaceToken({ section, t }) {
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <SecondaryButton type="button" onClick={() => setOpen(true)}>
                {t('integrations.ticket.actions.reconnect')}
            </SecondaryButton>
        );
    }

    return (
        <div className="w-full">
            <Connect section={section} canManage t={t} compact />
        </div>
    );
}

function Disconnect({ integration, t }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <DangerButton
            type="button"
            disabled={processing}
            onClick={() => {
                if (window.confirm(t('integrations.actions.disconnect_confirm'))) {
                    destroy(integration.disconnectHref, { preserveScroll: true });
                }
            }}
        >
            {t('integrations.actions.disconnect')}
        </DangerButton>
    );
}
