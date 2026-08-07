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

// Die Fingerprint-Regeln eines Projekts: sie korrigieren das Grouping dort, wo
// das Standardverfahren zu grob oder zu fein greift. Ansehen darf jedes
// Mitglied — die Regeln erklären, warum die Fehlerliste so aussieht, wie sie
// aussieht. Ändern darf nur die Verwaltung; entschieden wird das serverseitig,
// `canManage` blendet hier lediglich aus, was ohnehin abgewiesen würde.
export default function Grouping({ project, organization, rules, attributes, canManage }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('grouping.title', { project: project.name })}
                appName={shell.appName}
                help={t('grouping.help')}
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
                <Card
                    title={t('grouping.rules.title')}
                    description={t('grouping.rules.description')}
                >
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {t('grouping.rules.retroactive_hint')}
                    </p>
                </Card>

                {rules.length === 0 && (
                    <Card>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('grouping.rules.empty')}
                        </p>
                    </Card>
                )}

                {rules.map((rule) => (
                    <RuleCard
                        key={rule.id}
                        rule={rule}
                        attributes={attributes}
                        canManage={canManage}
                    />
                ))}

                {canManage && <CreateRule project={project} attributes={attributes} />}
            </div>
        </>
    );
}

function RuleCard({ rule, attributes, canManage }) {
    const t = useT();

    return (
        <Card
            title={
                <span className="flex items-center gap-2">
                    {rule.name}
                    {!rule.active && (
                        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-normal text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            {t('grouping.rules.inactive_badge')}
                        </span>
                    )}
                </span>
            }
            description={t('grouping.rules.position', { position: rule.position })}
        >
            {canManage ? (
                <div className="space-y-4">
                    <RuleForm rule={rule} attributes={attributes} />
                    <RuleActions rule={rule} />
                </div>
            ) : (
                <RuleSummary rule={rule} />
            )}
        </Card>
    );
}

// Die Nur-Lese-Ansicht für Mitglieder ohne Verwaltungsrecht. Sie zeigt dasselbe
// wie das Formular, nur ohne Eingabefelder — die Frage „warum liegen die beiden
// in einer Gruppe?" beantwortet sie genauso.
function RuleSummary({ rule }) {
    const t = useT();

    return (
        <dl className="space-y-3 text-sm">
            <div>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                    {t('grouping.matchers.label')}
                </dt>
                <dd className="mt-1 space-y-1">
                    {rule.matchers.map((matcher, index) => (
                        <code
                            key={index}
                            className="block rounded-md bg-gray-100 px-3 py-1 font-mono text-xs break-all text-gray-800 dark:bg-gray-900 dark:text-gray-200"
                        >
                            {matcher.negated ? '!' : ''}
                            {matcher.attribute}: {matcher.pattern}
                        </code>
                    ))}
                </dd>
            </div>

            <div>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                    {t('grouping.fingerprint.label')}
                </dt>
                <dd className="mt-1">
                    <code className="block rounded-md bg-gray-100 px-3 py-1 font-mono text-xs break-all text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                        {rule.fingerprint.join(' · ')}
                    </code>
                </dd>
            </div>
        </dl>
    );
}

function RuleForm({ rule, attributes }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        name: rule.name,
        matchers: rule.matchers,
        fingerprint: rule.fingerprint,
        position: rule.position,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(rule.href, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div>
                <InputLabel htmlFor={`name_${rule.id}`} value={t('grouping.name')} />
                <TextInput
                    id={`name_${rule.id}`}
                    name="name"
                    value={data.name}
                    required
                    className="mt-1"
                    onChange={(e) => setData('name', e.target.value)}
                />
                <InputError message={errors.name} className="mt-2" />
            </div>

            <div>
                <InputLabel
                    htmlFor={`position_${rule.id}`}
                    value={t('grouping.rules.position_label')}
                />
                <TextInput
                    id={`position_${rule.id}`}
                    name="position"
                    type="number"
                    min="0"
                    value={data.position}
                    className="mt-1 max-w-24"
                    onChange={(e) => setData('position', e.target.value)}
                />
                <InputError message={errors.position} className="mt-2" />
            </div>

            <MatcherFields
                idPrefix={`rule_${rule.id}`}
                matchers={data.matchers}
                attributes={attributes}
                errors={errors}
                onChange={(matchers) => setData('matchers', matchers)}
            />

            <FingerprintFields
                idPrefix={`rule_${rule.id}`}
                values={data.fingerprint}
                errors={errors}
                onChange={(values) => setData('fingerprint', values)}
            />

            <PrimaryButton type="submit" disabled={processing}>
                {t('grouping.save')}
            </PrimaryButton>
        </form>
    );
}

function RuleActions({ rule }) {
    const t = useT();
    const { post, delete: destroy, processing } = useForm({});

    return (
        <div className="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
            <SecondaryButton
                type="button"
                disabled={processing}
                onClick={() => post(rule.toggleHref, { preserveScroll: true })}
            >
                {t(rule.active ? 'grouping.disable' : 'grouping.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(rule.href, { preserveScroll: true })}
            >
                {t('grouping.delete')}
            </DangerButton>
        </div>
    );
}

// Die Bedingungen. Mindestens eine bleibt immer stehen: eine Regel ohne
// Bedingung träfe auf jede Meldung zu und zöge das ganze Projekt in eine
// Gruppe. Der Server weist das ab — hier gibt es die Möglichkeit gar nicht
// erst.
function MatcherFields({ idPrefix, matchers, attributes, errors, onChange }) {
    const t = useT();

    const update = (index, field, value) => {
        onChange(
            matchers.map((matcher, i) => (i === index ? { ...matcher, [field]: value } : matcher))
        );
    };

    return (
        <div>
            <InputLabel value={t('grouping.matchers.label')} />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('grouping.matchers.hint')}
            </p>

            <div className="mt-2 space-y-2">
                {matchers.map((matcher, index) => (
                    <div key={index} className="flex flex-wrap items-center gap-2">
                        <SelectInput
                            id={`${idPrefix}_attribute_${index}`}
                            name={`matchers[${index}][attribute]`}
                            value={matcher.attribute}
                            options={attributes.map((name) => ({ value: name, label: name }))}
                            className="w-48"
                            onChange={(e) => update(index, 'attribute', e.target.value)}
                        />

                        <TextInput
                            id={`${idPrefix}_pattern_${index}`}
                            name={`matchers[${index}][pattern]`}
                            value={matcher.pattern}
                            required
                            placeholder={t('grouping.matchers.placeholder')}
                            className="grow"
                            onChange={(e) => update(index, 'pattern', e.target.value)}
                        />

                        <label className="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                            <Checkbox
                                checked={Boolean(matcher.negated)}
                                onChange={(e) => update(index, 'negated', e.target.checked)}
                            />
                            {t('grouping.matchers.negated')}
                        </label>

                        {matchers.length > 1 && (
                            <SecondaryButton
                                type="button"
                                onClick={() => onChange(matchers.filter((_, i) => i !== index))}
                            >
                                {t('grouping.matchers.remove')}
                            </SecondaryButton>
                        )}
                    </div>
                ))}
            </div>

            <SecondaryButton
                type="button"
                className="mt-2"
                onClick={() =>
                    onChange([
                        ...matchers,
                        { attribute: attributes[0], pattern: '', negated: false },
                    ])
                }
            >
                {t('grouping.matchers.add')}
            </SecondaryButton>

            <InputError message={errors.matchers} className="mt-2" />
        </div>
    );
}

function FingerprintFields({ idPrefix, values, errors, onChange }) {
    const t = useT();

    return (
        <div>
            <InputLabel value={t('grouping.fingerprint.label')} />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('grouping.fingerprint.hint')}
            </p>

            <div className="mt-2 space-y-2">
                {values.map((value, index) => (
                    <div key={index} className="flex flex-wrap items-center gap-2">
                        <TextInput
                            id={`${idPrefix}_fingerprint_${index}`}
                            name={`fingerprint[${index}]`}
                            value={value}
                            required
                            placeholder={t('grouping.fingerprint.placeholder')}
                            className="grow font-mono"
                            onChange={(e) =>
                                onChange(
                                    values.map((entry, i) => (i === index ? e.target.value : entry))
                                )
                            }
                        />

                        {values.length > 1 && (
                            <SecondaryButton
                                type="button"
                                onClick={() => onChange(values.filter((_, i) => i !== index))}
                            >
                                {t('grouping.fingerprint.remove')}
                            </SecondaryButton>
                        )}
                    </div>
                ))}
            </div>

            <SecondaryButton
                type="button"
                className="mt-2"
                onClick={() => onChange([...values, '{{ default }}'])}
            >
                {t('grouping.fingerprint.add')}
            </SecondaryButton>

            <InputError message={errors.fingerprint} className="mt-2" />
        </div>
    );
}

function CreateRule({ project, attributes }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        matchers: [{ attribute: attributes[0], pattern: '', negated: false }],
        fingerprint: [''],
    });

    const submit = (e) => {
        e.preventDefault();
        post(project.groupingHref, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Card title={t('grouping.create.title')} description={t('grouping.create.description')}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="new_rule_name" value={t('grouping.name')} />
                    <TextInput
                        id="new_rule_name"
                        name="name"
                        value={data.name}
                        required
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('grouping.name_hint')}
                    </p>
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <MatcherFields
                    idPrefix="new_rule"
                    matchers={data.matchers}
                    attributes={attributes}
                    errors={errors}
                    onChange={(matchers) => setData('matchers', matchers)}
                />

                <FingerprintFields
                    idPrefix="new_rule"
                    values={data.fingerprint}
                    errors={errors}
                    onChange={(values) => setData('fingerprint', values)}
                />

                <PrimaryButton type="submit" disabled={processing}>
                    {t('grouping.create.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}
