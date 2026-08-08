import React, { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
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

// Die Form eines mehrzeiligen Feldes. Sie steht hier und nicht in Form.jsx, weil
// sie in dieser Anwendung an drei Stellen vorkommt und überall dieselbe ist —
// eine gemeinsame Komponente dafür wäre eine Zeile Ersparnis und eine Datei
// mehr, die man beim Lesen aufschlagen muss.
const TEXTAREA_CLASS =
    'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';

// Die Zuständigkeits-Regeln eines Projekts: wem ein Fehler gehört, abgeleitet
// aus dem Ort, an dem er passiert ist. Ansehen darf jedes Mitglied — die Liste
// ist die Antwort auf „warum steht mein Name an diesem Fehler?". Ändern darf nur
// die Verwaltung; entschieden wird das serverseitig, `canManage` blendet hier
// lediglich aus, was ohnehin abgewiesen würde.
export default function Ownership({
    project,
    organization,
    rules,
    matcherOptions,
    limits,
    canManage,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('ownership.title', { project: project.name })}
                appName={shell.appName}
                help={t('ownership.help')}
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
                    title={t('ownership.rules.title')}
                    description={t('ownership.rules.description')}
                >
                    <div className="space-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <p>{t('ownership.rules.last_wins_hint')}</p>
                        <p>{t('ownership.rules.not_retroactive_hint')}</p>
                    </div>
                </Card>

                <AutoAssign project={project} canManage={canManage} />

                <Preview project={project} />

                {rules.length === 0 && (
                    <Card>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('ownership.rules.empty')}
                        </p>
                    </Card>
                )}

                {rules.map((rule) => (
                    <RuleCard
                        key={rule.id}
                        rule={rule}
                        matcherOptions={matcherOptions}
                        limits={limits}
                        canManage={canManage}
                    />
                ))}

                {canManage && (
                    <>
                        <CreateRule
                            project={project}
                            matcherOptions={matcherOptions}
                            limits={limits}
                        />
                        <ImportCodeowners project={project} />
                    </>
                )}
            </div>
        </>
    );
}

// Der Schalter, der aus Vorschlägen Zuweisungen macht. Er steht **über** den
// Regeln und nicht in den Projekteinstellungen: die Frage „was passiert, wenn
// ich das anmache?" beantwortet die Liste darunter, und getrennt davon wäre er
// ein Häkchen ohne Zusammenhang.
function AutoAssign({ project, canManage }) {
    const t = useT();
    const [busy, setBusy] = useState(false);

    const toggle = (enabled) => {
        setBusy(true);

        router.patch(
            project.autoAssignHref,
            { enabled },
            { preserveScroll: true, onFinish: () => setBusy(false) }
        );
    };

    return (
        <Card title={t('ownership.auto.title')} description={t('ownership.auto.description')}>
            <label className="flex items-start gap-3">
                <Checkbox
                    checked={project.autoAssign}
                    disabled={!canManage || busy}
                    onChange={(e) => toggle(e.target.checked)}
                />
                <span className="text-sm text-gray-700 dark:text-gray-200">
                    {t('ownership.auto.label')}
                    <span className="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                        {t('ownership.auto.hint')}
                    </span>
                </span>
            </label>
        </Card>
    );
}

// „Wer wäre für so ein Ereignis zuständig?" — die Frage vor dem Einschalten.
//
// Sie geht an den Server und wird nicht hier gerechnet: die Antwort muss
// dieselbe sein wie beim echten Fehler, und eine zweite Auswertung in
// JavaScript wäre genau die zweite Meinung, die eine Vorschau nicht sein darf.
function Preview({ project }) {
    const { shell } = usePage().props;
    const t = useT();
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState(false);
    const form = useForm({ path: '', url: '', module: '', tag_key: '', tag_value: '' });

    // Die Antwort ist eine Auskunft und keine Seite: sie landet im Zustand
    // dieser Karte und nicht in den Seiten-Eigenschaften. Derselbe Weg wie bei
    // der Vorschau der Alarmregeln.
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);

        try {
            const response = await fetch(project.previewHref, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': shell.csrf,
                },
                body: JSON.stringify(form.data),
            });

            setResult(response.ok ? await response.json() : null);
        } catch {
            setResult(null);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Card title={t('ownership.preview.title')} description={t('ownership.preview.description')}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    {['path', 'url', 'module'].map((field) => (
                        <div key={field}>
                            <InputLabel
                                htmlFor={`preview_${field}`}
                                value={t(`ownership.matcher.${field}`)}
                                className="text-xs"
                            />
                            <TextInput
                                id={`preview_${field}`}
                                name={field}
                                value={form.data[field]}
                                placeholder={t(`ownership.preview.placeholder.${field}`)}
                                className="mt-1 w-full font-mono"
                                onChange={(e) => form.setData(field, e.target.value)}
                            />
                        </div>
                    ))}
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div>
                        <InputLabel
                            htmlFor="preview_tag_key"
                            value={t('ownership.preview.tag_key')}
                            className="text-xs"
                        />
                        <TextInput
                            id="preview_tag_key"
                            name="tag_key"
                            value={form.data.tag_key}
                            placeholder={t('ownership.preview.placeholder.tag_key')}
                            className="mt-1 w-full font-mono"
                            onChange={(e) => form.setData('tag_key', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel
                            htmlFor="preview_tag_value"
                            value={t('ownership.preview.tag_value')}
                            className="text-xs"
                        />
                        <TextInput
                            id="preview_tag_value"
                            name="tag_value"
                            value={form.data.tag_value}
                            placeholder={t('ownership.preview.placeholder.tag_value')}
                            className="mt-1 w-full font-mono"
                            onChange={(e) => form.setData('tag_value', e.target.value)}
                        />
                    </div>
                </div>

                <PrimaryButton type="submit" disabled={busy}>
                    {t('ownership.preview.submit')}
                </PrimaryButton>
            </form>

            {result && <PreviewResult result={result} />}
        </Card>
    );
}

function PreviewResult({ result }) {
    const t = useT();

    if (result.empty) {
        return (
            <p className="mt-4 text-sm text-gray-600 dark:text-gray-400">
                {t('ownership.preview.nothing_given')}
            </p>
        );
    }

    return (
        <div className="mt-4 space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
            <p className="text-sm text-gray-800 dark:text-gray-100">
                {result.assignee
                    ? t(
                          result.autoAssign
                              ? 'ownership.preview.would_assign'
                              : 'ownership.preview.would_suggest',
                          { assignee: result.assignee.label }
                      )
                    : t('ownership.preview.nobody')}
            </p>

            {result.matches.length > 0 && (
                <ul className="space-y-1">
                    {result.matches.map((match) => (
                        <li
                            key={match.id}
                            className="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-400"
                        >
                            <code className="rounded bg-gray-100 px-2 py-0.5 font-mono break-all dark:bg-gray-900">
                                {match.expression}
                            </code>
                            <span>{match.owners.join(', ')}</span>
                            {match.winner && (
                                <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                                    {t('ownership.preview.winner')}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function RuleCard({ rule, matcherOptions, limits, canManage }) {
    const t = useT();

    return (
        <Card
            title={
                <span className="flex flex-wrap items-center gap-2">
                    <code className="font-mono text-sm break-all">{rule.expression}</code>
                    {!rule.active && (
                        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-normal text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            {t('ownership.rules.inactive_badge')}
                        </span>
                    )}
                    {rule.source === 'codeowners' && (
                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-600 dark:bg-gray-900 dark:text-gray-300">
                            {t('ownership.rules.from_codeowners')}
                        </span>
                    )}
                </span>
            }
            description={t('ownership.rules.position', { position: rule.position })}
        >
            {canManage ? (
                <div className="space-y-4">
                    <RuleForm rule={rule} matcherOptions={matcherOptions} limits={limits} />
                    <RuleActions rule={rule} />
                </div>
            ) : (
                <p className="text-sm text-gray-600 dark:text-gray-400">{rule.owners.join(', ')}</p>
            )}
        </Card>
    );
}

// Die Felder einer Regel — dieselben beim Anlegen und beim Ändern. Zwei
// Fassungen wären zweimal dieselbe Frage „braucht diese Art einen Schlüssel?",
// und die zweite bekäme eine neue Art nicht mit.
function RuleFields({ idPrefix, data, errors, matcherOptions, limits, onChange }) {
    const t = useT();
    const needsKey =
        matcherOptions.find((option) => option.value === data.matcher)?.needsKey ?? false;

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-3">
                <div>
                    <InputLabel
                        htmlFor={`${idPrefix}_matcher`}
                        value={t('ownership.fields.matcher')}
                    />
                    <SelectInput
                        id={`${idPrefix}_matcher`}
                        name="matcher"
                        value={data.matcher}
                        options={matcherOptions}
                        className="mt-1 w-full"
                        onChange={(e) => onChange('matcher', e.target.value)}
                    />
                    <InputError message={errors.matcher} className="mt-2" />
                </div>

                {needsKey && (
                    <div>
                        <InputLabel
                            htmlFor={`${idPrefix}_tag_key`}
                            value={t('ownership.fields.tag_key')}
                        />
                        <TextInput
                            id={`${idPrefix}_tag_key`}
                            name="tag_key"
                            value={data.tag_key}
                            placeholder={t('ownership.preview.placeholder.tag_key')}
                            className="mt-1 w-full font-mono"
                            onChange={(e) => onChange('tag_key', e.target.value)}
                        />
                        <InputError message={errors.tag_key} className="mt-2" />
                    </div>
                )}

                <div className={needsKey ? '' : 'sm:col-span-2'}>
                    <InputLabel
                        htmlFor={`${idPrefix}_pattern`}
                        value={t('ownership.fields.pattern')}
                    />
                    <TextInput
                        id={`${idPrefix}_pattern`}
                        name="pattern"
                        value={data.pattern}
                        required
                        placeholder={t('ownership.preview.placeholder.path')}
                        className="mt-1 w-full font-mono"
                        onChange={(e) => onChange('pattern', e.target.value)}
                    />
                    <InputError message={errors.pattern} className="mt-2" />
                </div>
            </div>

            <div>
                <InputLabel htmlFor={`${idPrefix}_owners`} value={t('ownership.fields.owners')} />
                {/* Eine Zeile je Zuständigem statt eines Feldes mit Kommas:
                    ein Teamname darf ein Komma enthalten, eine Zeile nicht. */}
                <textarea
                    id={`${idPrefix}_owners`}
                    name="owners"
                    rows={2}
                    value={data.owners}
                    placeholder={t('ownership.fields.owners_placeholder')}
                    className={`${TEXTAREA_CLASS} font-mono text-sm`}
                    onChange={(e) => onChange('owners', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('ownership.fields.owners_hint', { max: limits.maxOwners })}
                </p>
                <InputError message={errors.owners ?? errors['owners.0']} className="mt-2" />
            </div>
        </>
    );
}

// Die Zuständigen fahren als Text durch das Formular und werden erst beim
// Absenden zur Liste. Das ist die Stelle, an der aus „eine Zeile je Person"
// wieder wird, was der Server erwartet.
const toOwnerList = (text) =>
    String(text)
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');

function RuleForm({ rule, matcherOptions, limits }) {
    const t = useT();
    const form = useForm({
        matcher: rule.matcher,
        tag_key: rule.tag_key ?? '',
        pattern: rule.pattern,
        owners: rule.owners.join('\n'),
        position: rule.position,
    });

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({ ...data, owners: toOwnerList(data.owners) })).patch(rule.href, {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <RuleFields
                idPrefix={`rule_${rule.id}`}
                data={form.data}
                errors={form.errors}
                matcherOptions={matcherOptions}
                limits={limits}
                onChange={form.setData}
            />

            <div>
                <InputLabel
                    htmlFor={`position_${rule.id}`}
                    value={t('ownership.fields.position')}
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

            <PrimaryButton type="submit" disabled={form.processing}>
                {t('ownership.save')}
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
                {t(rule.active ? 'ownership.disable' : 'ownership.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(rule.href, { preserveScroll: true })}
            >
                {t('ownership.delete')}
            </DangerButton>
        </div>
    );
}

function CreateRule({ project, matcherOptions, limits }) {
    const t = useT();
    const form = useForm({ matcher: 'path', tag_key: '', pattern: '', owners: '' });

    const submit = (e) => {
        e.preventDefault();

        form.transform((data) => ({ ...data, owners: toOwnerList(data.owners) })).post(
            project.ownershipHref,
            { preserveScroll: true, onSuccess: () => form.reset() }
        );
    };

    return (
        <Card title={t('ownership.create.title')} description={t('ownership.create.description')}>
            <form onSubmit={submit} className="space-y-4">
                <RuleFields
                    idPrefix="new_rule"
                    data={form.data}
                    errors={form.errors}
                    matcherOptions={matcherOptions}
                    limits={limits}
                    onChange={form.setData}
                />

                <PrimaryButton type="submit" disabled={form.processing}>
                    {t('ownership.create.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

// Der Import einer CODEOWNERS-Datei: eingefügt und nicht angebunden. Die Zeilen
// kommen **ans Ende** der Liste und überstimmen damit alles darüber — dieselbe
// Auflösung wie in der Datei selbst.
function ImportCodeowners({ project }) {
    const t = useT();
    const form = useForm({ contents: '' });

    const submit = (e) => {
        e.preventDefault();

        form.post(project.importHref, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Card title={t('ownership.import.title')} description={t('ownership.import.description')}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="codeowners" value={t('ownership.import.label')} />
                    <textarea
                        id="codeowners"
                        name="contents"
                        rows={6}
                        value={form.data.contents}
                        required
                        placeholder={t('ownership.import.placeholder')}
                        className={`${TEXTAREA_CLASS} font-mono text-xs`}
                        onChange={(e) => form.setData('contents', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('ownership.import.hint')}
                    </p>
                    <InputError message={form.errors.contents} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={form.processing}>
                    {t('ownership.import.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}
