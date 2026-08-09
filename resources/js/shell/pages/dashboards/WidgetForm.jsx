import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';
import { useTranslations } from '../../i18n.js';

// Das Formular einer Kachel: Überschrift, Darstellung, Abfrage — und was die
// Kachel an der Filterleiste für sich anders sieht.
//
// **Die Abfrage steht in denselben Feldern wie in der freien Auswertung.** Die
// Auswahl kommt vollständig vom Server (`catalog`): welche Quellen es gibt,
// wonach sich in ihnen gruppieren und worüber sich rechnen lässt. Eine Liste,
// die hier entstünde, böte früher oder später etwas an, das der Motor abweist.
//
// **Ein Quellenwechsel nimmt mit, was es dort auch gibt.** Alles stehen zu
// lassen hieße, die Kachel mit Feldern zu speichern, die der Motor als unbekannt
// zurückweist; alles zu leeren hieße, jemandem die Arbeit zweimal aufzugeben,
// der nur zwei Quellen vergleichen will.
export default function WidgetForm({
    widget,
    catalog,
    grid,
    projectOptions,
    environments,
    action,
    method,
    onClose,
}) {
    const { t } = useTranslations();
    const query = widget?.query ?? {};
    const overrides = widget?.overrides ?? {};

    const form = useForm({
        title: widget?.title ?? '',
        type: widget?.type ?? 'line',
        dataset: query.dataset ?? 'errors',
        fields: query.fields ?? [],
        metrics: (query.metrics ?? ['count()']).map(parseMetric),
        q: query.q ?? '',
        sort: query.sort ?? '',
        limit: String(query.limit ?? 10),
        interval: query.interval ?? '',
        overrides: {
            period: overrides.period ?? '',
            from: overrides.from ?? '',
            to: overrides.to ?? '',
            environment: overrides.environment ?? '',
            project: overrides.project ?? '',
        },
    });

    const [showOverrides, setShowOverrides] = useState(
        Boolean(overrides.period || overrides.environment || overrides.project)
    );

    const dataset =
        catalog.datasets.find((option) => option.value === form.data.dataset) ??
        catalog.datasets[0];
    const type = grid.types.find((option) => option.value === form.data.type);

    const changeDataset = (value) => {
        const next = catalog.datasets.find((option) => option.value === value);

        form.setData({
            ...form.data,
            dataset: value,
            fields: form.data.fields.filter((field) => next?.group_by.includes(field)),
            metrics: form.data.metrics.filter(
                (metric) => metric.field === '' || (next?.aggregate ?? []).includes(metric.field)
            ),
        });
    };

    const submit = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            metrics: data.metrics.map(metricToString),
            limit: Number(data.limit) || 10,
        }));

        form[method](action, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Card className="mb-4">
            <form onSubmit={submit}>
                <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {widget ? t('dashboards.widget.edit') : t('dashboards.widget.add')}
                </h2>

                <div className="mt-4 grid gap-4 md:grid-cols-3">
                    <div className="md:col-span-2">
                        <InputLabel htmlFor="widget_title" value={t('dashboards.widget.title')} />
                        <TextInput
                            id="widget_title"
                            className="mt-1 w-full"
                            value={form.data.title}
                            maxLength={120}
                            onChange={(e) => form.setData('title', e.target.value)}
                        />
                        <InputError message={form.errors.title} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="widget_type" value={t('dashboards.widget.type')} />
                        <SelectInput
                            id="widget_type"
                            className="mt-1 w-full"
                            value={form.data.type}
                            options={grid.types.map((option) => ({
                                value: option.value,
                                label: option.label,
                            }))}
                            onChange={(e) => form.setData('type', e.target.value)}
                        />
                        <InputError message={form.errors.type} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="widget_dataset"
                            value={t('dashboards.widget.dataset')}
                        />
                        <SelectInput
                            id="widget_dataset"
                            className="mt-1 w-full"
                            value={form.data.dataset}
                            options={catalog.datasets.map((option) => ({
                                value: option.value,
                                label: option.label,
                            }))}
                            onChange={(e) => changeDataset(e.target.value)}
                        />
                    </div>

                    <div className="md:col-span-2">
                        <InputLabel value={t('dashboards.widget.fields')} />
                        <div className="mt-1 flex flex-wrap gap-2">
                            {[0, 1].map((index) => (
                                <SelectInput
                                    key={index}
                                    aria-label={`${t('dashboards.widget.fields')} ${index + 1}`}
                                    value={form.data.fields[index] ?? ''}
                                    placeholder={t('dashboards.widget.field_none')}
                                    options={(dataset?.group_by ?? []).map((field) => ({
                                        value: field,
                                        label: field,
                                    }))}
                                    onChange={(e) => {
                                        const fields = [...form.data.fields];

                                        if (e.target.value === '') {
                                            fields.splice(index, 1);
                                        } else {
                                            fields[index] = e.target.value;
                                        }

                                        form.setData('fields', fields.filter(Boolean));
                                    }}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="md:col-span-3">
                        <InputLabel value={t('dashboards.widget.metrics')} />
                        <div className="mt-1 space-y-2">
                            {form.data.metrics.map((metric, index) => (
                                <div key={index} className="flex flex-wrap items-center gap-2">
                                    <SelectInput
                                        aria-label={`${t('dashboards.widget.metrics')} ${index + 1}`}
                                        value={metric.aggregate}
                                        options={catalog.aggregates.map((option) => ({
                                            value: option.value,
                                            label: option.label,
                                        }))}
                                        onChange={(e) =>
                                            setMetric(form, index, {
                                                ...metric,
                                                aggregate: e.target.value,
                                                field: needsField(catalog, e.target.value)
                                                    ? metric.field
                                                    : '',
                                            })
                                        }
                                    />

                                    {needsField(catalog, metric.aggregate) && (
                                        <SelectInput
                                            aria-label={t('dashboards.widget.metric_field')}
                                            value={metric.field}
                                            placeholder={t('dashboards.widget.field_none')}
                                            options={(dataset?.aggregate ?? []).map((field) => ({
                                                value: field,
                                                label: field,
                                            }))}
                                            onChange={(e) =>
                                                setMetric(form, index, {
                                                    ...metric,
                                                    field: e.target.value,
                                                })
                                            }
                                        />
                                    )}

                                    {form.data.metrics.length > 1 && (
                                        <SecondaryButton
                                            type="button"
                                            onClick={() =>
                                                form.setData(
                                                    'metrics',
                                                    form.data.metrics.filter(
                                                        (_, position) => position !== index
                                                    )
                                                )
                                            }
                                        >
                                            −
                                        </SecondaryButton>
                                    )}
                                </div>
                            ))}

                            <SecondaryButton
                                type="button"
                                onClick={() =>
                                    form.setData('metrics', [
                                        ...form.data.metrics,
                                        { aggregate: 'count', field: '' },
                                    ])
                                }
                            >
                                +
                            </SecondaryButton>
                        </div>
                        <InputError message={form.errors.metrics} className="mt-1" />
                    </div>

                    <div className="md:col-span-2">
                        <InputLabel htmlFor="widget_q" value={t('dashboards.widget.search')} />
                        <TextInput
                            id="widget_q"
                            className="mt-1 w-full"
                            value={form.data.q}
                            placeholder={t('dashboards.widget.search_placeholder')}
                            onChange={(e) => form.setData('q', e.target.value)}
                        />
                        <InputError message={form.errors.q} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="widget_sort" value={t('dashboards.widget.sort')} />
                        <TextInput
                            id="widget_sort"
                            className="mt-1 w-full"
                            value={form.data.sort}
                            placeholder={t('dashboards.widget.sort_placeholder')}
                            onChange={(e) => form.setData('sort', e.target.value)}
                        />
                    </div>

                    {/* Zeilenzahl und Schrittweite gehören zu verschiedenen
                        Darstellungen: ein Verlauf hat Stützstellen, eine
                        Rangliste Zeilen. Angezeigt wird deshalb nur, was zur
                        gewählten Darstellung gehört. */}
                    {type?.series ? (
                        <div>
                            <InputLabel
                                htmlFor="widget_interval"
                                value={t('dashboards.widget.interval')}
                            />
                            <SelectInput
                                id="widget_interval"
                                className="mt-1 w-full"
                                value={form.data.interval}
                                placeholder={t('dashboards.widget.interval_auto')}
                                options={catalog.intervals}
                                onChange={(e) => form.setData('interval', e.target.value)}
                            />
                        </div>
                    ) : (
                        <div>
                            <InputLabel
                                htmlFor="widget_limit"
                                value={t('dashboards.widget.limit')}
                            />
                            <TextInput
                                id="widget_limit"
                                type="number"
                                min="1"
                                max={catalog.limits.maxRows}
                                className="mt-1 w-full"
                                value={form.data.limit}
                                onChange={(e) => form.setData('limit', e.target.value)}
                            />
                        </div>
                    )}
                </div>

                <div className="mt-4 border-t border-gray-100 pt-3 dark:border-gray-700">
                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <Checkbox
                            checked={showOverrides}
                            onChange={(e) => {
                                setShowOverrides(e.target.checked);

                                if (!e.target.checked) {
                                    form.setData('overrides', {
                                        period: '',
                                        from: '',
                                        to: '',
                                        environment: '',
                                        project: '',
                                    });
                                }
                            }}
                        />
                        {t('dashboards.widget.overrides.title')}
                    </label>

                    {showOverrides && (
                        <>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t('dashboards.widget.overrides.hint')}
                            </p>

                            <div className="mt-3 grid gap-4 md:grid-cols-4">
                                <div>
                                    <InputLabel
                                        htmlFor="widget_period"
                                        value={t('dashboards.widget.overrides.period')}
                                    />
                                    <SelectInput
                                        id="widget_period"
                                        className="mt-1 w-full"
                                        value={form.data.overrides.period}
                                        placeholder={t(
                                            'dashboards.widget.overrides.period_inherit'
                                        )}
                                        options={grid.periods ?? []}
                                        onChange={(e) =>
                                            setOverride(form, 'period', e.target.value)
                                        }
                                    />
                                    <InputError
                                        message={form.errors['overrides.period']}
                                        className="mt-1"
                                    />
                                </div>

                                {form.data.overrides.period === 'custom' && (
                                    <>
                                        <div>
                                            <InputLabel
                                                htmlFor="widget_from"
                                                value={t('dashboards.widget.overrides.from')}
                                            />
                                            <TextInput
                                                id="widget_from"
                                                type="date"
                                                className="mt-1 w-full"
                                                value={form.data.overrides.from}
                                                onChange={(e) =>
                                                    setOverride(form, 'from', e.target.value)
                                                }
                                            />
                                            <InputError
                                                message={form.errors['overrides.from']}
                                                className="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <InputLabel
                                                htmlFor="widget_to"
                                                value={t('dashboards.widget.overrides.to')}
                                            />
                                            <TextInput
                                                id="widget_to"
                                                type="date"
                                                className="mt-1 w-full"
                                                value={form.data.overrides.to}
                                                onChange={(e) =>
                                                    setOverride(form, 'to', e.target.value)
                                                }
                                            />
                                            <InputError
                                                message={form.errors['overrides.to']}
                                                className="mt-1"
                                            />
                                        </div>
                                    </>
                                )}

                                <div>
                                    <InputLabel
                                        htmlFor="widget_environment"
                                        value={t('dashboards.widget.overrides.environment')}
                                    />
                                    <SelectInput
                                        id="widget_environment"
                                        className="mt-1 w-full"
                                        value={form.data.overrides.environment}
                                        placeholder={t(
                                            'dashboards.widget.overrides.environment_inherit'
                                        )}
                                        options={(environments ?? []).map((name) => ({
                                            value: name,
                                            label: name,
                                        }))}
                                        onChange={(e) =>
                                            setOverride(form, 'environment', e.target.value)
                                        }
                                    />
                                </div>

                                <div>
                                    <InputLabel
                                        htmlFor="widget_project"
                                        value={t('dashboards.widget.overrides.project')}
                                    />
                                    <SelectInput
                                        id="widget_project"
                                        className="mt-1 w-full"
                                        value={form.data.overrides.project}
                                        placeholder={t(
                                            'dashboards.widget.overrides.project_inherit'
                                        )}
                                        options={projectOptions.map((option) => ({
                                            value: option.slug,
                                            label: option.name,
                                        }))}
                                        onChange={(e) =>
                                            setOverride(form, 'project', e.target.value)
                                        }
                                    />
                                </div>
                            </div>
                        </>
                    )}
                </div>

                <div className="mt-4 flex items-center gap-2">
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {t('dashboards.widget.submit')}
                    </PrimaryButton>
                    <SecondaryButton type="button" onClick={onClose}>
                        {t('dashboards.widget.cancel')}
                    </SecondaryButton>
                </div>
            </form>
        </Card>
    );
}

function setMetric(form, index, metric) {
    const metrics = [...form.data.metrics];

    metrics[index] = metric;

    form.setData('metrics', metrics);
}

function setOverride(form, key, value) {
    form.setData('overrides', { ...form.data.overrides, [key]: value });
}

function needsField(catalog, aggregate) {
    return catalog.aggregates.find((option) => option.value === aggregate)?.needsField ?? false;
}

// `p95(duration)` ⇄ { aggregate: 'p95', field: 'duration' } — dieselbe
// Schreibweise wie in der Adresszeile der freien Auswertung und in der
// Datenbank.
function parseMetric(metric) {
    const match = /^([a-z0-9_]+)\((.*)\)$/i.exec(String(metric).trim());

    return match ? { aggregate: match[1], field: match[2] } : { aggregate: 'count', field: '' };
}

function metricToString(metric) {
    return `${metric.aggregate}(${metric.field ?? ''})`;
}
