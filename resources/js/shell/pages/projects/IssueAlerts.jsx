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
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Die Alarm-Regeln für Fehler eines Projekts.
//
// Ansehen darf jedes Mitglied — welche Regeln scharf sind, ist die erste Frage,
// wenn etwas **nicht** gemeldet wurde. Ändern darf nur die Verwaltung;
// entschieden wird das serverseitig, `canManage` blendet hier lediglich aus, was
// ohnehin abgewiesen würde.
export default function IssueAlerts({
    project,
    organization,
    rules,
    history,
    conditionOptions,
    filterOptions,
    comparisonOptions,
    actionOptions,
    matchOptions,
    channelOptions,
    limits,
    canManage,
}) {
    const { shell } = usePage().props;
    const t = useT();

    const catalog = {
        conditionOptions,
        filterOptions,
        comparisonOptions,
        actionOptions,
        matchOptions,
        channelOptions,
        limits,
    };

    return (
        <>
            <PageHead
                title={t('issue_alerts.title', { project: project.name })}
                appName={shell.appName}
                help={t('issue_alerts.help')}
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
                    title={t('issue_alerts.intro.title')}
                    description={t('issue_alerts.intro.description')}
                >
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {t('issue_alerts.intro.pending_hint')}
                    </p>
                </Card>

                {rules.length === 0 && (
                    <Card>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('issue_alerts.list.empty')}
                        </p>
                    </Card>
                )}

                {rules.map((rule) => (
                    <RuleCard
                        key={rule.id}
                        rule={rule}
                        project={project}
                        catalog={catalog}
                        canManage={canManage}
                    />
                ))}

                {canManage && rules.length < limits.maxPerProject && (
                    <Card
                        title={t('issue_alerts.create.title')}
                        description={t('issue_alerts.create.description')}
                    >
                        <RuleForm project={project} catalog={catalog} />
                    </Card>
                )}

                <History history={history} />
            </div>
        </>
    );
}

function RuleCard({ rule, project, catalog, canManage }) {
    const t = useT();

    return (
        <Card
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {rule.name}
                    {!rule.active && (
                        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-normal text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            {t('issue_alerts.list.inactive_badge')}
                        </span>
                    )}
                </span>
            }
            description={t('issue_alerts.list.subtitle', { minutes: rule.frequencyMinutes })}
        >
            <div className="space-y-4">
                <Summary rule={rule} catalog={catalog} />

                {canManage ? (
                    <div className="space-y-4">
                        <RuleForm rule={rule} project={project} catalog={catalog} />
                        <Actions rule={rule} />
                    </div>
                ) : null}
            </div>
        </Card>
    );
}

// Was die Regel sagt, in einem Satz je Teil — die Fassung, die man liest, bevor
// man das Formular überhaupt aufklappt.
function Summary({ rule, catalog }) {
    const t = useT();

    const label = (options, value) =>
        options.find((option) => option.value === value)?.label ?? value;

    const conditions = rule.conditions.map((condition) =>
        label(catalog.conditionOptions, condition.type)
    );
    const filters = rule.filters.map(
        (filter) =>
            `${label(catalog.filterOptions, filter.type)} ${label(catalog.comparisonOptions, filter.comparison)} ${filter.value}`
    );
    const actions = rule.actions.map((action) => {
        if (action.type !== 'channel') {
            return label(catalog.actionOptions, action.type);
        }

        const channel =
            action.channel_id === null || action.channel_id === undefined
                ? t('issue_alerts.fields.all_channels')
                : label(catalog.channelOptions, action.channel_id);

        return `${label(catalog.actionOptions, action.type)}: ${channel}`;
    });

    return (
        <dl className="grid gap-3 text-sm sm:grid-cols-4">
            <SummaryItem label={t('issue_alerts.list.conditions')} values={conditions} />
            <SummaryItem
                label={t('issue_alerts.list.filters')}
                values={filters.length === 0 ? [t('issue_alerts.list.no_filters')] : filters}
            />
            <SummaryItem label={t('issue_alerts.list.actions')} values={actions} />
            <SummaryItem
                label={t('issue_alerts.list.triggers')}
                values={[String(rule.triggerCount)]}
            />
        </dl>
    );
}

function SummaryItem({ label, values }) {
    return (
        <div>
            <dt className="text-xs text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="mt-1 text-gray-900 dark:text-gray-100">
                {values.length === 0 ? '—' : values.join(' · ')}
            </dd>
        </div>
    );
}

function Actions({ rule }) {
    const t = useT();
    const toggle = useForm({});
    const remove = useForm({});

    return (
        <div className="flex flex-wrap gap-2">
            <SecondaryButton
                type="button"
                disabled={toggle.processing}
                onClick={() => toggle.post(rule.toggleHref, { preserveScroll: true })}
            >
                {rule.active ? t('issue_alerts.actions.disable') : t('issue_alerts.actions.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={remove.processing}
                onClick={() => remove.delete(rule.href, { preserveScroll: true })}
            >
                {t('issue_alerts.actions.delete')}
            </DangerButton>
        </div>
    );
}

// Die Vorgaben einer neuen Regel: der Anlass, den man als ersten anlegt, und
// der Weg, der ohne weitere Auswahl funktioniert.
const blank = {
    name: '',
    condition_match: 'any',
    filter_match: 'all',
    conditions: [{ type: 'new_issue', value: '', window: '' }],
    filters: [],
    actions: [{ type: 'channel', channel_id: '' }],
    frequency_minutes: 30,
};

function fromRule(rule) {
    return {
        name: rule.name,
        condition_match: rule.conditionMatch,
        filter_match: rule.filterMatch,
        conditions: rule.conditions.map((condition) => ({
            type: condition.type,
            value: condition.value ?? '',
            window: condition.window ?? '',
        })),
        filters: rule.filters.map((filter) => ({
            type: filter.type,
            comparison: filter.comparison,
            value: filter.value ?? '',
            key: filter.key ?? '',
        })),
        actions: rule.actions.map((action) => ({
            type: action.type,
            channel_id: action.channel_id ?? '',
        })),
        frequency_minutes: rule.frequencyMinutes,
    };
}

// Ein leeres Auswahlfeld geht als `null` hinaus und nicht als leerer Text: „alle
// Kanäle" und „Kanal Nr. 0" wären sonst dasselbe.
function payload(data) {
    return {
        ...data,
        conditions: data.conditions.map((condition) => ({
            type: condition.type,
            value: condition.value === '' ? null : Number(condition.value),
            window: condition.window === '' ? null : Number(condition.window),
        })),
        actions: data.actions.map((action) => ({
            type: action.type,
            channel_id: action.channel_id === '' ? null : Number(action.channel_id),
        })),
    };
}

// Das Formular — für eine bestehende Regel (`rule` gesetzt) und für eine neue
// (ohne). Eines für beide, weil die Felder dieselben sind: zwei Fassungen wären
// die Stelle, an der ein neues Feld nur an einer von beiden landet.
function RuleForm({ rule = null, project, catalog }) {
    const t = useT();
    const { shell } = usePage().props;
    const form = useForm(rule === null ? blank : fromRule(rule));
    const [preview, setPreview] = useState(null);
    const [previewing, setPreviewing] = useState(false);

    const id = (field) => `rule_${rule?.id ?? 'new'}_${field}`;

    const setRow = (list, index, patch) => {
        form.setData(
            list,
            form.data[list].map((row, position) =>
                position === index ? { ...row, ...patch } : row
            )
        );
    };

    const addRow = (list, row) => form.setData(list, [...form.data[list], row]);

    const removeRow = (list, index) =>
        form.setData(
            list,
            form.data[list].filter((_, position) => position !== index)
        );

    const submit = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true };

        if (rule === null) {
            form.transform(payload).post(project.rulesHref, {
                ...options,
                onSuccess: () => form.reset(),
            });

            return;
        }

        form.transform(payload).patch(rule.href, options);
    };

    // Die Vorschau ist bewusst kein Inertia-Aufruf: sie liefert eine Liste und
    // keine Seite, und sie bezieht sich auf einen Entwurf, der noch nicht
    // gespeichert ist.
    const showPreview = async () => {
        setPreviewing(true);

        try {
            const response = await fetch(project.previewHref, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': shell.csrf,
                },
                body: JSON.stringify(payload(form.data)),
            });

            setPreview(response.ok ? await response.json() : null);
        } catch {
            setPreview(null);
        } finally {
            setPreviewing(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel htmlFor={id('name')} value={t('issue_alerts.fields.name')} />
                    <TextInput
                        id={id('name')}
                        value={form.data.name}
                        required
                        className="mt-1"
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={form.errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor={id('frequency')}
                        value={t('issue_alerts.fields.frequency', {
                            min: catalog.limits.minFrequencyMinutes,
                            max: catalog.limits.maxFrequencyMinutes,
                        })}
                    />
                    <TextInput
                        id={id('frequency')}
                        type="number"
                        min={catalog.limits.minFrequencyMinutes}
                        max={catalog.limits.maxFrequencyMinutes}
                        value={form.data.frequency_minutes}
                        className="mt-1"
                        onChange={(e) => form.setData('frequency_minutes', e.target.value)}
                    />
                    <InputError message={form.errors.frequency_minutes} className="mt-2" />
                </div>
            </div>

            <Section
                title={t('issue_alerts.fields.condition_match')}
                match={form.data.condition_match}
                matchOptions={catalog.matchOptions}
                onMatch={(value) => form.setData('condition_match', value)}
                onAdd={() => addRow('conditions', { type: 'new_issue', value: '', window: '' })}
                addLabel={t('issue_alerts.fields.add_condition')}
                empty={form.data.conditions.length === 0}
                id={id('condition_match')}
            >
                {form.data.conditions.map((condition, index) => (
                    <ConditionRow
                        key={index}
                        index={index}
                        condition={condition}
                        options={catalog.conditionOptions}
                        errors={form.errors}
                        onChange={(patch) => setRow('conditions', index, patch)}
                        onRemove={() => removeRow('conditions', index)}
                        id={id(`condition_${index}`)}
                    />
                ))}
                <InputError message={form.errors.conditions} className="mt-2" />
            </Section>

            <Section
                title={t('issue_alerts.fields.filter_match')}
                match={form.data.filter_match}
                matchOptions={catalog.matchOptions}
                onMatch={(value) => form.setData('filter_match', value)}
                onAdd={() =>
                    addRow('filters', { type: 'level', comparison: 'eq', value: '', key: '' })
                }
                addLabel={t('issue_alerts.fields.add_filter')}
                empty={form.data.filters.length === 0}
                id={id('filter_match')}
            >
                {form.data.filters.map((filter, index) => (
                    <FilterRow
                        key={index}
                        index={index}
                        filter={filter}
                        options={catalog.filterOptions}
                        comparisonOptions={catalog.comparisonOptions}
                        errors={form.errors}
                        onChange={(patch) => setRow('filters', index, patch)}
                        onRemove={() => removeRow('filters', index)}
                        id={id(`filter_${index}`)}
                    />
                ))}
            </Section>

            <Section
                onAdd={() => addRow('actions', { type: 'channel', channel_id: '' })}
                addLabel={t('issue_alerts.fields.add_action')}
                empty={form.data.actions.length === 0}
                title={t('issue_alerts.list.actions')}
            >
                {form.data.actions.map((action, index) => (
                    <ActionRow
                        key={index}
                        index={index}
                        action={action}
                        options={catalog.actionOptions}
                        channelOptions={catalog.channelOptions}
                        errors={form.errors}
                        onChange={(patch) => setRow('actions', index, patch)}
                        onRemove={() => removeRow('actions', index)}
                        id={id(`action_${index}`)}
                    />
                ))}
                <InputError message={form.errors.actions} className="mt-2" />
            </Section>

            <div className="flex flex-wrap items-center gap-2">
                <PrimaryButton type="submit" disabled={form.processing}>
                    {rule === null
                        ? t('issue_alerts.actions.create')
                        : t('issue_alerts.actions.save')}
                </PrimaryButton>

                <SecondaryButton type="button" disabled={previewing} onClick={showPreview}>
                    {previewing
                        ? t('issue_alerts.actions.previewing')
                        : t('issue_alerts.actions.preview')}
                </SecondaryButton>
            </div>

            {preview && <Preview preview={preview} />}
        </form>
    );
}

// Ein Abschnitt des Formulars: die Überschrift mit dem „alle/eine"-Schalter und
// darunter die Zeilen.
function Section({ title, match, matchOptions, onMatch, onAdd, addLabel, id, empty, children }) {
    const t = useT();

    return (
        <div className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <InputLabel htmlFor={id} value={title} className="mb-0" />
                {match !== undefined && (
                    <SelectInput
                        id={id}
                        className="max-w-48"
                        value={match}
                        options={matchOptions}
                        onChange={(e) => onMatch(e.target.value)}
                    />
                )}
                <SecondaryButton type="button" className="ms-auto" onClick={onAdd}>
                    {addLabel}
                </SecondaryButton>
            </div>

            <div className="space-y-2">
                {children}
                {empty && (
                    <p className="text-sm text-gray-600 dark:text-gray-400">
                        {t('issue_alerts.list.no_filters')}
                    </p>
                )}
            </div>
        </div>
    );
}

function RemoveButton({ onRemove }) {
    const t = useT();

    return (
        <SecondaryButton type="button" onClick={onRemove}>
            {t('issue_alerts.fields.remove')}
        </SecondaryButton>
    );
}

function ConditionRow({ index, condition, options, errors, onChange, onRemove, id }) {
    const t = useT();
    const option = options.find((entry) => entry.value === condition.type);

    return (
        <div className="grid items-end gap-2 sm:grid-cols-[2fr_1fr_1fr_auto]">
            <div>
                <SelectInput
                    id={id}
                    value={condition.type}
                    options={options}
                    onChange={(e) => onChange({ type: e.target.value })}
                />
                <InputError message={errors[`conditions.${index}.type`]} className="mt-1" />
            </div>

            {option?.hasValue ? (
                <div>
                    <InputLabel
                        htmlFor={`${id}_value`}
                        value={t('issue_alerts.fields.value')}
                        className="text-xs"
                    />
                    <TextInput
                        id={`${id}_value`}
                        type="number"
                        min="1"
                        value={condition.value}
                        onChange={(e) => onChange({ value: e.target.value })}
                    />
                    <InputError message={errors[`conditions.${index}.value`]} className="mt-1" />
                </div>
            ) : (
                <div />
            )}

            {option?.window ? (
                <div>
                    <InputLabel
                        htmlFor={`${id}_window`}
                        value={t(
                            option.window === 'hours'
                                ? 'issue_alerts.fields.window_hours'
                                : 'issue_alerts.fields.window_minutes'
                        )}
                        className="text-xs"
                    />
                    <TextInput
                        id={`${id}_window`}
                        type="number"
                        min="1"
                        value={condition.window}
                        onChange={(e) => onChange({ window: e.target.value })}
                    />
                    <InputError message={errors[`conditions.${index}.window`]} className="mt-1" />
                </div>
            ) : (
                <div />
            )}

            <RemoveButton onRemove={onRemove} />
        </div>
    );
}

function FilterRow({ index, filter, options, comparisonOptions, errors, onChange, onRemove, id }) {
    const t = useT();
    const option = options.find((entry) => entry.value === filter.type);

    // Nur die Vergleiche, die zu dieser Einschränkung gehören: „Alter enthält"
    // wäre eine Auswahl, die die Prüfung anschließend abweist.
    const allowed = comparisonOptions.filter((entry) =>
        (option?.comparisons ?? []).includes(entry.value)
    );

    return (
        <div className="grid items-end gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]">
            <div>
                <SelectInput
                    id={id}
                    value={filter.type}
                    options={options}
                    onChange={(e) => {
                        const next = options.find((entry) => entry.value === e.target.value);

                        onChange({
                            type: e.target.value,
                            comparison: next?.comparisons?.[0] ?? 'eq',
                        });
                    }}
                />
                <InputError message={errors[`filters.${index}.type`]} className="mt-1" />
            </div>

            {option?.hasKey ? (
                <div>
                    <InputLabel
                        htmlFor={`${id}_key`}
                        value={t('issue_alerts.fields.tag_key')}
                        className="text-xs"
                    />
                    <TextInput
                        id={`${id}_key`}
                        value={filter.key}
                        onChange={(e) => onChange({ key: e.target.value })}
                    />
                    <InputError message={errors[`filters.${index}.key`]} className="mt-1" />
                </div>
            ) : (
                <div />
            )}

            <div>
                <InputLabel
                    htmlFor={`${id}_comparison`}
                    value={t('issue_alerts.fields.comparison')}
                    className="text-xs"
                />
                <SelectInput
                    id={`${id}_comparison`}
                    value={filter.comparison}
                    options={allowed}
                    onChange={(e) => onChange({ comparison: e.target.value })}
                />
                <InputError message={errors[`filters.${index}.comparison`]} className="mt-1" />
            </div>

            <div>
                <InputLabel
                    htmlFor={`${id}_value`}
                    value={t('issue_alerts.fields.filter_value')}
                    className="text-xs"
                />
                <TextInput
                    id={`${id}_value`}
                    type={option?.numeric ? 'number' : 'text'}
                    value={filter.value}
                    onChange={(e) => onChange({ value: e.target.value })}
                />
                <InputError message={errors[`filters.${index}.value`]} className="mt-1" />
            </div>

            <RemoveButton onRemove={onRemove} />
        </div>
    );
}

function ActionRow({ index, action, options, channelOptions, errors, onChange, onRemove, id }) {
    const t = useT();

    return (
        <div className="grid items-end gap-2 sm:grid-cols-[2fr_2fr_auto]">
            <div>
                <SelectInput
                    id={id}
                    value={action.type}
                    options={options}
                    onChange={(e) => onChange({ type: e.target.value })}
                />
                <InputError message={errors[`actions.${index}.type`]} className="mt-1" />
            </div>

            {action.type === 'channel' ? (
                <div>
                    <InputLabel
                        htmlFor={`${id}_channel`}
                        value={t('issue_alerts.fields.channel')}
                        className="text-xs"
                    />
                    <SelectInput
                        id={`${id}_channel`}
                        value={action.channel_id}
                        placeholder={t('issue_alerts.fields.all_channels')}
                        options={channelOptions.map((channel) => ({
                            value: channel.value,
                            label: channel.active
                                ? channel.label
                                : `${channel.label} ${t('issue_alerts.fields.channel_inactive')}`,
                        }))}
                        onChange={(e) => onChange({ channel_id: e.target.value })}
                    />
                    <InputError message={errors[`actions.${index}.channel_id`]} className="mt-1" />
                </div>
            ) : (
                <div />
            )}

            <RemoveButton onRemove={onRemove} />
        </div>
    );
}

// Die Vorschau: welche Fehler diese Regel derzeit träfe.
function Preview({ preview }) {
    const t = useT();

    return (
        <div className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <p className="text-xs text-gray-500 dark:text-gray-400">
                {t('issue_alerts.preview.caption', { days: preview.lookbackDays })}{' '}
                {t('issue_alerts.preview.summary', {
                    matched: preview.matched,
                    scanned: preview.scanned,
                })}
                {preview.truncated
                    ? ` ${t('issue_alerts.preview.truncated', { limit: preview.issues.length })}`
                    : ''}
            </p>

            {preview.issues.length === 0 ? (
                <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {t('issue_alerts.preview.empty', { days: preview.lookbackDays })}
                </p>
            ) : (
                <ul className="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    {preview.issues.map((issue) => (
                        <li key={issue.id} className="flex flex-wrap items-center gap-3 py-2">
                            <a
                                href={issue.href}
                                className="font-medium text-gray-900 underline dark:text-gray-100"
                            >
                                {issue.title}
                            </a>
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                {issue.levelLabel} · {issue.timesSeenLabel}
                            </span>
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                {t('issue_alerts.preview.reasons')} {issue.reasons.join(', ')}
                            </span>
                            <span className="ms-auto text-xs text-gray-500 dark:text-gray-400">
                                {issue.lastSeenLabel}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {t('issue_alerts.preview.note', { days: preview.lookbackDays })}
            </p>
        </div>
    );
}

// Der Verlauf über alle Regeln des Projekts.
function History({ history }) {
    const t = useT();

    return (
        <Card
            title={t('issue_alerts.history.title')}
            description={t('issue_alerts.history.description')}
        >
            {history.length === 0 ? (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    {t('issue_alerts.history.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    {history.map((entry) => (
                        <li key={entry.id} className="flex flex-wrap items-center gap-3 py-2">
                            <span className="font-medium text-gray-900 dark:text-gray-100">
                                {entry.rule}
                            </span>
                            {entry.issueHref ? (
                                <a
                                    href={entry.issueHref}
                                    className="text-gray-600 underline dark:text-gray-400"
                                >
                                    {entry.issue}
                                </a>
                            ) : (
                                <span className="text-gray-600 dark:text-gray-400">
                                    {entry.issue}
                                </span>
                            )}
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                {entry.reasons.join(', ')}
                            </span>
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                {entry.deliveryCount === 0
                                    ? t('issue_alerts.history.no_deliveries')
                                    : t('issue_alerts.history.deliveries', {
                                          count: entry.deliveryCount,
                                      })}
                            </span>
                            <span className="ms-auto text-xs text-gray-500 dark:text-gray-400">
                                {entry.occurredAtLabel}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
