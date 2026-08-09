import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { InputError, PrimaryButton, TextInput } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Die Kontingent-Seite — dieselbe für ein Projekt und für eine Organisation.
// Was sich unterscheidet, steht in den Props: der Gegenstand, die geerbten
// Grenzen der Organisation und die Liste der Schlüssel gibt es nur beim
// Projekt. Zwei Seiten wären zwei Stellen, an denen eine neue Datenart
// nachzupflegen ist.
export default function Quotas({
    scope,
    organization,
    project,
    updateHref,
    periodLabel,
    windowDays,
    categories,
    inherited,
    keys,
    discards,
    permissions,
}) {
    const { shell } = usePage().props;
    const t = useT();
    const subject = project ? project.name : organization.name;

    return (
        <>
            <PageHead
                title={t('quotas.title', { subject })}
                appName={shell.appName}
                help={t('quotas.help')}
                meta={
                    <div className="flex items-center gap-3">
                        {project && (
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
                <Card title={t('quotas.intro.title')} description={t('quotas.intro.description')}>
                    <div className="space-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <p>{t('quotas.intro.unlimited_hint')}</p>
                        <p>{t('quotas.intro.separate_hint')}</p>
                        <p>{t('quotas.intro.warning_hint')}</p>
                        <p>{t('quotas.intro.period', { period: periodLabel })}</p>
                    </div>
                </Card>

                {permissions.manage ? (
                    <Settings categories={categories} updateHref={updateHref} />
                ) : (
                    <ReadOnlySettings categories={categories} />
                )}

                {scope === 'project' && <Inherited rows={inherited} organization={organization} />}

                {scope === 'project' && <Keys keys={keys} />}

                <Discards discards={discards} windowDays={windowDays} />
            </div>
        </>
    );
}

// Das Formular: alle Datenarten auf einmal. Sie teilen sich einen Vorrat — wer
// die Fehler anhebt, will im selben Blick sehen, was das für die Transaktionen
// bedeutet.
function Settings({ categories, updateHref }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        quotas: Object.fromEntries(
            categories.map((category) => [
                category.value,
                {
                    per_month: category.perMonth ?? '',
                    per_minute: category.perMinute ?? '',
                },
            ])
        ),
    });

    const setValue = (category, field, value) => {
        setData('quotas', {
            ...data.quotas,
            [category]: { ...data.quotas[category], [field]: value },
        });
    };

    const submit = (e) => {
        e.preventDefault();
        patch(updateHref, { preserveScroll: true });
    };

    return (
        <Card title={t('quotas.settings.title')} description={t('quotas.settings.description')}>
            <form onSubmit={submit} className="space-y-4">
                {categories.map((category) => (
                    <div
                        key={category.value}
                        className="grid grid-cols-1 gap-4 border-b border-gray-200 pb-4 last:border-0 last:pb-0 md:grid-cols-3 dark:border-gray-700"
                    >
                        <div>
                            <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {category.label}
                            </p>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {category.hint}
                            </p>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t('quotas.settings.usage')}: <Usage category={category} />
                            </p>
                        </div>

                        <div>
                            <label
                                htmlFor={`per_month_${category.value}`}
                                className="block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                {t('quotas.settings.per_month')}
                            </label>
                            <TextInput
                                id={`per_month_${category.value}`}
                                name={`per_month_${category.value}`}
                                type="number"
                                min="1"
                                value={data.quotas[category.value].per_month}
                                className="mt-1"
                                onChange={(e) =>
                                    setValue(category.value, 'per_month', e.target.value)
                                }
                            />
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t('quotas.settings.per_month_hint')}
                            </p>
                            <InputError
                                message={errors[`quotas.${category.value}.per_month`]}
                                className="mt-2"
                            />
                        </div>

                        <div>
                            <label
                                htmlFor={`per_minute_${category.value}`}
                                className="block text-sm font-medium text-gray-700 dark:text-gray-300"
                            >
                                {t('quotas.settings.per_minute')}
                            </label>
                            <TextInput
                                id={`per_minute_${category.value}`}
                                name={`per_minute_${category.value}`}
                                type="number"
                                min="1"
                                value={data.quotas[category.value].per_minute}
                                className="mt-1"
                                onChange={(e) =>
                                    setValue(category.value, 'per_minute', e.target.value)
                                }
                            />
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t('quotas.settings.per_minute_hint')}
                            </p>
                            <InputError
                                message={errors[`quotas.${category.value}.per_minute`]}
                                className="mt-2"
                            />
                        </div>
                    </div>
                ))}

                <PrimaryButton type="submit" disabled={processing}>
                    {t('quotas.settings.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

function ReadOnlySettings({ categories }) {
    const t = useT();

    return (
        <Card
            title={t('quotas.settings.title')}
            description={t('quotas.settings.read_only_description')}
        >
            <dl className="divide-y divide-gray-200 dark:divide-gray-700">
                {categories.map((category) => (
                    <div key={category.value} className="flex justify-between gap-3 py-2 text-sm">
                        <dt className="text-gray-500 dark:text-gray-400">{category.label}</dt>
                        <dd className="text-gray-900 dark:text-gray-100">
                            <Usage category={category} />
                        </dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

// „412.000 von 515.000 (80 %)" — oder ohne Grenze nur die verbrauchte Zahl.
// Der Anteil ohne Grenze wäre ein Anteil von nichts.
function Usage({ category }) {
    const t = useT();

    if (category.perMonth === null) {
        return (
            <span>
                {category.usageLabel} · {t('quotas.settings.unlimited')}
            </span>
        );
    }

    return (
        <span className={category.percent >= 100 ? 'text-rose-600 dark:text-rose-400' : undefined}>
            {t('quotas.settings.usage_of', {
                usage: category.usageLabel,
                limit: category.perMonth,
            })}{' '}
            ({t('quotas.settings.percent', { percent: category.percent })})
        </span>
    );
}

// Die Grenzen der Organisation über dem Projekt: wer hier ein großzügiges
// eigenes Kontingent sieht und trotzdem abgewiesen wird, sucht sonst an der
// falschen Stelle.
function Inherited({ rows, organization }) {
    const t = useT();

    return (
        <Card title={t('quotas.inherited.title')} description={t('quotas.inherited.description')}>
            {rows.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('quotas.inherited.empty')}
                </p>
            ) : (
                <dl className="divide-y divide-gray-200 dark:divide-gray-700">
                    {rows.map((row) => (
                        <div key={row.value} className="flex justify-between gap-3 py-2 text-sm">
                            <dt className="text-gray-500 dark:text-gray-400">{row.label}</dt>
                            <dd className="text-gray-900 dark:text-gray-100">
                                {row.perMonth === null
                                    ? `${row.usageLabel} · ${t('quotas.settings.unlimited')}`
                                    : t('quotas.settings.usage_of', {
                                          usage: row.usageLabel,
                                          limit: row.perMonth,
                                      })}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            <Link
                href={organization.quotasHref}
                className="mt-4 inline-block text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
            >
                {t('quotas.inherited.link')}
            </Link>
        </Card>
    );
}

function Keys({ keys }) {
    const t = useT();

    return (
        <Card title={t('quotas.keys.title')} description={t('quotas.keys.description')}>
            {keys.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">{t('quotas.keys.empty')}</p>
            ) : (
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <th className="py-2">{t('quotas.keys.name')}</th>
                            <th className="py-2">{t('quotas.keys.per_minute')}</th>
                            <th className="py-2">{t('quotas.keys.usage')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                        {keys.map((key) => (
                            <tr key={key.id}>
                                <td className="py-2 text-gray-900 dark:text-gray-100">
                                    {key.name}
                                    {!key.active && (
                                        <span className="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                            {t('quotas.keys.inactive')}
                                        </span>
                                    )}
                                </td>
                                <td className="py-2 text-gray-600 dark:text-gray-300">
                                    {key.perMinute === null
                                        ? t('quotas.keys.unlimited')
                                        : key.perMinute}
                                </td>
                                <td className="py-2 text-gray-600 dark:text-gray-300">
                                    {key.usage}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Card>
    );
}

// Was verworfen wurde, steht auf derselben Seite wie die Grenzen — sonst ist
// eine gerissene Grenze eine stille Lücke in den Daten.
function Discards({ discards, windowDays }) {
    const t = useT();

    return (
        <Card
            title={t('quotas.discards.title')}
            description={t('quotas.discards.description', { days: windowDays })}
        >
            {discards.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('quotas.discards.empty')}
                </p>
            ) : (
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <th className="py-2">{t('quotas.discards.reason')}</th>
                            <th className="py-2">{t('quotas.discards.origin')}</th>
                            <th className="py-2 text-right">{t('quotas.discards.quantity')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                        {discards.map((row) => (
                            <tr key={`${row.origin}-${row.reason}`}>
                                <td className="py-2 text-gray-900 dark:text-gray-100">
                                    {row.reasonLabel}
                                </td>
                                <td className="py-2 text-gray-600 dark:text-gray-300">
                                    {row.originLabel}
                                </td>
                                <td className="py-2 text-right text-gray-900 dark:text-gray-100">
                                    {row.quantityLabel}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Card>
    );
}
