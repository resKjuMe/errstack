import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    InputError,
    InputLabel,
    PrimaryButton,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Die Schwellen der Leistungserkennung eines Projekts.
//
// Ein Formular über alle Muster und nicht eines je Muster: die Werte werden im
// Zusammenhang gesetzt. Wer die Schwelle für N+1 anhebt, weil zu viel in der
// Liste steht, will im selben Zug sehen, wie die benachbarten Muster stehen —
// und nicht acht Mal speichern.
//
// Ansehen darf jedes Mitglied; sie erklären, warum ein bekanntes Problem nicht
// in der Liste auftaucht. Ändern darf die Verwaltung; entschieden wird das
// serverseitig, `permissions.manage` blendet hier nur aus, was ohnehin
// abgewiesen würde.
export default function Performance({ project, organization, problems, issuesHref, permissions }) {
    const { shell } = usePage().props;
    const t = useT();

    // Der Ausgangszustand kommt vom Server und ist zugleich die Form, in der
    // gespeichert wird: ein Feld-Baum je Muster. Damit ist die Zuordnung von
    // Eingabefeld zu Prüfregel dieselbe wie serverseitig, und ein Fehler landet
    // an dem Feld, das ihn ausgelöst hat.
    const form = useForm({
        problems: Object.fromEntries(
            problems.map((problem) => [
                problem.value,
                {
                    enabled: problem.enabled,
                    thresholds: Object.fromEntries(
                        problem.thresholds.map((threshold) => [threshold.key, threshold.value])
                    ),
                },
            ])
        ),
    });

    const setEnabled = (problem, enabled) => {
        form.setData('problems', {
            ...form.data.problems,
            [problem]: { ...form.data.problems[problem], enabled },
        });
    };

    const setThreshold = (problem, key, value) => {
        const current = form.data.problems[problem];

        form.setData('problems', {
            ...form.data.problems,
            [problem]: {
                ...current,
                thresholds: { ...current.thresholds, [key]: value },
            },
        });
    };

    const submit = (event) => {
        event.preventDefault();
        form.patch(project.updateHref, { preserveScroll: true });
    };

    return (
        <>
            <PageHead
                title={t('performance_issues.settings.title')}
                appName={shell.appName}
                help={t('performance_issues.settings.help')}
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

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <p className="text-sm text-gray-600 dark:text-gray-400">
                        {t('performance_issues.settings.help')}
                    </p>
                    <p className="mt-2 text-sm">
                        <Link href={issuesHref} className="underline">
                            {t('performance_issues.settings.issues_link')}
                        </Link>
                    </p>
                    {!permissions.manage && (
                        <p className="mt-2 text-sm text-amber-700 dark:text-amber-300">
                            {t('performance_issues.settings.read_only')}
                        </p>
                    )}
                </Card>

                {problems.map((problem) => (
                    <ProblemCard
                        key={problem.value}
                        problem={problem}
                        data={form.data.problems[problem.value]}
                        errors={form.errors}
                        canManage={permissions.manage}
                        onToggle={(enabled) => setEnabled(problem.value, enabled)}
                        onThreshold={(key, value) => setThreshold(problem.value, key, value)}
                        t={t}
                    />
                ))}

                {permissions.manage && (
                    <div className="flex justify-end">
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {t('performance_issues.settings.save')}
                        </PrimaryButton>
                    </div>
                )}
            </form>
        </>
    );
}

function ProblemCard({ problem, data, errors, canManage, onToggle, onThreshold, t }) {
    return (
        <Card title={problem.label} description={problem.description}>
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <Checkbox
                    checked={data.enabled}
                    disabled={!canManage}
                    onChange={(e) => onToggle(e.target.checked)}
                />
                {t('performance_issues.settings.enabled')}
            </label>

            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {problem.thresholds.map((threshold) => {
                    const field = `problems.${problem.value}.thresholds.${threshold.key}`;
                    const value = data.thresholds[threshold.key];
                    const changed = Number(value) !== Number(threshold.default);

                    return (
                        <div key={threshold.key}>
                            <InputLabel
                                htmlFor={`${problem.value}_${threshold.key}`}
                                value={threshold.label}
                            />
                            <TextInput
                                id={`${problem.value}_${threshold.key}`}
                                className="mt-1 w-full"
                                type="number"
                                inputMode="numeric"
                                min={threshold.min}
                                max={threshold.max}
                                value={value}
                                disabled={!canManage || !data.enabled}
                                onChange={(e) => onThreshold(threshold.key, e.target.value)}
                            />
                            {/* Die Vorgabe steht immer dabei — sonst sieht ein
                                eingestellter Wert aus wie ein geerbter, und wer
                                zurückstellen will, wüsste nicht, worauf. */}
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {changed
                                    ? t('performance_issues.settings.changed_hint', {
                                          value: threshold.default,
                                      })
                                    : t('performance_issues.settings.default_hint', {
                                          value: threshold.default,
                                      })}
                            </p>
                            <InputError message={errors[field]} className="mt-1" />
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}
