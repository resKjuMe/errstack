import React from 'react';
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
import { useT } from '../../i18n.js';

// Die Stichproben-Regeln eines Projekts: sie entscheiden, welcher Anteil der
// gemeldeten Antwortzeiten gespeichert wird. Ansehen darf jedes Mitglied — die
// Regeln erklären, warum in der Performance-Übersicht mehr Aufrufe stehen als
// gespeicherte Messungen. Ändern darf nur die Verwaltung; entschieden wird das
// serverseitig, `canManage` blendet hier lediglich aus, was ohnehin abgewiesen
// würde.
export default function Sampling({
    project,
    organization,
    rules,
    conditions,
    windowSeconds,
    canManage,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('sampling.title', { project: project.name })}
                appName={shell.appName}
                help={t('sampling.help')}
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
                    title={t('sampling.rules.title')}
                    description={t('sampling.rules.description')}
                >
                    <div className="space-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <p>{t('sampling.rules.irreversible_hint')}</p>
                        <p>{t('sampling.rules.errors_hint')}</p>
                    </div>
                </Card>

                {rules.length === 0 && (
                    <Card>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('sampling.rules.empty')}
                        </p>
                    </Card>
                )}

                {rules.map((rule) => (
                    <RuleCard
                        key={rule.id}
                        rule={rule}
                        conditions={conditions}
                        windowSeconds={windowSeconds}
                        canManage={canManage}
                    />
                ))}

                {canManage && (
                    <CreateRule
                        project={project}
                        conditions={conditions}
                        windowSeconds={windowSeconds}
                    />
                )}
            </div>
        </>
    );
}

// Die Quote steht in der Datenbank als Anteil (0,01) und in der Oberfläche als
// Prozentwert (1) — niemand denkt in Anteilen über Verkehr. Umgerechnet wird
// deshalb an genau diesen zwei Stellen und nirgends sonst; `toFixed` schneidet
// die Nachkommastellen ab, die beim Multiplizieren mit 100 aus einer
// Gleitkommazahl herausfallen (1 statt 1.0000000000000002).
const toPercent = (rate) => Number((Number(rate) * 100).toFixed(6));
const toRate = (percent) => Number(percent) / 100;

function RuleCard({ rule, conditions, windowSeconds, canManage }) {
    const t = useT();

    return (
        <Card
            title={
                <span className="flex items-center gap-2">
                    {rule.name}
                    {!rule.active && (
                        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-normal text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            {t('sampling.rules.inactive_badge')}
                        </span>
                    )}
                </span>
            }
            description={t('sampling.rules.position', { position: rule.position })}
        >
            {canManage ? (
                <div className="space-y-4">
                    <RuleForm rule={rule} conditions={conditions} windowSeconds={windowSeconds} />
                    <RuleActions rule={rule} />
                </div>
            ) : (
                <RuleSummary rule={rule} conditions={conditions} windowSeconds={windowSeconds} />
            )}
        </Card>
    );
}

// Die Nur-Lese-Ansicht für Mitglieder ohne Verwaltungsrecht. Sie zeigt dasselbe
// wie das Formular, nur ohne Eingabefelder — die Frage „warum fehlen Messungen?"
// beantwortet sie genauso.
function RuleSummary({ rule, conditions, windowSeconds }) {
    const t = useT();
    const set = conditions.filter((field) => rule[field]);

    return (
        <dl className="space-y-3 text-sm">
            <div>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                    {t('sampling.conditions.label')}
                </dt>
                <dd className="mt-1 space-y-1">
                    {set.length === 0 && (
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('sampling.conditions.all')}
                        </p>
                    )}
                    {set.map((field) => (
                        <code
                            key={field}
                            className="block rounded-md bg-gray-100 px-3 py-1 font-mono text-xs break-all text-gray-800 dark:bg-gray-900 dark:text-gray-200"
                        >
                            {t(`sampling.conditions.${field}`)}: {rule[field]}
                        </code>
                    ))}
                </dd>
            </div>

            <div>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                    {t('sampling.rate.label')}
                </dt>
                <dd className="mt-1">
                    {toPercent(rule.sample_rate)} {t('sampling.rate.suffix')} ·{' '}
                    {t('sampling.minimum.label')} {rule.minimum_per_window}{' '}
                    {t('sampling.minimum.suffix')} ({windowSeconds} s)
                </dd>
            </div>
        </dl>
    );
}

function RuleForm({ rule, conditions, windowSeconds }) {
    const t = useT();
    const form = useForm({
        name: rule.name,
        transaction_name: rule.transaction_name ?? '',
        environment: rule.environment ?? '',
        release: rule.release ?? '',
        op: rule.op ?? '',
        percent: toPercent(rule.sample_rate),
        minimum_per_window: rule.minimum_per_window,
        position: rule.position,
    });

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({ ...data, sample_rate: toRate(data.percent) })).patch(
            rule.href,
            { preserveScroll: true }
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div>
                <InputLabel htmlFor={`name_${rule.id}`} value={t('sampling.name')} />
                <TextInput
                    id={`name_${rule.id}`}
                    name="name"
                    value={form.data.name}
                    required
                    className="mt-1"
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} className="mt-2" />
            </div>

            <div>
                <InputLabel
                    htmlFor={`position_${rule.id}`}
                    value={t('sampling.rules.position_label')}
                />
                <TextInput
                    id={`position_${rule.id}`}
                    name="position"
                    type="number"
                    min="0"
                    value={form.data.position}
                    className="mt-1 max-w-24"
                    onChange={(e) => form.setData('position', e.target.value)}
                />
                <InputError message={form.errors.position} className="mt-2" />
            </div>

            <ConditionFields
                idPrefix={`rule_${rule.id}`}
                conditions={conditions}
                data={form.data}
                errors={form.errors}
                onChange={form.setData}
            />

            <RateFields
                idPrefix={`rule_${rule.id}`}
                data={form.data}
                errors={form.errors}
                windowSeconds={windowSeconds}
                onChange={form.setData}
            />

            <PrimaryButton type="submit" disabled={form.processing}>
                {t('sampling.save')}
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
                {t(rule.active ? 'sampling.disable' : 'sampling.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(rule.href, { preserveScroll: true })}
            >
                {t('sampling.delete')}
            </DangerButton>
        </div>
    );
}

// Die vier Bedingungen. Alle sind freiwillig: eine Regel ohne Bedingung trifft
// auf jeden Aufruf zu und ist damit die Vorgabe des Projekts — anders als beim
// Grouping ist das hier erwünscht und nicht verboten.
function ConditionFields({ idPrefix, conditions, data, errors, onChange }) {
    const t = useT();

    return (
        <div>
            <InputLabel value={t('sampling.conditions.label')} />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('sampling.conditions.hint')}
            </p>

            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {conditions.map((field) => (
                    <div key={field}>
                        <InputLabel
                            htmlFor={`${idPrefix}_${field}`}
                            value={t(`sampling.conditions.${field}`)}
                            className="text-xs"
                        />
                        <TextInput
                            id={`${idPrefix}_${field}`}
                            name={field}
                            value={data[field]}
                            placeholder={t(`sampling.conditions.placeholder.${field}`)}
                            className="mt-1 w-full font-mono"
                            onChange={(e) => onChange(field, e.target.value)}
                        />
                        <InputError message={errors[field]} className="mt-2" />
                    </div>
                ))}
            </div>

            {conditions.every((field) => !data[field]) && (
                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {t('sampling.conditions.all')}
                </p>
            )}
        </div>
    );
}

function RateFields({ idPrefix, data, errors, windowSeconds, onChange }) {
    const t = useT();

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div>
                <InputLabel htmlFor={`${idPrefix}_percent`} value={t('sampling.rate.label')} />
                <div className="mt-1 flex items-center gap-2">
                    <TextInput
                        id={`${idPrefix}_percent`}
                        name="percent"
                        type="number"
                        // Bis auf ein Millionstel Prozent: bei Millionen Aufrufen
                        // je Stunde ist das noch eine sinnvolle Quote, und die
                        // Spalte trägt sie.
                        step="0.000001"
                        min="0.000001"
                        max="100"
                        value={data.percent}
                        required
                        className="max-w-32"
                        onChange={(e) => onChange('percent', e.target.value)}
                    />
                    <span className="text-sm text-gray-600 dark:text-gray-400">
                        {t('sampling.rate.suffix')}
                    </span>
                </div>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('sampling.rate.hint')}
                </p>
                <InputError message={errors.sample_rate} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor={`${idPrefix}_minimum`} value={t('sampling.minimum.label')} />
                <div className="mt-1 flex items-center gap-2">
                    <TextInput
                        id={`${idPrefix}_minimum`}
                        name="minimum_per_window"
                        type="number"
                        min="0"
                        value={data.minimum_per_window}
                        className="max-w-24"
                        onChange={(e) => onChange('minimum_per_window', e.target.value)}
                    />
                    <span className="text-sm text-gray-600 dark:text-gray-400">
                        {t('sampling.minimum.suffix')}
                    </span>
                </div>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('sampling.minimum.hint', { seconds: windowSeconds })}
                </p>
                <InputError message={errors.minimum_per_window} className="mt-2" />
            </div>
        </div>
    );
}

function CreateRule({ project, conditions, windowSeconds }) {
    const t = useT();
    const form = useForm({
        name: '',
        transaction_name: '',
        environment: '',
        release: '',
        op: '',
        percent: 10,
        minimum_per_window: 1,
    });

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({ ...data, sample_rate: toRate(data.percent) })).post(
            project.samplingHref,
            { preserveScroll: true, onSuccess: () => form.reset() }
        );
    };

    return (
        <Card title={t('sampling.create.title')} description={t('sampling.create.description')}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="new_rule_name" value={t('sampling.name')} />
                    <TextInput
                        id="new_rule_name"
                        name="name"
                        value={form.data.name}
                        required
                        className="mt-1"
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('sampling.name_hint')}
                    </p>
                    <InputError message={form.errors.name} className="mt-2" />
                </div>

                <ConditionFields
                    idPrefix="new_rule"
                    conditions={conditions}
                    data={form.data}
                    errors={form.errors}
                    onChange={form.setData}
                />

                <RateFields
                    idPrefix="new_rule"
                    data={form.data}
                    errors={form.errors}
                    windowSeconds={windowSeconds}
                    onChange={form.setData}
                />

                <PrimaryButton type="submit" disabled={form.processing}>
                    {t('sampling.create.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}
