import React, { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    TextInput,
} from '../../components/Form.jsx';

// Client-Schlüssel eines Projekts. Jeder Schlüssel ist eine DSN, die in ein
// Sentry-SDK eingetragen wird; abgeschaltete Schlüssel bleiben sichtbar, ihre
// Meldungen werden aber abgewiesen. Die Seite bekommt nur zu sehen, wer die
// Schlüssel auch verwalten darf — entschieden wird das serverseitig.
export default function Keys({ project, organization, keys, canDelete }) {
    const { shell } = usePage().props;

    return (
        <>
            <PageHead
                title={`Client-Schlüssel · ${project.name}`}
                appName={shell.appName}
                help="Die DSN enthält den öffentlichen Schlüssel und die Projekt-Nummer — mehr braucht ein SDK nicht. Für getrennte Umgebungen oder mehrere Anwendungen lohnt je ein eigener Schlüssel: Fällt einer aus, lässt er sich abschalten, ohne die übrigen stillzulegen."
                meta={
                    <div className="flex items-center gap-3">
                        <Link
                            href={project.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {project.name}
                        </Link>
                        <Link
                            href={organization.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {organization.name}
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                {keys.map((entry) => (
                    <KeyCard key={entry.id} entry={entry} canDelete={canDelete} />
                ))}

                <CreateKey project={project} />
            </div>
        </>
    );
}

function KeyCard({ entry, canDelete }) {
    return (
        <Card
            title={
                <span className="flex items-center gap-2">
                    {entry.name}
                    {!entry.active && (
                        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-normal text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            abgeschaltet
                        </span>
                    )}
                </span>
            }
            description={
                entry.active
                    ? 'Meldungen mit dieser DSN werden angenommen.'
                    : 'Meldungen mit dieser DSN werden abgewiesen.'
            }
        >
            <div className="space-y-4">
                <Dsn dsn={entry.dsn} />
                <KeySettings entry={entry} />
                <KeyActions entry={entry} canDelete={canDelete} />
            </div>
        </Card>
    );
}

// DSN zum Ablesen und Kopieren. Sie steht im Klartext: ohne sie lässt sich
// nichts einrichten, und wer die Seite sehen darf, darf sie ohnehin neu ziehen.
function Dsn({ dsn }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(dsn);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Ohne Zugriff auf die Zwischenablage (kein HTTPS, abgelehnte
            // Berechtigung) bleibt der Text zum Markieren stehen.
            setCopied(false);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-3">
            <code className="grow rounded-md bg-gray-100 px-3 py-2 font-mono text-sm break-all text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                {dsn}
            </code>

            <SecondaryButton type="button" onClick={copy}>
                {copied ? 'Kopiert' : 'Kopieren'}
            </SecondaryButton>
        </div>
    );
}

function KeySettings({ entry }) {
    const { data, setData, patch, processing, errors } = useForm({
        name: entry.name,
        rate_limit_per_minute: entry.rateLimitPerMinute ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(entry.href, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <InputLabel htmlFor={`name_${entry.id}`} value="Name" />
                <TextInput
                    id={`name_${entry.id}`}
                    name="name"
                    value={data.name}
                    required
                    className="mt-1"
                    onChange={(e) => setData('name', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Nur zur Unterscheidung, etwa nach Umgebung oder Anwendung.
                </p>
                <InputError message={errors.name} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor={`limit_${entry.id}`} value="Kontingent (Meldungen/Minute)" />
                <TextInput
                    id={`limit_${entry.id}`}
                    name="rate_limit_per_minute"
                    type="number"
                    min="1"
                    value={data.rate_limit_per_minute}
                    placeholder="unbegrenzt"
                    className="mt-1"
                    onChange={(e) => setData('rate_limit_per_minute', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Leer lassen heißt unbegrenzt. Greift mit der Datenaufnahme.
                </p>
                <InputError message={errors.rate_limit_per_minute} className="mt-2" />
            </div>

            <div className="md:col-span-2">
                <PrimaryButton type="submit" disabled={processing}>
                    Speichern
                </PrimaryButton>
            </div>
        </form>
    );
}

function KeyActions({ entry, canDelete }) {
    const { post, delete: destroy, processing, errors } = useForm({});

    return (
        <div className="border-t border-gray-200 pt-4 dark:border-gray-700">
            <div className="flex flex-wrap items-center gap-3">
                <SecondaryButton
                    type="button"
                    disabled={processing}
                    onClick={() => post(entry.toggleHref, { preserveScroll: true })}
                >
                    {entry.active ? 'Abschalten' : 'Wieder einschalten'}
                </SecondaryButton>

                <DangerButton
                    type="button"
                    disabled={processing}
                    onClick={() => post(entry.rotateHref, { preserveScroll: true })}
                >
                    Neu erzeugen
                </DangerButton>

                {canDelete && (
                    <DangerButton
                        type="button"
                        disabled={processing}
                        onClick={() => destroy(entry.href, { preserveScroll: true })}
                    >
                        Löschen
                    </DangerButton>
                )}
            </div>

            <InputError message={errors.key} className="mt-2" />

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                „Neu erzeugen" tauscht den Schlüssel in der DSN aus — die bisherige gilt danach
                nicht mehr und muss überall ersetzt werden.
            </p>
        </div>
    );
}

function CreateKey({ project }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        rate_limit_per_minute: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(project.keysHref, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Card
            title="Weiteren Schlüssel anlegen"
            description="Ein eigener Schlüssel je Umgebung oder Anwendung — dann trifft das Abschalten nur den, der es betrifft."
        >
            <form onSubmit={submit} className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <InputLabel htmlFor="new_key_name" value="Name" />
                    <TextInput
                        id="new_key_name"
                        name="name"
                        value={data.name}
                        required
                        placeholder="Staging"
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="new_key_limit" value="Kontingent (Meldungen/Minute)" />
                    <TextInput
                        id="new_key_limit"
                        name="rate_limit_per_minute"
                        type="number"
                        min="1"
                        value={data.rate_limit_per_minute}
                        placeholder="unbegrenzt"
                        className="mt-1"
                        onChange={(e) => setData('rate_limit_per_minute', e.target.value)}
                    />
                    <InputError message={errors.rate_limit_per_minute} className="mt-2" />
                </div>

                <div className="md:col-span-2">
                    <PrimaryButton type="submit" disabled={processing}>
                        Schlüssel anlegen
                    </PrimaryButton>
                </div>
            </form>
        </Card>
    );
}
