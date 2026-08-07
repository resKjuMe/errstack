import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
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
import { useT } from '../../i18n.js';

// Datenschutz-Einstellungen — dieselbe Seite für ein Projekt und für eine
// Organisation. Was sie unterscheidet, steht in `scope`: das Projekt hat die drei
// Schalter, die geerbten Regeln der Organisation und die Vorschau, die
// Organisation nur ihre eigenen Regeln.
//
// Die Reihenfolge der Karten folgt der Frage, mit der jemand hierherkommt: „was
// passiert mit meinen Daten?" — erst also, was gar nicht gespeichert wird, dann
// die eigenen Regeln, dann das immer Gültige, und zuletzt die Probe am Beispiel.
export default function Index({
    scope,
    project,
    organization,
    permissions,
    options,
    rules,
    inheritedRules,
    typeOptions,
    defaults,
    filteredMarker,
    sample,
    preview,
}) {
    const { shell } = usePage().props;
    const t = useT();

    const isProject = scope === 'project';
    const owner = isProject ? project : organization;
    const createHref = isProject ? project.rulesHref : organization.rulesHref;

    return (
        <>
            <PageHead
                title={t('privacy.title', { name: owner.name })}
                appName={shell.appName}
                help={t('privacy.help')}
                meta={
                    <div className="flex items-center gap-3">
                        {isProject && (
                            <Link
                                href={project.href}
                                className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                            >
                                {project.name}
                            </Link>
                        )}
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
                {isProject && (
                    <Options project={project} options={options} canManage={permissions.manage} />
                )}

                <Rules
                    rules={rules}
                    createHref={createHref}
                    typeOptions={typeOptions}
                    canManage={permissions.manage}
                    scopeHint={t(`privacy.scope.${scope}`)}
                />

                {isProject && inheritedRules.length > 0 && (
                    <InheritedRules rules={inheritedRules} organization={organization} />
                )}

                <DefaultRules defaults={defaults} marker={filteredMarker} />

                {isProject && <Preview project={project} sample={sample} preview={preview} />}
            </div>
        </>
    );
}

// Die drei Schalter. Ein Formular für alle drei: sie beschreiben eine
// gemeinsame Haltung zum Speichern, und wer einen umlegt, sieht dabei die
// anderen beiden.
function Options({ project, options, canManage }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        scrub_ip_addresses: options.scrub_ip_addresses,
        scrub_user_data: options.scrub_user_data,
        scrub_attachments: options.scrub_attachments,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(project.optionsHref, { preserveScroll: true });
    };

    const fields = [
        ['scrub_ip_addresses', 'privacy.options.ip', 'privacy.options.ip_hint'],
        ['scrub_user_data', 'privacy.options.user', 'privacy.options.user_hint'],
        ['scrub_attachments', 'privacy.options.attachments', 'privacy.options.attachments_hint'],
    ];

    return (
        <Card
            title={t('privacy.options.title')}
            description={
                canManage
                    ? t('privacy.options.description')
                    : `${t('privacy.options.description')} ${t('privacy.options.read_only')}`
            }
        >
            <form onSubmit={submit} className="space-y-4">
                {fields.map(([field, label, hint]) => (
                    <div key={field}>
                        <label className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <Checkbox
                                name={field}
                                className="mt-0.5"
                                checked={data[field]}
                                disabled={!canManage}
                                onChange={(e) => setData(field, e.target.checked)}
                            />
                            <span>
                                <span className="font-medium">{t(label)}</span>
                                <span className="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                    {t(hint)}
                                </span>
                            </span>
                        </label>
                        <InputError message={errors[field]} className="mt-2" />
                    </div>
                ))}

                {canManage && (
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('privacy.options.submit')}
                    </PrimaryButton>
                )}
            </form>
        </Card>
    );
}

function Rules({ rules, createHref, typeOptions, canManage, scopeHint }) {
    const t = useT();

    return (
        <Card
            title={t('privacy.rules.title')}
            description={`${t('privacy.rules.description')} ${scopeHint}`}
        >
            <div className="space-y-4">
                {rules.length === 0 && (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        {t('privacy.rules.empty')}
                    </p>
                )}

                {rules.map((rule) => (
                    <RuleRow
                        key={rule.id}
                        rule={rule}
                        typeOptions={typeOptions}
                        canManage={canManage}
                    />
                ))}

                {canManage && <CreateRule createHref={createHref} typeOptions={typeOptions} />}
            </div>
        </Card>
    );
}

// Die Felder einer Regel. In der Zeile und im Anlege-Formular dieselben —
// getrennt geschrieben würden die Hinweise auseinanderlaufen.
function RuleFields({ data, setData, errors, idPrefix, typeOptions }) {
    const t = useT();

    return (
        <>
            <div>
                <InputLabel htmlFor={`${idPrefix}_type`} value={t('privacy.rules.type')} />
                <SelectInput
                    id={`${idPrefix}_type`}
                    name="type"
                    value={data.type}
                    options={typeOptions}
                    className="mt-1"
                    onChange={(e) => setData('type', e.target.value)}
                />
                <InputError message={errors.type} className="mt-2" />
            </div>

            <div>
                <InputLabel
                    htmlFor={`${idPrefix}_expression`}
                    value={t('privacy.rules.expression')}
                />
                <TextInput
                    id={`${idPrefix}_expression`}
                    name="expression"
                    value={data.expression}
                    required
                    className="mt-1 font-mono"
                    onChange={(e) => setData('expression', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t(
                        data.type === 'pattern'
                            ? 'privacy.rules.expression_hint_pattern'
                            : 'privacy.rules.expression_hint_field'
                    )}
                </p>
                <InputError message={errors.expression} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor={`${idPrefix}_path`} value={t('privacy.rules.path')} />
                <TextInput
                    id={`${idPrefix}_path`}
                    name="path"
                    value={data.path ?? ''}
                    placeholder={t('privacy.rules.path_placeholder')}
                    className="mt-1 font-mono"
                    onChange={(e) => setData('path', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('privacy.rules.path_hint')}
                </p>
                <InputError message={errors.path} className="mt-2" />
            </div>
        </>
    );
}

function RuleRow({ rule, typeOptions, canManage }) {
    const t = useT();
    const {
        data,
        setData,
        patch,
        delete: destroy,
        processing,
        errors,
    } = useForm({
        type: rule.type,
        expression: rule.expression,
        path: rule.path ?? '',
        is_active: rule.isActive,
    });

    if (!canManage) {
        return <ReadOnlyRule rule={rule} />;
    }

    const submit = (e) => {
        e.preventDefault();
        patch(rule.href, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="grid grid-cols-1 gap-4 border-t border-gray-200 pt-4 md:grid-cols-3 dark:border-gray-700"
        >
            <RuleFields
                data={data}
                setData={setData}
                errors={errors}
                idPrefix={`rule_${rule.id}`}
                typeOptions={typeOptions}
            />

            <div className="flex flex-wrap items-center gap-3 md:col-span-3">
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <Checkbox
                        name="is_active"
                        checked={data.is_active}
                        onChange={(e) => setData('is_active', e.target.checked)}
                    />
                    {t('privacy.rules.active')}
                </label>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('privacy.rules.save')}
                </PrimaryButton>

                <DangerButton
                    type="button"
                    disabled={processing}
                    onClick={() => destroy(rule.href, { preserveScroll: true })}
                >
                    {t('privacy.rules.delete')}
                </DangerButton>
            </div>
        </form>
    );
}

// Eine Regel für Betrachter ohne Änderungsrecht — und dieselbe Darstellung für
// die geerbten Regeln der Organisation.
function ReadOnlyRule({ rule }) {
    const t = useT();

    return (
        <div className="flex flex-wrap items-baseline gap-2 border-t border-gray-200 pt-3 text-sm dark:border-gray-700">
            <span className="text-xs text-gray-500 dark:text-gray-400">{rule.typeLabel}</span>
            <code className="font-mono break-all text-gray-800 dark:text-gray-200">
                {rule.expression}
            </code>
            <span className="text-xs text-gray-500 dark:text-gray-400">
                {rule.path ?? t('privacy.rules.path_placeholder')}
            </span>
            {!rule.isActive && (
                <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                    {t('privacy.rules.inactive')}
                </span>
            )}
        </div>
    );
}

function CreateRule({ createHref, typeOptions }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        type: typeOptions[0]?.value ?? 'field',
        expression: '',
        path: '',
        is_active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(createHref, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <form
            onSubmit={submit}
            className="grid grid-cols-1 gap-4 border-t border-gray-200 pt-4 md:grid-cols-3 dark:border-gray-700"
        >
            <RuleFields
                data={data}
                setData={setData}
                errors={errors}
                idPrefix="new_rule"
                typeOptions={typeOptions}
            />

            <div className="md:col-span-3">
                <PrimaryButton type="submit" disabled={processing}>
                    {t('privacy.rules.add')}
                </PrimaryButton>
            </div>
        </form>
    );
}

function InheritedRules({ rules, organization }) {
    const t = useT();

    return (
        <Card title={t('privacy.inherited.title')} description={t('privacy.inherited.description')}>
            <div className="space-y-1">
                {rules.map((rule) => (
                    <ReadOnlyRule key={rule.id} rule={rule} />
                ))}
            </div>

            <div className="mt-4">
                <Link href={organization.privacyHref}>
                    <SecondaryButton type="button">{t('privacy.inherited.manage')}</SecondaryButton>
                </Link>
            </div>
        </Card>
    );
}

// Die Standardregeln, zugeklappt. Sie beantworten eine Nachfrage („ist das
// wirklich abgedeckt?") und nicht die erste Frage — aufgeklappt sind es über
// vierzig Einträge, und die stünden sonst vor allem anderen.
function DefaultRules({ defaults, marker }) {
    const t = useT();
    const count = defaults.fields.length + defaults.patterns.length;

    return (
        <Card
            title={t('privacy.defaults.title')}
            description={`${t('privacy.defaults.description')} ${t('privacy.defaults.marker', { marker })}`}
        >
            <details className="rounded-md border border-gray-200 dark:border-gray-700">
                <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                    {t('privacy.defaults.show', { count })}
                </summary>

                <div className="space-y-4 px-3 py-3">
                    <div>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            {t('privacy.defaults.fields')}
                        </p>
                        <p className="mt-1 font-mono text-sm break-all text-gray-800 dark:text-gray-200">
                            {defaults.fields.join('  ·  ')}
                        </p>
                    </div>

                    <div>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            {t('privacy.defaults.patterns')}
                        </p>
                        <ul className="mt-1 space-y-1">
                            {defaults.patterns.map((pattern) => (
                                <li
                                    key={pattern}
                                    className="font-mono text-xs break-all text-gray-800 dark:text-gray-200"
                                >
                                    {pattern}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </details>
        </Card>
    );
}

// Die Probe am Beispiel. Das Beispiel steht im Textfeld und ist änderbar: wer
// prüfen will, ob seine Regel greift, tippt sein eigenes Feld hinein — statt zu
// warten, bis die nächste echte Meldung eintrifft.
function Preview({ project, sample, preview }) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm({
        sample: JSON.stringify(sample, null, 4),
    });

    const submit = (e) => {
        e.preventDefault();
        post(project.previewHref, { preserveScroll: true });
    };

    return (
        <Card title={t('privacy.preview.title')} description={t('privacy.preview.description')}>
            <form onSubmit={submit} className="space-y-3">
                <div>
                    <InputLabel htmlFor="privacy_sample" value={t('privacy.preview.sample')} />
                    <textarea
                        id="privacy_sample"
                        name="sample"
                        rows={12}
                        value={data.sample}
                        onChange={(e) => setData('sample', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 font-mono text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    />
                    <InputError message={errors.sample} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('privacy.preview.submit')}
                </PrimaryButton>
            </form>

            {preview && <PreviewResult preview={preview} />}
        </Card>
    );
}

function PreviewResult({ preview }) {
    const t = useT();

    return (
        <div className="mt-4 space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
            <div>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {preview.paths.length === 0
                        ? t('privacy.preview.removed_none')
                        : t('privacy.preview.removed', { count: preview.paths.length })}
                </p>
                <ul className="mt-1 flex flex-wrap gap-2">
                    {preview.paths.map((path) => (
                        <li
                            key={path}
                            className="rounded-full bg-amber-100 px-2 py-0.5 font-mono text-xs text-amber-900 dark:bg-amber-900 dark:text-amber-100"
                        >
                            {path}
                        </li>
                    ))}
                </ul>

                {preview.truncated && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('privacy.preview.truncated')}
                    </p>
                )}
            </div>

            <div>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t('privacy.preview.result')}
                </p>
                <pre className="mt-1 overflow-x-auto rounded-md bg-gray-100 px-3 py-2 font-mono text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                    {preview.event}
                </pre>
            </div>
        </div>
    );
}
