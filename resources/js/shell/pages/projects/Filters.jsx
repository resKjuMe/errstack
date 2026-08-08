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

// Die Eingangsfilter eines Projekts. Die Zählung steht bewusst neben jedem
// Schalter und nicht auf einer eigenen Seite: eine gefilterte Meldung
// hinterlässt in der Fehlerliste keine Lücke, und ohne die Zahl daneben wäre
// nicht zu erkennen, ob ein Filter zwei Meldungen im Monat nimmt oder die
// Hälfte des Aufkommens.
export default function Filters({
    project,
    organization,
    kinds,
    rules,
    ruleOptions,
    browserDefaults,
    knownHosts,
    windowDays,
    filtered,
    permissions,
    maxPerKind,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('inbound.title', { project: project.name })}
                appName={shell.appName}
                help={t('inbound.help')}
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
                <Switches
                    project={project}
                    kinds={kinds}
                    windowDays={windowDays}
                    filtered={filtered}
                    canManage={permissions.manage}
                />

                {kinds
                    .filter((kind) => kind.usesRules)
                    .map((kind) => (
                        <RuleList
                            key={kind.value}
                            project={project}
                            kind={kind}
                            rules={rules[kind.value] ?? []}
                            ruleOptions={ruleOptions}
                            browserDefaults={browserDefaults}
                            canManage={permissions.manage}
                            maxPerKind={maxPerKind}
                        />
                    ))}

                <Card title={t('inbound.known.title')} description={t('inbound.known.description')}>
                    <div className="flex flex-wrap gap-1">
                        {knownHosts.map((host) => (
                            <code
                                key={host}
                                className="rounded-md bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-800 dark:bg-gray-900 dark:text-gray-200"
                            >
                                {host}
                            </code>
                        ))}
                    </div>
                </Card>
            </div>
        </>
    );
}

// Ein Formular für alle sieben Schalter: sie beschreiben eine gemeinsame
// Haltung dazu, was als Rauschen gilt, und wer einen umlegt, sieht dabei die
// anderen sechs samt ihrer Wirkung.
function Switches({ project, kinds, windowDays, filtered, canManage }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm(
        Object.fromEntries(kinds.map((kind) => [kind.column, kind.enabled]))
    );

    const submit = (e) => {
        e.preventDefault();
        patch(project.optionsHref, { preserveScroll: true });
    };

    return (
        <Card
            title={t('inbound.options.title')}
            description={
                canManage
                    ? t('inbound.options.description')
                    : `${t('inbound.options.description')} ${t('inbound.options.read_only')}`
            }
        >
            <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
                {t('inbound.options.counted', { days: windowDays, count: filtered })}
            </p>

            <form onSubmit={submit} className="space-y-4">
                {kinds.map((kind) => (
                    <div key={kind.value}>
                        <label className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <Checkbox
                                name={kind.column}
                                className="mt-0.5"
                                checked={data[kind.column]}
                                disabled={!canManage}
                                onChange={(e) => setData(kind.column, e.target.checked)}
                            />
                            <span className="flex-1">
                                <span className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{kind.label}</span>
                                    <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-normal text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                        {t('inbound.options.filtered', { count: kind.filtered })}
                                    </span>
                                </span>
                                <span className="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                    {kind.hint}
                                </span>
                            </span>
                        </label>
                        <InputError message={errors[kind.column]} className="mt-2" />
                    </div>
                ))}

                {canManage && (
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('inbound.options.submit')}
                    </PrimaryButton>
                )}
            </form>
        </Card>
    );
}

// Die Liste einer Filterart. Sie steht auch dann da, wenn die Art abgeschaltet
// ist — mit einem Hinweis darauf: eine Liste, die verschwindet, sobald man den
// Schalter umlegt, sieht aus wie eine gelöschte.
function RuleList({ project, kind, rules, ruleOptions, browserDefaults, canManage, maxPerKind }) {
    const t = useT();

    return (
        <Card
            title={t('inbound.rules.title', { kind: kind.label })}
            description={t(`inbound.rules.${kind.value}_description`)}
        >
            <div className="space-y-4">
                {!kind.enabled && (
                    <p className="text-xs text-amber-700 dark:text-amber-500">
                        {t('inbound.rules.disabled_hint')}
                    </p>
                )}

                {kind.value === 'legacy_browser' && rules.length === 0 && (
                    <div className="text-xs text-gray-500 dark:text-gray-400">
                        <p>{t('inbound.rules.browser_defaults')}</p>
                        <div className="mt-1 flex flex-wrap gap-1">
                            {browserDefaults.map((entry) => (
                                <code
                                    key={entry}
                                    className="rounded-md bg-gray-100 px-2 py-0.5 font-mono text-gray-800 dark:bg-gray-900 dark:text-gray-200"
                                >
                                    {entry}
                                </code>
                            ))}
                        </div>
                    </div>
                )}

                {rules.length === 0 && kind.value !== 'legacy_browser' && (
                    <p className="text-sm text-gray-600 dark:text-gray-400">
                        {t('inbound.rules.empty')}
                    </p>
                )}

                {rules.map((rule) => (
                    <Rule key={rule.id} rule={rule} canManage={canManage} />
                ))}

                {canManage && rules.length < maxPerKind && (
                    <CreateRule project={project} kind={kind} ruleOptions={ruleOptions} />
                )}
            </div>
        </Card>
    );
}

function Rule({ rule, canManage }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        expression: rule.expression,
    });

    const submit = (e) => {
        e.preventDefault();
        // Eigener Fehlerbeutel je Formular. Ohne ihn sind Inertias Fehler
        // seitenweit: ein abgelehntes Muster erschiene unter *jedem*
        // Eingabefeld der Seite, auch unter denen der drei anderen Filterarten.
        patch(rule.href, { preserveScroll: true, errorBag: `rule_${rule.id}` });
    };

    if (!canManage) {
        return (
            <div className="flex items-center gap-2">
                <code className="flex-1 rounded-md bg-gray-100 px-3 py-1 font-mono text-xs break-all text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                    {rule.expression}
                </code>
                {!rule.isActive && (
                    <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                        {t('inbound.rules.inactive_badge')}
                    </span>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-2 border-t border-gray-200 pt-4 dark:border-gray-700">
            <form onSubmit={submit} className="flex flex-wrap items-start gap-2">
                <div className="min-w-48 flex-1">
                    <InputLabel
                        htmlFor={`expression_${rule.id}`}
                        value={t('inbound.rules.expression')}
                    />
                    <TextInput
                        id={`expression_${rule.id}`}
                        name="expression"
                        value={data.expression}
                        required
                        className="mt-1 w-full font-mono"
                        onChange={(e) => setData('expression', e.target.value)}
                    />
                    <InputError message={errors.expression} className="mt-2" />
                </div>
                <PrimaryButton type="submit" className="mt-6" disabled={processing}>
                    {t('inbound.rules.save')}
                </PrimaryButton>
            </form>

            <RuleActions rule={rule} />
        </div>
    );
}

function RuleActions({ rule }) {
    const t = useT();
    const { post, processing: toggling } = useForm({});
    const { delete: destroy, processing: deleting } = useForm({});

    return (
        <div className="flex flex-wrap items-center gap-2">
            <SecondaryButton
                type="button"
                disabled={toggling}
                onClick={() => post(rule.toggleHref, { preserveScroll: true })}
            >
                {t(rule.isActive ? 'inbound.rules.disable' : 'inbound.rules.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={deleting}
                onClick={() => destroy(rule.href, { preserveScroll: true })}
            >
                {t('inbound.rules.delete')}
            </DangerButton>

            {!rule.isActive && (
                <span className="text-xs text-gray-500 dark:text-gray-400">
                    {t('inbound.rules.inactive_badge')}
                </span>
            )}
        </div>
    );
}

function CreateRule({ project, kind, ruleOptions }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        kind: kind.value,
        expression: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(project.rulesHref, {
            preserveScroll: true,
            errorBag: `new_${kind.value}`,
            onSuccess: () => reset('expression'),
        });
    };

    return (
        <form
            onSubmit={submit}
            className="flex flex-wrap items-start gap-2 border-t border-gray-200 pt-4 dark:border-gray-700"
        >
            {/* Die Art steht fest — die Liste gehört zu genau einer. Das Feld ist
                trotzdem da und nicht nur versteckt: sonst wäre beim Abschicken
                nicht zu sehen, wohin der Eintrag geht. */}
            <div>
                <InputLabel htmlFor={`kind_${kind.value}`} value={t('inbound.rules.kind')} />
                <SelectInput
                    id={`kind_${kind.value}`}
                    name="kind"
                    value={data.kind}
                    className="mt-1"
                    disabled
                    onChange={(e) => setData('kind', e.target.value)}
                >
                    {ruleOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </SelectInput>
                <InputError message={errors.kind} className="mt-2" />
            </div>

            <div className="min-w-48 flex-1">
                <InputLabel
                    htmlFor={`new_expression_${kind.value}`}
                    value={t('inbound.rules.expression')}
                />
                <TextInput
                    id={`new_expression_${kind.value}`}
                    name="expression"
                    value={data.expression}
                    required
                    placeholder={t(`inbound.rules.${kind.value}_placeholder`)}
                    className="mt-1 w-full font-mono"
                    onChange={(e) => setData('expression', e.target.value)}
                />
                <InputError message={errors.expression} className="mt-2" />
            </div>

            <PrimaryButton type="submit" className="mt-6" disabled={processing}>
                {t('inbound.rules.create')}
            </PrimaryButton>
        </form>
    );
}
